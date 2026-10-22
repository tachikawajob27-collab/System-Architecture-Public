<?php

declare(strict_types=1);

namespace Customize\Service\AmazonPay;

use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;

/**
 * AmazonPay v2 返金 (createRefund) サービス (Issue #395, PR #392 スコープ外分).
 *
 * 2系は同一ロジックが3箇所に重複実装されている:
 *   - SC_Helper_CSV_Ex::doUploadReturnsCsv (cartDev L834-878) — 倉庫返品 CSV の自動返金 (本 Issue の対象)
 *   - SC_Helper_Purchase_Ex::orderCaptureCancel (cartDev L304-337) — 売上取消の共通処理
 *   - LC_Page_Admin_Order_Edit_Ex 'refund' action (cartDev L349-386) — 管理画面の手動売上取消
 * 3箇所とも「dtb_order.memo04 (chargeId) を取得 → createRefund → 成功時 memo05/06 更新」という
 * 同一フローのため、本サービスへ1本化する。Issue #395 (本Issue) の呼出元は WMS 経路
 * (ReturnsCsvImportService) のみ。
 *
 * ★admin 手動返金 (Issue #2388, PR #2397) からも本サービスを再利用して配線済み
 * ({@see \Customize\Controller\Admin\Order\OrderViewController::amazonpayRefund()})。
 * 2系 'refund' action (cartDev L354-355) も refund_amount は常に payment_total 固定
 * (ユーザー入力の部分返金額は存在しない) のため、本サービスの「全額固定・DB冪等ガード」前提は
 * admin 手動返金でも成立する。
 * `orderCaptureCancel` からの配線は Issue #1906 で「唯一の呼出元 (change_order_status の
 * 5→500遷移) が2系でも到達不能な死にコード」と確定・クローズ済みのため対応不要。
 *
 * ★STEP1-3 精読で判明した Issue 記述との差分:
 * Issue #395 は「2系 substr_replace による v1→v2 chargeId 変換ロジックを移植」と書いているが、
 * 上記3箇所の createRefund 呼出コードを行単位で確認した結果、**refund の chargeId (memo04) には
 * substr_replace は一切適用されていない** (cartDev SC_Helper_CSV_Ex L840-841 / SC_Helper_Purchase_Ex
 * L305-309 / LC_Page_Admin_Order_Edit_Ex L352-354 のいずれも memo04 をそのまま chargeId に渡す)。
 * substr_replace('A'→'C') が適用されるのは cancelCharge / captureCharge の chargeId (memo02) のみ
 * (2系: change_auth L406, lfAmazonPayCv2 L2381-2382)。理由: memo04 は capture 成功時に AmazonPay API
 * が返した capture 済み chargeId であり (21文字目が既に 'C')、v1 authorization ID (21文字目 'A') を
 * 保持する memo02 とは意味が異なる。したがって本サービスは memo04 を無変換のまま
 * AmazonPayRequestService::createRefund へ渡す (Issue の前提をそのまま実装に持ち込まない、CLAUDE.md STEP4)。
 *
 * ★2系に無い追加ガード (本サービス独自. Issue #395 Phase5検証項目「二重処理」対応):
 * 2系の3箇所いずれも refund 経路に二重処理チェックは無いが、CSV は再アップロードされうる導線のため
 * 送信前に dtb_order.memo05='RefundInitiated' かつ memo06 既済なら再送せず `AmazonPayRefundResult
 * ::alreadySent()` を返す (DB冪等ガード)。`alreadySent` は `ok=true` だが `alreadySent=true` を
 * 持つため、呼出元は「今回実際に送信して成功した」のか「既に処理済みで送信しなかった」のかを
 * 区別できる (対比レビューで検出、Issue #2388、2026-08-21・2回目: admin手動返金はボタンが
 * 返金Pending中も出続けるデッドコード踏襲があるため、2度目の押下でこのガードに当たると
 * 「送信していないのに成功した」という誤解を招くメッセージになりうる。Controller 側で
 * `alreadySent` を見て文言を出し分ける)。
 * ★対比レビューで削除: 当初 createRefund 失敗時に getCharge の state='Closed' を「返金済み」
 *   シグナルとして扱うフォールバックを追加していたが、AmazonPay v2 の Charge オブジェクトに
 *   'Closed' という生の state 値は存在しない (2系 L1284 の 'Closed' は statusAmazonPay() が
 *   Captured/Canceled をマッピングした後の値で、Charge の生 state ではない)。常に false になる
 *   死にコードであるうえ、docblock の「2系と同じシグナル」という説明も誤りだったため削除した。
 *   createRefund 失敗時の二重処理検出は refundId が無いと getRefund でも判定できず、本 Issue の
 *   スコープでは対応しない (上記 DB冪等ガードのみで CSV 再アップロードは防げる)。
 *
 * 2系挙動と一致する点:
 * - 成功判定は **HTTP status が 200 または 201 かつ statusDetails.state='RefundInitiated'**
 *   (2系 SC_Helper_CSV_Ex L859 / LC_Page_Admin_Order_Edit_Ex L373 と同一)
 * - 成功時 dtb_order.memo05=state('RefundInitiated'), memo06=refundId (2系 L862 / L376)
 * - 失敗は log warning のみ. 例外を投げず WMS 全体の取込を止めない (2系 L865-868 も throw 無し)
 * - 冪等キーは AmazonPayRequestService::generateIdempotencyKey (2系 L844 と同一式)
 */
