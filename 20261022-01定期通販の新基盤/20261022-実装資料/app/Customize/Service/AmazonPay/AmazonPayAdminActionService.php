<?php

declare(strict_types=1);

namespace Customize\Service\AmazonPay;

use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;

/**
 * AmazonPay 管理画面 手動操作サービス (Issue #2399, PR #2397 スコープ外分).
 *
 * 2系 `LC_Page_Admin_Order_Edit_Ex::amazonpay()` (cartDev L288-561) のうち、
 * 「売上取消」(refund, PR #2397 で移植済み・{@see AmazonPayRefundService}) を除く3操作を移植する:
 *
 *  - `capture()`     — 売上確定   (case 'capture' L301-347)
 *  - `changeAuth()`  — オーソリ変更 (case 'change_auth' L390-470。既存オーソリをクローズして再オーソリ)
 *  - `cancelAuth()`  — オーソリ取消 (case 'cancel_auth' L474-508。成功時は受注をキャンセルにする)
 *
 * ★`case 'new_auth':` (L512-557) は移植しない。2系全体を実測したが
 *   (`grep -rn "amazonpay_type" cartDev/data/Smarty/templates/` / `grep -rn '\$this->amazonpay\(' cartDev/data/class_extends/`)、
 *   `amazonpay_type='new_auth'` を送信する UI 要素も、`amazonpay('new_auth', ...)` を直接呼ぶ
 *   コードも2系に一切存在しない。`$_POST['amazonpay_type']` の値は edit.tpl L3140-3148 の
 *   5ボタン (refund/change_auth×3/capture/cancel_auth) のいずれかのみ。switch 文の中に
 *   到達不能な case が残っているだけの死にコード (CLAUDE.md「2系にも死んだコードがある」) のため、
 *   4系にも実装しない。
 *
 * ## STEP0 実測 (2026-08-21、2系 `ai-analysis-db`)
 *
 * 操作ログが無いため間接指標で必要性を測った:
 *
 * | 指標 | 件数 |
 * |---|---:|
 * | `payment_id=9` 実受注 | 129,845 |
 * | うち `order_status_id=5`(発送済み) かつ `memo04`(capture_id) 未設定 | **534** |
 * | うち `order_status_id=8`(出荷準備中。未売上確定が正常) | 483 |
 *
 * ★上記3件はいずれも `del_flg` 条件無しの母集団 (`WHERE payment_id=9` のみ)。
 *   `del_flg=0`(未削除)で絞ると実受注は129,722件になる (2026-08-21 対比レビューで検出)。
 *   534/483 も同じ無条件母集団から出しているため3件の相対関係に矛盾は無いが、他箇所
 *   (Issue本文等)の「129,845」を引用する際は del_flg 無条件の値であることに注意する。
 *
 * 発送済みなのに未売上確定 (=WMS 出荷連動の自動売上確定
 * {@see AmazonPayCaptureService} が失敗したまま放置された) 受注が 534 件実在する。
 * capture ボタンはこの取りこぼしを手動で拾う唯一の手段のため実装対象とする。
 *
 * change_auth / cancel_auth は2系に操作ログが無く、直接の実測はできない
 * (`memo02` は上書きされるため過去の変更回数を数えられない)。ただし edit.tpl の表示条件は
 * Open 状態 (未売上確定・未取消のオーソリ) の AmazonPay 受注で
 * capture/change_auth/cancel_auth の3ボタンを**同時に**出す1ブロックであり、PR #2397 時点の
 * 4系は Open 状態で何のボタンも出ない (Closed 限定で refund のみ表示) 状態だった。
 * capture の実装だけでは Open ブロックのボタン欠落は解消しないため、3操作をまとめて実装する
 * (どれか1つだけ実装しても表示条件上「Open なら3ボタンとも出る」という2系の分岐を崩す)。
 *
 * ## 2系挙動と一致する点 (行単位精読で確認)
 *
 * - capture: 成功判定は HTTP status=200 かつ `statusDetails.state=='Captured'` (2系 L327)。
 *   `Captured`→`Completed` に書き換えて memo03 に保存 (2系 L330, v1バージョン互換)。
 *   memo04=chargeId (2系 L332)。失敗時 `state=='Declined'` なら「※Declined」を付記 (2系 L339-340)。
 *   chargeId (memo02) の v1→v2 変換は {@see AmazonPayRequestService::normalizeChargeIdForCancel()}
 *   と同一の `substr_replace(...,'C',20,1)` (2系 L307 と L136-137 は同一処理)。
 * - changeAuth: chargePermissionId (`dtb_customer.amazon_billing_agreement_id`) が空なら
 *   memo01='AmazonPayError', memo02='amazon_charge_permission_id なし' を書いてから中断
 *   (2系 L395-399、電文を一切送らない)。既存オーソリ (memo02) があればまずキャンセル
 *   (失敗してもログのみで処理続行、2系 L413-417)。新規オーソリは
 *   `chargePermissionId`/`chargeAmount`/`captureNow=false`/`canHandlePendingAuthorization=false`/
 *   `merchantMetadata(merchantReferenceId='AS'.orderId, merchantStoreName=ブランド名, noteToBuyer固定)`
 *   (2系 L428-444)。成功判定は HTTP status=201 かつ `state=='Authorized'` (2系 L456)。
 *   成功時 memo02=新chargeId, **memo04=null** (2系 L458、売上確定をやり直す前提でクリアする)。
 *   失敗時 memo01='AmazonPayError', memo02=reasonCode (2系 L466)。
 * - cancelAuth: memo02 が空なら API を呼ばずに専用エラー (2系 L504-506)。
 *   非空ならキャンセル電文 (v1→v2変換あり)。成功判定は HTTP status=200 かつ
 *   `state=='Canceled'` (2系 L491)。**成功時の受注ステータス更新 (2系 L494 `status=3`) は
 *   本サービスの範囲外**。`PaygentOrderService::authCancel()` と同じ分担
 *   (Controller 側が `$result->ok` を見て更新する。{@see \Customize\Controller\Admin\Order\OrderViewController::paygentAuthCancel()} と同型)。
 *
 * ## 4系固有の対応
 *
 * - 冪等キーは {@see AmazonPayRequestService::generateIdempotencyKey()} (2系と同一式)。
 * - API 呼び出し例外は catch して log + error 扱いとする (2系は SDK 例外を捕捉しないが、
 *   Symfony は未捕捉例外で 500 になるため {@see AmazonPayRefundService} と同じ方針で防御する)。
 * - `capture()` の memo02 空チェック (`ChargeIdMissing`) は2系に無い追加ガード。2系は空文字のまま
 *   `substr_replace('', 'C', 20, 1)` → `captureCharge('C', ...)` を送ってしまい Amazon 側の 4xx に
 *   委ねるが、4系は無駄な API 呼び出しを避けるため送信前に弾く ({@see AmazonPayRefundService::refund()}
 *   の同種ガードと同じ方針)。失敗時のメッセージは2系の汎用エラーと同一文言に落ちるため、
 *   呼出元 (Controller) から見た挙動は変わらない。
 * - `capture()` 成功時の `$result->getChargeId() ?? $chargeId` フォールバックは2系
 *   `strval($capture_response->chargeId)`(null なら空文字になる)と異なり、レスポンスに
 *   chargeId が無い場合は送信した chargeId を保持する。成功判定 (status=200 かつ
 *   state='Captured') を満たす応答に chargeId が欠けることは実運用では起こらない前提のため
 *   通常経路には影響しないが、2系の「空文字で上書きする」動作とは意図的に異なる
 *   (memo04 を空にするより送信済み chargeId を残す方が安全という判断)。
 */
