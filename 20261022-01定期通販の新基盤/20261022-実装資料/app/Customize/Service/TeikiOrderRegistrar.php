<?php

namespace Customize\Service;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;

/**
 * 定期次回受注の登録サービス
 *
 * 2系 SC_Batch_Daily::lfRegistOrder の **受注登録部** (L457-617) を移植。
 * PR #85 (データ取得) → PR #86 (明細構築) → PR #88 (金額計算) に続く lfRegistOrder 分割移植 **第4弾**。
 *
 * 担当範囲:
 * 1. 明細合計集計 (taxfree_total / subtotal / taxtotal) (2系 L334-339 相当)
 * 2. 合計金額確定 (TeikiOrderTotalCalculator 呼出)
 * 3. dtb_order INSERT (2系 L466-510 registerOrder)
 * 4. dtb_customer_point_history の order_id 更新 (2系 L513-515)
 * 5. dtb_order_item INSERT (2系 L520 registerOrderDetail → 4系 dtb_order_item)
 * 6. dtb_shipping INSERT (2系 L524-534 registerShipping)
 * 7. 配送方法確定 + dtb_shipping UPDATE (2系 L536-574 lfChangeOrderShippingMethod)
 * 8. dtb_wk_daily_batch の order_id 更新 (2系 L577)
 * 9. 与信情報収集して返却 (2系 L579-617)
 *
 * **スコープ外（後続実装）:**
 * - getDiscountPoint (独自ポイント / Phase 4 後半) → $pointDiscount / $pointHistoryId で受け取る
 * - getFirstDeferredCharge (後払い優遇判定 / Phase 4 後半) → $firstDeferredCharge で受け取る
 * - 送料計算 (現行コードはコメントアウト状態 / 常時 $delivFee=0)
 *
 * **2系→4系 テーブル名・カラム名 主要変換:**
 * - dtb_order_detail → dtb_order_item (order_item_type_id=1 が商品)
 * - dtb_order.status → dtb_order.order_status_id
 * - dtb_order.deliv_fee → dtb_order.delivery_fee_total
 * - dtb_order.order_name01 → dtb_order.name01
 * - dtb_order.order_tel01 → dtb_order.phone_number
 * - dtb_order.order_zip01 → dtb_order.postal_code
 * - dtb_order.order_pref → dtb_order.pref_id
 * - dtb_shipping.deliv_id → dtb_shipping.delivery_id
 * - dtb_shipping.shipping_date (お届け日) はそのまま dtb_shipping.shipping_date (rename なし)
 * - dtb_shipping.shipping_name01 → dtb_shipping.name01
 * - 4系 dtb_order に delivery_id カラムなし → dtb_shipping.delivery_id のみ更新
 */
class TeikiOrderRegistrar
{
    /** device_type_id = 80: バッチ (2系 L480) */
    public const DEVICE_TYPE_BATCH = 80;

    /** order_item_type_id */
    public const ITEM_TYPE_PRODUCT  = 1;
    public const ITEM_TYPE_DELIVERY = 2;
    public const ITEM_TYPE_CHARGE   = 3;

    /** 配送方法ID: ゆうパック */
    public const DELIV_YU_PACK = 2;

    /** 支払方法ID */
    public const PAYMENT_ID_DAIBIKI   = 4;
    public const PAYMENT_ID_CARD      = 5;
    public const PAYMENT_ID_NP        = 6;
    public const PAYMENT_ID_FREE      = 7;
    public const PAYMENT_ID_AMAZONPAY = 9;
    public const PAYMENT_ID_VAMOS     = 13;

    /** mtb_tax_type (dtb_order_item.tax_type_id、Issue #1419) */
    private const TAX_TYPE_TAXATION    = 1;
    private const TAX_TYPE_NON_TAXABLE = 2;

    /** mtb_tax_display_type: 1=税抜(EXCLUDED)。この店舗の価格は常に税抜のため固定 (Issue #1419) */
    private const TAX_DISPLAY_TYPE_EXCLUDED = 1;

