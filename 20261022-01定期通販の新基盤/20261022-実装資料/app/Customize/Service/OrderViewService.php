<?php

namespace Customize\Service;

use Doctrine\ORM\EntityManagerInterface;

/**
 * 受注詳細参照表示 Service (2系 LC_Page_Admin_Order_Edit_Ex の最小読み取り版 移植).
 *
 * 2系 Order_Edit_Ex は 4327行と巨大なため複数 PR に分割移植する。
 * 本 Service は **読み取り専用**で、受注ヘッダー / 明細 / 配送先 / 顧客 / 定期サマリ
 * の表示用データ取得のみを担当。編集・操作系は別 Service / Controller で実装。
 *
 * **2系→4系 主要変換:**
 * - dtb_order.order_id → dtb_order.id
 * - dtb_order.status → order_status_id
 * - dtb_order_detail → dtb_order_item (order_item_type_id で 1=商品 / 2=送料 / 3=手数料 区別)
 * - dtb_customer.customer_id → dtb_customer.id
 * - dtb_customer.tel01 → phone_number
 * - dtb_customer.pref → pref_id
 * - dtb_shipping.shipping_date は4系も同名
 */
class OrderViewService
{
    /** dtb_order_item.order_item_type_id: 商品 */
    public const ORDER_ITEM_TYPE_PRODUCT = 1;
    /** dtb_order_item.order_item_type_id: 送料 */
    public const ORDER_ITEM_TYPE_DELIV_FEE = 2;
    /** dtb_order_item.order_item_type_id: 手数料 */
    public const ORDER_ITEM_TYPE_CHARGE = 3;

    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {}

    /**
     * 受注ヘッダー情報を取得.
     *
     * @return array<string, mixed>|null
     */
    public function findOrder(int $orderId, int $brandId): ?array
    {
        $row = $this->em->getConnection()->fetchAssociative(
            <<<SQL
            SELECT
                o.id AS order_id,
                o.customer_id,
                o.name01, o.name02, o.kana01, o.kana02,
                o.company_name,
                o.email, o.phone_number, o.postal_code,
                o.pref_id, o.addr01, o.addr02,
                o.payment_id, o.payment_method,
                o.payment_total, o.subtotal, o.charge, o.delivery_fee_total,
                o.discount, o.tax,
                o.order_status_id,
                o.create_date, o.update_date, o.payment_date, o.commit_date,
                o.message, o.note,
                o.is_today_shipping,
                o.is_first,
                o.teiki_id,
                o.brand_id,
                o.nyukin_status, o.nyukin_date, o.ng_limit_date,
                o.memo01, o.memo02, o.memo03, o.memo04, o.memo05,
                o.memo06, o.memo07, o.memo08, o.memo09, o.memo10,
                -- VAMOS 与信フラグ (2系 edit.tpl L3272: 「更新」ボタンの表示条件 is_va_credit==1)
                o.is_va_credit,
                p.name AS pref_name,
                os.name AS order_status_name
            FROM dtb_order o
            LEFT JOIN mtb_pref p ON o.pref_id = p.id
            LEFT JOIN mtb_order_status os ON o.order_status_id = os.id
            WHERE o.id = :id AND o.brand_id = :bid
            SQL,
            ['id' => $orderId, 'bid' => $brandId],
        );
        return $row === false ? null : $row;
    }

    /**
     * 受注明細 (dtb_order_item) を取得.
     *
     * @return list<array<string, mixed>>
     */
    public function findOrderItems(int $orderId): array
    {
        return $this->em->getConnection()->fetchAllAssociative(
            <<<SQL
            SELECT
                id,
                order_item_type_id,
                product_id, product_class_id,
                product_name, product_code,
                class_category_name1, class_category_name2,
                price, quantity, tax, tax_rate,
                shipping_id
            FROM dtb_order_item
            WHERE order_id = :order_id
            ORDER BY shipping_id ASC, id ASC
            SQL,
            ['order_id' => $orderId],
        );
    }

    /**
     * 受注に紐づく配送先一覧.
     *
     * @return list<array<string, mixed>>
     */
    public function findShippings(int $orderId): array
    {
        return $this->em->getConnection()->fetchAllAssociative(
            <<<SQL
            SELECT
                s.id AS shipping_id,
                s.name01, s.name02, s.kana01, s.kana02,
                s.phone_number, s.postal_code,
                s.pref_id, s.addr01, s.addr02,
                s.shipping_date, s.delivery_date,
                s.tracking_number,
                s.delivery_id,
                p.name AS pref_name,
                d.name AS delivery_name
            FROM dtb_shipping s
            LEFT JOIN mtb_pref p ON s.pref_id = p.id
            LEFT JOIN dtb_delivery d ON s.delivery_id = d.id
            WHERE s.order_id = :order_id
            ORDER BY s.id ASC
            SQL,
            ['order_id' => $orderId],
        );
    }

