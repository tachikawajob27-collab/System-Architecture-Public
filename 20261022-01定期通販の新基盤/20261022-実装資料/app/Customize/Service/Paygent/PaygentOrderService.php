<?php

namespace Customize\Service\Paygent;

use Customize\Lib\Csv\Cp932Unmappable;
use Customize\Lib\Paygent\PaygentSdkLoader;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Paygent 受注操作サービス (Order_Edit 与信操作)
 *
 * 2系 mdl_paygent/include.php::sfPaygentOrder (L3092-3582) のうち
 * **クレジットカード単純電文 3 mode + change_auth / change_commit (2 系多段処理)** を移植:
 *  - auth_cancel        オーソリ取消 (telegram_kind '021')
 *  - card_commit        売上確定     (telegram_kind '022')
 *  - card_commit_cancel 売上取消     (telegram_kind '023')
 *  - change_auth        オーソリ変更 (telegram_kind '020', 内部で旧オーソリ取消の 2 段階処理)
 *  - change_commit      売上変更     (telegram_kind '022' 新規売上, 内部で change_commit_auth →
 *                                     新規売上 → change_commit_cancel の 3 段階処理. Issue #258)
 *
 * 他決済方式 (キャリア/電子マネー/Yahoo!Wallet/Paidy/後払い) は本サービススコープ外。
 *
 * **電文構成:**
 *  共通電文 (2系 sfGetPaygentShare L691-715):
 *   - merchant_id / connect_id / connect_password    … 環境変数経由
 *   - telegram_kind                                   … '021' / '022' / '023'
 *   - telegram_version = '1.0'
 *   - trading_id = order_id
 *   - partner = 'lockon'
 *   - payment_id                                      … dtb_order.memo06 (前回 Paygent 払い ID)
 *
 * **DB更新 (2系 sfPaygentOrder L3496-3501 default ケース):**
 *  成功時:
 *   - memo09 = $kind (021/022/023)
 *   - memo06 = レスポンス内 payment_id (新規払いID)
 *   - memo05 = '' (エラーメッセージクリア)
 *  失敗時:
 *   - memo05 = "エラー詳細 : <detail> エラーコード<code>"
 *   - memo09 はそのまま (変更前ステータス保持)
 *
 * **呼出元の追加処理 (本 Service 範囲外):**
 *  - auth_cancel 成功時: dtb_order.order_status_id = 3 (注文取消し)
 *    → Controller 側で実施
 *
 * **ステータス矛盾チェック (2系 sfPaygentOrder L3183-3199 移植. Issue #262):**
 *  - 単純電文 (auth_cancel / card_commit / card_commit_cancel) 実行前に
 *    memo09 が {021, 023, 0291, 0201} のいずれかなら電文送信せず error 返却.
 *  - change_auth 実行前は上記 + {022, 029} も禁止.
 *  - Twig 側の memo09 判定によるボタン出し分けと合わせ, API 直叩き / 連打による二重電文送信を防ぐ.
 */
class PaygentOrderService
{
    /** telegram_kind: オーソリ取得 / オーソリ変更 (2系 PAYGENT_CREDIT) */
    public const TELEGRAM_KIND_CREDIT = '020';

    /** memo09 ステータス: オーソリ変更処理中 (2系 PAYGENT_CREDIT_PROCESSING) */
    public const STATUS_CREDIT_PROCESSING = '0201';

    /** telegram_kind: オーソリ取消 (2系 PAYGENT_AUTH_CANCEL) */
    public const TELEGRAM_KIND_AUTH_CANCEL = '021';

    /** telegram_kind: 売上確定 (2系 PAYGENT_CARD_COMMIT) */
    public const TELEGRAM_KIND_CARD_COMMIT = '022';

    /** telegram_kind: 売上取消 (2系 PAYGENT_CARD_COMMIT_CANCEL) */
    public const TELEGRAM_KIND_CARD_COMMIT_CANCEL = '023';

    /** memo09 ステータス: 売上変更 (2系 PAYGENT_CARD_COMMIT_REVICE) */
    public const STATUS_CARD_COMMIT_REVICE = '029';

    /** memo09 ステータス: 売上変更処理中 (2系 PAYGENT_CARD_COMMIT_REVICE_PROCESSING) */
    public const STATUS_CARD_COMMIT_REVICE_PROCESSING = '0291';

    /**
     * 全電文共通のステータス矛盾 memo09 値 (2系 sfPaygentOrder L3185-3186 general block).
     * auth_cancel / card_commit / card_commit_cancel / change_auth 全てで禁止する.
     */
    private const COMMON_FORBIDDEN_STATUSES = [
        self::TELEGRAM_KIND_AUTH_CANCEL,           // '021' オーソリ取消済
        self::TELEGRAM_KIND_CARD_COMMIT_CANCEL,    // '023' 売上取消済
        self::STATUS_CARD_COMMIT_REVICE_PROCESSING, // '0291' 売上変更処理中
        self::STATUS_CREDIT_PROCESSING,             // '0201' オーソリ変更処理中
    ];

    /**
     * change_auth 追加禁止 memo09 値 (2系 L3187).
     * 売上確定済 / 売上変更済 の受注に change_auth を発行すると Paygent 側で矛盾するため中断.
     */
    private const CHANGE_AUTH_FORBIDDEN_STATUSES = [
        self::TELEGRAM_KIND_AUTH_CANCEL,           // '021' オーソリ取消済
        self::TELEGRAM_KIND_CARD_COMMIT_CANCEL,    // '023' 売上取消済
        self::STATUS_CARD_COMMIT_REVICE_PROCESSING, // '0291' 売上変更処理中
        self::STATUS_CREDIT_PROCESSING,             // '0201' オーソリ変更処理中
        self::TELEGRAM_KIND_CARD_COMMIT,            // '022' 売上確定済
        self::STATUS_CARD_COMMIT_REVICE,            // '029' 売上変更済
    ];

    /** 2系 mdl_paygent/include.php L343: define("TELEGRAM_VERSION", '1.0') */
    public const TELEGRAM_VERSION = '1.0';

    /** 2系 partner キー固定値 */
    public const PARTNER = 'lockon';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
        private readonly PaygentPaymentConfig $paymentConfig,
        /** 機密設定ディレクトリ (modenv_properties.php / PEM の置き場) */
        private readonly string $paygentConfigDir,
    ) {}

    /**
     * オーソリ取消 (2系 paygent_type='auth_cancel').
     */
    public function authCancel(int $orderId): PaygentOrderResult
    {
        return $this->sendSimpleTelegram($orderId, self::TELEGRAM_KIND_AUTH_CANCEL, 'auth_cancel');
    }

    /**
     * 売上確定 (2系 paygent_type='card_commit').
     */
    public function cardCommit(int $orderId): PaygentOrderResult
    {
        return $this->sendSimpleTelegram($orderId, self::TELEGRAM_KIND_CARD_COMMIT, 'card_commit');
    }

    /**
     * 売上取消 (2系 paygent_type='card_commit_cancel').
     */
    public function cardCommitCancel(int $orderId): PaygentOrderResult
    {
        return $this->sendSimpleTelegram($orderId, self::TELEGRAM_KIND_CARD_COMMIT_CANCEL, 'card_commit_cancel');
    }

    /**
     * オーソリ変更 (2系 paygent_type='change_auth' L3208-3223 / L3388-3402).
     *
     * payment_total が更新された受注について、新規オーソリを取得しなおし、旧オーソリは取消する
     * 2 段階処理。値引登録/削除や受注編集で金額が変わった直後に呼ぶ。
     *
     * **2系仕様 (sfPaygentOrder change_auth):**
     *  1. memo09 がステータス矛盾 (CHANGE_AUTH_FORBIDDEN_STATUSES) なら中断
     *  2. memo09 = '0201' (オーソリ変更処理中) に変更
     *  3. 新規オーソリ電文送信 (telegram_kind='020', payment_amount=現 payment_total)
     *  4. 新オーソリ成功 → 旧 payment_id (memo06) でオーソリ取消電文送信 (telegram_kind='021')
     *  5. 旧オーソリ取消成功時: memo09='020', memo06=新payment_id, memo05=''
     *  6. 旧オーソリ取消失敗時: memo09='020', memo06=新payment_id を更新したうえでエラー返却
     *     (新オーソリは確保済 → memo06 は新規 ID で上書き)
     *  7. 新オーソリ失敗時: memo09 を元 status に戻し memo05 にエラー詳細
     *
     * **本実装の前提:**
     *  - payment_id (dtb_payment.id) のチェックはしない (呼出元でクレカ payment のみ呼ぶ前提)
     *  - quick_memo (serialize) から payment_class / split_count を復元 (2系 L3218-3220)
     */
    public function changeAuth(int $orderId): PaygentOrderResult
    {
        $conn = $this->em->getConnection();

        $connection = $this->loadConnection();
        if ($connection === null) {
            return PaygentOrderResult::error(self::TELEGRAM_KIND_CREDIT, 'Paygent 接続情報未設定', 'NO_CONFIG');
        }

        // 受注情報取得 (memo09 / memo06 / payment_total / quick_memo)
        $order = $conn->fetchAssociative(
            'SELECT memo09, memo06, payment_total, quick_memo FROM dtb_order WHERE id = :id',
            ['id' => $orderId]
        );
        if ($order === false) {
            return PaygentOrderResult::error(self::TELEGRAM_KIND_CREDIT, '受注が見つかりません', 'NO_ORDER');
        }

        $status        = (string) ($order['memo09'] ?? '');
        $oldPaymentId  = (string) ($order['memo06'] ?? '');
        $paymentAmount = (int) ($order['payment_total'] ?? 0);

        // ステータス矛盾チェック (2系 L3185-3199)
        if (in_array($status, self::CHANGE_AUTH_FORBIDDEN_STATUSES, true)) {
            $this->logger->warning('[PaygentOrderService] change_auth ステータス矛盾でスキップ', [
                'order_id' => $orderId,
                'memo09'   => $status,
            ]);
            return PaygentOrderResult::error(self::TELEGRAM_KIND_CREDIT, 'ステータス矛盾エラー', 'STATUS_CONFLICT');
        }

        // 旧 payment_id 必須
        if ($oldPaymentId === '') {
            $this->logger->warning('[PaygentOrderService] change_auth memo06 空でスキップ', ['order_id' => $orderId]);
            return PaygentOrderResult::error(self::TELEGRAM_KIND_CREDIT, 'Paygent 決済IDが見つかりません。', 'NO_PAYMENT_ID');
        }

        // memo09 を「オーソリ変更処理中」に更新 (2系 L3211-3213)
        $conn->executeStatement(
            'UPDATE dtb_order SET memo09 = :memo09, update_date = CURRENT_TIMESTAMP WHERE id = :id',
            ['memo09' => self::STATUS_CREDIT_PROCESSING, 'id' => $orderId]
        );

        try {
            PaygentSdkLoader::load($this->paygentConfigDir);
        } catch (\Throwable $e) {
            $this->logger->error('[PaygentOrderService] SDK ロード失敗 (change_auth)', [
                'order_id' => $orderId, 'message' => $e->getMessage(),
            ]);
            // memo09 を元に戻す
            $this->updateOrderOnFailure($conn, $orderId, $e->getMessage());
            $conn->executeStatement(
                'UPDATE dtb_order SET memo09 = :memo09 WHERE id = :id',
                ['memo09' => $status, 'id' => $orderId]
            );
            return PaygentOrderResult::error(self::TELEGRAM_KIND_CREDIT, $e->getMessage(), 'SDK_LOAD_FAILED');
        }

        // quick_memo から payment_class / split_count 復元 (2系 L3218-3220)
        $arrPaymentParam = [];
        $quickMemo = (string) ($order['quick_memo'] ?? '');
        if ($quickMemo !== '') {
            $unserialized = @unserialize($quickMemo, ['allowed_classes' => false]);
            if (is_array($unserialized)) {
                $arrPaymentParam = $unserialized;
            }
        }

        // 新規オーソリ電文 (payment_id は付けない / 共通電文 + change_auth 特有)
        $telegram = [
            'merchant_id'      => $connection['merchant_id'],
            'connect_id'       => $connection['connect_id'],
            'connect_password' => $connection['connect_password'],
            'telegram_kind'    => self::TELEGRAM_KIND_CREDIT,
            'telegram_version' => self::TELEGRAM_VERSION,
            'trading_id'       => (string) $orderId,
            'partner'          => self::PARTNER,
            // change_auth 特有 (2系 L3216-3222)
            'payment_amount'   => (string) $paymentAmount,
            'ref_trading_id'   => (string) $orderId,
            'payment_class'    => isset($arrPaymentParam['payment_class']) ? (string) $arrPaymentParam['payment_class'] : '',
            'split_count'      => isset($arrPaymentParam['split_count'])  ? (string) $arrPaymentParam['split_count']  : '',
            '3dsecure_ryaku'   => '1',
        ];

        // @phpstan-ignore class.notFound
        $sdk = new \PaygentB2BModule();
        $sdk->init();
        foreach ($telegram as $key => $value) {
            $sdk->reqPut($key, (string) $value);
        }

        try {
            $sdk->post();
        } catch (\Throwable $e) {
            $this->logger->error('[PaygentOrderService] change_auth post 例外', [
                'order_id' => $orderId, 'message' => $e->getMessage(),
            ]);
            $this->updateOrderOnFailure($conn, $orderId, $e->getMessage());
            $conn->executeStatement(
                'UPDATE dtb_order SET memo09 = :memo09 WHERE id = :id',
                ['memo09' => $status, 'id' => $orderId]
            );
            return PaygentOrderResult::error(self::TELEGRAM_KIND_CREDIT, $e->getMessage(), 'POST_EXCEPTION');
        }

        $arrRes = [];
        while ($sdk->hasResNext()) {
            $arrRes[] = $sdk->resNext();
        }
        $resultStatus = (string) ($sdk->getResultStatus() ?? '');

        // 新オーソリ失敗 (2系 L3504-3573 default)
        if ($resultStatus !== '0') {
            $responseCode   = (string) ($sdk->getResponseCode() ?? '');
            $responseDetail = (string) ($sdk->getResponseDetail() ?? '');
            $responseDetail = Cp932Unmappable::Drop->convert($responseDetail, 'UTF-8', 'SJIS-win');
            $errorMessage   = sprintf('エラー詳細 : %s エラーコード%s', $responseDetail, $responseCode);

            // memo09 = 元 status, memo05 = エラー詳細 (2系 L3509-3511 / L3565)
            $conn->executeStatement(
                'UPDATE dtb_order SET memo09 = :memo09, memo05 = :memo05, update_date = CURRENT_TIMESTAMP WHERE id = :id',
                ['memo09' => $status, 'memo05' => $errorMessage, 'id' => $orderId]
            );

            $this->logger->warning('[PaygentOrderService] change_auth 新オーソリ失敗', [
                'order_id'       => $orderId,
                'response_code'  => $responseCode,
                'response_detail'=> $responseDetail,
            ]);
            return PaygentOrderResult::error(self::TELEGRAM_KIND_CREDIT, $responseDetail, $responseCode);
        }

        // 新オーソリ成功 → 旧オーソリ取消 (2系 L3388-3402)
        $newPaymentId = (string) ($arrRes[0]['payment_id'] ?? '');

        $cancelResult = $this->sendChangeAuthCancel($orderId, $oldPaymentId, $connection);

        if (! $cancelResult->ok) {
            // 旧オーソリ取消失敗 (2系 L3392-3396): memo09=新kind, memo06=新payment_id, memo05=エラー詳細
            // 2系 L3578 と同様、$arrVal を 1 本にまとめて UPDATE
            $cancelErrorMessage = sprintf(
                '変更後の金額によるオーソリが成功しましたが、変更前のオーソリ取消に失敗しました。（%s）<br />同一取引IDで複数のオーソリが発生しているため、取引ID:%d, 決済ID:%sのオーソリをペイジェントオンラインから取り消してください。',
                (string) $cancelResult->errorCode,
                $orderId,
                $oldPaymentId,
            );
            $conn->executeStatement(
                'UPDATE dtb_order SET memo09 = :memo09, memo06 = :memo06, memo05 = :memo05, update_date = CURRENT_TIMESTAMP WHERE id = :id',
                [
                    'memo09' => self::TELEGRAM_KIND_CREDIT,
                    'memo06' => $newPaymentId,
                    'memo05' => $cancelErrorMessage,
                    'id'     => $orderId,
                ]
            );
            $this->logger->warning('[PaygentOrderService] change_auth 旧オーソリ取消失敗 (新オーソリは確保済)', [
                'order_id'        => $orderId,
                'new_payment_id'  => $newPaymentId,
                'old_payment_id'  => $oldPaymentId,
                'cancel_error'    => $cancelResult->errorDetail,
                'cancel_code'     => $cancelResult->errorCode,
            ]);
            return $cancelResult;
        }

        // 両方成功 (2系 L3397-3402)
        $conn->executeStatement(
            'UPDATE dtb_order SET memo09 = :memo09, memo06 = :memo06, memo05 = :memo05, update_date = CURRENT_TIMESTAMP WHERE id = :id',
            [
                'memo09' => self::TELEGRAM_KIND_CREDIT,
                'memo06' => $newPaymentId,
                'memo05' => '',
                'id'     => $orderId,
            ]
        );

        $this->logger->info('[PaygentOrderService] change_auth 成功', [
            'order_id'        => $orderId,
            'old_payment_id'  => $oldPaymentId,
            'new_payment_id'  => $newPaymentId,
            'payment_amount'  => $paymentAmount,
        ]);
        return PaygentOrderResult::ok(self::TELEGRAM_KIND_CREDIT);
    }

    /**
     * change_auth 内部の旧オーソリ取消 (2系 paygent_type='change_auth_cancel').
     *
     * 通常の auth_cancel と違い、payment_id を引数で受け取る (memo06 から取らない)。
     * change_auth フロー内では memo06 はまだ旧 payment_id のままだが、2系のロジックに揃えるため
     * 明示的に旧 payment_id を引数で渡す。
     *
     * **副作用なし**: DB 更新は呼出元 changeAuth() 側で memo09/06/05 一括 UPDATE するため、
     * 本関数は SDK 呼出と結果整形のみ実施する。
     *
     * @param array{merchant_id:string, connect_id:string, connect_password:string} $connection
     */
    private function sendChangeAuthCancel(int $orderId, string $oldPaymentId, array $connection): PaygentOrderResult
    {
        // SDK は changeAuth() 側で既に load 済み
        $telegram = [
            'merchant_id'      => $connection['merchant_id'],
            'connect_id'       => $connection['connect_id'],
            'connect_password' => $connection['connect_password'],
            'telegram_kind'    => self::TELEGRAM_KIND_AUTH_CANCEL,
            'telegram_version' => self::TELEGRAM_VERSION,
            'trading_id'       => (string) $orderId,
            'partner'          => self::PARTNER,
            'payment_id'       => $oldPaymentId,
        ];

        // @phpstan-ignore class.notFound
        $sdk = new \PaygentB2BModule();
        $sdk->init();
        foreach ($telegram as $key => $value) {
            $sdk->reqPut($key, (string) $value);
        }

        try {
            $sdk->post();
        } catch (\Throwable $e) {
            return PaygentOrderResult::error(self::TELEGRAM_KIND_AUTH_CANCEL, $e->getMessage(), 'POST_EXCEPTION');
        }

        while ($sdk->hasResNext()) {
            $sdk->resNext();
        }
        $resultStatus = (string) ($sdk->getResultStatus() ?? '');

        if ($resultStatus === '0') {
            return PaygentOrderResult::ok(self::TELEGRAM_KIND_AUTH_CANCEL);
        }

        $responseCode   = (string) ($sdk->getResponseCode() ?? '');
        $responseDetail = (string) ($sdk->getResponseDetail() ?? '');
        $responseDetail = Cp932Unmappable::Drop->convert($responseDetail, 'UTF-8', 'SJIS-win');

        return PaygentOrderResult::error(self::TELEGRAM_KIND_AUTH_CANCEL, $responseDetail, $responseCode);
    }

    /**
     * 売上変更 (2系 paygent_type='change_commit' L3236-3250 / L3404-3420).
     *
     * 既に売上確定済み (memo09='022') の受注に対して金額変更を行う際の 3 段階電文処理:
     *  1. change_commit_auth (新金額で新規オーソリ) → 新 payment_id 取得
     *  2. card_commit (新規売上, kind='022') を **新 payment_id** で送信
     *  3. change_commit_cancel (旧 payment_id の売上キャンセル, kind='023')
     *
     * **2系仕様 (sfPaygentOrder change_commit):**
     *  1. memo09 がステータス矛盾 (COMMON_FORBIDDEN_STATUSES) なら kind='029' で中断
     *     (2系 L3190: change_commit のみ error kind を PAYGENT_CARD_COMMIT_REVICE で返す)
     *  2. memo09 = '0291' (売上変更処理中) に更新
     *  3. change_commit_auth 電文送信 (kind='020', payment_amount=現 payment_total, payment_id なし)
     *  4. 新オーソリ成功 → 新 payment_id で card_commit 電文送信 (kind='022')
     *  5. 新売上成功 → 旧 payment_id で change_commit_cancel 電文送信 (kind='023')
     *  6. 全成功時: memo09='029' (売上変更済), memo06=新 payment_id, memo05=''
     *  7. 新オーソリ失敗時: memo09 を元 status に戻し memo05="新規のオーソリ確保に失敗しました。..."
     *  8. 新売上失敗時: memo09 を元 status に戻し memo05="変更後の金額での売上に失敗しました。..."
     *  9. 旧売上キャンセル失敗時 (部分成功): memo09='029', memo06=新 payment_id,
     *     memo05="変更後の金額による売上が成功しましたが、変更前の売上取消に失敗しました。..."
     *
     * **本実装の前提:**
     *  - payment_id (dtb_payment.id) のチェックはしない (呼出元でクレカ payment のみ呼ぶ前提)
     *  - quick_memo (serialize) から payment_class / split_count を復元 (2系 L3218-3220)
     *  - Twig 側で memo09='022' の受注にのみボタンを出すため、単独で叩いても矛盾チェックが働く
     */
    public function changeCommit(int $orderId): PaygentOrderResult
    {
        $conn = $this->em->getConnection();

        $connection = $this->loadConnection();
        if ($connection === null) {
            return PaygentOrderResult::error(self::STATUS_CARD_COMMIT_REVICE, 'Paygent 接続情報未設定', 'NO_CONFIG');
        }

        // 受注情報取得 (memo09 / memo06 / payment_total / quick_memo)
        $order = $conn->fetchAssociative(
            'SELECT memo09, memo06, payment_total, quick_memo FROM dtb_order WHERE id = :id',
            ['id' => $orderId]
        );
        if ($order === false) {
            return PaygentOrderResult::error(self::STATUS_CARD_COMMIT_REVICE, '受注が見つかりません', 'NO_ORDER');
        }

        $status        = (string) ($order['memo09'] ?? '');
        $oldPaymentId  = (string) ($order['memo06'] ?? '');
        $paymentAmount = (int) ($order['payment_total'] ?? 0);

        // ステータス矛盾チェック (2系 L3185-3199).
        // change_commit は L3190 に従い kind を PAYGENT_CARD_COMMIT_REVICE (029) で返す.
        if (in_array($status, self::COMMON_FORBIDDEN_STATUSES, true)) {
            $this->logger->warning('[PaygentOrderService] change_commit ステータス矛盾でスキップ', [
                'order_id' => $orderId,
                'memo09'   => $status,
            ]);
            return PaygentOrderResult::error(self::STATUS_CARD_COMMIT_REVICE, 'ステータス矛盾エラー', 'STATUS_CONFLICT');
        }

        // 旧 payment_id 必須
        if ($oldPaymentId === '') {
            $this->logger->warning('[PaygentOrderService] change_commit memo06 空でスキップ', ['order_id' => $orderId]);
            return PaygentOrderResult::error(self::STATUS_CARD_COMMIT_REVICE, 'Paygent 決済IDが見つかりません。', 'NO_PAYMENT_ID');
        }

        // memo09 を「売上変更処理中」に更新 (2系 L3237-3239)
        $conn->executeStatement(
            'UPDATE dtb_order SET memo09 = :memo09, update_date = CURRENT_TIMESTAMP WHERE id = :id',
            ['memo09' => self::STATUS_CARD_COMMIT_REVICE_PROCESSING, 'id' => $orderId]
        );

        try {
            PaygentSdkLoader::load($this->paygentConfigDir);
        } catch (\Throwable $e) {
            $this->logger->error('[PaygentOrderService] SDK ロード失敗 (change_commit)', [
                'order_id' => $orderId, 'message' => $e->getMessage(),
            ]);
            $conn->executeStatement(
                'UPDATE dtb_order SET memo09 = :memo09, memo05 = :memo05, update_date = CURRENT_TIMESTAMP WHERE id = :id',
                ['memo09' => $status, 'memo05' => $e->getMessage(), 'id' => $orderId]
            );
            return PaygentOrderResult::error(self::STATUS_CARD_COMMIT_REVICE, $e->getMessage(), 'SDK_LOAD_FAILED');
        }

        // quick_memo から payment_class / split_count 復元 (2系 L3218-3220)
        $arrPaymentParam = [];
        $quickMemo = (string) ($order['quick_memo'] ?? '');
        if ($quickMemo !== '') {
            $unserialized = @unserialize($quickMemo, ['allowed_classes' => false]);
            if (is_array($unserialized)) {
                $arrPaymentParam = $unserialized;
            }
        }

        // Step 1: change_commit_auth (2系 L3241 sfPaygentOrder('change_commit_auth', ...))
        $authResult = $this->sendChangeCommitAuth($orderId, $paymentAmount, $arrPaymentParam, $connection);

        if (! $authResult->ok) {
            // 新オーソリ失敗 (2系 L3520-3522 case 'change_commit_auth' + L3508-3512).
            // beforeStatus (=元 memo09) に戻す + memo05 セット.
            $errorMessage = sprintf(
                '新規のオーソリ確保に失敗しました。（%s）<br />ペイジェントオンラインから売上変更してください。',
                (string) $authResult->errorCode
            );
            $conn->executeStatement(
                'UPDATE dtb_order SET memo09 = :memo09, memo05 = :memo05, update_date = CURRENT_TIMESTAMP WHERE id = :id',
                ['memo09' => $status, 'memo05' => $errorMessage, 'id' => $orderId]
            );

            $this->logger->warning('[PaygentOrderService] change_commit 新規オーソリ失敗', [
                'order_id'      => $orderId,
                'response_code' => $authResult->errorCode,
            ]);
            // 2系 L3244: kind を PAYGENT_CARD_COMMIT_REVICE (029) に強制
            return PaygentOrderResult::error(self::STATUS_CARD_COMMIT_REVICE, $errorMessage, (string) $authResult->errorCode);
        }

        $newPaymentId = (string) $authResult->newPaymentId;

        // Step 2: 新 payment_id で card_commit 電文送信 (2系 L3236 case 'change_commit' の
        // kind=PAYGENT_CARD_COMMIT [L3116]. arrSend['payment_id']=新 payment_id [L3248]).
        $telegram = [
            'merchant_id'      => $connection['merchant_id'],
            'connect_id'       => $connection['connect_id'],
            'connect_password' => $connection['connect_password'],
            'telegram_kind'    => self::TELEGRAM_KIND_CARD_COMMIT,
            'telegram_version' => self::TELEGRAM_VERSION,
            'trading_id'       => (string) $orderId,
            'partner'          => self::PARTNER,
            'payment_id'       => $newPaymentId,
        ];

        // @phpstan-ignore class.notFound (Paygent SDK はグローバル namespace)
        $sdk = new \PaygentB2BModule();
        $sdk->init();
        foreach ($telegram as $key => $value) {
            $sdk->reqPut($key, (string) $value);
        }

        try {
            $sdk->post();
        } catch (\Throwable $e) {
            $this->logger->error('[PaygentOrderService] change_commit main post 例外', [
                'order_id' => $orderId, 'message' => $e->getMessage(),
            ]);
            $conn->executeStatement(
                'UPDATE dtb_order SET memo09 = :memo09, memo05 = :memo05, update_date = CURRENT_TIMESTAMP WHERE id = :id',
                ['memo09' => $status, 'memo05' => $e->getMessage(), 'id' => $orderId]
            );
            return PaygentOrderResult::error(self::STATUS_CARD_COMMIT_REVICE, $e->getMessage(), 'POST_EXCEPTION');
        }

        while ($sdk->hasResNext()) {
            $sdk->resNext();
        }
        $resultStatus = (string) ($sdk->getResultStatus() ?? '');

        // 新売上失敗 (2系 L3515-3517 case 'change_commit')
        if ($resultStatus !== '0') {
            $responseCode = (string) ($sdk->getResponseCode() ?? '');
            $errorMessage = sprintf(
                '変更後の金額での売上に失敗しました。（%s）<br />取引ID:%d, 決済ID:%s',
                $responseCode,
                $orderId,
                $newPaymentId,
            );
            $conn->executeStatement(
                'UPDATE dtb_order SET memo09 = :memo09, memo05 = :memo05, update_date = CURRENT_TIMESTAMP WHERE id = :id',
                ['memo09' => $status, 'memo05' => $errorMessage, 'id' => $orderId]
            );

            $this->logger->warning('[PaygentOrderService] change_commit 新売上失敗', [
                'order_id'       => $orderId,
                'response_code'  => $responseCode,
                'new_payment_id' => $newPaymentId,
            ]);
            return PaygentOrderResult::error(self::STATUS_CARD_COMMIT_REVICE, $errorMessage, $responseCode);
        }

        // Step 3: 旧 payment_id の売上キャンセル (2系 L3404-3420)
        $cancelResult = $this->sendChangeCommitCancel($orderId, $oldPaymentId, $connection);

        if (! $cancelResult->ok) {
            // 部分成功 (新売上OK / 旧売上取消NG): 2系 L3408-3413.
            // memo09='029', memo06=新 payment_id, memo05=部分成功メッセージ.
            $cancelErrorMessage = sprintf(
                '変更後の金額による売上が成功しましたが、変更前の売上取消に失敗しました。（%s）<br />同一取引IDで複数の売上が発生しているため、取引ID:%d, 決済ID:%sの売上をペイジェントオンラインから取り消してください。',
                (string) $cancelResult->errorCode,
                $orderId,
                $oldPaymentId,
            );
            $conn->executeStatement(
                'UPDATE dtb_order SET memo09 = :memo09, memo06 = :memo06, memo05 = :memo05, update_date = CURRENT_TIMESTAMP WHERE id = :id',
                [
                    'memo09' => self::STATUS_CARD_COMMIT_REVICE,
                    'memo06' => $newPaymentId,
                    'memo05' => $cancelErrorMessage,
                    'id'     => $orderId,
                ]
            );

            $this->logger->warning('[PaygentOrderService] change_commit 旧売上取消失敗 (部分成功)', [
                'order_id'       => $orderId,
                'new_payment_id' => $newPaymentId,
                'old_payment_id' => $oldPaymentId,
                'cancel_code'    => $cancelResult->errorCode,
            ]);
            return PaygentOrderResult::error(self::STATUS_CARD_COMMIT_REVICE, $cancelErrorMessage, (string) $cancelResult->errorCode);
        }

        // 全成功 (2系 L3415-3419)
        $conn->executeStatement(
            'UPDATE dtb_order SET memo09 = :memo09, memo06 = :memo06, memo05 = :memo05, update_date = CURRENT_TIMESTAMP WHERE id = :id',
            [
                'memo09' => self::STATUS_CARD_COMMIT_REVICE,
                'memo06' => $newPaymentId,
                'memo05' => '',
                'id'     => $orderId,
            ]
        );

        $this->logger->info('[PaygentOrderService] change_commit 成功', [
            'order_id'       => $orderId,
            'old_payment_id' => $oldPaymentId,
            'new_payment_id' => $newPaymentId,
            'payment_amount' => $paymentAmount,
        ]);
        return PaygentOrderResult::ok(self::STATUS_CARD_COMMIT_REVICE);
    }

    /**
     * change_commit 内部の新規オーソリ (2系 paygent_type='change_commit_auth' L3209-3223 / L3382-3384).
     *
     * kind=PAYGENT_CREDIT (020), payment_amount 付与, payment_id なし の電文.
     * 成功時は応答から payment_id を取り出して呼出元へ返却する.
     *
     * **副作用なし**: DB 更新は呼出元 changeCommit() 側で memo09/06/05 一括 UPDATE するため、
     * 本関数は SDK 呼出と結果整形のみ実施する (2系 L3209 は change_auth のときのみ memo09 更新).
     *
     * @param array<string, mixed>                                                     $arrPaymentParam  quick_memo unserialize 結果
     * @param array{merchant_id:string, connect_id:string, connect_password:string}    $connection
     */
    private function sendChangeCommitAuth(int $orderId, int $paymentAmount, array $arrPaymentParam, array $connection): PaygentOrderResult
    {
        // SDK は changeCommit() 側で既に load 済み
        $telegram = [
            'merchant_id'      => $connection['merchant_id'],
            'connect_id'       => $connection['connect_id'],
            'connect_password' => $connection['connect_password'],
            'telegram_kind'    => self::TELEGRAM_KIND_CREDIT,
            'telegram_version' => self::TELEGRAM_VERSION,
            'trading_id'       => (string) $orderId,
            'partner'          => self::PARTNER,
            // change_commit_auth 特有 (2系 L3215-3222)
            'payment_amount'   => (string) $paymentAmount,
            'ref_trading_id'   => (string) $orderId,
            'payment_class'    => isset($arrPaymentParam['payment_class']) ? (string) $arrPaymentParam['payment_class'] : '',
            'split_count'      => isset($arrPaymentParam['split_count'])  ? (string) $arrPaymentParam['split_count']  : '',
            '3dsecure_ryaku'   => '1',
        ];

        // @phpstan-ignore class.notFound
        $sdk = new \PaygentB2BModule();
        $sdk->init();
        foreach ($telegram as $key => $value) {
            $sdk->reqPut($key, (string) $value);
        }

        try {
            $sdk->post();
        } catch (\Throwable $e) {
            return PaygentOrderResult::error(self::TELEGRAM_KIND_CREDIT, $e->getMessage(), 'POST_EXCEPTION');
        }

        $arrRes = [];
        while ($sdk->hasResNext()) {
            $arrRes[] = $sdk->resNext();
        }
        $resultStatus = (string) ($sdk->getResultStatus() ?? '');

        if ($resultStatus === '0') {
            $newPaymentId = (string) ($arrRes[0]['payment_id'] ?? '');
            return PaygentOrderResult::okWithPaymentId(self::TELEGRAM_KIND_CREDIT, $newPaymentId);
        }

        $responseCode   = (string) ($sdk->getResponseCode() ?? '');
        $responseDetail = (string) ($sdk->getResponseDetail() ?? '');
        $responseDetail = Cp932Unmappable::Drop->convert($responseDetail, 'UTF-8', 'SJIS-win');

        return PaygentOrderResult::error(self::TELEGRAM_KIND_CREDIT, $responseDetail, $responseCode);
    }

    /**
     * change_commit 内部の旧売上キャンセル (2系 paygent_type='change_commit_cancel' L3406).
     *
     * kind=PAYGENT_CARD_COMMIT_CANCEL (023) を **旧 payment_id** で送る.
     * 通常の cardCommitCancel と違い、memo06 からではなく引数で受け取った旧 payment_id を使う.
     *
     * **副作用なし**: DB 更新は呼出元 changeCommit() 側で memo09/06/05 一括 UPDATE する.
     *
     * @param array{merchant_id:string, connect_id:string, connect_password:string} $connection
     */
    private function sendChangeCommitCancel(int $orderId, string $oldPaymentId, array $connection): PaygentOrderResult
    {
        // SDK は changeCommit() 側で既に load 済み
        $telegram = [
            'merchant_id'      => $connection['merchant_id'],
            'connect_id'       => $connection['connect_id'],
            'connect_password' => $connection['connect_password'],
            'telegram_kind'    => self::TELEGRAM_KIND_CARD_COMMIT_CANCEL,
            'telegram_version' => self::TELEGRAM_VERSION,
            'trading_id'       => (string) $orderId,
            'partner'          => self::PARTNER,
            'payment_id'       => $oldPaymentId,
        ];

        // @phpstan-ignore class.notFound
        $sdk = new \PaygentB2BModule();
        $sdk->init();
        foreach ($telegram as $key => $value) {
            $sdk->reqPut($key, (string) $value);
        }

        try {
            $sdk->post();
        } catch (\Throwable $e) {
            return PaygentOrderResult::error(self::TELEGRAM_KIND_CARD_COMMIT_CANCEL, $e->getMessage(), 'POST_EXCEPTION');
        }

        while ($sdk->hasResNext()) {
            $sdk->resNext();
        }
        $resultStatus = (string) ($sdk->getResultStatus() ?? '');

        if ($resultStatus === '0') {
            return PaygentOrderResult::ok(self::TELEGRAM_KIND_CARD_COMMIT_CANCEL);
        }

        $responseCode   = (string) ($sdk->getResponseCode() ?? '');
        $responseDetail = (string) ($sdk->getResponseDetail() ?? '');
        $responseDetail = Cp932Unmappable::Drop->convert($responseDetail, 'UTF-8', 'SJIS-win');

        return PaygentOrderResult::error(self::TELEGRAM_KIND_CARD_COMMIT_CANCEL, $responseDetail, $responseCode);
    }

    /**
     * 単純電文 (auth_cancel / card_commit / card_commit_cancel) を Paygent SDK 経由で送付する。
     *
     * 2系 sfPaygentOrder L3342-3578 の default ケース処理に相当。
     * 開始時に memo09 のステータス矛盾チェック (2系 L3185-3199, Issue #262) を実施し,
     * 二重電文送信を防ぐ.
     */
    private function sendSimpleTelegram(int $orderId, string $kind, string $paygentTypeLabel): PaygentOrderResult
    {
        $conn = $this->em->getConnection();

        // 接続情報チェック
        $connection = $this->loadConnection();
        if ($connection === null) {
            return PaygentOrderResult::error($kind, 'Paygent 接続情報未設定', 'NO_CONFIG');
        }

        // dtb_order から memo06 (前回 Paygent 払い ID) と memo09 (現在ステータス) を取得
        $order = $conn->fetchAssociative(
            'SELECT memo06, memo09 FROM dtb_order WHERE id = :id',
            ['id' => $orderId]
        );
        if ($order === false) {
            return PaygentOrderResult::error($kind, '受注が見つかりません', 'NO_ORDER');
        }

        // ステータス矛盾チェック (2系 sfPaygentOrder L3185-3186 general block, Issue #262).
        // memo09 が 021/023/0291/0201 (取消済 or 処理中) の場合は電文送信せず中断.
        // API 直叩き / 連打による二重キャンセル・二重売上確定を防ぐ.
        $currentStatus = (string) ($order['memo09'] ?? '');
        if (in_array($currentStatus, self::COMMON_FORBIDDEN_STATUSES, true)) {
            $this->logger->warning('[PaygentOrderService] ステータス矛盾でスキップ', [
                'order_id'     => $orderId,
                'paygent_type' => $paygentTypeLabel,
                'memo09'       => $currentStatus,
            ]);
            return PaygentOrderResult::error($kind, 'ステータス矛盾エラー', 'STATUS_CONFLICT');
        }

        $paymentId = (string) ($order['memo06'] ?? '');
        if ($paymentId === '') {
            $this->logger->warning('[PaygentOrderService] memo06 (payment_id) が空のため処理スキップ', [
                'order_id'     => $orderId,
                'paygent_type' => $paygentTypeLabel,
            ]);
            return PaygentOrderResult::error($kind, 'Paygent 決済IDが見つかりません。', 'NO_PAYMENT_ID');
        }

        try {
            PaygentSdkLoader::load($this->paygentConfigDir);
        } catch (\Throwable $e) {
            $this->logger->error('[PaygentOrderService] SDK ロード失敗', [
                'order_id' => $orderId,
                'message'  => $e->getMessage(),
            ]);
            return PaygentOrderResult::error($kind, $e->getMessage(), 'SDK_LOAD_FAILED');
        }

        // 電文構築
        $telegram = $this->buildShareTelegram($kind, $orderId, $paymentId, $connection);

        // SDK に投入
        // @phpstan-ignore class.notFound (Paygent SDK はグローバル namespace、phpstan 除外パス済み)
        $sdk = new \PaygentB2BModule();
        $sdk->init();
        foreach ($telegram as $key => $value) {
            $sdk->reqPut($key, (string) $value);
        }

        // 実行
        try {
            $sdk->post();
        } catch (\Throwable $e) {
            $this->logger->error('[PaygentOrderService] post 例外', [
                'order_id'     => $orderId,
                'paygent_type' => $paygentTypeLabel,
                'message'      => $e->getMessage(),
            ]);
            return PaygentOrderResult::error($kind, $e->getMessage(), 'POST_EXCEPTION');
        }

        // レスポンス取得 (2系 sfPaygentOrder L3349-3361)
        $arrRes = [];
        while ($sdk->hasResNext()) {
            $arrRes[] = $sdk->resNext();
        }
        $resultStatus = (string) ($sdk->getResultStatus() ?? '');

        // 正常終了
        if ($resultStatus === '0') {
            $responsePaymentId = (string) ($arrRes[0]['payment_id'] ?? '');
            $this->updateOrderOnSuccess($conn, $orderId, $kind, $responsePaymentId);

            $this->logger->info('[PaygentOrderService] 処理成功', [
                'order_id'     => $orderId,
                'paygent_type' => $paygentTypeLabel,
                'kind'         => $kind,
            ]);
            return PaygentOrderResult::ok($kind);
        }

        // 異常終了
        $responseCode   = (string) ($sdk->getResponseCode() ?? '');
        $responseDetail = (string) ($sdk->getResponseDetail() ?? '');
        $responseDetail = Cp932Unmappable::Drop->convert($responseDetail, 'UTF-8', 'SJIS-win');
        $errorMessage   = sprintf('エラー詳細 : %s エラーコード%s', $responseDetail, $responseCode);

        $this->updateOrderOnFailure($conn, $orderId, $errorMessage);

        $this->logger->warning('[PaygentOrderService] 処理失敗', [
            'order_id'       => $orderId,
            'paygent_type'   => $paygentTypeLabel,
            'response_code'  => $responseCode,
            'response_detail'=> $responseDetail,
        ]);
        return PaygentOrderResult::error($kind, $responseDetail, $responseCode);
    }

    /**
     * 成功時 DB 更新 (2系 sfPaygentOrder L3496-3500 default ケース).
     *
     *  memo09 = $kind
     *  memo06 = レスポンス内 payment_id (新規払いID)
     *  memo05 = '' (エラーメッセージクリア)
     */
    private function updateOrderOnSuccess(Connection $conn, int $orderId, string $kind, string $responsePaymentId): void
    {
        $conn->executeStatement(
            'UPDATE dtb_order SET memo09 = :memo09, memo06 = :memo06, memo05 = :memo05, update_date = CURRENT_TIMESTAMP WHERE id = :id',
            [
                'memo09' => $kind,
                'memo06' => $responsePaymentId,
                'memo05' => '',
                'id'     => $orderId,
            ]
        );
    }

    /**
     * 失敗時 DB 更新 (2系 sfPaygentOrder L3562-3565 default ケース).
     *
     *  memo05 = "エラー詳細 : <detail> エラーコード<code>"
     *  memo09 は変更しない (変更前ステータス保持)
     */
    private function updateOrderOnFailure(Connection $conn, int $orderId, string $errorMessage): void
    {
        $conn->executeStatement(
            'UPDATE dtb_order SET memo05 = :memo05, update_date = CURRENT_TIMESTAMP WHERE id = :id',
            [
                'memo05' => $errorMessage,
                'id'     => $orderId,
            ]
        );
    }

    /**
     * 共通電文 (2系 sfGetPaygentShare L691-715).
     *
     * @param array{merchant_id:string, connect_id:string, connect_password:string} $connection
     * @return array<string, string>
     */
    private function buildShareTelegram(string $kind, int $orderId, string $paymentId, array $connection): array
    {
        return [
            'merchant_id'      => $connection['merchant_id'],
            'connect_id'       => $connection['connect_id'],
            'connect_password' => $connection['connect_password'],
            'telegram_kind'    => $kind,
            'telegram_version' => self::TELEGRAM_VERSION,
            'trading_id'       => (string) $orderId,
            'partner'          => self::PARTNER,
            'payment_id'       => $paymentId,
        ];
    }

    /**
     * Paygent 接続情報を取得する (PaygentAuthorizer と同じ路線).
     *
     * @return array{merchant_id:string, connect_id:string, connect_password:string}|null
     */
    private function loadConnection(): ?array
    {
        // 2系 sfGetPaymentDB (dtb_payment.memo01/02/04) から取得する。
        // env 上書きの扱いは PaygentPaymentConfig を参照。
        return $this->paymentConfig->getConnection();
    }
}