    /** mtb_rounding_type のデフォルト (taxRule文字列が想定外の値のときのフォールバック、Issue #1419) */
    private const ROUNDING_TYPE_DEFAULT = 2;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TeikiOrderTotalCalculator $totalCalculator,
        private readonly PurchaseShippingService $purchaseShippingService,
        private readonly DailyTeikiUpdateService $dailyTeikiUpdateService,
    ) {}

    /**
     * 定期次回受注を登録する (2系 lfRegistOrder L457-617)
     *
     * @param int    $teikiId             定期ID
     * @param int    $customerId          顧客ID
     * @param int    $brandId             ブランドID
     * @param array<string,mixed> $customer 4系 dtb_customer の行データ
     *                                     (name01/name02/kana01/phone_number/postal_code/pref_id/addr01/addr02/email/payment_id)
     *                                     payment_id は与信情報収集 (L581-605) で dtb_customer.payment_id として使用
     * @param int    $paymentId           支払方法ID
     * @param string $paymentMethod       支払方法名 (dtb_payment.payment_method)
     * @param list<array<string,mixed>> $details TeikiOrderDetailBuilder::build()['details']
     * @param int    $discount            値引き合計 (dtb_teiki.discount の合計。呼出側で集計)
     * @param int    $delivFee            送料 (現行コメントアウトにより常時0)
     * @param bool   $isDelivFee          送料発生フラグ (false=初回送料無料 / #309)
     * @param string $taxRule             端数処理 ('floor'|'ceil'|'round')
     * @param ?int   $firstDeferredCharge 後払い優遇手数料 (#310/#362: 2系ロジック境界 202503 超過後は null で新ロジック適用)
     * @param int    $pointDiscount       ポイント値引き額 (0=なし / Phase 4 後半)
     * @param ?int   $pointHistoryId      dtb_customer_point_history.id (null=なし)
     * @param string $nextShippingDate    次回お届け日 (Y-m-d)
     * @param ?int   $delivIdManual       手動配送方法ID (null=自動判定)
     * @param ?int   $timeIdManual        手動時間帯ID (null=自動判定)
     * @param ?int   $delivTimeId              dtb_teiki の希望時間帯ID (null=指定なし)
     * @param ?int   $shippingArrangementId     dtb_teiki.shipping_arrangement_id (null=なし)
     *
     * @return array{
     *   order_id: int,
     *   credit_type: string|null,
     *   credit_info: array<string,mixed>|null
     * }
     */
    public function register(
        int $teikiId,
        int $customerId,
        int $brandId,
        array $customer,
        int $paymentId,
        string $paymentMethod,
        array $details,
        int $discount = 0,
        int $delivFee = 0,
        bool $isDelivFee = true,
        string $taxRule = 'floor',
        ?int $firstDeferredCharge = null,
        int $pointDiscount = 0,
        ?int $pointHistoryId = null,
        string $nextShippingDate = '',
        ?int $delivIdManual = null,
        ?int $timeIdManual = null,
        ?int $delivTimeId = null,
        ?int $shippingArrangementId = null,
    ): array {
        $conn = $this->em->getConnection();

        // 初回送料無料 (2系 L362-365 / #309): is_delivfee=false の場合は送料0を強制
        if (!$isDelivFee) {
            $delivFee = 0;
        }

        // 明細から合計集計 (2系 L334-339)
        ['taxfree_total' => $taxfreeTotal, 'subtotal' => $subtotal, 'taxtotal' => $taxtotal] =
            $this->aggregateTotals($details, $taxRule);

        // 合計金額・ステータス確定
        $calc = $this->totalCalculator->calculate(
            $taxfreeTotal,
            $subtotal,
            $taxtotal,
            $discount,
            $delivFee,
            $paymentId,
            $brandId,
            $paymentMethod,
            $pointDiscount,
            $firstDeferredCharge,
        );

        // dtb_order INSERT (2系 L466-510)
        $orderId = (int) $conn->fetchOne("SELECT nextval('dtb_order_id_seq')");
        $conn->executeStatement(
            <<<SQL
            INSERT INTO dtb_order (
                id, order_no, order_date, brand_id, customer_id, payment_id, payment_method,
                name01, name02, kana01,
                phone_number, postal_code, pref_id,
                addr01, addr02, email,
                teiki_id, device_type_id,
                subtotal, tax, delivery_fee_total, charge, discount,
                total, payment_total, order_status_id,
                discriminator_type, create_date, update_date
            ) VALUES (
                :id, :order_no, CURRENT_TIMESTAMP, :brand_id, :customer_id, :payment_id, :payment_method,
                :name01, :name02, :kana01,
                :phone_number, :postal_code, :pref_id,
                :addr01, :addr02, :email,
                :teiki_id, :device_type_id,
                :subtotal, :tax, :delivery_fee_total, :charge, :discount,
                :total, :payment_total, :order_status_id,
                'order', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
            )
            SQL,
            [
                'id'                 => $orderId,
                // ★4系固有列。`order_no = id::text` / `order_date = create_date` が規約
                //   (Issue #1725。空だと購入履歴詳細が引けず受注一覧の受注日も空欄になる)
                'order_no'           => (string) $orderId,
                'brand_id'           => $brandId,
                'customer_id'        => $customerId,
                'payment_id'         => $calc['payment_id'],
                'payment_method'     => $calc['payment_method'],
                'name01'             => $customer['name01'],
                'name02'             => $customer['name02'] ?? '',
                'kana01'             => $customer['kana01'] ?? null,
                'phone_number'       => $customer['phone_number'] ?? null,
                'postal_code'        => $customer['postal_code'] ?? null,
                'pref_id'            => isset($customer['pref_id']) ? (int) $customer['pref_id'] : null,
                'addr01'             => $customer['addr01'] ?? null,
                'addr02'             => $customer['addr02'] ?? null,
                'email'              => $customer['email'] ?? null,
                'teiki_id'           => $teikiId,
                'device_type_id'     => self::DEVICE_TYPE_BATCH,
                'subtotal'           => $calc['subtotal'],
                'tax'                => $calc['tax'],
                'delivery_fee_total' => $calc['deliv_fee'],
                'charge'             => $calc['charge'],
                'discount'           => $calc['discount'],
                'total'              => $calc['total'],
                'payment_total'      => $calc['payment_total'],
                'order_status_id'    => $calc['status'],
            ]
        );

        // ポイントテーブル更新 (2系 L513-515)
        if ($pointHistoryId !== null) {
            $conn->executeStatement(
                'UPDATE dtb_customer_point_history SET order_id = :order_id WHERE id = :id',
                ['order_id' => $orderId, 'id' => $pointHistoryId]
            );
        }

        // dtb_order_item INSERT (2系 L520 registerOrderDetail)
        $this->insertOrderItems($orderId, $details, $calc, $taxRule);

        // dtb_shipping INSERT (2系 L524-534 registerShipping)
        $shippingId = $this->insertShipping($orderId, $customer, $nextShippingDate);

        // 4系固有: 明細と配送の紐付け (2系 dtb_order_detail に shipping_id 列は無い)。
        // AdminOrderEntryService / OrderCopyService / PaymentChangeOrderUpdater と同一手順。
        $conn->executeStatement(
            'UPDATE dtb_order_item SET shipping_id = :sid WHERE order_id = :oid',
            ['sid' => $shippingId, 'oid' => $orderId],
        );

        // 配送方法確定 + dtb_shipping UPDATE (2系 L536-574)
        $this->resolveAndUpdateShipping(
            $orderId,
            $calc['payment_id'],
            (int) ($customer['payment_id'] ?? $calc['payment_id']),
            $delivIdManual,
            $timeIdManual,
            $delivTimeId,
            $shippingArrangementId,
        );

        // dtb_wk_daily_batch の order_id 更新 (2系 L577)
        $conn->executeStatement(
            <<<SQL
            UPDATE dtb_wk_daily_batch
            SET order_id = :order_id
            WHERE teiki_id = :teiki_id
              AND customer_id = :customer_id
              AND brand_id = :brand_id
              AND order_id IS NULL
            SQL,
            ['order_id' => $orderId, 'teiki_id' => $teikiId, 'customer_id' => $customerId, 'brand_id' => $brandId]
        );

        // 与信情報収集 (2系 L579-617)
        [$creditType, $creditInfo] = $this->collectCreditInfo(
            $orderId, $teikiId, $customerId, $customer, $calc
        );

        return [
            'order_id'    => $orderId,
            'credit_type' => $creditType,
            'credit_info' => $creditInfo,
        ];
    }

    /**
     * 明細から taxfree_total / subtotal / taxtotal を集計する (2系 L334-336 相当)
     *
     * discount は dtb_teiki.discount の合計で呼出側が渡す（2系 L338 は teiki ループ内で加算）。
     *
     * @param list<array<string,mixed>> $details
     * @return array{taxfree_total: int, subtotal: int, taxtotal: int}
     */
    private function aggregateTotals(array $details, string $taxRule): array
    {
        $taxfreeTotal = 0;
        $subtotal     = 0;
        $taxtotal     = 0;

        foreach ($details as $detail) {
            $price   = (int) $detail['price'];
            $qty     = (int) $detail['quantity'];
            $taxRate = (int) $detail['tax_rate'];

            $taxPerUnit = $this->calcTax($price, $taxRate, $taxRule);

            $taxfreeTotal += $price * $qty;
            $taxtotal     += $taxPerUnit * $qty;
            $subtotal     += ($price + $taxPerUnit) * $qty;
        }

        return ['taxfree_total' => $taxfreeTotal, 'subtotal' => $subtotal, 'taxtotal' => $taxtotal];
    }

    /**
     * 端数処理付き税額計算 (2系 SC_Utils_Ex::sfTax 相当)
     */
    private function calcTax(int $price, int $taxRate, string $taxRule): int
    {
        $raw = $price * $taxRate / 100;
        return match ($taxRule) {
            'ceil'  => (int) ceil($raw),
            'round' => (int) round($raw),
            default => (int) floor($raw),
        };
    }

    /**
     * dtb_order_item INSERT (2系 registerOrderDetail → 4系 dtb_order_item)
     *
     * 商品行 (order_item_type_id=1) だけを書く。送料・手数料は dtb_order の列にだけ持つ (2系と同じ)。
     */
    private function insertOrderItems(int $orderId, array $details, array $calc, string $taxRule): void
    {
        $conn = $this->em->getConnection();

        // 商品明細 (order_item_type_id=1)
        foreach ($details as $detail) {
            $itemId     = (int) $conn->fetchOne("SELECT nextval('dtb_order_item_id_seq')");
            $price      = (int) $detail['price'];
            $qty        = (int) $detail['quantity'];
            $taxRate    = (int) $detail['tax_rate'];
            $taxPerUnit = $this->calcTax($price, $taxRate, $taxRule);

            $conn->executeStatement(
                <<<SQL
                INSERT INTO dtb_order_item (
                    id, order_id, order_item_type_id,
                    product_id, product_class_id, product_code, product_name,
                    price, quantity, tax, tax_rate,
                    tax_adjust, tax_rule_id, tax_type_id, tax_display_type_id, rounding_type_id,
                    teiki_detail_id, course_id, container_id,
                    discriminator_type
                ) VALUES (
                    :id, :order_id, :type,
                    :product_id, :product_class_id, :product_code, :product_name,
                    :price, :quantity, :tax, :tax_rate,
                    0, :tax_rule_id, :tax_type_id, :tax_display_type_id, :rounding_type_id,
                    :teiki_detail_id, :course_id, :container_id,
                    'orderitem'
                )
                SQL,
                [
                    'id'                   => $itemId,
                    'order_id'             => $orderId,
                    'type'                 => self::ITEM_TYPE_PRODUCT,
                    'product_id'           => (int) $detail['product_id'],
                    'product_class_id'     => $this->resolveProductClassId($conn, (int) $detail['product_id']),
                    'product_code'         => (string) $detail['product_code'],
                    'product_name'         => (string) $detail['product_name'],
                    'price'                => $price,
                    'quantity'             => $qty,
                    'tax'                  => $taxPerUnit,
                    'tax_rate'             => $taxRate,
                    // 2系 `cartDev/data/class/batch/SC_Batch_Daily.php` L260 / L319
                    // `$arrOrderDetail[$idx]["tax_rule"] = $this->arrBaseInfo["tax_rule"];` 相当
                    // (L520 `registerOrderDetail()` → `dtb_order_detail.tax_rule`、Issue #1423)。
                    // 4系の `$taxRule` は `DailyBatchCommand` が `dtb_base_info.tax_rule` から導出した
                    // 文字列なので、数値へ戻せば 2系と同じ値になる。
                    // ★送料行(type=2)・手数料行(type=3)には入れない。2系 `dtb_order_detail` にその行自体が
                    //   存在せず(送料/手数料は `dtb_order` の列)、移行後の4系実測でも
                    //   type=2 の6行 / type=3 の9行は `tax_rule_id` が全件 NULL (2026-08-26)。
                    'tax_rule_id'          => $this->resolveRoundingTypeId($taxRule),
                    'tax_type_id'          => $taxRate > 0 ? self::TAX_TYPE_TAXATION : self::TAX_TYPE_NON_TAXABLE,
                    'tax_display_type_id'  => self::TAX_DISPLAY_TYPE_EXCLUDED,
                    'rounding_type_id'     => $this->resolveRoundingTypeId($taxRule),
                    'teiki_detail_id'      => $detail['teiki_detail_id'] ?? null,
                    'course_id'            => (int) ($detail['course_id'] ?? 0),
                    'container_id'         => $detail['container_id'] ?? null,
                ]
            );

            // 商品オプション (2系 L903-913)
            foreach ($detail['option'] ?? [] as $opt) {
                $optId = (int) $conn->fetchOne("SELECT nextval('dtb_order_detail_option_id_seq')");
                $conn->executeStatement(
                    'INSERT INTO dtb_order_detail_option (id, order_id, order_detail_id, option_id, option_detail_id, create_date) VALUES (:id, :order_id, :detail_id, :option_id, :option_detail_id, CURRENT_TIMESTAMP)',
                    [
                        'id'               => $optId,
                        'order_id'         => $orderId,
                        'detail_id'        => $itemId,
                        'option_id'        => (int) $opt['option_id'],
                        'option_detail_id' => (int) $opt['option_detail_id'],
                    ]
                );
            }
        }

        // ★送料行 (type 2)・手数料行 (type 3) は作らない (2026-10-10)。
        //   2系 registerOrderDetail は商品行だけを dtb_order_detail に書き、送料・手数料は dtb_order の列
        //   (deliv_fee / charge) にだけ持つ。移行した 230万明細も、4系の LP/チャット購入の受注も商品行だけで、
        //   定期受注 (daily / 管理画面の個別受注作成) だけが送料行・手数料行を持っていた。
        //   金額は dtb_order.delivery_fee_total / charge に入っているので明細が無くても変わらない。
    }

    /**
     * `dtb_order_item.product_class_id` の解決 (post_migrate.sql 2-b-3 の逆引きUPDATEと同一ロジック、
     * Issue #1419)。StoreConfirmService::resolveProductClassId() と同一実装。詳細はそちらのdocblock参照。
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
     * `dtb_order_item.rounding_type_id` (および商品行の `tax_rule_id`、Issue #1423) の解決
     * (Issue #1419)。本クラスは既に `$taxRule`
     * ('floor'|'ceil'|'round') を `calcTax()` の端数処理に使っているため、同じ値を
     * mtb_rounding_type の数値体系(1=四捨五入/2=切り捨て/3=切り上げ)へ変換するだけでよい。
     * 未設定のまま受注編集画面を開くと`Order::getTotalByTaxRate()`が
     * `OrderItem.RoundingType.id`をnull参照して500になる。
     */
    private function resolveRoundingTypeId(string $taxRule): int
    {
        return match ($taxRule) {
            'round' => 1,
            'ceil'  => 3,
            'floor' => 2,
            default => self::ROUNDING_TYPE_DEFAULT,
        };
    }

    /**
     * dtb_shipping INSERT (2系 registerShipping L665-698)
     *
     * delivery_id / time_id は後続の resolveAndUpdateShipping で確定・更新するため NULL で INSERT。
     *
     * @return int 採番した dtb_shipping.id (呼出元が dtb_order_item.shipping_id の紐付けに使う)
     */
    private function insertShipping(int $orderId, array $customer, string $nextShippingDate): int
    {
        $conn    = $this->em->getConnection();
        $shippingId = (int) $conn->fetchOne("SELECT nextval('dtb_shipping_id_seq')");

        $shippingDate = $nextShippingDate !== '' ? $nextShippingDate : null;

        $conn->executeStatement(
            <<<SQL
            INSERT INTO dtb_shipping (
                id, order_id,
                name01, name02, kana01,
                phone_number, postal_code, pref_id,
                addr01, addr02,
                shipping_date,
                discriminator_type, create_date, update_date
            ) VALUES (
                :id, :order_id,
                :name01, :name02, :kana01,
                :phone_number, :postal_code, :pref_id,
                :addr01, :addr02,
                :shipping_date,
                'shipping', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
            )
            SQL,
            [
                'id'            => $shippingId,
                'order_id'      => $orderId,
                'name01'        => $customer['name01'],
                'name02'        => $customer['name02'] ?? '',
                'kana01'        => $customer['kana01'] ?? null,
                'phone_number'  => $customer['phone_number'] ?? null,
                'postal_code'   => $customer['postal_code'] ?? null,
                'pref_id'       => isset($customer['pref_id']) ? (int) $customer['pref_id'] : null,
                'addr01'        => $customer['addr01'] ?? null,
                'addr02'        => $customer['addr02'] ?? null,
                'shipping_date' => $shippingDate,
            ]
        );

        return $shippingId;
    }

    /**
     * 配送方法確定 + dtb_shipping UPDATE (2系 L536-574)
     *
     * 2系 L567 で dtb_order.deliv_id も更新していたが、4系 dtb_order に delivery_id カラムは
     * 存在しないため dtb_shipping.delivery_id のみ更新する。
     */
    /**
     * @param int $paymentId         最終支払方法ID (totalCalculator 後の値。0円時は7になる可能性あり)
     * @param int $customerPaymentId 顧客登録上の支払方法ID (dtb_customer.payment_id)
     *                               2系 L558 の置き配判定は $arrCustomer["payment_id"] != 4 で判定するため分離
     */
    private function resolveAndUpdateShipping(
        int $orderId,
        int $paymentId,
        int $customerPaymentId,
        ?int $delivIdManual,
        ?int $timeIdManual,
        ?int $delivTimeId,
        ?int $shippingArrangementId,
    ): void {
        $conn = $this->em->getConnection();

        if ($delivIdManual !== null) {
            // 手動設定 (2系 L553-556)
            $delivId = $delivIdManual;
            $timeId  = $timeIdManual;
        } else {
            // 自動判定 (2系 L538-541)
            // ★2系 SC_Batch_Daily は cartDev(admin)側のバッチなので、
            //   `SC_Helper_Purchase_Ex::lfChangeOrderShippingMethod`(代引き強制ゆうパック +
            //   容器係数 weight_rate 付き重量)が動く。front base 版と取り違えないこと (Issue #1719)。
            $delivId = $this->purchaseShippingService->resolveShippingMethodForOrderAdmin($orderId, false);
            if ($paymentId === self::PAYMENT_ID_DAIBIKI) {
                // 代引きはゆうパックに強制変更 (2系 L539-541)
                $delivId = self::DELIV_YU_PACK;
            }

            // time_id 決定 (2系 L543-556)
            if (empty($delivTimeId)) {
                // 時間帯指定なし
                $timeId = null;
                if ($delivId === self::DELIV_YU_PACK) {
                    // ゆうパックは最終便に強制 (2系 L547)
                    $timeId = $conn->fetchOne(
                        // Issue #993: (delivery_id, time_id) 体系。最新時間帯は time_id 降順で取る。
                        'SELECT time_id FROM dtb_delivery_time WHERE delivery_id = :did ORDER BY time_id DESC LIMIT 1',
                        ['did' => $delivId]
                    );
                    $timeId = $timeId !== false ? (int) $timeId : null;
                }
            } else {
                // 時間帯指定あり (2系 L551-552)
                $timeId = $delivTimeId;
            }
        }

        // 置き配設定 (2系 L557-563): ゆうパック + 顧客登録支払方法が非代引きのみ設定
        // 2系は $arrCustomer["payment_id"] != 4 で判定 → $customerPaymentId を使う
        $finalArrangementId = ($delivId === self::DELIV_YU_PACK && $customerPaymentId !== self::PAYMENT_ID_DAIBIKI)
            ? $shippingArrangementId
            : null;

        // 配送時間帯テキスト取得 (2系 L572)
        $deliveryTime = null;
        if ($timeId !== null) {
            $raw = $conn->fetchOne(
                // Issue #993: (delivery_id, time_id) 複合キーで突合(グローバル id ではない)。
                'SELECT delivery_time FROM dtb_delivery_time WHERE delivery_id = :did AND time_id = :tid',
                ['did' => $delivId, 'tid' => $timeId]
            );
            $deliveryTime = is_string($raw) ? $raw : null;
        }

        // dtb_shipping UPDATE
        $conn->executeStatement(
            <<<SQL
            UPDATE dtb_shipping
            SET delivery_id             = :delivery_id,
                time_id                 = :time_id,
                delivery_time           = :delivery_time,
                shipping_arrangement_id = :shipping_arrangement_id,
                update_date             = CURRENT_TIMESTAMP
            WHERE order_id = :order_id
            SQL,
            [
                'delivery_id'             => $delivId,
                'time_id'                 => $timeId,
                'delivery_time'           => $deliveryTime,
                'shipping_arrangement_id' => $finalArrangementId,
                'order_id'                => $orderId,
            ]
        );
    }

    /**
     * 与信情報収集 (2系 L579-617)
     *
     * @param array<string,mixed> $customer
     * @param array<string,mixed> $calc TeikiOrderTotalCalculator::calculate() の返り値
     * @return array{0: string|null, 1: array<string,mixed>|null}
     */
    private function collectCreditInfo(
        int $orderId,
        int $teikiId,
        int $customerId,
        array $customer,
        array $calc,
    ): array {
        $finalPaymentId = $calc['payment_id'];

        if ($finalPaymentId === self::PAYMENT_ID_CARD) {
            // クレカ (2系 L581-588)
            return ['card', [
                'order_id'      => $orderId,
                'teiki_id'      => $teikiId,
                'customer_id'   => $customerId,
                'payment_id'    => (int) ($customer['payment_id'] ?? $finalPaymentId),
                'payment_total' => $calc['payment_total'],
            ]];
        }

        if ($finalPaymentId === self::PAYMENT_ID_VAMOS) {
            // コンビニ後払い（VAMOS） (2系 L598-605)
            return ['va', [
                'order_id'    => $orderId,
                'teiki_id'    => $teikiId,
                'customer_id' => $customerId,
                'payment_id'  => (int) ($customer['payment_id'] ?? $finalPaymentId),
            ]];
        }

        if ($finalPaymentId === self::PAYMENT_ID_AMAZONPAY) {
            // AmazonPay (2系 L606-613)
            return ['amazon', [
                'order_id'      => $orderId,
                'teiki_id'      => $teikiId,
                'customer_id'   => $customerId,
                'payment_id'    => (int) ($customer['payment_id'] ?? $finalPaymentId),
                'payment_total' => $calc['payment_total'],
            ]];
        }

        // その他: 定期情報を更新 (2系 L615-616 lfUpdateTeikiInfo)
        $this->dailyTeikiUpdateService->updateTeikiInfo($orderId);

        return [null, null];
    }
}
