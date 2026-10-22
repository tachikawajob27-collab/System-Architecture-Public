<?php

namespace Customize\Service\AmazonPay;

use Customize\Lib\AmazonPay\AmazonPaySdkLoader;
use Psr\Log\LoggerInterface;

/**
 * AmazonPay 支払方法変更専用 CheckoutSession サービス.
 *
 * 2系 LC_Page_Mypage_PaymentChange_Ex::lfSetAmazonPay (L578-673) を移植.
 *
 * 支払方法変更フローは受注に紐づかない (paymentIntent=Confirm で chargeAmount=1 JPY
 * のダミー与信を通してカード認証 → chargePermissionId を得て顧客に紐付け).
 * このため charge 系のみを持つ `AmazonPayRequestService` は流用できず、
 * 2系と同じく SDK の `\Amazon\Pay\API\Client` を直接叩いて payment_change 用の payload を構築する.
 *
 * **2-stage 呼出:**
 *  - Stage 2 (mode=change POST): updateCheckoutSessionForPaymentChange
 *      paymentIntent=Confirm, chargeAmount=1JPY で updateCheckoutSession →
 *      応答 webCheckoutDetails.amazonPayRedirectUrl を返す → 呼出元は 302 でリダイレクト
 *  - Stage 3 (amazon=comp GET): completeCheckoutSessionForPaymentChange
 *      chargeAmount=1JPY で completeCheckoutSession →
 *      応答 chargePermissionId を返す → 呼出元は dtb_customer.amazon_billing_agreement_id に保存
 *
 * **config 取得:**
 *  `AmazonPayConfigProvider` (2系 config.php の `$GLOBALS['amazon_payment_config']` 相当を ENV 化)
 *  から SDK config を受け取る. SDK 本体は `AmazonPaySdkLoader::load()` で同梱物をオートロードする.
 */
class AmazonPayCheckoutService
{
    /** paymentIntent: 与信のみ (2系 lfSetAmazonPay L591) */
    public const PAYMENT_INTENT_CONFIRM = 'Confirm';

    /** 通貨コード (2系 lfSetAmazonPay L595) */
    public const CURRENCY_JPY = 'JPY';

    /** 支払方法変更用ダミー与信額 (2系 lfSetAmazonPay L594) */
    public const DUMMY_CHARGE_AMOUNT = '1';

