<?php

declare(strict_types=1);

namespace Customize\Service\AmazonPay;

use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;

/**
 * AmazonPay v2 売上確定 (capture) サービス.
 *
 * 2系 SC_Helper_CSV_Ex::lfAmazonPayCv2 (cartDev L2374-2423) を移植.
 *
 * 呼出フロー (WMS 出荷完了時):
 * 1. dtb_order.memo02 から Amazon chargeId を取得 (2系 L2378-2379)
 * 2. v1→v2 chargeId 変換 (2系 L2381-2382 の substr_replace idempotent 適用)
 * 3. AmazonPayRequestService::captureCharge で v2 SDK に売上確定電文送信 (2系 L2394)
 * 4. 成功時: dtb_order.memo03='Completed', memo04=chargeId 更新 (2系 L2397-2402)
 * 5. 失敗時: getCharge で二重処理チェック (2系 L2409-2419). 既に Captured 済みなら 4. と同じ更新.
 *
 * 2系挙動と一致する点:
 * - 成功判定は **HTTP status=200 かつ statusDetails.state='Captured'** の両方 (2系 L2397)
 * - 二重処理チェック側 (getCharge) は state のみで判定する (2系 L2414 に status 判定は無い)
 * - capture 失敗は log warning + memo03/04 未更新のみ. 例外 throw しない (WMS 全体の transaction を止めない)
 * - status 'Captured' を 'Completed' に書き換えて memo03 に保存 (2系 L2400 v1 バージョン互換)
 * - 冪等キーは 2系 L2385 と同一式 (AmazonPayRequestService::generateIdempotencyKey)
 */
class AmazonPayCaptureService
{
    /** 2系 v1→v2 chargeId 変換位置 (0-indexed 20 文字目を 'C' に置換). */
    private const CHARGE_ID_TYPE_INDEX = 20;

    /** 2系 v1 バージョン互換 memo03 値. */
    private const STATUS_COMPLETED = 'Completed';

    /** AmazonPay v2 statusDetails.state 成功値. */
    private const STATE_CAPTURED = 'Captured';

