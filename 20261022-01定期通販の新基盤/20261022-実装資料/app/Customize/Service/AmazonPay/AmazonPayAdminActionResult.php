<?php

declare(strict_types=1);

namespace Customize\Service\AmazonPay;

/**
 * AmazonPay 管理画面 手動操作 (capture/change_auth/cancel_auth) 結果 DTO (Issue #2399).
 *
 * `PaygentOrderResult` と同型 (ok/errorCode の最小構成)。2系
 * `LC_Page_Admin_Order_Edit_Ex::amazonpay()` (cartDev L288-561) の各 case は成功/失敗の
 * 2値でしか分岐しないため、`AmazonPayRefundResult` の `alreadySent` のような追加状態は不要。
 *
 * `declined` / `chargeIdMissing` のみ専用フラグを持つ。理由:
 * - capture (case 'capture' L339): 失敗時に `statusDetails->state=='Declined'` かどうかで
 *   alert 文言が変わる (「※Declined」の有無)。他の失敗理由 (Unknown 等) と区別する必要がある。
 * - cancel_auth (case 'cancel_auth' L504-506): chargeId が空のときは API を呼ばずに
 *   専用メッセージ「取引IDが確認できないため、AmazonPayオーソリ取消エラーが発生しました」を出す。
 *   API 呼び出し自体の失敗 (L501「AmazonPayオーソリ取消エラーが発生しました」) とは文言が異なる。
 * change_auth (case 'change_auth') は失敗理由によらず単一メッセージ
 * (「AmazonPayオーソリエラーが発生しました」) のため `errorCode` だけで足り、専用フラグは不要。
 */
final class AmazonPayAdminActionResult
{
    private function __construct(
        public readonly bool $ok,
        public readonly ?string $errorCode = null,
        public readonly bool $declined = false,
        public readonly bool $chargeIdMissing = false,
    ) {
    }

    public static function ok(): self
    {
        return new self(true);
    }

    /** 2系 L339-340: statusDetails->state=='Declined' (capture 専用). */
    public static function declined(): self
    {
        return new self(false, 'Declined', declined: true);
    }

    /** 2系 L504-506: memo02 (chargeId) が空 (cancel_auth 専用). */
    public static function chargeIdMissing(): self
    {
        return new self(false, 'ChargeIdMissing', chargeIdMissing: true);
    }

    public static function error(?string $code = null): self
    {
        return new self(false, $code);
    }
}