    /**
     * 受注に紐づく定期サマリ (teiki_id がある場合).
     *
     * @return array<string, mixed>|null
     */
    public function findTeikiSummary(?int $teikiId): ?array
    {
        if ($teikiId === null || $teikiId <= 0) {
            return null;
        }
        $row = $this->em->getConnection()->fetchAssociative(
            <<<SQL
            SELECT
                t.teiki_id,
                t.detail_id,
                t.customer_id,
                t.product_id,
                t.course_id,
                t.next_shipping_date,
                t.interval,
                t.interval_type,
                t.quantity,
                t.discount,
                t.status,
                ts.name AS status_name,
                t.is_auto_cancel,
                -- Issue #2824: 解約理由変更UI(_teiki_operations_info.twig)の select 初期選択に必須。
                t.cancel_teiki_reason_id,
                t.deliv_id,
                t.deliv_time_id,
                t.is_deliv_manual,
                -- view.twig の置き配 select が teiki.shipping_arrangement_id を参照するため必須。
                -- 未選択だと Twig の strict_variables で Key ... does not exist となり
                -- 定期を持つ受注(実測 1,106,708 / 1,132,606件)の受注詳細が全て500になる。
                t.shipping_arrangement_id
            FROM dtb_teiki t
            LEFT JOIN mtb_teiki_status ts ON t.status = ts.id
            WHERE t.teiki_id = :teiki_id AND t.del_flg = 0
            ORDER BY t.detail_id ASC
            LIMIT 1
            SQL,
            ['teiki_id' => $teikiId],
        );
        return $row === false ? null : $row;
    }

    /**
     * 顧客サマリ (受注編集ページの右ペイン用).
     *
     * ## `c.payment_id` を引く理由 (Issue #2222)
     *
     * 受注詳細 定期ブロックの「定期 全体 支払方法変更」select の初期選択に使う。
     * 2系 `cartDev/data/Smarty/templates/admin/order/edit.tpl` L2519 は
     * `{html_options options=$arrPayment selected=$arrCustomer.payment_id}` で開いており、
     * その `$arrCustomer` は `LC_Page_Admin_Order_Edit_Ex.php` L2720 →
     * `SC_Helper_Customer.php` L282-296 が返す `dtb_customer` の行そのもの。
     * つまり初期選択の実体は `dtb_customer.payment_id`。
     *
     * ★`dtb_customer.order_payment_id` とは**別列** (`Customize\Entity\CustomerTrait`
     *   L14-15 が `order_payment_id` / L98-101 が `payment_id`)。
     * ★この列は同じ画面の送信先が書き換える当の値でもある
     *   (2系 `SC_Helper_Teiki_Ex::sfPaymentTeiki()` L562 /
     *    4系 `Customize\Service\TeikiService::setPaymentForCustomer()`)。
     *   `dtb_teiki` に payment 系の列は無い (2026-08-19 に 2系/4系とも
     *   `information_schema.columns` で 0件を確認)。
     *
     * ★この経緯を SQL 側のコメントに書かないこと。下の `<<<SQL` は**変数展開される**
     *   ヒアドキュメントなので、2系のドル記号付き変数名を書くと展開されて SQL が壊れる
     *   (CI の PHPStan が Undefined variable で検出した)。
     *
     * @return array<string, mixed>|null
     */
    public function findCustomerSummary(?int $customerId, int $brandId): ?array
    {
        if ($customerId === null || $customerId <= 0) {
            return null;
        }
        $row = $this->em->getConnection()->fetchAssociative(
            <<<SQL
            SELECT
                c.id AS customer_id,
                c.name01, c.name02, c.kana01, c.kana02,
                c.email, c.phone_number,
                c.customer_status_id,
                c.create_date,
                c.note,
                c.is_shipping_mail,
                c.order_age,
                c.customer_card_id,
                -- 定期の支払方法 select の初期選択 (Issue #2222)。詳細は docblock 参照
                c.payment_id,
                -- 顧客情報一括変更フォームの初期値 (Issue #3080)
                c.postal_code, c.pref_id, c.addr01, c.addr02,
                cs.name AS customer_status_name,
                (SELECT COUNT(*) FROM dtb_order o2
                 WHERE o2.customer_id = c.id AND o2.brand_id = c.brand_id) AS order_count_total
            FROM dtb_customer c
            LEFT JOIN mtb_customer_status cs ON c.customer_status_id = cs.id
            WHERE c.id = :id AND c.brand_id = :bid
            SQL,
            ['id' => $customerId, 'bid' => $brandId],
        );
        return $row === false ? null : $row;
    }
}