class AmazonPayRefundService
{
    /** AmazonPay v2 statusDetails.state 返金開始成功値 (兼 dtb_order.memo05 保存値). */
    private const STATE_REFUND_INITIATED = 'RefundInitiated';

    public function __construct(
        private readonly Connection $connection,
        private readonly AmazonPayRequestService $amazonPayRequestService,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * AmazonPay v2 返金 (createRefund) を実行する.
     *
     * @param int $orderId      dtb_order.id
     * @param int $refundAmount 返金金額 (2系は payment_total をそのまま渡す)
     */
    public function refund(int $orderId, int $refundAmount): AmazonPayRefundResult
    {
        // dtb_order.memo04 (chargeId) / memo05 (状態) / memo06 (refundId) を取得 (2系 L840)
        $row = $this->connection->fetchAssociative(
            'SELECT memo04, memo05, memo06 FROM dtb_order WHERE id = :id',
            ['id' => $orderId]
        );
        if ($row === false) {
            $this->logger->warning('[AmazonPayRefund] 注文が見つかりません。', ['order_id' => $orderId]);
            return AmazonPayRefundResult::error('OrderNotFound');
        }

        $chargeId = (string) ($row['memo04'] ?? '');
        if ($chargeId === '') {
            $this->logger->warning('[AmazonPayRefund] memo04 (chargeId) が未設定。', ['order_id' => $orderId]);
            return AmazonPayRefundResult::error('ChargeIdMissing');
        }

        if ($refundAmount <= 0) {
            $this->logger->warning('[AmazonPayRefund] refundAmount が0以下。スキップ。', [
                'order_id'      => $orderId,
                'refund_amount' => $refundAmount,
            ]);
            return AmazonPayRefundResult::error('InvalidRefundAmount');
        }

        // DB冪等ガード (本サービス独自. 2系に無し. Issue #395 Phase5検証「二重処理」対応)
        $existingRefundId = (string) ($row['memo06'] ?? '');
        if ((string) ($row['memo05'] ?? '') === self::STATE_REFUND_INITIATED && $existingRefundId !== '') {
            $this->logger->info('[AmazonPayRefund] 既に返金開始済み。二重送信スキップ。', [
                'order_id'  => $orderId,
                'refund_id' => $existingRefundId,
            ]);
            return AmazonPayRefundResult::alreadySent($existingRefundId);
        }

        // AmazonPay v2 createRefund 電文送信 (2系 L855)
        try {
            $result = $this->amazonPayRequestService->createRefund($chargeId, $refundAmount);
        } catch (\Throwable $e) {
            $this->logger->error('[AmazonPayRefund] createRefund 例外', [
                'order_id' => $orderId,
                'message'  => $e->getMessage(),
            ]);
            return AmazonPayRefundResult::error('Exception', $e->getMessage());
        }

        // AMAZONPAY_* 未設定で送信していない場合
        if ($result->skipped) {
            $this->logger->warning(
                '[AmazonPayRefund] AMAZONPAY_* が未設定のため返金を送信できません。',
                ['order_id' => $orderId]
            );
            return AmazonPayRefundResult::error('NotConfigured');
        }

        // 成功判定: HTTP status 200 or 201 かつ state='RefundInitiated' (2系 L859)
        if (
            in_array($result->status, [AmazonPayRequestService::STATUS_OK, AmazonPayRequestService::STATUS_CREATED], true)
            && $result->getState() === self::STATE_REFUND_INITIATED
        ) {
            $refundId = $result->getRefundId() ?? '';
            $this->recordRefundSuccess($orderId, $refundId);
            $this->logger->info('[AmazonPayRefund] 返金開始成功', [
                'order_id'  => $orderId,
                'refund_id' => $refundId,
            ]);
            return AmazonPayRefundResult::ok($refundId);
        }

        // 失敗 (2系 L865-868 も throw 無し。WMS 全体の取込は止めない)
        $reasonCode = $result->getReasonCode() ?? 'Unknown';
        $this->logger->warning('[AmazonPayRefund] createRefund 失敗。', [
            'order_id'    => $orderId,
            'status'      => $result->status,
            'reason_code' => $reasonCode,
        ]);

        return AmazonPayRefundResult::error($reasonCode);
    }

    /**
     * 返金成功時の dtb_order 更新 (2系 L862 / L376).
     *
     * memo05 = 'RefundInitiated', memo06 = refundId.
     */
    private function recordRefundSuccess(int $orderId, string $refundId): void
    {
        $this->connection->executeStatement(
            <<<'SQL'
                UPDATE dtb_order
                   SET memo05      = :status,
                       memo06      = :refund_id,
                       update_date = CURRENT_TIMESTAMP
                 WHERE id = :id
            SQL,
            [
                'status'    => self::STATE_REFUND_INITIATED,
                'refund_id' => $refundId,
                'id'        => $orderId,
            ]
        );
    }
}