    public function __construct(
        private readonly AmazonPayConfigProvider $configProvider,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Stage 2: paymentIntent=Confirm で updateCheckoutSession (2系 L584-617).
     *
     * @param string $sessionId  Amazon Checkout Session ID (session に保存済のもの)
     * @param string $brandName  merchantMetadata.merchantStoreName に使う brand 名
     * @param string $returnUrl  checkoutResultReturnUrl (絶対 URL. Amazon 認証完了後の戻り先)
     */
    public function updateCheckoutSessionForPaymentChange(
        string $sessionId,
        string $brandName,
        string $returnUrl,
    ): AmazonCheckoutResult {
        if (!$this->configProvider->isConfigured()) {
            $this->logger->warning('[AmazonPayCheckout] AMAZONPAY_* が未設定のため送信しません');
            return AmazonCheckoutResult::error('AmazonPay 設定が未投入', 'NOT_CONFIGURED');
        }
        try {
            $client = $this->createClient();
        } catch (\Throwable $e) {
            $this->logger->error('[AmazonPayCheckout] client 生成失敗', ['message' => $e->getMessage()]);
            return AmazonCheckoutResult::error($e->getMessage(), 'CLIENT_INIT_FAILED');
        }

        // 2系 lfSetAmazonPay L586-601 相当のペイロード
        $payload = [
            'webCheckoutDetails' => [
                'checkoutResultReturnUrl' => $returnUrl,
            ],
            'paymentDetails' => [
                'paymentIntent'                => self::PAYMENT_INTENT_CONFIRM,
                'canHandlePendingAuthorization' => false,
                'chargeAmount' => [
                    'amount'       => self::DUMMY_CHARGE_AMOUNT,
                    'currencyCode' => self::CURRENCY_JPY,
                ],
            ],
            'merchantMetadata' => [
                'merchantStoreName' => $brandName,
            ],
        ];

        try {
            $responseObject = $client->updateCheckoutSession($sessionId, $payload);
        } catch (\Throwable $e) {
            $this->logger->error('[AmazonPayCheckout] updateCheckoutSession 例外', [
                'session_id' => $sessionId,
                'message'    => $e->getMessage(),
            ]);
            return AmazonCheckoutResult::error($e->getMessage(), 'SDK_EXCEPTION');
        }

        $status = (int) ($responseObject['status'] ?? 0);
        $result = json_decode((string) ($responseObject['response'] ?? ''));

        // 2系 L607-611: status=200 AND statusDetails.state='Open' なら redirectUrl 返す
        if ($status === 200
            && isset($result->statusDetails->state)
            && $result->statusDetails->state === 'Open'
        ) {
            $redirectUrl = (string) ($result->webCheckoutDetails->amazonPayRedirectUrl ?? '');
            if ($redirectUrl === '') {
                $this->logger->error('[AmazonPayCheckout] amazonPayRedirectUrl 空', [
                    'session_id' => $sessionId,
                ]);
                return AmazonCheckoutResult::error('AmazonPayRedirectUrl が空', 'NO_REDIRECT_URL');
            }
            return AmazonCheckoutResult::updateOk($redirectUrl);
        }

        // 2系 L612-617: 失敗時の reasonCode をエラー詳細に
        $reasonCode = isset($result->reasonCode) ? (string) $result->reasonCode : '';
        $this->logger->warning('[AmazonPayCheckout] updateCheckoutSession 失敗', [
            'session_id'  => $sessionId,
            'status'      => $status,
            'reason_code' => $reasonCode,
        ]);
        return AmazonCheckoutResult::error(
            "AmazonPayお支払い情報エラー「{$reasonCode}」",
            $reasonCode,
        );
    }

    /**
     * Stage 3: completeCheckoutSession → chargePermissionId 取得 (2系 L619-666).
     *
     * @param string $sessionId Amazon Checkout Session ID
     */
    public function completeCheckoutSessionForPaymentChange(string $sessionId): AmazonCheckoutResult
    {
        if (!$this->configProvider->isConfigured()) {
            $this->logger->warning('[AmazonPayCheckout] AMAZONPAY_* が未設定のため送信しません');
            return AmazonCheckoutResult::error('AmazonPay 設定が未投入', 'NOT_CONFIGURED');
        }
        try {
            $client = $this->createClient();
        } catch (\Throwable $e) {
            $this->logger->error('[AmazonPayCheckout] client 生成失敗', ['message' => $e->getMessage()]);
            return AmazonCheckoutResult::error($e->getMessage(), 'CLIENT_INIT_FAILED');
        }

        // 2系 lfSetAmazonPay L620-625 相当のペイロード
        $completePayload = [
            'chargeAmount' => [
                'amount'       => (int) self::DUMMY_CHARGE_AMOUNT, // 2系は int リテラル 1
                'currencyCode' => self::CURRENCY_JPY,
            ],
        ];

        try {
            $responseObject = $client->completeCheckoutSession($sessionId, $completePayload);
        } catch (\Throwable $e) {
            $this->logger->error('[AmazonPayCheckout] completeCheckoutSession 例外', [
                'session_id' => $sessionId,
                'message'    => $e->getMessage(),
            ]);
            return AmazonCheckoutResult::error($e->getMessage(), 'SDK_EXCEPTION');
        }

        $status = (int) ($responseObject['status'] ?? 0);
        $result = json_decode((string) ($responseObject['response'] ?? ''));

        // 2系 L635-640: status != 200 なら reasonCode エラー
        if ($status !== 200) {
            $reasonCode = isset($result->reasonCode) ? (string) $result->reasonCode : '';
            $this->logger->warning('[AmazonPayCheckout] completeCheckoutSession 失敗', [
                'session_id'  => $sessionId,
                'status'      => $status,
                'reason_code' => $reasonCode,
            ]);
            return AmazonCheckoutResult::error(
                "AmazonPayお支払い情報エラー「{$reasonCode}」",
                $reasonCode,
            );
        }

        // 2系 L643: chargePermissionId 取得
        $chargePermissionId = isset($result->chargePermissionId) ? (string) $result->chargePermissionId : '';
        if ($chargePermissionId === '') {
            $this->logger->error('[AmazonPayCheckout] chargePermissionId 空', [
                'session_id' => $sessionId,
            ]);
            return AmazonCheckoutResult::error('chargePermissionId 空', 'NO_CHARGE_PERMISSION');
        }

        return AmazonCheckoutResult::completeOk($chargePermissionId);
    }

    /**
     * SDK クライアントを生成する (2系 lfSetAmazonPay の `new Amazon\Pay\API\Client($config)` 相当).
     *
     * SDK は phpstan.neon の excludePaths で解析対象外のため未定義クラスに見える。
     * 戻り値を mixed にして呼出側の型検査を避けるのは AmazonPayRequestService と同方式
     * (あちらは callable(object) 経由)。new の抑止は phpstan.neon の ignoreErrors 側。
     */
    private function createClient(): mixed
    {
        AmazonPaySdkLoader::load();

        return new \Amazon\Pay\API\Client($this->configProvider->getSdkConfig());
    }
}
