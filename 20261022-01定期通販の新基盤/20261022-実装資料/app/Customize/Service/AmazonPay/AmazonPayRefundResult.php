<?php

declare(strict_types=1);

namespace Customize\Service\AmazonPay;

/**
 * AmazonPay 返金 (createRefund) 結果 DTO (Issue #395).
 *
 * `AmazonPayCaptureResult` と同型。呼出元 (ReturnsCsvImportService) が WMS 全体の処理を
 * 止めずに warning ログへ留めるための情報を持つ。
 *
 * ★対比レビューで検出 (Issue #2388, 2026-08-21、2回目): `AmazonPayRefundService::refund()` の
 * DB冪等ガード (memo05='RefundInitiated' && memo06既済なら未送信で成功扱い) は WMS 経路には
 * 無害だが、admin 手動返金では「押しても何も送っていないのに『AmazonPay売上取消』という
 * 成功メッセージが出る」ことになりうる (canRefund 判定のデッドコード踏襲により、返金 Pending 中
 * もボタンが出続けるため、2度目の押下でこのガードに当たる)。`ok` と区別できるよう
 * `alreadySent` を追加し、呼出元 (Controller) が正確なメッセージを出し分けられるようにした。
 */
final class AmazonPayRefundResult
{
    private function __construct(
        public readonly bool $ok,
        public readonly ?string $refundId = null,
        public readonly ?string $errorCode = null,
        public readonly ?string $errorDetail = null,
        /** true: 既に返金開始済みのため、今回は実際には createRefund を送信していない (DB冪等ガード) */
        public readonly bool $alreadySent = false,
    ) {}

    public static function ok(?string $refundId = null): self
    {
        return new self(true, $refundId);
    }

    /** DB冪等ガードにより送信をスキップしたときの成功 (既存の $refundId をそのまま返す)。 */
    public static function alreadySent(string $refundId): self
    {
        return new self(true, $refundId, alreadySent: true);
    }

    public static function error(string $code, ?string $detail = null): self
    {
        return new self(false, null, $code, $detail);
    }
}