class AmazonPayAdminActionService
{
    /** AmazonPay v2 statusDetails.state 成功値 (capture). */
    private const STATE_CAPTURED = 'Captured';

    /** 2系 L330: v1 バージョン互換で memo03 に保存する値. */
    private const STATUS_COMPLETED = 'Completed';

    /** AmazonPay v2 statusDetails.state 拒否値 (2系 L339 の「※Declined」判定). */
    private const STATE_DECLINED = 'Declined';

    /** AmazonPay v2 statusDetails.state 成功値 (change_auth 新規オーソリ). */
    private const STATE_AUTHORIZED = 'Authorized';

    /** AmazonPay v2 statusDetails.state 成功値 (cancel_auth). */
    private const STATE_CANCELED = 'Canceled';

    /** 2系 L397: chargePermissionId が引けないときに memo01 へ書くセンチネル (AmazonPayError と同一). */
    private const MEMO01_AUTH_ERROR = 'AmazonPayError';

    public function __construct(
        private readonly Connection $connection,
        private readonly AmazonPayRequestService $amazonPayRequestService,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * 売上確定 (2系 case 'capture' L301-347).
     */
    public function capture(int $orderId): AmazonPayAdminActionResult
    {
        $row = $this->connection->fetchAssociative(
            'SELECT payment_total, memo02 FROM dtb_order WHERE id = :id',
            ['id' => $orderId]
        );
        if ($row === false) {
            $this->logger->warning('[AmazonPayAdminAction] capture: 注文が見つかりません。', ['order_id' => $orderId]);

            return AmazonPayAdminActionResult::error('OrderNotFound');
        }

        $rawChargeId = (string) ($row['memo02'] ?? '');
        if ($rawChargeId === '') {
            $this->logger->warning('[AmazonPayAdminAction] capture: memo02 (chargeId) が未設定。', ['order_id' => $orderId]);

            return AmazonPayAdminActionResult::error('ChargeIdMissing');
        }

        // v1→v2 chargeId 変換 (2系 L307)
        $chargeId     = AmazonPayRequestService::normalizeChargeIdForCancel($rawChargeId);
        $paymentTotal = (int) ($row['payment_total'] ?? 0);

        try {
            $result = $this->amazonPayRequestService->captureCharge($chargeId, $paymentTotal);
        } catch (\Throwable $e) {
            $this->logger->error('[AmazonPayAdminAction] capture: captureCharge 例外', [
                'order_id' => $orderId,
                'message'  => $e->getMessage(),
            ]);

            return AmazonPayAdminActionResult::error('Exception');
        }

        if ($result->skipped) {
            $this->logger->warning('[AmazonPayAdminAction] capture: AMAZONPAY_* が未設定のため送信できません。', ['order_id' => $orderId]);

            return AmazonPayAdminActionResult::error('NotConfigured');
        }

        // 2系 L327: status==200 && state=='Captured'
        if ($result->status === AmazonPayRequestService::STATUS_OK && $result->getState() === self::STATE_CAPTURED) {
            $capturedChargeId = $result->getChargeId() ?? $chargeId;
            $this->connection->executeStatement(
                'UPDATE dtb_order SET memo03 = :status, memo04 = :charge_id, update_date = CURRENT_TIMESTAMP WHERE id = :id',
                ['status' => self::STATUS_COMPLETED, 'charge_id' => $capturedChargeId, 'id' => $orderId]
            );
            $this->logger->info('[AmazonPayAdminAction] capture: 売上確定成功', ['order_id' => $orderId]);

            return AmazonPayAdminActionResult::ok();
        }

        $reasonCode = $result->getReasonCode() ?? 'Unknown';
        $this->logger->warning('[AmazonPayAdminAction] capture: 失敗', [
            'order_id'    => $orderId,
            'status'      => $result->status,
            'reason_code' => $reasonCode,
            'state'       => $result->getState(),
        ]);

        // 2系 L339-340: state=='Declined' なら「※Declined」を付記
        if ($result->getState() === self::STATE_DECLINED) {
            return AmazonPayAdminActionResult::declined();
        }

        return AmazonPayAdminActionResult::error($reasonCode);
    }

    /**
     * オーソリ変更 (2系 case 'change_auth' L390-470).
     *
     * @param int $brandId 現在ログイン中の管理画面ブランド (2系 `$_SESSION["brand_id"]` 相当。
     *                      merchantMetadata.merchantStoreName に使う)
     */
    public function changeAuth(int $orderId, int $brandId): AmazonPayAdminActionResult
    {
        $row = $this->connection->fetchAssociative(
            'SELECT payment_total, customer_id, memo02 FROM dtb_order WHERE id = :id',
            ['id' => $orderId]
        );
        if ($row === false) {
            $this->logger->warning('[AmazonPayAdminAction] changeAuth: 注文が見つかりません。', ['order_id' => $orderId]);

            return AmazonPayAdminActionResult::error('OrderNotFound');
        }

        $customerId = (int) ($row['customer_id'] ?? 0);
        $chargePermissionId = (string) ($this->connection->fetchOne(
            'SELECT amazon_billing_agreement_id FROM dtb_customer WHERE id = :id',
            ['id' => $customerId]
        ) ?: '');

        // 2系 L395-399: chargePermissionId が引けなければ電文を送らずセンチネルを書いて中断
        if ($chargePermissionId === '') {
            $this->logger->warning('[AmazonPayAdminAction] changeAuth: amazon_charge_permission_id なし', ['order_id' => $orderId]);
            $this->connection->executeStatement(
                'UPDATE dtb_order SET memo01 = :memo01, memo02 = :memo02, update_date = CURRENT_TIMESTAMP WHERE id = :id',
                ['memo01' => self::MEMO01_AUTH_ERROR, 'memo02' => 'amazon_charge_permission_id なし', 'id' => $orderId]
            );

            return AmazonPayAdminActionResult::error('NoChargePermissionId');
        }

        // 既存オーソリのクローズ (2系 L404-418)。失敗してもログのみで処理続行する。
        // ★v1→v2 変換 (`normalizeChargeIdForCancel`) はここでは行わない。
        //   `AmazonPayRequestService::cancelCharge()` が引数の chargeId に対して内部で
        //   同じ変換を適用するため (二重適用は substr_replace の冪等性により実害は無いが、
        //   読み手が「どちらで変換されるのか」を誤読しないよう生値のまま渡す)。
        $rawChargeId = (string) ($row['memo02'] ?? '');
        if ($rawChargeId !== '') {
            try {
                $cancelResult = $this->amazonPayRequestService->cancelCharge($rawChargeId);
                if ($cancelResult->status !== AmazonPayRequestService::STATUS_OK || $cancelResult->getState() !== self::STATE_CANCELED) {
                    $this->logger->warning('[AmazonPayAdminAction] changeAuth: 既存オーソリのクローズに失敗 (処理続行)', [
                        'order_id'    => $orderId,
                        'reason_code' => $cancelResult->getReasonCode(),
                    ]);
                }
            } catch (\Throwable $e) {
                $this->logger->warning('[AmazonPayAdminAction] changeAuth: cancelCharge 例外 (処理続行)', [
                    'order_id' => $orderId,
                    'message'  => $e->getMessage(),
                ]);
            }
        }

        $paymentTotal = (int) ($row['payment_total'] ?? 0);
        $brandName    = (string) ($this->connection->fetchOne('SELECT name FROM dtb_brand WHERE id = :id', ['id' => $brandId]) ?: '');

        try {
            // 2系 L431-444
            $result = $this->amazonPayRequestService->createCharge(
                chargePermissionId: $chargePermissionId,
                paymentTotal: $paymentTotal,
                CaptureNow: false,
                canHandlePendingAuthorization: false,
                merchantMetadataMerchantReferenceId: 'AS' . $orderId,
                merchantStoreName: $brandName,
            );
        } catch (\Throwable $e) {
            $this->logger->error('[AmazonPayAdminAction] changeAuth: createCharge 例外', [
                'order_id' => $orderId,
                'message'  => $e->getMessage(),
            ]);

            return AmazonPayAdminActionResult::error('Exception');
        }

        if ($result->skipped) {
            $this->logger->warning('[AmazonPayAdminAction] changeAuth: AMAZONPAY_* が未設定のため送信できません。', ['order_id' => $orderId]);

            return AmazonPayAdminActionResult::error('NotConfigured');
        }

        // 2系 L456: status==201 && state=='Authorized'
        if ($result->status === AmazonPayRequestService::STATUS_CREATED && $result->getState() === self::STATE_AUTHORIZED) {
            $newChargeId = $result->getChargeId() ?? '';
            // 2系 L458: memo02=新chargeId, memo04=null (売上確定をやり直す前提でクリア)
            $this->connection->executeStatement(
                'UPDATE dtb_order SET memo02 = :memo02, memo04 = NULL, update_date = CURRENT_TIMESTAMP WHERE id = :id',
                ['memo02' => $newChargeId, 'id' => $orderId]
            );
            $this->logger->info('[AmazonPayAdminAction] changeAuth: オーソリ変更成功', [
                'order_id'  => $orderId,
                'charge_id' => $newChargeId,
            ]);

            return AmazonPayAdminActionResult::ok();
        }

        // 2系 L463-467
        // ★`?? 'Unknown'` で補完しない (Issue #3557 と同型。逆方向の #1942 も同じ判断)。
        //   2系 cartDev LC_Page_Admin_Order_Edit_Ex L464 は
        //   `$code = strval($create_response->reasonCode);` で、欠落時は **空文字**を
        //   memo02 に書く。'Unknown' を書くと後段 `cancelAuth()` の memo02 空判定
        //   (`chargeIdMissing`) をすり抜けて、偽の chargeId でキャンセルAPIを叩きうる。
        //   同型の memo02 書き込みは PaymentChangeOrderUpdater / StoreLoginPurchaseService /
        //   OrderAuthoriEntryService / AmazonPaySettlementService の4経路とも `(string)` キャスト。
        //   ★`capture()` / `cancelAuth()` 側の `?? 'Unknown'` は memo02 を書かず
        //   ログ・戻り値だけに使うのでそのまま (2系 L337/L499 も memo02 を更新しない)。
        $reasonCode = (string) $result->getReasonCode();
        $this->connection->executeStatement(
            'UPDATE dtb_order SET memo01 = :memo01, memo02 = :memo02, update_date = CURRENT_TIMESTAMP WHERE id = :id',
            ['memo01' => self::MEMO01_AUTH_ERROR, 'memo02' => $reasonCode, 'id' => $orderId]
        );
        $this->logger->warning('[AmazonPayAdminAction] changeAuth: 新規オーソリ失敗', [
            'order_id'    => $orderId,
            'reason_code' => $reasonCode,
        ]);

        return AmazonPayAdminActionResult::error($reasonCode);
    }

    /**
     * オーソリ取消 (2系 case 'cancel_auth' L474-508).
     *
     * ★成功時の受注ステータス更新 (2系 L494 `status=3`) は本サービスの範囲外。
     * `PaygentOrderService::authCancel()` と同じ分担で Controller 側が更新する
     * ({@see \Customize\Controller\Admin\Order\OrderViewController::paygentAuthCancel()} と同型)。
     */
    public function cancelAuth(int $orderId): AmazonPayAdminActionResult
    {
        $rawChargeId = (string) ($this->connection->fetchOne(
            'SELECT memo02 FROM dtb_order WHERE id = :id',
            ['id' => $orderId]
        ) ?: '');

        // 2系 L504-506: chargeId が空なら API を呼ばず専用エラー
        if ($rawChargeId === '') {
            $this->logger->warning('[AmazonPayAdminAction] cancelAuth: memo02 (chargeId) が未設定。', ['order_id' => $orderId]);

            return AmazonPayAdminActionResult::chargeIdMissing();
        }

        // ★v1→v2 変換は行わない。`cancelCharge()` が内部で同じ変換を適用するため
        //   (changeAuth() の既存オーソリクローズと同じ理由。二重適用を避け生値のまま渡す)。
        try {
            $result = $this->amazonPayRequestService->cancelCharge($rawChargeId);
        } catch (\Throwable $e) {
            $this->logger->error('[AmazonPayAdminAction] cancelAuth: cancelCharge 例外', [
                'order_id' => $orderId,
                'message'  => $e->getMessage(),
            ]);

            return AmazonPayAdminActionResult::error('Exception');
        }

        if ($result->skipped) {
            $this->logger->warning('[AmazonPayAdminAction] cancelAuth: AMAZONPAY_* が未設定のため送信できません。', ['order_id' => $orderId]);

            return AmazonPayAdminActionResult::error('NotConfigured');
        }

        // 2系 L491: status==200 && state=='Canceled'
        if ($result->status === AmazonPayRequestService::STATUS_OK && $result->getState() === self::STATE_CANCELED) {
            $this->logger->info('[AmazonPayAdminAction] cancelAuth: オーソリ取消成功', ['order_id' => $orderId]);

            return AmazonPayAdminActionResult::ok();
        }

        $reasonCode = $result->getReasonCode() ?? 'Unknown';
        $this->logger->warning('[AmazonPayAdminAction] cancelAuth: 失敗', [
            'order_id'    => $orderId,
            'reason_code' => $reasonCode,
        ]);

        return AmazonPayAdminActionResult::error($reasonCode);
    }
}
