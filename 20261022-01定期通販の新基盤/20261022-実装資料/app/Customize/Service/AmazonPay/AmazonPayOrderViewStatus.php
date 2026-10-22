<?php

declare(strict_types=1);

namespace Customize\Service\AmazonPay;

/**
 * 受注編集画面「AmazonPay ステータス」カード向けの状態解決結果 (Issue #2388)。
 *
 * 2系 `LC_Page_Admin_Order_Edit_Ex::process()` (cartDev L226-281) は `getCharge()` を1回、
 * 必要なら `getRefund()` をもう1回呼ぶだけで、見出し ($amazonpay_status) と本文の分岐
 * (edit.tpl L3129-3141) を**同じ変数**から出す。4系もこれに合わせ、
 * {@see AmazonPayOrderStatusService::resolveForOrderView()} が1回の照会で本オブジェクトを
 * 組み立て、見出し・本文・ボタン表示可否の全てをこの1つの結果から導出する
 * (対比レビュー指摘: 見出し用とボタン用で別々に API を叩くと、2回の照会の間に状態が
 * 変わった場合に見出しとボタンが不整合になり得るため)。
 */
final class AmazonPayOrderViewStatus
{
    private function __construct(
        /** 2系 edit.tpl L3129 の `$amazonpay_status` と同じ生値 ('Closed'/'Open'/'Pending'/'Declined'/'オーソリ失敗')。取得不能なら空文字 */
        public readonly string $status,
        /** refundId (memo06) が無ければ null。あれば 2系 `refundStatusAmazonPay()` の生値 ('Completed'/'Pending'/'Declined') */
        public readonly ?string $refundState,
        /** memo04 (画面表示用)。空なら未売上確定/取消済み */
        public readonly string $captureId,
        /** 「売上取消」ボタンを表示してよいか (edit.tpl L3132-3141 の複合条件) */
        public readonly bool $canRefund,
    ) {
    }

    /** API未接続・取得失敗・未オーソリ (getId が無い) 時の空状態。 */
    public static function empty(): self
    {
        return new self('', null, '', false);
    }

    public static function create(string $status, ?string $refundState, string $captureId, bool $canRefund): self
    {
        return new self($status, $refundState, $captureId, $canRefund);
    }
}
