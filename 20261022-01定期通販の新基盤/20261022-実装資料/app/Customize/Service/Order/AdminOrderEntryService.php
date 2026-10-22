<?php

declare(strict_types=1);

namespace Customize\Service\Order;

use Customize\Service\CustomerPurchaseSummaryService;
use Customize\Service\OrderAuthoriEntryService;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Eccube\Repository\BaseInfoRepository;
use Psr\Log\LoggerInterface;

/**
 * 管理画面 受注登録サービス (2系 `LC_Page_Admin_Order_Entry_Ex` 移植, Issue #818)
 *
 * 電話・FAX 注文等をオペレーターが代理で起票する画面のサーバー側処理。
 * 2系の実体は `cartDev/data/class_extends/page_extends/admin/order/LC_Page_Admin_Order_Entry_Ex.php`
 * (872行)。`data/class/pages/admin/order/LC_Page_Admin_Order_Entry.php` は存在せず、
 * `_Ex` が `LC_Page_Admin` を直接 extends する「_Ex のみで完結」パターンなので `_Ex` が正解
 * (`cartDev/html/7p3ZTev9wC9r/order/entry.php` L25-27 が `new LC_Page_Admin_Order_Entry_Ex()`)。
 *
 * 本サービスが担当するのは 2系 `doRegister()` (L576-835) と `lfSetPaymentTotal()` (L318-391)。
 *
 * ## ★本 Issue (#818) の中核 — `dtb_teiki` の生成
 *
 * コア `admin_order_new`(`Eccube\Controller\Admin\Order\EditController`) は `teiki` で grep して
 * 0ヒット = **定期契約を一切作れない**。そのためコース商品を無理に登録しても2回目以降の
 * 自動出荷対象にならない。2系 L702-826 の定期生成をここに移植する。
 *
 * ## ★2系の「死んだ条件」をそのまま踏襲している箇所 (検算済み)
 *
 * 2系 L736 は `if ($arrCourse["course_type"] == 4 || $arrCourseDetail[0]["interval"] == 0)` で
 * 「都度」を判定するが、**2系 `dtb_course` に `course_type` 列は存在しない**
 * (`information_schema.columns` 実測: `dtb_course` は27列、`course_type` は無い。
 *  `course_type` があるのは `dtb_course_detail` の方)。
 * PHP の未定義キー参照は NULL となり `null == 4` は false なので、
 * **実効的な判定は `interval == 0` のみ**。よって本実装も `interval == 0` だけを見る。
 * (2系実測: `dtb_course_detail` 451行のうち `interval=0` は112行)
 *
 * ## 2系→4系 テーブル/カラム変換 (すべて information_schema で実在確認済み)
 *
 * - `dtb_order_detail` → `dtb_order_item` (`order_item_type_id=1` が商品行)
 * - `dtb_order.status` → `dtb_order.order_status_id`
 * - `dtb_order.deliv_fee` → `dtb_order.delivery_fee_total`
 * - `dtb_order.order_name01/order_tel01/order_zip01/order_pref/order_email`
 *   → `name01/phone_number/postal_code/pref_id/email`
 * - `dtb_order.deliv_id` → 4系 `dtb_order` に列なし。配送方法は `dtb_shipping.delivery_id`
 * - `dtb_shipping.shipping_name01` 等の prefix なし (`name01`)。
 *   `shipping_date` は 4系も `shipping_date` (`delivery_date` ではない。
 *   `scripts/data_migration/setbased/transform_dtb_shipping.sql` の Issue #996 是正コメント参照)
 * - `dtb_products` → `dtb_product` (`product_id` → `id`)
 * - `dtb_customer.customer_id/tel01/zip01/pref/status` → `id/phone_number/postal_code/pref_id/customer_status_id`
 * - `dtb_teiki` / `dtb_teiki_option` / `dtb_course` / `dtb_course_detail` / `dtb_order_detail_option`
 *   は 2系と同名 (`dtb_teiki` のみ 4系で代理PK `id` が追加されている。Issue #642 / PR #1024)
 *
 * ## 4系固有で明示的に埋める列 (2系に対応列が無いので「踏襲」では決まらない)
 *
 * - `dtb_order.order_no` = `id::text` / `order_date` = `create_date`
 *   (実測: 非NULL 1,132,584行すべてこの規約。空だとマイページ履歴詳細が引けない)
 * - `dtb_order.discriminator_type='order'` / `dtb_order_item.discriminator_type='orderitem'`
 *   / `dtb_shipping.discriminator_type='shipping'`
 * - `dtb_order_item.tax` / `tax_type_id` / `tax_display_type_id` / `tax_rule_id` / `rounding_type_id`
 *   — 導出規則は `transform_dtb_order_item.sql` (#879 で補完されたカラム群) と同一にした
 * - `dtb_teiki.status` — 2系は DB 既定値 1 に任せているが **4系は NOT NULL かつ既定値なし**なので
 *   明示的に 1 (継続中) を入れる
 *
 * ## スコープ外 (別 Issue)
 *
 * - Paygent 登録カード番号の表示 (2系 `customer_search` の `sfGetPaygentCreditStock`)。→ Issue化
 * - コース検索モーダル (`search/course_search.php?parent=order_new`) からのコースID流し込み。
 *   本画面は `dtb_course` のプルダウン (2系 `getCourseList`) を持つのでコース選択自体は可能。
 *   モーダル連携自体は既存 Issue #1418 で追跡中
 * - コース変更 / 複数コース同時登録などの複合分岐、既存受注へのコース追加は Issue #1412/#1413/#1414 で追跡中
 */
class AdminOrderEntryService
{
    /** VAMOS 後払い (2系 MDL_VAMOS_PAYMENT_ID)。この決済のみ受注ステータス 9000 で起票する */
    public const PAYMENT_VAMOS = 13;

    /** 新規受付 (2系 ORDER_NEW) */
    public const STATUS_NEW = 1;

    /** 審査中 (2系 L637) */
    public const STATUS_REVIEW = 9000;

