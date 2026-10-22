<?php

namespace Customize\Service\AmazonPay;

/**
 * Amazon Pay API 呼び出し結果.
 *
 * 2系は SDK の戻り値配列から **HTTP status と レスポンス本文の両方**を見て成否を判定する:
 *   - createCharge:  `$res['status'] == 201 && $body->statusDetails->state == 'Authorized'`
 *                    (SC_Helper_Purchase_Ex L187)
 *   - cancelCharge:  `$res['status'] != 200 || $body->statusDetails->state != 'Canceled'` でエラー扱い
 *                    (同 L143)
 *
 * このため status を捨てず呼び出し側へ渡す。
 */
final class AmazonPayApiResult
{
    public function __construct(
        /** HTTP ステータス. SDK 呼び出しに至らなかった場合は null */
        public readonly ?int $status,
        /** レスポンス本文を json_decode したもの. 取得できなければ null */
        public readonly ?object $response,
        /** 設定不足等でリクエストを送っていない場合 true */
        public readonly bool $skipped = false,
    ) {
    }

    /**
     * `statusDetails->state` を取り出す (2系の $response->statusDetails->state 相当).
     */
    public function getState(): ?string
    {
        $state = $this->response->statusDetails->state ?? null;

        return $state === null ? null : (string) $state;
    }

    /**
     * `reasonCode` を取り出す (2系はエラー時に memo02 へ保存する値).
     */
    public function getReasonCode(): ?string
    {
        $code = $this->response->reasonCode ?? null;

        return $code === null ? null : (string) $code;
    }

    /**
     * chargeId (2系 L190 の `strval($create_response->chargeId)`).
     */
    public function getChargeId(): ?string
    {
        $chargeId = $this->response->chargeId ?? null;

        return $chargeId === null ? null : (string) $chargeId;
    }

    /**
     * chargePermissionId (2系 `lfAmazonPaymentCv2Confirm` L351 の
     * `strval($get_amazon_data['response']->chargePermissionId)`).
     */
    public function getChargePermissionId(): ?string
    {
        $id = $this->response->chargePermissionId ?? null;

        return $id === null ? null : (string) $id;
    }

    /**
     * `webCheckoutDetails.amazonPayRedirectUrl` (2系 `lfAmazonPaymentCv2Confirm` L338、
     * updateCheckoutSession 成功時に Amazon 側の追加認証へ飛ばす先)。
     */
    public function getAmazonPayRedirectUrl(): ?string
    {
        $url = $this->response->webCheckoutDetails->amazonPayRedirectUrl ?? null;

        return $url === null ? null : (string) $url;
    }

    /**
     * refundId (2系 SC_Helper_CSV_Ex L860 ほか `strval($result->refundId)` 相当. Issue #395).
     */
    public function getRefundId(): ?string
    {
        $refundId = $this->response->refundId ?? null;

        return $refundId === null ? null : (string) $refundId;
    }
}
