<?php

declare(strict_types=1);

namespace Customize\Service\Shipping;

use Customize\Lib\Csv\Cp932Unmappable;
use Customize\Repository\OrderRepository as CustomizeOrderRepository;
use Customize\Service\AmazonPay\AmazonPayRefundService;
use Customize\Service\AmazonPay\AmazonPayRequestService;
use Customize\Service\Mail\ReturnNoticeMailService;
use Customize\Service\OrderWarehouseReturnPointService;
use Customize\Service\Paygent\PaygentOrderService;
use Customize\Service\Vamos\VamosService;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * 倉庫返品 CSV 取込 Service.
 *
 * 2系: SC_Helper_CSV_Ex::doUploadReturnsCsv (L605-1117, 約 513 行) を移植.
 *
 * CSV レイアウト (2系 準拠):
 *   [0] ブランド名 (dtb_brand.name_wms と一致確認)
 *   [1] 注文番号 (dtb_order.id)
 *   [2] 返品理由 ("長期不在" / "住所不明" / "受取拒否")
 *
 * 主要フロー:
 *   1. CSV parse (SJIS→UTF-8, 1行目 header skip)
 *   2. row loop:
 *      a. ブランド名 wms 一致確認
 *      b. 注文番号存在 + 発送済 (order_status_id=5) 判定
 *      c. dtb_order_status アイコン確認:
 *         - status=48 (一部返品案内済み) → status=49 (一部返品処理待ち) に変更
 *         - status=49 → skip (既に処理中)
 *         - status=50 → is_auto_return=true (自動返金対象)
 *      d. dtb_customer_status 会員ステータス確認 (9/999 → メール送信除外)
 *      e. dtb_order.order_status_id=500 (倉庫戻り), アイコン追加 (20009-20011),
 *         ポイント失効 (OrderWarehouseReturnPointService, Issue #2728。raw DBAL UPDATEは
 *         Doctrine ORMのpreUpdateを発火させないため明示呼出が必要)
 *      f. is_auto_return 判定:
 *         - Paygent/AmazonPay/NP後払い/無料 以外 (後払い(VAMOS)・代引き・自社後払い) は自動処理対象
 *           (★VAMOS は 2系 2026-09-02 a8cf5cf で対象外→対象に変更。Issue #3779)
 *      g. is_auto_return=true 分岐:
 *         - payment_id=5  (Paygent):    card_commit_cancel 電文送信 (PaygentOrderService)
 *         - payment_id=12 (自社後払い): nyukin_status=4 に更新
 *         - payment_id=9  (AmazonPay):  createRefund (AmazonPayRefundService::refund, Issue #395)
 *         - payment_id=13 (VAMOS):      cancelVaCredit (VamosService::cancelCredit 実装済)
 *      h. is_auto_return=false 分岐:
 *         - 縛りコース (dtb_course_shibari) チェック
 *         - 縛り未満 + 代引き (payment_id=4) → 違約金メール対象 + 定期解約
 *         - 初回返品 (order_count=1) + 住所不明/受取拒否 → 定期解約
 *      i. 定期解約 (縛りコース満たしていれば):
 *         - dtb_teiki.status=3, cancel_teiki_reason_id=12
 *         - 白データ (order_status_id IN 1/9000/9001/9002/9003) の与信キャンセル
 *   3. commit 後にメールを **即時送信** ({@see ReturnNoticeMailService}. 2系 L1082-1113 と同じ。Issue #2821):
 *      - template_id=40 通常返品案内
 *      - template_id=67 違約金催促
 *      - template_id=68 口座返金案内 ($refundFlg 対象)
 *        ★2系 doUploadReturnsCsv は Paygent (L825) / AmazonPay (L871) いずれも
 *        `$refund_flg = true;` がコメントアウトされており、L693 の初期値 false から
 *        実際には一度も true にならない (dead code)。4系もこれを忠実に踏襲し、
 *        AmazonPayRefundService::refund 成功時も $refundFlg は変更しない
 *        (CLAUDE.md「2系バグも踏襲」。$refundFlg を true にする「修正」はしない)。
 *
 * PR-2g スコープ外だった payment_id=9 AmazonPay createRefund は本 Issue (#395) で実装済み
 * (AmazonPayRefundService::refund, dispatchAutoReturn 内で呼出).
 *
 * スコープ外 (後続 PR):
 *   - payment_id=13 VAMOS cancelVaCredit → 実装済 (VamosService::cancelCredit)
 *   - NP後払い (payment_id=6/11) — 2系でも大半コメントアウトのため未対応
 */
class ReturnsCsvImportService
{
    // ============ order_status_id (dtb_order) ============
    /** 発送済. */
    private const STATUS_SHIPPED = 5;
    /** 倉庫戻り. */
    private const STATUS_WAREHOUSE_RETURN = 500;
    /** キャンセル (auth_cancel 後). */
    private const STATUS_CANCEL = 3;
    /** 白データ (auth 済まだ発送前). 2系 doUploadReturnsCsv L992 の in-list. */
    private const WHITE_STATUSES = [1, 9000, 9001, 9002, 9003];

    // ============ アイコン status (dtb_order_status) ============
    /** 「一部返品案内済み」. */
    private const ICON_GUIDE = 48;
    /** 「一部返品処理待ち」. */
    private const ICON_RETURN = 49;
    /** 「自動返金対象」. */
    private const ICON_AUTO_RETURN = 50;
    /** 「決済未キャンセル」 (自動キャンセル失敗時). */
    private const ICON_NOT_AUTH_CANCEL = 51;

    /** 返品理由 → アイコン status_id マッピング. */
    private const RETURN_REASON_ICON_MAP = [
        '長期不在' => 20009,
        '住所不明' => 20010,
        '受取拒否' => 20011,
    ];

    // ============ payment_id ============
    private const PAYMENT_ID_DAIBIKI = 4;
    private const PAYMENT_ID_PAYGENT = 5;
    private const PAYMENT_ID_AMAZONPAY = 9;
    private const PAYMENT_ID_JISHA_ATOBARAI = 12;
    /** 2系 MDL_VAMOS_PAYMENT_ID. */
    private const PAYMENT_ID_VAMOS = 13;

    /**
     * 自動返金対象外 payment_id (2系 L811).
     * これらは is_auto_return=false 分岐で個別処理される.
     *
     * ★2026-09-02 の 2系 commit a8cf5cf (cartDev PR #55「倉庫返品のコンビニ後払いは必ず決済キャンセルするように変更」)
     *   で `MDL_VAMOS_PAYMENT_ID`(13) がこのリストから**外れた**。コンビニ後払い(VAMOS)は代引き等と同じ
     *   「通常処理」= is_auto_return=true → {@see self::dispatchAutoReturn()} の VAMOS 分岐で与信キャンセル依頼を
     *   必ず出す (2系 L809-814 / L899-906)。外す前は else 側に落ちて「決済未キャンセル」アイコン 51 が付き、
     *   返品案内メールも送られなかった。Issue #3779 #6 で追従 (2026-09-17)。
     */
    private const NON_AUTO_RETURN_PAYMENTS = [5, 6, 7, 9, 11];

    // ============ dtb_customer_status メール送信除外 ============
    /** 会員ステータス「BAN対象」等の除外コード (2系 L770). */
    private const MAIL_EXCLUDE_CUSTOMER_STATUSES = [9, 999];

    // ============ 定期解約 ============
    /** dtb_teiki.cancel_teiki_reason_id: 「倉庫返品」. */
    private const TEIKI_CANCEL_REASON_RETURNS = 12;

    public function __construct(
        private readonly Connection $connection,
        private readonly LoggerInterface $logger,
        private readonly Security $security,
        private readonly PaygentOrderService $paygentOrderService,
        private readonly VamosService $vamosService,
        private readonly CustomizeOrderRepository $customizeOrderRepository,
        private readonly AmazonPayRequestService $amazonPayRequestService,
        private readonly AmazonPayRefundService $amazonPayRefundService,
        private readonly OrderWarehouseReturnPointService $warehouseReturnPointService,
        private readonly ReturnNoticeMailService $returnNoticeMailService,
    ) {}

    /**
     * 倉庫返品 CSV を取り込む.
     *
     * @return array{errors: list<string>, processed: int}
     */
    public function import(UploadedFile $file, int $brandId): array
    {
        $this->logger->info('[倉庫返品] START');

        $errors = [];
        $processed = 0;

        $tmp = $this->convertSjisToUtf8($file->getRealPath());
        if ($tmp === null) {
            return ['errors' => ['CSVファイルの読み込みに失敗しました。'], 'processed' => 0];
        }

        $fp = fopen($tmp, 'r');
        if ($fp === false) {
            @unlink($tmp);
            return ['errors' => ['CSVファイルを開けませんでした。'], 'processed' => 0];
        }

        // ブランドマスタ (name_wms 判定用)
        $brand = $this->connection->fetchAssociative(
            'SELECT name_wms FROM dtb_brand WHERE id = :id',
            ['id' => $brandId]
        );
        if ($brand === false) {
            fclose($fp);
            @unlink($tmp);
            return ['errors' => ['ブランド情報が取得できません。'], 'processed' => 0];
        }
        $brandNameWms = (string) ($brand['name_wms'] ?? '');

        $this->connection->beginTransaction();

        /** @var list<int> $arrOrderId 通常返品案内メール対象 */
        $arrOrderId = [];
        /** @var list<int> $arrPenaltyId 違約金メール対象 (縛りコース未満 + 代引き) */
        $arrPenaltyId = [];
        /** @var list<int> $arrRefundId 口座返金メール対象 (Paygent/AmazonPay Refund 済) */
        $arrRefundId = [];

        try {
            $line = 0;
            while (($csv = fgetcsv($fp, 4096)) !== false) {
                $line++;
                if ($line === 1) {
                    continue;
                }
                // fgetcsv() は空行を [null] として返し、[](空配列)を返すことは無い
                // (false は while条件で既に除外済み)。empty($csv) は到達不能なので消す。
                if ($csv === [null]) {
                    continue;
                }

                $rowResult = $this->processRow(
                    $line,
                    $csv,
                    $brandNameWms,
                    $arrOrderId,
                    $arrPenaltyId,
                    $arrRefundId,
                );
                if ($rowResult['error'] !== null) {
                    $errors[] = $rowResult['error'];
                    break;
                }
                if ($rowResult['processed']) {
                    $processed++;
                }
            }

            if (!empty($errors)) {
                $this->connection->rollBack();
                $this->logger->warning('[倉庫返品] rollback due to errors', ['errors' => $errors]);
                return ['errors' => $errors, 'processed' => 0];
            }

            $this->connection->commit();

            // メール即時送信 (2系 L1082-1113 と同じく commit 後。送信失敗は取込全体を失敗にしない)
            $this->returnNoticeMailService->sendAll($arrOrderId, ReturnNoticeMailService::TEMPLATE_RETURN_NOTICE);
            $this->returnNoticeMailService->sendAll($arrPenaltyId, ReturnNoticeMailService::TEMPLATE_PENALTY);
            $this->returnNoticeMailService->sendAll($arrRefundId, ReturnNoticeMailService::TEMPLATE_REFUND);

            $this->logger->info(sprintf('[倉庫返品] END processed=%d', $processed));
            return ['errors' => [], 'processed' => $processed];
        } catch (\Throwable $e) {
            $this->connection->rollBack();
            $this->logger->error('[倉庫返品] exception', ['message' => $e->getMessage()]);
            return ['errors' => ['取込中にエラーが発生しました: ' . $e->getMessage()], 'processed' => 0];
        } finally {
            fclose($fp);
            @unlink($tmp);
        }
    }

    /**
     * 1 行分の処理 (2系 L642-1067 loop body).
     *
     * @param array<int, mixed> $csv 生の CSV row
     * @param list<int> $arrOrderId (by-ref) 通常返品案内メール対象
     * @param list<int> $arrPenaltyId (by-ref) 違約金メール対象
     * @param list<int> $arrRefundId (by-ref) 口座返金メール対象
     * @return array{error: ?string, processed: bool}
     */
    private function processRow(
        int $line,
        array $csv,
        string $brandNameWms,
        array &$arrOrderId,
        array &$arrPenaltyId,
        array &$arrRefundId,
    ): array {
        // レイアウト検証 (2系 L656-659)
        if (!isset($csv[1]) || !is_numeric($csv[1])) {
            return ['error' => "[ERROR]{$line}行目:CSVが倉庫返品のレイアウトではありません。", 'processed' => false];
        }
        $orderId = (int) $csv[1];
        if ($orderId <= 0) {
            return ['error' => "[ERROR]{$line}行目でエラーが発生しました。注文番号が空です。", 'processed' => false];
        }

        // 受注取得 (2系 L668)
        $order = $this->connection->fetchAssociative(
            <<<'SQL'
                SELECT id AS order_id, customer_id, order_status_id AS status, payment_id, payment_total, brand_id
                  FROM dtb_order
                 WHERE id = :id
            SQL,
            ['id' => $orderId]
        );
        if ($order === false) {
            $this->logger->info("[倉庫返品] {$line}行目:注文番号{$orderId}が存在しません。skip");
            return ['error' => null, 'processed' => false];
        }

        // レイアウト検証: 返品理由 (2系 L674-677, L679-682)
        if (!isset($csv[2]) || !is_string($csv[2])) {
            return ['error' => "[ERROR]{$line}行目:CSVが倉庫返品のレイアウトではありません。", 'processed' => false];
        }
        $reason = (string) $csv[2];
        if (!isset(self::RETURN_REASON_ICON_MAP[$reason])) {
            $this->logger->info("[倉庫返品] {$line}行目:自動返品処理が出来ないステータス({$reason})です。skip");
            return ['error' => null, 'processed' => false];
        }

        // ブランド一致 (2系 L685-688)
        $csvBrandName = (string) ($csv[0] ?? '');
        if ($csvBrandName !== $brandNameWms) {
            return ['error' => "[ERROR]{$line}行目:取込対象の商品が違います。({$csvBrandName})", 'processed' => false];
        }

        // 既存アイコンによる分岐 (2系 L697-764)
        [$isAutoReturn, $shouldContinue] = $this->processOrderStatusIcons($orderId, (int) $order['status'], (int) $order['payment_id']);
        if ($shouldContinue) {
            return ['error' => null, 'processed' => false];
        }

        // 会員ステータスによるメール送信フラグ (2系 L768-773)
        $customerFlg = !$this->isMailExcludedCustomer((int) $order['customer_id']);

        // 対応状況：発送済みでない場合スキップ (2系 L784-787)
        if ((int) $order['status'] !== self::STATUS_SHIPPED) {
            $this->logger->info(sprintf('[倉庫返品] 発送済ステータスでないため処理しない。受注番号：%d', $orderId));
            return ['error' => null, 'processed' => false];
        }

        // 受注データのステータス倉庫戻り + アイコン追加 (2系 L789-807)
        $this->markOrderReturned($orderId, (int) $order['customer_id'], (int) $order['status'], self::RETURN_REASON_ICON_MAP[$reason]);

        // 返金処理チェック (2系 L810-815)
        $paymentId = (int) $order['payment_id'];
        if (!$isAutoReturn && !in_array($paymentId, self::NON_AUTO_RETURN_PAYMENTS, true)) {
            // クレカ・AmazonPay・NP・無料以外 (=後払い(VAMOS)・代引き等) は自動処理 (2系 2026-09-02 以降)
            $isAutoReturn = true;
        }

        $penaltyFlg = false;
        $refundFlg = false;
        $isCommitNum = true;

        if ($isAutoReturn) {
            // 自動キャンセル対象 (2系 L817-907)
            $this->dispatchAutoReturn($orderId, $paymentId, (int) ($order['payment_total'] ?? 0), $refundFlg);
        } else {
            // 手動対応対象 (2系 L908-958)
            $this->addNotAuthCancelIcon($orderId, (int) ($order['payment_total'] ?? 0));

            // 縛りコースチェック
            $isCommitNum = $this->checkShibariCourse($orderId, (int) $order['customer_id']);

            // 連絡なし返品 + 縛りコース未満 + 代引き (2系 L952-956)
            if (!$isCommitNum && $paymentId === self::PAYMENT_ID_DAIBIKI) {
                $isCommitNum = true;
                $penaltyFlg  = true;
            }

            // 縛りコース未満 + 初回返品 + 住所不明/受取拒否 (2系 L961-968)
            if (!$isCommitNum) {
                $orderCount = $this->customizeOrderRepository->getOrderCount($orderId, (int) $order['customer_id']);
                if ($orderCount === 1 && ($reason === '住所不明' || $reason === '受取拒否')) {
                    $this->logger->info(sprintf('[倉庫返品] 初回返品（受注番号）: %d', $orderId));
                    $isCommitNum = true;
                }
            }
            // 返金してない顧客にはメールしない
            $customerFlg = false;
        }

        // 定期解約処理 (2系 L970-1055)
        if ($isCommitNum) {
            $this->cancelTeikiAndWhiteData($orderId);
        }

        // メール対象キュー
        if ($refundFlg) {
            $arrRefundId[] = $orderId;
        } elseif ($customerFlg) {
            $arrOrderId[] = $orderId;
        }
        if ($penaltyFlg) {
            $arrPenaltyId[] = $orderId;
        }

        return ['error' => null, 'processed' => true];
    }

    /**
     * 既存の dtb_order_status アイコンから is_auto_return / skip 判定 (2系 L697-764).
     *
     * @return array{0: bool, 1: bool} [$isAutoReturn, $shouldContinue]
     */
    private function processOrderStatusIcons(int $orderId, int $orderStatus, int $paymentId): array
    {
        /** @var list<array{status: int}> $rows */
        $rows = $this->connection->fetchAllAssociative(
            'SELECT status FROM dtb_order_status WHERE order_id = :id ORDER BY create_date',
            ['id' => $orderId]
        );

        $isAutoReturn = false;
        $existingIcon = 0;

        foreach ($rows as $row) {
            $status = (int) $row['status'];
            if ($status === self::ICON_GUIDE || $status === self::ICON_RETURN) {
                $existingIcon = $status;
            }
            if ($status === self::ICON_AUTO_RETURN) {
                $isAutoReturn = true;
            }
        }

        // 「一部返品案内済み」 → 「一部返品処理待ち」に変更 (発送済のみ)
        if ($existingIcon === self::ICON_GUIDE) {
            if ($orderStatus !== self::STATUS_SHIPPED) {
                $this->logger->info(sprintf('[倉庫返品] 注文アイコン変更は発送済ステータスではないため処理しない。受注番号：%d', $orderId));
                return [$isAutoReturn, true];
            }

            $this->connection->executeStatement(
                <<<'SQL'
                    UPDATE dtb_order_status
                       SET status = :new_status,
                           create_date = CURRENT_TIMESTAMP
                     WHERE status = :old_status AND order_id = :order_id
                SQL,
                [
                    'new_status' => self::ICON_RETURN,
                    'old_status' => self::ICON_GUIDE,
                    'order_id'   => $orderId,
                ]
            );

            $log = sprintf('[倉庫返品] 注文アイコン「一部返品案内済み」=>「一部返品処理待ち」に変更。受注番号：%d', $orderId);

            // VAMOS 後払い出荷報告済取引キャンセル依頼 (2系 L746-753)
            if ($paymentId === self::PAYMENT_ID_VAMOS) {
                if ($this->vamosService->cancelCredit($orderId)) {
                    $log .= sprintf(' 【VAMOS与信】出荷報告済取引キャンセル依頼が完了致しました。受注番号：%d', $orderId);
                } else {
                    $log .= sprintf(' 【VAMOS与信】出荷報告済取引キャンセル依頼が失敗しました。受注番号：%d', $orderId);
                }
            }

            $this->logger->info($log);
            return [$isAutoReturn, true];
        }

        // 「一部返品処理待ち」既存 → 以降処理しない
        if ($existingIcon === self::ICON_RETURN) {
            $this->logger->info(sprintf('[倉庫返品] 注文アイコンが「一部返品処理待ち」を含むため処理は行いません。受注番号: %d', $orderId));
            return [$isAutoReturn, true];
        }

        return [$isAutoReturn, false];
    }

    /**
     * dtb_customer_status に 9 or 999 が入っていればメール除外 (2系 L770-773).
     */
    private function isMailExcludedCustomer(int $customerId): bool
    {
        $row = $this->connection->fetchAssociative(
            'SELECT status FROM dtb_customer_status WHERE customer_id = :cid AND status IN (:excl)',
            [
                'cid'  => $customerId,
                'excl' => self::MAIL_EXCLUDE_CUSTOMER_STATUSES,
            ],
            ['excl' => ArrayParameterType::INTEGER]
        );
        return $row !== false;
    }

    /**
     * dtb_order.order_status_id=500 + アイコン追加 (2系 L789-807).
     *
     * raw DBAL の UPDATE は Doctrine ORM の preUpdate イベントを発火させないため、
     * 2系がDBトリガー (`trigger_order_returns`) で行っていたポイント失効を
     * {@see OrderWarehouseReturnPointService} で明示的に呼び出す (Issue #2728)。
     */
    private function markOrderReturned(int $orderId, int $customerId, int $oldStatus, int $iconId): void
    {
        $this->connection->executeStatement(
            'UPDATE dtb_order SET order_status_id = :status, update_date = CURRENT_TIMESTAMP WHERE id = :id',
            ['status' => self::STATUS_WAREHOUSE_RETURN, 'id' => $orderId]
        );

        $this->warehouseReturnPointService->apply(
            $this->connection,
            $orderId,
            $customerId,
            $oldStatus,
            self::STATUS_WAREHOUSE_RETURN,
        );

        // アイコンが既に立っていなければ INSERT
        $cnt = (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM dtb_order_status WHERE order_id = :oid AND status = :st',
            ['oid' => $orderId, 'st' => $iconId]
        );
        if ($cnt === 0) {
            $this->connection->executeStatement(
                <<<'SQL'
                    INSERT INTO dtb_order_status (order_id, status, create_date)
                    VALUES (:oid, :st, CURRENT_TIMESTAMP)
                SQL,
                ['oid' => $orderId, 'st' => $iconId]
            );
        }
    }

    /**
     * 自動返金対象の払い戻し処理 (2系 L817-907).
     *
     * @param bool $refundFlg by-ref. ★2系 L825/L871 の `$refund_flg = true;` はコメントアウトされ
     *             dead code のため、4系も一切 true にしない (クラス docblock 参照)。引数は2系の
     *             シグネチャ・呼出元 (processRow の $arrRefundId 振り分け) との対応を保つために残す。
     */
    private function dispatchAutoReturn(int $orderId, int $paymentId, int $paymentTotal, bool &$refundFlg): void
    {
        switch ($paymentId) {
            case self::PAYMENT_ID_PAYGENT:
                $result = $this->paygentOrderService->cardCommitCancel($orderId);
                $this->logger->info(sprintf(
                    '[倉庫返品] Paygent 売上取消 order_id=%d ok=%s',
                    $orderId,
                    $result->ok ? 'true' : 'false'
                ));
                break;

            case self::PAYMENT_ID_JISHA_ATOBARAI:
                // 自社後払い (電算): nyukin_status=4 に更新 (2系 L898).
                $this->connection->executeStatement(
                    'UPDATE dtb_order SET nyukin_status = 4, update_date = CURRENT_TIMESTAMP WHERE id = :id',
                    ['id' => $orderId]
                );
                break;

            case self::PAYMENT_ID_AMAZONPAY:
                // AmazonPay createRefund (2系 doUploadReturnsCsv L834-868, Issue #395).
                // 失敗時も例外を投げず warning ログのみ (WMS 全体の取込は継続. 2系 L865-868 も throw 無し)。
                $refundResult = $this->amazonPayRefundService->refund($orderId, $paymentTotal);
                if ($refundResult->ok) {
                    $this->logger->info(sprintf(
                        '[倉庫返品] AmazonPay 返金開始 order_id=%d refund_id=%s',
                        $orderId,
                        $refundResult->refundId ?? ''
                    ));
                } else {
                    $this->logger->warning(sprintf(
                        '[倉庫返品] AmazonPay 返金失敗 order_id=%d payment_total=%d reason=%s',
                        $orderId,
                        $paymentTotal,
                        $refundResult->errorCode ?? 'Unknown'
                    ));
                }
                // ★2系 L871 の $refund_flg = true; はコメントアウトされ dead code (クラス docblock 参照).
                // 4系もこれを忠実に踏襲し $refundFlg は変更しない。
                break;

            case self::PAYMENT_ID_VAMOS:
                // VAMOS cancelVaCredit (2系 L899-906).
                if ($this->vamosService->cancelCredit($orderId)) {
                    $this->logger->info(sprintf('[倉庫返品] VAMOS 与信キャンセル依頼完了 order_id=%d', $orderId));
                } else {
                    $this->logger->warning(sprintf('[倉庫返品] VAMOS 与信キャンセル依頼失敗 order_id=%d', $orderId));
                }
                break;
        }
    }

    /**
     * 決済未キャンセルアイコン追加 (2系 L911-919).
     *
     * 請求金額>0 のみ. 手動対応ケースに立てる目印.
     */
    private function addNotAuthCancelIcon(int $orderId, int $paymentTotal): void
    {
        if ($paymentTotal <= 0) {
            return;
        }
        $cnt = (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM dtb_order_status WHERE order_id = :oid AND status = :st',
            ['oid' => $orderId, 'st' => self::ICON_NOT_AUTH_CANCEL]
        );
        if ($cnt === 0) {
            $this->connection->executeStatement(
                <<<'SQL'
                    INSERT INTO dtb_order_status (order_id, status, create_date)
                    VALUES (:oid, :st, CURRENT_TIMESTAMP)
                SQL,
                ['oid' => $orderId, 'st' => self::ICON_NOT_AUTH_CANCEL]
            );
        }
    }

    /**
     * 縛りコース (dtb_course_shibari) の受取回数が num を満たしているか判定 (2系 L921-948).
     *
     * @return bool true=満たしている / false=未達
     */
    private function checkShibariCourse(int $orderId, int $customerId): bool
    {
        $brandId = (int) ($this->connection->fetchOne(
            'SELECT brand_id FROM dtb_order WHERE id = :id',
            ['id' => $orderId]
        ) ?: 0);
        if ($brandId === 0) {
            return true;
        }

        /** @var list<array{course_id: int, teiki_detail_id: int, shibari_num: int}> $products */
        $products = $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT doi.course_id,
                       doi.teiki_detail_id,
                       dcs.num AS shibari_num
                  FROM dtb_order_item doi
                 INNER JOIN dtb_course_shibari dcs
                    ON doi.course_id  = dcs.course_id
                   AND doi.product_id = dcs.product_id
                 WHERE doi.order_id = :oid
                 ORDER BY doi.id
            SQL,
            ['oid' => $orderId]
        );

        foreach ($products as $row) {
            // 2系 L931-942: 受取回数集計 (発送済/返品/500+51 = 決済未キャンセルを含む)
            $commitNum = (int) $this->connection->fetchOne(
                <<<'SQL'
                    SELECT SUM(CASE
                        WHEN (do2.order_status_id IN (5, 8)
                              OR (do2.order_status_id = 500 AND dos.status = 51))
                        THEN doi.quantity
                        ELSE 0
                    END)
                      FROM dtb_order do2
                     INNER JOIN dtb_order_item doi ON do2.id = doi.order_id
                      LEFT JOIN dtb_order_status dos ON do2.id = dos.order_id
                     WHERE do2.customer_id     = :cid
                       AND do2.id              <= :oid
                       AND do2.brand_id        = :bid
                       AND doi.course_id       = :cs
                       AND doi.teiki_detail_id = :td
                SQL,
                [
                    'cid' => $customerId,
                    'oid' => $orderId,
                    'bid' => $brandId,
                    'cs'  => (int) $row['course_id'],
                    'td'  => (int) $row['teiki_detail_id'],
                ]
            );
            if ($commitNum < (int) $row['shibari_num']) {
                return false;
            }
        }
        return true;
    }

    /**
     * 定期解約 + 残り白データ与信キャンセル (2系 L970-1055 + sfCancelTeiki L55-77).
     *
     * 2系 sfCancelTeiki は 2 段の UPDATE を行う:
     *   1. note 追記: teiki_id + brand_id 単位 (全 detail に共通適用)
     *   2. status=3 + reason: teiki_id + detail_id + brand_id + status<>3 (冪等性ガード)
     * 4系も同じ 2 段構成に忠実移植する.
     *
     * ★論理削除済み (`dtb_teiki.del_flg = 1`) の定期は解約処理を行わない (Issue #2823)。
     *   2系は `$teiki_data_sql` (cartDev SC_Helper_CSV_Ex L971-976) では `del_flg` を絞らないが、
     *   直後に `SC_Helper_Teiki_Ex::getDetail()` (同 **L889-899**) で
     *   `del_flg = 0 AND teiki_id = ? AND detail_id = ? AND brand_id = ?` を引き、
     *   del_flg=1 だと**空配列**が返る。それをそのまま `sfCancelTeiki($row, 12)` に渡すため
     *   2本の UPDATE がどちらも `teiki_id = NULL` となり **0件更新 = 実質スキップ**になる。
     *   4系にはこの絞りが無く、論理削除済みの定期にも note 追記と status=3 が入っていた。
     *
     *   ★ただし**白データ与信キャンセルは 2系でもスキップされない**。2系の
     *   `$teiki_sql_other` (同 L987-994) は `dt.del_flg` を条件に持たず、
     *   `sfCancelTeiki` が0件更新で終わった直後の**同じ foreach 内**で実行されるため。
     *   よってここも `continue` せず {@see self::cancelWhiteDataOrders()} は必ず呼ぶ
     *   (外側 SELECT に `AND tk.del_flg = 0` を足すと与信キャンセルまで落ちて2系と乖離する)。
     *
     *   ★`brand_id` の出所だけは2系と異なる。2系 `getDetail()` / `sfCancelTeiki()` は
     *   `$this->brand_id` (= `$_SESSION["brand_id"]`。`SC_Helper_Teiki_Ex::__construct()` L40-45) を使い、
     *   4系は結合した `tk.brand_id` を使う。2系DB実測で `tk.brand_id <> do2.brand_id` は **0件**のため
     *   現データでは差が出ない。本CSVは行ごとに `dtb_brand.name_wms` でブランドを確定するので
     *   行の値を使う4系のほうが素直であり、意図的にこのままにする。
     */
    private function cancelTeikiAndWhiteData(int $orderId): void
    {
        /** @var list<array{teiki_id: int, detail_id: int, course_id: int, product_id: int, brand_id: int, note: ?string, del_flg: int}> $teikiList */
        $teikiList = $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT tk.teiki_id, tk.detail_id, tk.course_id, tk.product_id, tk.brand_id, tk.note, tk.del_flg
                  FROM dtb_order do2
                 INNER JOIN dtb_order_item doi ON do2.id = doi.order_id
                 INNER JOIN dtb_teiki tk       ON doi.teiki_detail_id = tk.detail_id
                 WHERE do2.id = :oid
            SQL,
            ['oid' => $orderId]
        );

        if (empty($teikiList)) {
            return;
        }

        $reasonName = (string) ($this->connection->fetchOne(
            'SELECT name FROM mtb_stop_teiki_reason WHERE id = :id',
            ['id' => self::TEIKI_CANCEL_REASON_RETURNS]
        ) ?: '');
        $supportName = $this->getSupportName();

        foreach ($teikiList as $teiki) {
            $teikiId  = (int) $teiki['teiki_id'];
            $detailId = (int) $teiki['detail_id'];
            $brandId  = (int) $teiki['brand_id'];

            // 2系 getDetail() が del_flg=0 で絞るため、論理削除済みは解約2本の UPDATE をスキップ.
            // (白データ与信キャンセルは2系でも実行されるので下で必ず呼ぶ)
            if ((int) $teiki['del_flg'] !== 0) {
                $this->logger->info(sprintf(
                    '[倉庫返品] 論理削除済みの定期のため解約をスキップ。定期番号：%d_%d',
                    $teikiId,
                    $detailId
                ));
                $this->cancelWhiteDataOrders($teikiId, $detailId);
                continue;
            }

            $comment = sprintf(
                '[解約]商品ID：%s を解約に変更。（%s）',
                (string) $teiki['product_id'],
                $reasonName
            );
            $newNote = (($teiki['note'] ?? '') !== '' ? $teiki['note'] . PHP_EOL : '')
                . date('Y/m/d H:i ') . ':' . $comment . $supportName . PHP_EOL;

            // 2系 sfCancelTeiki L72: note 追記 (teiki_id + brand_id 単位)
            $this->connection->executeStatement(
                <<<'SQL'
                    UPDATE dtb_teiki
                       SET note        = :note,
                           update_date = CURRENT_TIMESTAMP
                     WHERE teiki_id = :tid AND brand_id = :bid
                SQL,
                ['note' => $newNote, 'tid' => $teikiId, 'bid' => $brandId]
            );

            // 2系 sfCancelTeiki L73: status=3 + reason (teiki_id + detail_id + brand_id + status<>3 冪等性ガード)
            $this->connection->executeStatement(
                <<<'SQL'
                    UPDATE dtb_teiki
                       SET status                  = 3,
                           cancel_teiki_reason_id  = :reason_id,
                           cancel_date             = CURRENT_TIMESTAMP,
                           update_date             = CURRENT_TIMESTAMP
                     WHERE teiki_id = :tid
                       AND detail_id = :did
                       AND brand_id = :bid
                       AND status <> 3
                SQL,
                [
                    'reason_id' => self::TEIKI_CANCEL_REASON_RETURNS,
                    'tid'       => $teikiId,
                    'did'       => $detailId,
                    'bid'       => $brandId,
                ]
            );
            $this->logger->info(sprintf('[倉庫返品] 定期データ解約。定期番号：%d_%d', $teikiId, $detailId));

            $this->cancelWhiteDataOrders($teikiId, $detailId);
        }
    }

    /**
     * 定期の残り白データ (未発送でオーソリ済) の受注をキャンセルしオーソリ取消電文を送る (2系 L987-1052).
     */
    private function cancelWhiteDataOrders(int $teikiId, int $detailId): void
    {
        /** @var list<array{order_id: int, payment_id: int}> $whites */
        $whites = $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT do2.id AS order_id, do2.payment_id
                  FROM dtb_teiki dt
                 INNER JOIN dtb_order_item doi ON dt.detail_id = doi.teiki_detail_id
                 INNER JOIN dtb_order do2      ON doi.order_id = do2.id
                 WHERE dt.teiki_id           = :tid
                   AND dt.detail_id          = :did
                   AND do2.order_status_id IN (:statuses)
            SQL,
            [
                'tid'      => $teikiId,
                'did'      => $detailId,
                'statuses' => self::WHITE_STATUSES,
            ],
            ['statuses' => ArrayParameterType::INTEGER]
        );

        foreach ($whites as $white) {
            $wOrderId   = (int) $white['order_id'];
            $wPaymentId = (int) $white['payment_id'];

            // 受注データキャンセル
            $this->connection->executeStatement(
                'UPDATE dtb_order SET order_status_id = :cancel, update_date = CURRENT_TIMESTAMP WHERE id = :id',
                ['cancel' => self::STATUS_CANCEL, 'id' => $wOrderId]
            );

            // 与信キャンセル
            switch ($wPaymentId) {
                case self::PAYMENT_ID_PAYGENT:
                    $result = $this->paygentOrderService->authCancel($wOrderId);
                    $this->logger->info(sprintf(
                        '[倉庫返品] 白データ Paygent auth_cancel order_id=%d ok=%s',
                        $wOrderId,
                        $result->ok ? 'true' : 'false'
                    ));
                    break;

                case self::PAYMENT_ID_AMAZONPAY:
                    $this->cancelAmazonAuthorize($wOrderId);
                    break;

                case self::PAYMENT_ID_VAMOS:
                    // 白データ VAMOS 与信キャンセル (2系 L1043-1050).
                    if ($this->vamosService->cancelCredit($wOrderId)) {
                        $this->logger->info(sprintf('[倉庫返品] 白データ VAMOS 与信キャンセル依頼完了 order_id=%d', $wOrderId));
                    } else {
                        $this->logger->warning(sprintf('[倉庫返品] 白データ VAMOS 与信キャンセル依頼失敗 order_id=%d', $wOrderId));
                    }
                    break;
            }
        }
    }

    /**
     * AmazonPay v2 オーソリキャンセル (2系 doUploadReturnsCsv cartDev L1008-1024).
     *
     * ★2系は cancelCharge の結果を `GC_Utils_Ex::gfPrintLog_r` でログに出すだけで
     *   status / state による成否判定を **していない**。ここでも判定は足さずログに留める。
     */
    private function cancelAmazonAuthorize(int $orderId): void
    {
        $row = $this->connection->fetchAssociative(
            'SELECT memo02 FROM dtb_order WHERE id = :id',
            ['id' => $orderId]
        );
        if ($row === false || empty($row['memo02'])) {
            $this->logger->warning('[倉庫返品] AmazonPay chargeId (memo02) が未設定.', ['order_id' => $orderId]);
            return;
        }

        // v1→v2 chargeId 変換 (2系 L1017) は AmazonPayRequestService::cancelCharge 側で行う
        $chargeId = (string) $row['memo02'];

        try {
            $result = $this->amazonPayRequestService->cancelCharge($chargeId);
            if ($result->skipped) {
                $this->logger->warning('[倉庫返品] AMAZONPAY_* が未設定. auth_cancel skip.', ['order_id' => $orderId]);
                return;
            }
            // 2系 L1024 の gfPrintLog_r 相当 (判定はせず結果を残す)
            $this->logger->info('[倉庫返品] AmazonPay authResult', [
                'order_id' => $orderId,
                'status'   => $result->status,
                'state'    => $result->getState(),
                'reason_code' => $result->getReasonCode(),
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('[倉庫返品] AmazonPay cancelCharge 例外', [
                'order_id' => $orderId,
                'message'  => $e->getMessage(),
            ]);
        }
    }

    /**
     * 2系 sfGetSupportName 相当 (WmsCsvImportService と同一実装).
     */
    private function getSupportName(): string
    {
        $user = $this->security->getUser();
        if ($user === null) {
            return '：SYSTEM';
        }
        return '：' . $user->getUserIdentifier();
    }

    /**
     * CSV を SJIS→UTF-8 変換した一時ファイルパスを返す.
     */
    private function convertSjisToUtf8(string $srcPath): ?string
    {
        $content = @file_get_contents($srcPath);
        if ($content === false) {
            return null;
        }
        // ★CP932 未対応文字 / 不正バイト列は **削除** する (Issue #2534)。
        //   2系 `cartDev/data/class/SC_Initial.php:129` の
        //   `ini_set('mbstring.substitute_character', 'none')` がプロセス全体に効き、
        //   cartDev 側は CSV 関数でも上書きしない (2026-08-24 実測: cartDev 配下の
        //   `mb_substitute_character` 出現は `.bak`/`logs` を除いて **0件**)。
        //   4系の既定は 63 = `?` なので、明示しないと取込値に `?` が混ざる。
        $content = Cp932Unmappable::Drop->convert($content, 'UTF-8', 'SJIS-WIN, UTF-8');

        $tmp = tempnam(sys_get_temp_dir(), 'returns_csv_');
        if ($tmp === false) {
            return null;
        }
        file_put_contents($tmp, $content);
        return $tmp;
    }
}