    /** mtb_order_item_type: 1=商品 */
    private const ITEM_TYPE_PRODUCT = 1;

    /** 定期ステータス: 継続中 (2系 dtb_teiki.status の DB 既定値 1) */
    private const TEIKI_STATUS_ACTIVE = 1;

    /** 仮会員 (2系 dtb_customer.status の DB 既定値 1) */
    private const CUSTOMER_STATUS_PROVISIONAL = 1;

    /**
     * 手数料無料になる顧客アイコン
     * (2系 `SC_Helper_Purchase_Ex::lfCheckChangeNotiesNp` L668-683 の `$arrCheckIcon`)
     */
    private const FREE_CHARGE_CUSTOMER_STATUSES = [996, 997];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly BaseInfoRepository $baseInfoRepository,
        private readonly OrderAuthoriEntryService $orderAuthoriEntryService,
        private readonly CustomerPurchaseSummaryService $customerPurchaseSummaryService,
        private readonly LoggerInterface $logger,
    ) {}

    private function conn(): Connection
    {
        return $this->em->getConnection();
    }

    // =====================================================================
    // マスタ取得 (画面描画用)
    // =====================================================================

    /**
     * コース選択リスト (2系 `getCourseList()` L545-562)
     *
     * 2系: `SELECT id, name, is_cross FROM dtb_course dc WHERE dc.del_flg = 0 AND dc.brand_id = ? ORDER BY id desc`
     * 表示は `"{id}：{name}"`。
     *
     * @return array<int,string> course_id => 表示ラベル
     */
    public function getCourseList(int $brandId): array
    {
        $rows = $this->conn()->fetchAllAssociative(
            'SELECT id, name FROM dtb_course WHERE del_flg = 0 AND brand_id = :bid ORDER BY id DESC',
            ['bid' => $brandId],
        );

        $list = [];
        foreach ($rows as $row) {
            $list[(int) $row['id']] = $row['id'] . '：' . $row['name'];
        }

        return $list;
    }

    /**
     * 配送方法マスタ (2系 L78 `SELECT * FROM dtb_deliv WHERE del_flg = 0 ORDER BY deliv_id`)
     *
     * 2系 `dtb_deliv.service_name` → 4系 `dtb_delivery.name` / `del_flg=0` → `visible=true`。
     *
     * @return list<array<string,mixed>>
     */
    public function getDeliveryList(): array
    {
        return $this->conn()->fetchAllAssociative(
            'SELECT id, name FROM dtb_delivery WHERE visible = true ORDER BY id',
        );
    }

    /**
     * 配送時間帯マスタ (2系 L80 `SELECT * FROM dtb_delivtime ORDER BY deliv_id, time_id`)
     *
     * ★Issue #993: 4系 `dtb_delivery_time` は `(delivery_id, time_id)` の複合キー体系。
     * グローバル `id` ではなく `time_id` を value に使う (2系 `time_id` と同義)。
     *
     * @return list<array<string,mixed>>
     */
    public function getDeliveryTimeList(): array
    {
        return $this->conn()->fetchAllAssociative(
            'SELECT delivery_id, time_id, delivery_time FROM dtb_delivery_time ORDER BY delivery_id, time_id',
        );
    }

    /**
     * 支払方法マスタ (2系 L82 `SELECT * FROM dtb_payment WHERE del_flg = 0 ORDER BY payment_id`)
     *
     * @return list<array<string,mixed>>
     */
    public function getPaymentList(): array
    {
        return $this->conn()->fetchAllAssociative(
            'SELECT id, payment_method, charge FROM dtb_payment WHERE visible = true ORDER BY id',
        );
    }

    /**
     * コース明細 + 商品情報 (2系 `SC_Helper_Course::getDetailProducts()` L87-107)
     *
     * 2系 SQL:
     *   `SELECT dcd.*, dc.name AS course_name, dp.name AS product_name`
     *   `FROM dtb_course_detail dcd, dtb_course dc, dtb_products dp`
     *   `WHERE dcd.course_id = dc.id AND dcd.product_id = dp.product_id AND dcd.course_id = ?`
     *   `ORDER BY course_type, order_count`
     *
     * @return list<array<string,mixed>>
     */
    public function getCourseDetailProducts(int $courseId, ?int $productId = null): array
    {
        $sql = 'SELECT dcd.*, dc.name AS course_name, dp.name AS product_name'
             . ' FROM dtb_course_detail dcd, dtb_course dc, dtb_product dp'
             . ' WHERE dcd.course_id = dc.id AND dcd.product_id = dp.id AND dcd.course_id = :cid';
        $params = ['cid' => $courseId];

        if ($productId !== null) {
            $sql .= ' AND dcd.product_id = :pid';
            $params['pid'] = $productId;
        }
        $sql .= ' ORDER BY dcd.course_type, dcd.order_count';

        return $this->conn()->fetchAllAssociative($sql, $params);
    }

    /**
     * 商品オプション明細 (2系 `SC_Product_Ex::getProductOptionDetail()` L99-107)
     *
     * @return list<array<string,mixed>>
     */
    public function getProductOptionDetail(int $productId, int $brandId): array
    {
        return $this->conn()->fetchAllAssociative(
            'SELECT dpo.option_id, dpo.option_name, dpo.option_detail_id, dpo.option_detail_name'
            . ' FROM dtb_products_option_mapping dpom'
            . ' INNER JOIN dtb_products_option dpo ON dpom.option_id = dpo.option_id'
            . ' INNER JOIN dtb_products_option_disp dpod'
            . '   ON dpom.product_id = dpod.product_id AND dpom.option_id = dpod.option_id'
            . '  AND dpo.option_detail_id = dpod.option_detail_id'
            . ' WHERE dpom.product_id = :pid AND dpo.brand_id = :bid'
            . ' ORDER BY dpo.option_id, dpo.option_detail_id',
            ['pid' => $productId, 'bid' => $brandId],
        );
    }

    /**
     * 商品1件 (2系 `SC_Product_Ex::getProductRow()` L43-48)
     *
     * @return array<string,mixed>|null
     */
    public function getProductRow(int $productId, int $brandId): ?array
    {
        $row = $this->conn()->fetchAssociative(
            'SELECT id, name, product_code, tax_status FROM dtb_product WHERE id = :pid AND brand_id = :bid',
            ['pid' => $productId, 'bid' => $brandId],
        );

        return $row === false ? null : $row;
    }

    /**
     * 商品オプション1件 (2系 `SC_Product_Ex::getProductOptionRow()` L111-114)
     *
     * @return array<string,mixed>|null
     */
    public function getProductOptionRow(int $optionId, int $optionDetailId, int $brandId): ?array
    {
        $row = $this->conn()->fetchAssociative(
            'SELECT option_id, option_name, option_detail_id, option_detail_name FROM dtb_products_option'
            . ' WHERE option_id = :oid AND option_detail_id = :odid AND brand_id = :bid',
            ['oid' => $optionId, 'odid' => $optionDetailId, 'bid' => $brandId],
        );

        return $row === false ? null : $row;
    }

    /**
     * 顧客1件 (2系 `customer_search` モード L202-227 の流し込み元)
     *
     * @return array<string,mixed>|null
     */
    public function getCustomerRow(int $customerId, int $brandId): ?array
    {
        $row = $this->conn()->fetchAssociative(
            'SELECT id, name01, name02, kana01, email, phone_number, postal_code, pref_id,'
            . ' addr01, addr02, payment_id, customer_card_id, amazon_billing_agreement_id'
            . ' FROM dtb_customer WHERE id = :cid AND brand_id = :bid',
            ['cid' => $customerId, 'bid' => $brandId],
        );

        return $row === false ? null : $row;
    }

    // =====================================================================
    // 金額計算 (2系 lfSetPaymentTotal L318-391)
    // =====================================================================

    /**
     * 注文金額計算 (2系 `lfSetPaymentTotal()` L318-391 完全踏襲)
     *
     * 2系の要点:
     * - 明細ごとに `sfTax(price, tax_rate, tax_rule)` を **単価に対して**求め、数量倍する
     * - `subtotal` は税込単価 × 数量の合計
     * - `subtotal > 0` なら `total = subtotal + charge + deliv_fee - discount`
     * - `subtotal <= 0` かつ **送料0** なら **手数料を強制的に0** にする (L372-375)
     * - `total` が負なら 0 に丸める
     * - NP 切り替え案内対象の顧客 (顧客アイコン 996/997) は **手数料0** (L331-339)
     *
     * @param list<array<string,mixed>> $details 明細 (price/quantity/tax_rate/tax_rule)
     *
     * @return array{subtotal:int, tax:int, charge:int, deliv_fee:int, discount:int, total:int, payment_total:int}
     */
    public function calcPaymentTotal(array $details, int $charge, int $discount, int $delivFee, ?int $customerId): array
    {
        // 2系 L331-339: 顧客アイコンによる手数料無料判定
        if ($customerId !== null && $this->isFreeChargeCustomer($customerId)) {
            $charge = 0;
        }

        $subtotal = 0;
        $taxTotal = 0;

        foreach ($details as $detail) {
            $price    = (int) ($detail['price'] ?? 0);
            $quantity = (int) ($detail['quantity'] ?? 0);
            $taxRate  = (int) ($detail['tax_rate'] ?? 0);
            $taxRule  = (int) ($detail['tax_rule'] ?? 0);

            $tax = $this->roundByCalcRule($price * $taxRate / 100, $taxRule);

            $taxTotal += $tax * $quantity;
            $subtotal += ($price + $tax) * $quantity;
        }

        if ($subtotal <= 0) {
            // 2系 L369-381: 金額なし
            $subtotal = 0;
            if ($delivFee === 0) {
                // 送料がない場合は手数料も0にする (2系 L372-375)
                $charge = 0;
            }
        }

        $total = $subtotal + $charge + $delivFee - $discount;
        if ($total < 0) {
            $total = 0;
        }

        return [
            'subtotal'      => $subtotal,
            'tax'           => $taxTotal,
            'charge'        => $charge,
            'deliv_fee'     => $delivFee,
            'discount'      => $discount,
            'total'         => $total,
            'payment_total' => $total,
        ];
    }

    /**
     * NP 切り替え案内対象者か (2系 `SC_Helper_Purchase_Ex::lfCheckChangeNotiesNp` L668-683)
     */
    public function isFreeChargeCustomer(int $customerId): bool
    {
        $count = (int) $this->conn()->fetchOne(
            'SELECT count(*) FROM dtb_customer_status WHERE customer_id = :cid AND status IN (:statuses)',
            ['cid' => $customerId, 'statuses' => self::FREE_CHARGE_CUSTOMER_STATUSES],
            ['statuses' => \Doctrine\DBAL\ArrayParameterType::INTEGER],
        );

        return $count > 0;
    }

    /**
     * 端数処理 (2系 `SC_Helper_TaxRule::roundByCalcRule()` L394-414)
     *
     * 1=四捨五入 / 2=切り捨て / 3=切り上げ / それ以外=切り上げ (2系デフォルト)
     */
    public function roundByCalcRule(float $value, int $calcRule): int
    {
        return match ($calcRule) {
            1       => (int) round($value),
            2       => (int) floor($value),
            default => (int) ceil($value),
        };
    }

    /**
     * `dtb_base_info.tax_rule` (2系 `$this->arrInfo["tax_rule"]`)
     */
    public function getBaseTaxRule(): int
    {
        return (int) $this->baseInfoRepository->get()->getTaxRule();
    }

    // =====================================================================
    // 登録 (2系 doRegister L576-835)
    // =====================================================================

    /**
     * 受注を登録する (2系 `doRegister()` L576-835)
     *
     * @param array{
     *   edit_customer_id:?int, order_name01:string, order_email:?string, order_tel01:?string,
     *   order_zip01:?string, order_pref:?int, order_addr01:?string, order_addr02:?string,
     *   payment_id:int, deliv_id:int, time_id:?int, shipping_date:string,
     *   charge:int, discount:int, deliv_fee:int,
     *   details:list<array<string,mixed>>
     * } $form
     *
     * @return array{order_id:int, teiki_id:?int, teiki_detail_ids:list<int>, authorized:bool}
     */
    public function register(array $form, int $brandId): array
    {
        $conn = $this->conn();

        $paymentId = (int) $form['payment_id'];
        $details   = $form['details'];

        // 2系 L581
        $paymentMethod = (string) ($conn->fetchOne(
            'SELECT payment_method FROM dtb_payment WHERE id = :pid',
            ['pid' => $paymentId],
        ) ?: '');

        $calc = $this->calcPaymentTotal(
            $details,
            (int) $form['charge'],
            (int) $form['discount'],
            (int) $form['deliv_fee'],
            $form['edit_customer_id'] !== null ? (int) $form['edit_customer_id'] : null,
        );

        // ★2系 L607 は `begin()` の**前**に顧客登録を済ませている (L583-605)。
        //   顧客だけ先に確定させる挙動を踏襲する。
        $customerId = $this->upsertCustomer($form, $paymentId, $paymentMethod, $brandId);

        $teikiId        = null;
        $teikiDetailIds = [];

        // ★DB書込のみをトランザクションで囲う。与信APIの呼出は**外側**
        //   (ロールバックしても決済側に取引だけ残るのを避ける。OrderCopyService と同方針)
        $conn->beginTransaction();
        try {
            $orderId = $this->insertOrder($form, $customerId, $paymentId, $paymentMethod, $calc, $brandId);
            $itemIds = $this->insertOrderItems($orderId, $details);
            $shippingId = $this->insertShipping($orderId, $form);

            // 4系固有: 明細と配送の紐付け
            if ($itemIds !== []) {
                $conn->executeStatement(
                    'UPDATE dtb_order_item SET shipping_id = :sid WHERE order_id = :oid',
                    ['sid' => $shippingId, 'oid' => $orderId],
                );
            }

            // 2系 L702-826: 定期テーブルの登録
            ['teiki_id' => $teikiId, 'detail_ids' => $teikiDetailIds] = $this->registerTeiki(
                $orderId,
                $customerId,
                $brandId,
                (string) $form['shipping_date'],
                (int) $form['deliv_id'],
                $form['time_id'] !== null ? (int) $form['time_id'] : null,
                $form['edit_customer_id'] !== null,
            );

            $conn->commit();
        } catch (\Throwable $e) {
            $conn->rollBack();
            $this->logger->error('[AdminOrderEntry] 受注登録に失敗しロールバックしました', [
                'customer_id' => $customerId,
                'message'     => $e->getMessage(),
            ]);
            throw $e;
        }

        // 会員の購入サマリ (buy_times / buy_total / first_buy_date / last_buy_date) を再集計する
        // (Issue #2904)。
        //
        // 2系の経路:
        //   `LC_Page_Admin_Order_Entry_Ex.php:643` `$objPurchase->registerOrder(null, $arrOrderValues)`
        //   → `cartDev/data/class/helper/SC_Helper_Purchase.php::registerOrder()` の新規側は
        //     「新規受付の場合は対応状況 null で insert し, sfUpdateOrderStatus で引数で受け取った
        //      値に変更する」というコメントどおり `$arrValues['status'] = null` で INSERT してから
        //     `sfUpdateOrderStatus($order_id, $status, ...)` を呼ぶ
        //   → 同 `sfUpdateOrderStatus()` 末尾:
        //       if ($arrOrderOld['customer_id'] > 0 and $arrOrderOld['status'] != $newStatus) {
        //           SC_Customer_Ex::updateOrderSummary($arrOrderOld['customer_id']);
        //       }
        //     挿入直後の `status` は NULL、`$newStatus` は 1 か 9000 なので条件は**常に成立**し、
        //     本画面は `upsertCustomer()` が必ず会員を作る/引くので `customer_id > 0` も成立する。
        //
        // ★commit の**後**に置くこと。受注は既に成立しているのでここで例外を投げると
        //   呼出元が失敗を返し二重注文を招く。
        //   なお2系は本画面に限っては `begin()`(L607)〜`commit()`(L831) の**内側**で
        //   `updateOrderSummary()` が走る (commit 後に呼ぶのはフロントの `completeOrder()` の方)。
        //   成功時だけ確定するという結果は同じで、4系は例外時に受注ごと巻き戻さないぶん安全側。
        //
        // ★位置差が集計値を変えないことの確認: 2系の呼出点 (`registerOrder()` の中) と
        //   4系の commit 直後の間で `dtb_order` に入る更新は `teiki_id` だけ
        //   (明細・配送・定期は別テーブル)。集計が読む `payment_total` / `order_status_id` /
        //   `create_date` は動かないので結果は一致する。
        //
        // ★★訂正 (Issue #2844): 上の `$conn->commit()`(L452) は**実際にはDBへコミットしていない**。
        //   コア `TransactionListener` が `kernel.request` でリクエスト全体を包むトランザクションを
        //   既に開始しているため、ここの `beginTransaction()`/`commit()` は DBAL の入れ子カウンタを
        //   ±1するだけ (`nestTransactionsWithSavepoints` はこのプロジェクトで一度も有効化して
        //   いない = 常に無効)。実際の COMMIT が PostgreSQL へ送られるのは `kernel.terminate` の
        //   時点なので、`recalculateSafely()`(例外を握り潰すだけの版)をここで使うのは危険だった:
        //   集計SQLが失敗すると PostgreSQL 接続だけが aborted 状態になり、DBAL の
        //   `isRollbackOnly` は立たないまま `kernel.terminate` の `commit()` が呼ばれる。
        //   PostgreSQL は aborted なトランザクションへの `COMMIT` を暗黙の `ROLLBACK` として
        //   処理する仕様のため、**例外すら投げずに成功したように見えながらこの受注を含む
        //   リクエスト全体が静かに消える** ({@see \Customize\Service\StoreConfirmService} の
        //   同種の訂正、実測で確認済み)。`recalculateIsolated()` は SAVEPOINT で集計SQLを隔離し、
        //   失敗しても `ROLLBACK TO SAVEPOINT` で接続を健全な状態に戻すため、外側の
        //   `kernel.terminate` commit は正常に完走する(購入サマリだけ古いままになる、
        //   という安全側に倒れる)。
        $this->customerPurchaseSummaryService->recalculateIsolated($customerId, $conn);

        // 2系 L830: オーソリ実行 (`$objPurchase->orderAuthoriEntry($order_id, $payment_id)`)
        // 4系 OrderAuthoriEntryService::authorizeBulk は失敗 order_id の配列を返すので空なら成功。
        $authorized = $this->orderAuthoriEntryService->authorizeBulk([$orderId], $brandId) === [];
        if (!$authorized) {
            // 2系は戻り値を捨てているためステータスは変更しない (踏襲)。ログのみ残す。
            $this->logger->warning('[AdminOrderEntry] 与信登録に失敗しました', [
                'order_id'   => $orderId,
                'payment_id' => $paymentId,
            ]);
        }

        return [
            'order_id'         => $orderId,
            'teiki_id'         => $teikiId,
            'teiki_detail_ids' => $teikiDetailIds,
            'authorized'       => $authorized,
        ];
    }

    /**
     * 顧客管理テーブルの登録 (2系 L583-605 `SC_Helper_Customer_Ex::sfEditCustomerData()`)
     *
     * 2系は `kana01` に `order_name01` (漢字氏名) をそのまま入れている (L587) ため踏襲する。
     *
     * ★新規登録時の 2系との差:
     * 2系 `dtb_customer.password` は nullable でパスワード未設定のまま作られる (ログイン不可) が、
     * 4系は **NOT NULL**。同じ「ログインできない」状態を作るため、照合不可能なランダム値を入れる。
     * `salt` は 2系同様ランダム10文字。`customer_status_id` は 2系 `status` の DB 既定値 1 (仮会員) に揃える。
     *
     * @param array<string,mixed> $form
     */
    private function upsertCustomer(array $form, int $paymentId, string $paymentMethod, int $brandId): int
    {
        $conn = $this->conn();

        $values = [
            'name01'         => (string) $form['order_name01'],
            // 2系 L587: kana01 に order_name01 をそのまま入れる (2系の実挙動を踏襲)
            'kana01'         => (string) $form['order_name01'],
            // ★4系 dtb_customer.email は NOT NULL (2系は EXIST_CHECK 無しで空文字が入る運用。
            //   information_schema 実測: 2系 dtb_customer.email も NOT NULL で、2系 SC_FormParam は
            //   未入力時に空文字を返すため両者とも成立している)。null だと INSERT が例外になるため
            //   空文字にフォールバックする (実データ: 本画面由来1,876件中 空/NULL は1件のみ)。
            'email'          => $form['order_email'] ?? '',
            'phone_number'   => $form['order_tel01'] ?? null,
            'postal_code'    => $form['order_zip01'] ?? null,
            'pref_id'        => $form['order_pref'] !== null ? (int) $form['order_pref'] : null,
            'addr01'         => $form['order_addr01'] ?? null,
            'addr02'         => $form['order_addr02'] ?? null,
            'payment_id'     => $paymentId,
            'payment_method' => $paymentMethod,
        ];

        $editCustomerId = $form['edit_customer_id'] !== null ? (int) $form['edit_customer_id'] : null;

        if ($editCustomerId !== null) {
            // 既存 (2系 L604)
            $conn->update(
                'dtb_customer',
                $values + ['update_date' => $this->now()],
                ['id' => $editCustomerId, 'brand_id' => $brandId],
            );

            return $editCustomerId;
        }

        // 新規 (2系 L596-601)
        $now = $this->now();
        $customerId = (int) $conn->fetchOne("SELECT nextval('dtb_customer_id_seq')");

        $conn->insert('dtb_customer', $values + [
            'id'                 => $customerId,
            // 4系 NOT NULL。2系は name02 を書かないので空文字 (OrderCopyService と同じ扱い)
            'name02'             => '',
            'order_payment_id'   => $paymentId,             // 2系 L598
            'brand_id'           => $brandId,               // 2系 L599
            'secret_key'         => $this->generateSecretKey(),  // 2系 L600 sfGetUniqSecretKey
            'salt'               => bin2hex(random_bytes(5)),    // 2系 sfEditCustomerData: 10文字
            // ★2系はパスワード未設定 (NULL) のまま作る。4系は NOT NULL なので照合不能値を入れる。
            'password'           => bin2hex(random_bytes(32)),
            'customer_status_id' => self::CUSTOMER_STATUS_PROVISIONAL,
            'point'              => 0,
            'discriminator_type' => 'customer',
            'create_date'        => $now,
            'update_date'        => $now,
        ]);

        return $customerId;
    }

    /**
     * 受注テーブルの登録 (2系 L609-643)
     *
     * @param array<string,mixed> $form
     * @param array<string,int>   $calc
     */
    private function insertOrder(
        array $form,
        int $customerId,
        int $paymentId,
        string $paymentMethod,
        array $calc,
        int $brandId,
    ): int {
        $conn = $this->conn();

        $orderId = (int) $conn->fetchOne("SELECT nextval('dtb_order_id_seq')");
        $now     = $this->now();

        // 2系 L634-641: VAMOS のみ 9000 (審査中)、それ以外は 1 (新規受付)
        $orderStatusId = ($paymentId === self::PAYMENT_VAMOS) ? self::STATUS_REVIEW : self::STATUS_NEW;

        $conn->insert('dtb_order', [
            'id'                 => $orderId,
            // 4系固有 (2系に対応列なし)。実測で order_no = id::text / order_date = create_date が規約
            'order_no'           => (string) $orderId,
            'order_date'         => $now,
            'brand_id'           => $brandId,
            'customer_id'        => $customerId,
            'name01'             => (string) $form['order_name01'],
            // 4系 NOT NULL。2系は order_name02 を書かない (L616-617 は 01 系のみ) ので空文字
            'name02'             => '',
            'kana01'             => (string) $form['order_name01'],  // 2系 L617
            'email'              => $form['order_email'] ?? null,
            'phone_number'       => $form['order_tel01'] ?? null,
            'postal_code'        => $form['order_zip01'] ?? null,
            'pref_id'            => $form['order_pref'] !== null ? (int) $form['order_pref'] : null,
            'addr01'             => $form['order_addr01'] ?? null,
            'addr02'             => $form['order_addr02'] ?? null,
            'payment_id'         => $paymentId,
            'payment_method'     => $paymentMethod,
            'charge'             => $calc['charge'],
            'delivery_fee_total' => $calc['deliv_fee'],
            'discount'           => $calc['discount'],
            'subtotal'           => $calc['subtotal'],
            'tax'                => $calc['tax'],
            'total'              => $calc['total'],
            'payment_total'      => $calc['payment_total'],
            'order_status_id'    => $orderStatusId,
            // ★device_type_id は 2系 Entry が一度も書かない (grep 0ヒット) ため NULL のままにする。
            //   2系実測でも本画面由来の受注 1,876件 はすべて device_type_id IS NULL であり、
            //   この NULL が「管理画面から手動起票された受注」の目印になっている。
            'discriminator_type' => 'order',
            'create_date'        => $now,
            'update_date'        => $now,
        ]);

        return $orderId;
    }

    /**
     * 受注明細テーブルの登録 (2系 L645-679)
     *
     * 2系は `dtb_order_detail` へ 10列を `extractOnlyColsOf` で絞って INSERT し、
     * `option_id`/`option_detail_id` は `dtb_order_detail_option` へ別途 INSERT する
     * (2系 `dtb_order_detail` に option 列は存在しない — information_schema 実測)。
     *
     * @param list<array<string,mixed>> $details
     * @return list<int> 生成した dtb_order_item.id
     */
    private function insertOrderItems(int $orderId, array $details): array
    {
        $conn    = $this->conn();
        $itemIds = [];

        foreach ($details as $detail) {
            $itemId   = (int) $conn->fetchOne("SELECT nextval('dtb_order_item_id_seq')");
            $price    = (int) ($detail['price'] ?? 0);
            $quantity = (int) ($detail['quantity'] ?? 0);
            $taxRate  = (int) ($detail['tax_rate'] ?? 0);
            $taxRule  = (int) ($detail['tax_rule'] ?? 0);

            // 端数ルールは 1/2/3 以外なら base_info 既定へフォールバック
            // (transform_dtb_order_item.sql の eff_rule と同じ導出)
            $effRule = in_array($taxRule, [1, 2, 3], true) ? $taxRule : $this->getBaseTaxRule();

            $conn->insert('dtb_order_item', [
                'id'                  => $itemId,
                'order_id'            => $orderId,
                'order_item_type_id'  => self::ITEM_TYPE_PRODUCT,
                'product_id'          => (int) $detail['product_id'],
                // レビュー指摘 (#818 PR #1442、重大): product_class_id 未設定だと
                // OrderStatusManagementService::restoreStock() がこの明細を例外もログもなく
                // スキップし、キャンセル/返品時に在庫が戻らない (Issue #1419 と同一パターン)。
                'product_class_id'    => $this->resolveProductClassId($conn, (int) $detail['product_id']),
                'product_code'        => $detail['product_code'] ?? null,
                'product_name'        => (string) $detail['product_name'],
                'price'               => $price,
                'quantity'            => $quantity,
                'tax'                 => $this->roundByCalcRule($price * $taxRate / 100, $effRule),
                'tax_rate'            => $taxRate,
                'tax_adjust'          => 0,
                'tax_display_type_id' => 1,                          // 税別表示 (移行と同じ)
                'tax_type_id'         => $taxRate > 0 ? 1 : 2,       // 1=課税 / 2=非課税
                'tax_rule_id'         => $taxRule !== 0 ? $taxRule : null,
                'rounding_type_id'    => $effRule,
                'course_id'           => (int) $detail['course_id'],
                'discriminator_type'  => 'orderitem',
            ]);

            $itemIds[] = $itemId;

            // 2系 L669-678: 商品オプション
            $optionId       = $detail['option_id'] ?? null;
            $optionDetailId = $detail['option_detail_id'] ?? null;
            if ($optionId !== null && $optionId !== '' && (int) $optionId > 0) {
                $conn->insert('dtb_order_detail_option', [
                    'id'               => (int) $conn->fetchOne("SELECT nextval('dtb_order_detail_option_id_seq')"),
                    'order_id'         => $orderId,
                    'order_detail_id'  => $itemId,
                    'option_id'        => (int) $optionId,
                    'option_detail_id' => (int) $optionDetailId,
                    'create_date'      => $this->now(),
                ]);
            }
        }

        return $itemIds;
    }

    /**
     * `dtb_order_item.product_class_id` の解決 (StoreConfirmService::resolveProductClassId() /
     * TeikiOrderRegistrar::resolveProductClassId() と同一実装、Issue #1419 の再発防止)。
     *
     * ★2系にはproduct_class(商品バリエーション)の概念が無く、移行済みデータは
     *   `post_migrate.sql`が`product_id`→`dtb_product_class`の1:1補完UPDATEで後から埋めている。
     *   その補完は移行時の一度きりの後処理のため、本画面が本番稼働後に新規作成する受注には
     *   一切効かない。都度このメソッドで解決しないと`product_class_id`がNULLのまま残り、
     *   `OrderStatusManagementService::restoreStock()`がこの明細を例外もログもなくスキップして
     *   キャンセル/返品時に在庫が戻らない。
     */
    private function resolveProductClassId(Connection $conn, int $productId): ?int
    {
        $id = $conn->fetchOne(
            'SELECT id FROM dtb_product_class WHERE product_id = :product_id ORDER BY id ASC LIMIT 1',
            ['product_id' => $productId],
        );

        return $id !== false ? (int) $id : null;
    }

    /**
     * 配送テーブルの登録 (2系 L681-700)
     *
     * @param array<string,mixed> $form
     */
    private function insertShipping(int $orderId, array $form): int
    {
        $conn = $this->conn();

        $shippingId = (int) $conn->fetchOne("SELECT nextval('dtb_shipping_id_seq')");
        $now        = $this->now();

        $conn->insert('dtb_shipping', [
            'id'                 => $shippingId,
            'order_id'           => $orderId,
            'name01'             => (string) $form['order_name01'],
            // 4系 NOT NULL。2系は shipping_name02 を書かない (L685-686) ので空文字
            'name02'             => '',
            'kana01'             => (string) $form['order_name01'],  // 2系 L686
            'phone_number'       => $form['order_tel01'] ?? null,
            'postal_code'        => $form['order_zip01'] ?? null,
            'pref_id'            => $form['order_pref'] !== null ? (int) $form['order_pref'] : null,
            'addr01'             => $form['order_addr01'] ?? null,
            'addr02'             => $form['order_addr02'] ?? null,
            'time_id'            => $form['time_id'] !== null ? (int) $form['time_id'] : null,
            'delivery_id'        => (int) $form['deliv_id'],
            'delivery_time'      => $this->resolveDeliveryTime((int) $form['deliv_id'], $form['time_id']),
            // 2系 `shipping_date` → 4系も `shipping_date` (transform_dtb_shipping.sql / #996)
            'shipping_date'      => (string) $form['shipping_date'],
            'sort_no'            => 1,
            'discriminator_type' => 'shipping',
            'create_date'        => $now,
            'update_date'        => $now,
        ]);

        return $shippingId;
    }

    /**
     * 配送時間帯テキスト (4系 `dtb_shipping.delivery_time`。2系に対応列なし)
     *
     * Issue #993: `(delivery_id, time_id)` の複合キーで引く。
     */
    private function resolveDeliveryTime(int $deliveryId, mixed $timeId): ?string
    {
        if ($timeId === null || $timeId === '') {
            return null;
        }

        $raw = $this->conn()->fetchOne(
            'SELECT delivery_time FROM dtb_delivery_time WHERE delivery_id = :did AND time_id = :tid',
            ['did' => $deliveryId, 'tid' => (int) $timeId],
        );

        return is_string($raw) ? $raw : null;
    }

    // =====================================================================
    // ★定期テーブルの登録 (2系 L702-826) — 本 Issue の中核
    // =====================================================================

    /**
     * 定期テーブルの登録 (2系 L702-826)
     *
     * 受注明細1行ごとに「次回に届けるべき商品・数量・間隔・お届け日」を
     * `dtb_course_detail` から決めて `dtb_teiki` を1行作る。
     *
     * **定期 or 都度の判定 (2系 L735-739)**
     * 2系は `$arrCourse["course_type"] == 4 || $arrCourseDetail[0]["interval"] == 0` だが、
     * 前段は2系 `dtb_course` に `course_type` 列が存在しないため常に false (クラス docblock 参照)。
     * → **`interval == 0` のみが実効条件**。
     *
     * **次回商品の決定 (2系 L741-753)**
     * - コース明細に「次の回」(`$arrCourseDetail[1]`) があればそれを使う
     * - 無ければ `order_count <= 現在の order_count + 1` を `order_count DESC` で引いた先頭
     *   (= 最終回の設定を繰り返す)
     *
     * **次回お届け日 (2系 L778-793)**
     * 今回のお届け日 + **今回の**明細の interval (`interval_type` 0=月 / 1=週 / 2=日)。
     * ★次回分ではなく `$arrCourseDetail[0]` の間隔を使うのが2系の実装。
     *
     * **定期ID (2系 L712-723)**
     * 新規顧客は採番、既存顧客は `getTeikiIdFromCustomer()`
     * (`SELECT teiki_id FROM dtb_teiki WHERE customer_id = ? ORDER BY teiki_id LIMIT 1`) で
     * **既存の契約グループに明細を追加する**。4系は Issue #642 / PR #1024 で
     * `dtb_teiki` の PK が代理キー `id` になり `(teiki_id, detail_id)` が UNIQUE になったため
     * この2系モデルがそのまま成立する。
     *
     * @return array{teiki_id:?int, detail_ids:list<int>}
     */
    private function registerTeiki(
        int $orderId,
        int $customerId,
        int $brandId,
        string $shippingDate,
        int $delivId,
        ?int $timeId,
        bool $isExistingCustomer,
    ): array {
        $conn = $this->conn();

        // 2系 L706-708: 登録された注文データを取得
        $orderCreateDate = (string) $conn->fetchOne(
            'SELECT create_date FROM dtb_order WHERE id = :oid',
            ['oid' => $orderId],
        );
        $orderItems = $conn->fetchAllAssociative(
            'SELECT id, product_id, course_id FROM dtb_order_item WHERE order_id = :oid ORDER BY id',
            ['oid' => $orderId],
        );

        // 2系 L712-723: 定期ID取得
        $teikiId = null;
        if ($isExistingCustomer) {
            $existing = $conn->fetchOne(
                'SELECT teiki_id FROM dtb_teiki WHERE customer_id = :cid ORDER BY teiki_id LIMIT 1',
                ['cid' => $customerId],
            );
            $teikiId = $existing !== false && $existing !== null ? (int) $existing : null;
        }
        if ($teikiId === null) {
            $teikiId = (int) $conn->fetchOne("SELECT nextval('dtb_teiki_teiki_id_seq')");
        }

        $detailIds       = [];
        $isTeikiRegister = false;

        foreach ($orderItems as $item) {
            $courseId  = $item['course_id'] !== null ? (int) $item['course_id'] : 0;
            $productId = (int) $item['product_id'];
            if ($courseId === 0) {
                continue;
            }

            // 2系 L733: 今回追加のコース明細情報
            $courseDetails = $conn->fetchAllAssociative(
                'SELECT * FROM dtb_course_detail WHERE course_id = :cid AND product_id = :pid ORDER BY order_count',
                ['cid' => $courseId, 'pid' => $productId],
            );
            if ($courseDetails === []) {
                continue;
            }

            // 2系 L735-739: 都度なので定期情報を登録しない
            if ((int) ($courseDetails[0]['interval'] ?? 0) === 0) {
                continue;
            }

            // 2系 L741-753: 次回分の商品・数量・間隔
            if (isset($courseDetails[1])) {
                $next = $courseDetails[1];
            } else {
                $nextOrderCount = (int) $courseDetails[0]['order_count'] + 1;
                $nextRows = $conn->fetchAllAssociative(
                    'SELECT * FROM dtb_course_detail WHERE course_id = :cid AND order_count <= :oc'
                    . ' ORDER BY order_count DESC',
                    ['cid' => $courseId, 'oc' => $nextOrderCount],
                );
                if ($nextRows === []) {
                    continue;
                }
                $next = $nextRows[0];
            }

            // 2系 L778-793: 次回お届け日 = 今回のお届け日 + 今回の間隔
            $nextShippingDate = $this->addInterval(
                $shippingDate,
                (int) $courseDetails[0]['interval'],
                (int) $courseDetails[0]['interval_type'],
            );

            // 2系 L799: detail_id は dtb_teiki_detail_id_seq のグローバル採番
            $detailId = (int) $conn->fetchOne("SELECT nextval('dtb_teiki_detail_id_seq')");

            $conn->insert('dtb_teiki', [
                'teiki_id'           => $teikiId,
                'detail_id'          => $detailId,
                'customer_id'        => $customerId,
                'product_id'         => (int) $next['product_id'],
                'quantity'           => (int) $next['quantity'],
                'discount'           => 0,
                'deliv_id'           => $delivId,
                'deliv_time_id'      => $timeId,
                'interval'           => (int) $next['interval'],
                'interval_type'      => (int) $next['interval_type'],
                'next_shipping_date' => $nextShippingDate,
                'first_order_id'     => $orderId,
                'first_order_date'   => $orderCreateDate,
                'course_id'          => $courseId,
                'brand_id'           => $brandId,
                // ★2系は status を書かず DB 既定値 1 に任せているが、4系は NOT NULL かつ既定値なし
                'status'             => self::TEIKI_STATUS_ACTIVE,
                'del_flg'            => 0,
                'create_date'        => $this->now(),
                'update_date'        => $this->now(),
            ]);

            // 2系 L807: 受注明細更新 (定期明細ID更新)
            $conn->executeStatement(
                'UPDATE dtb_order_item SET teiki_detail_id = :did WHERE id = :id',
                ['did' => $detailId, 'id' => (int) $item['id']],
            );

            // 2系 L810-818: 商品オプション情報を定期と紐付ける
            $options = $conn->fetchAllAssociative(
                'SELECT option_id, option_detail_id FROM dtb_order_detail_option'
                . ' WHERE order_id = :oid AND order_detail_id = :did'
                . ' ORDER BY option_id, option_detail_id',
                ['oid' => $orderId, 'did' => (int) $item['id']],
            );
            foreach ($options as $option) {
                $conn->insert('dtb_teiki_option', [
                    'teiki_id'         => $teikiId,
                    'teiki_detail_id'  => $detailId,
                    'option_id'        => (int) $option['option_id'],
                    'option_detail_id' => (int) $option['option_detail_id'],
                    'create_date'      => $this->now(),
                ]);
            }

            $detailIds[]     = $detailId;
            $isTeikiRegister = true;
        }

        // 2系 L822-826: 定期作成ありなら受注に teiki_id を書き戻す
        if ($isTeikiRegister) {
            $conn->executeStatement(
                'UPDATE dtb_order SET teiki_id = :tid, update_date = CURRENT_TIMESTAMP WHERE id = :oid',
                ['tid' => $teikiId, 'oid' => $orderId],
            );

            return ['teiki_id' => $teikiId, 'detail_ids' => $detailIds];
        }

        return ['teiki_id' => null, 'detail_ids' => []];
    }

    /**
     * 次回お届け日の計算 (2系 L781-792)
     *
     * `interval_type`: 2=日ごと / 1=週間ごと / 0(既定)=ヶ月ごと
     * 2系は `date("Y/m/d", ...)` = **日付のみ**を入れる。
     */
    private function addInterval(string $baseDate, int $interval, int $intervalType): string
    {
        $modifier = match ($intervalType) {
            2       => '+' . $interval . ' day',
            1       => '+' . $interval . ' week',
            default => '+' . $interval . ' month',
        };

        return (new \DateTimeImmutable($baseDate))->modify($modifier)->format('Y-m-d');
    }

    /**
     * DB の現在時刻を文字列で取得する。
     *
     * ★PHP 側の `new \DateTime()` を使ってはいけない: アプリ TZ は Asia/Tokyo・DB は UTC のため
     * `format('Y-m-d H:i:s')` を timestamptz にバインドすると 9時間先の値が入る
     * ([[feedback_utc_vs_jst_timestamptz]] / OrderCopyService と同じ理由)。
     */
    private function now(): string
    {
        return (string) $this->conn()->fetchOne('SELECT CURRENT_TIMESTAMP');
    }

    /**
     * 会員の秘密鍵を生成する (2系 `SC_Helper_Customer_Ex::sfGetUniqSecretKey()` 相当)
     */
    private function generateSecretKey(): string
    {
        do {
            $key = bin2hex(random_bytes(16));
            $exists = (int) $this->conn()->fetchOne(
                'SELECT count(*) FROM dtb_customer WHERE secret_key = :key',
                ['key' => $key],
            );
        } while ($exists > 0);

        return $key;
    }
}