    public function __construct(
        private readonly Connection $connection,
        private readonly AmazonPayRequestService $amazonPayRequestService,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * AmazonPay v2 売上確定を実行する.
     *
     * @param int $orderId dtb_order.id
     */
    public function capture(int $orderId): AmazonPayCaptureResult
    {
        // dtb_order.memo02 (chargeId) と payment_total を取得 (2系 L2378)
        $row = $this->connection->fetchAssociative(
            'SELECT payment_total, memo02 FROM dtb_order WHERE id = :id',
            ['id' => $orderId]
        );
        if ($row === false) {
            $this->logger->warning('[AmazonPayCapture] 注文が見つかりません。', ['order_id' => $orderId]);
            return AmazonPayCaptureResult::error('OrderNotFound');
        }

        $rawChargeId = (string) ($row['memo02'] ?? '');
        if ($rawChargeId === '') {
            $this->logger->warning('[AmazonPayCapture] memo02 (chargeId) が未設定。', ['order_id' => $orderId]);
            return AmazonPayCaptureResult::error('ChargeIdMissing');
        }

        // v1→v2 chargeId 変換 (2系 L2381-2382).
        // v1 SDK 時代に保存された chargeId は position 20 に 'A' (Authorization) を持つ. capture には 'C' が必要.
        // 4系新規の chargeId は既に 'C' を持つため substr_replace は idempotent (no-op).
        $chargeId = strlen($rawChargeId) > self::CHARGE_ID_TYPE_INDEX
            ? substr_replace($rawChargeId, 'C', self::CHARGE_ID_TYPE_INDEX, 1)
            : $rawChargeId;

        $paymentTotal = (int) $row['payment_total'];
        if ($paymentTotal <= 0) {
            $this->logger->warning('[AmazonPayCapture] payment_total が 0 以下。スキップ。', [
                'order_id'      => $orderId,
                'payment_total' => $paymentTotal,
            ]);
            return AmazonPayCaptureResult::error('InvalidPaymentTotal');
        }

        // AmazonPay v2 captureCharge 電文送信 (2系 L2394)
        // 2系 L2383 の $capture_amount = dtb_order.payment_total をそのまま渡す
        // (プラグイン createCaptureChargePayload L300 の (int)$Order->getPaymentTotal() と同値).
        try {
            $result = $this->amazonPayRequestService->captureCharge($chargeId, $paymentTotal);
        } catch (\Throwable $e) {
            $this->logger->error('[AmazonPayCapture] captureCharge 例外', [
                'order_id' => $orderId,
                'message'  => $e->getMessage(),
            ]);
            return AmazonPayCaptureResult::error('Exception', $e->getMessage());
        }

        // AMAZONPAY_* 未設定で送信していない場合 (旧: プラグイン未マウント時のガードと同じ扱い)
        if ($result->skipped) {
            $this->logger->error(
                '[AmazonPayCapture] AMAZONPAY_* が未設定のため売上確定を送信できません。',
                ['order_id' => $orderId]
            );
            return AmazonPayCaptureResult::error('NotConfigured');
        }

        // 成功判定: HTTP status=200 かつ statusDetails.state == 'Captured' (2系 L2397)
        if ($result->status === AmazonPayRequestService::STATUS_OK && $result->getState() === self::STATE_CAPTURED) {
            $capturedChargeId = $result->getChargeId() ?? $chargeId;
            $this->recordCaptureSuccess($orderId, $capturedChargeId);
            $this->logger->info('[AmazonPayCapture] 売上確定成功', [
                'order_id'  => $orderId,
                'charge_id' => $capturedChargeId,
                'status'    => self::STATUS_COMPLETED,
            ]);
            return AmazonPayCaptureResult::ok();
        }

        // 失敗: getCharge で二重処理チェック (2系 L2405-2419).
        // Amazon 側で既に Captured 済み → capture リクエストは失敗するが状態としては成功扱いとする.
        $reasonCode = $result->getReasonCode() ?? 'Unknown';
        $this->logger->warning('[AmazonPayCapture] captureCharge 失敗。getCharge で二重処理チェック。', [
            'order_id'    => $orderId,
            'status'      => $result->status,
            'reason_code' => $reasonCode,
        ]);

        try {
            $checkResult = $this->amazonPayRequestService->getCharge($chargeId);
        } catch (\Throwable $e) {
            $this->logger->error('[AmazonPayCapture] getCharge 例外', [
                'order_id' => $orderId,
                'message'  => $e->getMessage(),
            ]);
            return AmazonPayCaptureResult::error($reasonCode, $e->getMessage());
        }

        // 2系 L2412-2414 は getCharge 側の HTTP status を見ず state だけで判定するため踏襲する
        if (!$checkResult->skipped && $checkResult->getState() === self::STATE_CAPTURED) {
            $capturedChargeId = $checkResult->getChargeId() ?? $chargeId;
            $this->recordCaptureSuccess($orderId, $capturedChargeId);
            $this->logger->info('[AmazonPayCapture] 二重処理検出。既に Captured 済み。', [
                'order_id'  => $orderId,
                'charge_id' => $capturedChargeId,
            ]);
            return AmazonPayCaptureResult::ok();
        }

        return AmazonPayCaptureResult::error($reasonCode);
    }

    /**
     * capture 成功時の dtb_order 更新 (2系 L2402 / L2417).
     *
     * memo03 = 'Completed' (v1 バージョン互換), memo04 = 最新 chargeId.
     */
    private function recordCaptureSuccess(int $orderId, string $chargeId): void
    {
        $this->connection->executeStatement(
            <<<'SQL'
                UPDATE dtb_order
                   SET memo03      = :status,
                       memo04      = :charge_id,
                       update_date = CURRENT_TIMESTAMP
                 WHERE id = :id
            SQL,
            [
                'status'    => self::STATUS_COMPLETED,
                'charge_id' => $chargeId,
                'id'        => $orderId,
            ]
        );
    }
}
