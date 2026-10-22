<?php

namespace Customize\Service;

use Doctrine\ORM\EntityManagerInterface;

/**
 * 受注一覧検索 Service (2系 LC_Page_Admin_Order_Ex::buildQuery + findOrders 移植).
 *
 * **2026-08-28 (Issue #2846) で 2系 `$this->search_table` の SELECT 全項目まで拡張。**
 * 定期状況 / 顧客情報 / お届け先 / 今回商品 / 出荷状況 / 回数 / 次回以降定期商品 /
 * 定期購入日 / 注文アイコン / 顧客アイコン の21データ項目を追加し、検索条件も
 * `search_order_deliv` `search_order_all` を追加した。
 * 未移植は 2系 SELECT の `nyukin_status` のみで、これは payment_id=12 (コンビニ後払いL)
 * 専用＝廃止済み・実受注0件 (CLAUDE.md 決済実態表) のため意図的に持ち込まない。
 *
 * 移植する検索条件:
 * - search_order_id (=)
 * - search_order_all (対象顧客の全注文)
 * - search_order_deliv (配送方法)
 * - search_customer_id (=)
 * - search_order_name (dc.name01 LIKE 空白除去)
 * - search_order_addr (dc.addr01+02 LIKE 空白除去)
 * - search_order_tel (dc.phone_number LIKE 記号除去)
 * - search_order_email (dc.email LIKE)
 * - search_order_pref (dc.pref_id =)
 * - search_order_status (IN リスト)
 * - search_payment_id (IN リスト)
 * - search_total1/2 (payment_total >= / <=)
 * - search_s_orderdate / e_orderdate (create_date 範囲)
 * - search_s_shippingdate / e_shippingdate (ds.shipping_date 範囲)
 * - search_product_name (dtb_order_item.product_name LIKE EXISTS)
 *
 * **2系→4系 主要変換:**
 * - dtb_order.order_id → dtb_order.id
 * - dtb_order.status → dtb_order.order_status_id
 * - dtb_order_detail → dtb_order_item
 * - dtb_customer.tel01 → phone_number
 * - dtb_customer.pref → pref_id
 * - brand_id スコープ
 */
class OrderListSearchService
{
    public const PAGE_MAX_DEFAULT = 100;

    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {}

    /**
     * 受注一覧検索.
     *
     * memo01/02/04/06 は AmazonPay(payment_id=9) のオーソリ状況列 (Issue #1292) が使う
     * chargePermissionId/chargeId/captureId/refundId。memo08/09 (VAMOS/Paygent, #1170) と
     * 同じくSELECT側で常時取得し、呼び出し側(Controller)で payment_id==9 の行だけ
     * `AmazonPayOrderStatusService` へ渡す。
     *
     * @param array<string, mixed> $filters
     * @return array{rows: list<array<string, mixed>>, linemax: int}
     */
    public function search(int $brandId, array $filters, int $pageNo, int $pageMax): array
    {
        $conn = $this->em->getConnection();

        // 2系 LC_Page_Admin_Order_Ex.php L189 `$where = 'do2.del_flg = 0 AND do2.brand_id = ?';` 相当。
        // 4系 dtb_order に del_flg は無く (2026-08-12 実測: public スキーマに列なし)、
        // 2系で del_flg=1 を立てていた自動キャンセル 2経路
        // (フロント PendingOrderCancelService / バッチ CreditPeddingCancelCommand) が
        // 立てる is_auto_cancel を除外条件にする (Issue #552)。
        // 既存行 (移行データを含む) は NULL のため coalesce で 0 扱いにして表示を変えない。
        // ★ここで隠れるのは本一覧 (admin_order_list) だけ。コア受注一覧 admin_order は
        //   Customize で override しておらず、管理ホーム app/template/admin/index.twig L350 の
        //   url('admin_order') から到達できるので、そちらには出続ける → Issue #2020。
        $where = 'coalesce(do2.is_auto_cancel, 0) = 0 AND do2.brand_id = :brand_id';
        $params = ['brand_id' => $brandId];

        $this->buildQuery($filters, $where, $params);

        // 2系 findOrders L1202 と同様 INNER JOIN dc (会員未紐づけ受注は2系も表示しない)
        // ★ds は 2系も 4系も実データ上 1受注1行 (2026-08-28 実測: order_id を GROUP BY して
        //   count(*)>1 になる受注は 4系 1,132,842行中 0件 / 2系 1,256,646行中 0件)。
        //   したがって GROUP BY で ds.id まで含めても行は増えない。集約関数 MIN() を挟まずに
        //   ds の各列 (お届け先・配送方法・出荷状況) をそのまま取れる (Issue #2846)。
        $fromJoin = 'dtb_order do2 '
                  . 'INNER JOIN dtb_customer dc ON do2.customer_id = dc.id '
                  . 'LEFT JOIN dtb_shipping ds ON do2.id = ds.order_id';

        $linemax = (int) $conn->fetchOne(
            "SELECT COUNT(DISTINCT do2.id) FROM {$fromJoin} WHERE {$where}",
            $params,
        );

        if ($linemax === 0) {
            return ['rows' => [], 'linemax' => 0];
        }

        $offset = max(0, ($pageNo - 1) * $pageMax);
        $rows = $conn->fetchAllAssociative(
            "SELECT
                do2.id AS order_id,
                do2.customer_id,
                do2.name01,
                do2.name02,
                do2.email,
                -- 2系 LC_Page_Admin_Order_Ex の SELECT `,dc.email AS customer_email`。
                -- 一覧の「個別通知」リンクの表示条件 (index.tpl の
                -- `{if \$arrResults[cnt].customer_email|strlen >= 1}`) はこちらを見る。
                -- ★上の Smarty 変数の頭は `\$` とエスケープすること。このSQLはPHPの
                --   二重引用符文字列なので、`--` のSQLコメント内であってもドル記号は
                --   変数として展開され、PHPStan が Undefined variable を出す。
                -- 2系 dtb_order に email 列は無いため do2.email とは別物 (Issue #1846)。
                dc.email AS customer_email,
                do2.payment_id,
                do2.payment_total,
                do2.order_status_id,
                do2.create_date,
                do2.is_today_shipping,
                do2.memo01,
                do2.memo02,
                do2.memo04,
                do2.memo06,
                do2.memo08,
                do2.memo09,
                do2.teiki_id,
                -- 顧客情報セル (2系 index.tpl L520-524): 会員マスタ側の氏名・住所を出す。
                -- ★注文者名 (do2.name01/02) ではない。2系 index.tpl は注文者名の列を持たず
                --   (order_name01 を出す旧テンプレートは L640- でコメントアウト済み)、
                --   顧客情報セルに dc.name01 を出している → Issue #2846 やること4。
                dc.name01 AS customer_name,
                -- 2系 dc.zip01。2系の zip02 は 440,388件中 0件が非空 (2026-08-28 実測) なので
                -- 4系の統合列 postal_code と等価。
                dc.postal_code AS customer_zip,
                dc.pref_id AS customer_pref,
                dc.addr01 AS customer_addr01,
                dc.addr02 AS customer_addr02,
                -- お届け先セル (2系 index.tpl L575-580)。2系 ds.shipping_* → 4系 dtb_shipping の同義列。
                ds.name01 AS shipping_name01,
                ds.postal_code AS shipping_zip01,
                ds.pref_id AS shipping_pref,
                ds.addr01 AS shipping_addr01,
                ds.addr02 AS shipping_addr02,
                ds.shipping_date,
                -- 出荷状況セル: 配送方法 + 未発送/発送済み (2系 index.tpl L561-570)。
                -- ★2系は do2.deliv_id を見るが、4系コアで配送方法は dtb_shipping 側へ移った
                --   (dtb_order に delivery_id 列は無い。information_schema で0件 → Issue #2819 と同根)。
                ds.delivery_id AS deliv_id,
                ds.shipping_commit_date,
                -- 今回商品 (発送予定/確約個数)。2系 SELECT の ARRAY_TO_STRING をそのまま移植。
                -- dtb_order_detail → dtb_order_item / dod.order_detail_id → dod.id。
                -- ★明細の並び順は 2系 order_detail_id を4系へ持ち込んでいないため id 順が最善
                --   (既知の制約 → Issue #2860)。
                ARRAY_TO_STRING(
                    ARRAY(
                        SELECT (dod.product_name || func_get_order_product_option(dod.order_id, dod.id) || ' × ' || dod.quantity || '個') ||
                            CASE WHEN (dcs.num > 0)
                                THEN '　(' || func_get_order_commit_num(do2.id, do2.customer_id, dod.product_id, do2.brand_id, 0) || ' / ' || dcs.num || ')'
                                ELSE ''
                            END
                        FROM dtb_order_item dod
                        LEFT JOIN dtb_course_shibari dcs ON dod.course_id = dcs.course_id AND dod.product_id = dcs.product_id
                        WHERE dod.order_id = do2.id
                        ORDER BY dod.id
                    ), ', '
                ) AS product_name,
                -- 注文アイコン / 顧客アイコン (2系 SELECT の ARRAY_TO_STRING をそのまま移植)
                ARRAY_TO_STRING(ARRAY(SELECT mose.name FROM dtb_order_status dos INNER JOIN mtb_order_status_ex mose ON dos.status = mose.id WHERE dos.order_id = do2.id ORDER BY dos.status), ', ') AS order_icon,
                ARRAY_TO_STRING(ARRAY(SELECT mcse.name FROM dtb_customer_status dcs INNER JOIN mtb_customer_status_ex mcse ON dcs.status = mcse.id WHERE dcs.customer_id = do2.customer_id ORDER BY dcs.status), ', ') AS customer_icon,
                -- 回数 (定期購入の累計回数)。2系と同じ PG関数を呼ぶ。
                -- 4系DBの func_get_order_count は 2系と同一定義の移植版 (order_id→id / status→order_status_id)。
                func_get_order_count(do2.id, do2.customer_id) AS order_count,
                -- 次回以降定期商品 (2系 SELECT teiki_product_name)。dtb_products → dtb_product / product_id → id。
                ARRAY_TO_STRING(
                    ARRAY(
                        SELECT dp.name || func_get_teiki_product_option(dt2.teiki_id, dt2.detail_id) || ' × ' || dt2.quantity || '個'
                        FROM dtb_teiki dt2
                        INNER JOIN dtb_product dp ON dt2.product_id = dp.id
                        WHERE dt2.teiki_id = do2.teiki_id
                          AND dt2.status <> 3
                        ORDER BY dt2.next_shipping_date, dt2.detail_id
                    ), ', '
                ) AS teiki_product_name,
                (SELECT MIN(dt3.status) FROM dtb_teiki dt3 WHERE dt3.teiki_id = do2.teiki_id) AS teiki_status,
                (SELECT MIN(dt4.first_order_date) FROM dtb_teiki dt4 WHERE dt4.teiki_id = do2.teiki_id) AS teiki_order_date
            FROM {$fromJoin}
            WHERE {$where}
            -- do2 / dc / ds はいずれも PK を GROUP BY に含めているので、PostgreSQL の
            -- 関数従属 (functional dependency) により各テーブルの残り列はそのまま SELECT できる。
            GROUP BY do2.id, dc.id, ds.id
            ORDER BY do2.id DESC, do2.update_date DESC
            LIMIT :limit OFFSET :offset",
            array_merge($params, ['limit' => $pageMax, 'offset' => $offset]),
        );

        return ['rows' => $rows, 'linemax' => $linemax];
    }

    /**
     * 検索条件が1つでも指定されているか (2系 L189-201/L349-352 の `count($arrWhereVal) > 1` 相当).
     *
     * ★2系の `> 1` は「配列が空でない」ではない。2系は
     *   `$arrWhereVal = array($_SESSION["brand_id"]);` と**ブランドIDを1件入れた状態から始める**
     *   ため、`> 1` = 「buildQuery が検索条件を1つ以上足した」の意味になる。
     *   字面の `> 1` を移すのではなく、この不変条件のほうを移す必要がある。
     *   本メソッドは brand_id を含まない空の $params から buildQuery を回し、
     *   1件でも積まれたかどうかで判定する。
     *
     * 偽のときは 2系 L350-351 と同じく検索を実行せず件数0とし、
     * 「何か検索条件を指定して下さい！！」を表示する (Issue #2846 やること3)。
     * ★ガードが無いと brand 3 だけで 863,906件 (2026-08-28 実測) の全件 COUNT が毎回走る。
     *
     * @param array<string, mixed> $filters
     */
    public function hasSearchCondition(array $filters): bool
    {
        $where  = '';
        $params = [];
        $this->buildQuery($filters, $where, $params);

        return count($params) > 0;
    }

    /**
     * 2系 buildQuery (L457-) の基本検索10+項目を 4系 WHERE/params に変換.
     *
     * @param array<string, mixed> $filters
     */
    private function buildQuery(array $filters, string &$where, array &$params): void
    {
        // search_order_id (=) — 2系 L469-472
        if (!empty($filters['search_order_id'])) {
            $where .= ' AND do2.id = :order_id';
            $params['order_id'] = (int) $filters['search_order_id'];
        }
        // search_order_all (注文番号→その顧客の全注文) — 2系 L473-476
        // `AND do2.customer_id = (SELECT customer_id FROM dtb_order WHERE order_id = ?)`
        // 2系は非スカラーになり得る書き方だが order_id は PK なので常に1行。4系も id で同じ。
        if (!empty($filters['search_order_all'])) {
            $where .= ' AND do2.customer_id = (SELECT o3.customer_id FROM dtb_order o3 WHERE o3.id = :order_all)';
            $params['order_all'] = (int) $filters['search_order_all'];
        }
        // search_customer_id (=) — 2系 L477-480
        if (!empty($filters['search_customer_id'])) {
            $where .= ' AND do2.customer_id = :customer_id';
            $params['customer_id'] = (int) $filters['search_customer_id'];
        }
        // search_order_name (dc.name01 LIKE 空白除去) — 2系 L465-468
        if (!empty($filters['search_order_name'])) {
            $where .= " AND replace(replace(dc.name01, '　', ' '), ' ', '') LIKE :order_name";
            $params['order_name'] = '%' . $this->escapeLike(preg_replace('/[ 　]/u', '', (string) $filters['search_order_name'])) . '%';
        }
        // search_order_addr (addr01+addr02 LIKE 空白除去) — 2系 L485-488
        if (!empty($filters['search_order_addr'])) {
            $where .= " AND replace(replace(concat(dc.addr01, dc.addr02), '　', ' '), ' ', '') LIKE :order_addr";
            $params['order_addr'] = '%' . $this->escapeLike(preg_replace('/[ 　]/u', '', (string) $filters['search_order_addr'])) . '%';
        }
        // search_order_tel (4系 phone_number LIKE 記号除去) — 2系 L489-492 SC_SelectSql_Ex::addSearchStr 互換
        if (!empty($filters['search_order_tel'])) {
            $where .= ' AND dc.phone_number LIKE :order_tel';
            $params['order_tel'] = '%' . $this->escapeLike(preg_replace('/[()\-]+/', '', (string) $filters['search_order_tel'])) . '%';
        }
        // search_order_email (dc.email LIKE) — 2系 L493-496
        if (!empty($filters['search_order_email'])) {
            $where .= ' AND dc.email LIKE :order_email';
            $params['order_email'] = '%' . $this->escapeLike((string) $filters['search_order_email']) . '%';
        }
        // search_order_pref (4系 pref_id =) — 2系 L481-484
        if (!empty($filters['search_order_pref'])) {
            $where .= ' AND dc.pref_id = :pref_id';
            $params['pref_id'] = (int) $filters['search_order_pref'];
        }
        // search_order_status (IN リスト) — 2系 L523-540
        $statuses = $filters['search_order_status'] ?? [];
        if (is_array($statuses) && !empty($statuses)) {
            $statusInts = array_values(array_filter(array_map('intval', $statuses)));
            if (!empty($statusInts)) {
                $placeholders = [];
                foreach ($statusInts as $i => $s) {
                    $key = 'status' . $i;
                    $placeholders[] = ':' . $key;
                    $params[$key] = $s;
                }
                $where .= ' AND do2.order_status_id IN (' . implode(',', $placeholders) . ')';
            }
        }
        // search_payment_id (IN リスト) — 2系 L505-522
        $payments = $filters['search_payment_id'] ?? [];
        if (is_array($payments) && !empty($payments)) {
            $payInts = array_values(array_filter(array_map('intval', $payments)));
            if (!empty($payInts)) {
                $placeholders = [];
                foreach ($payInts as $i => $p) {
                    $key = 'pay' . $i;
                    $placeholders[] = ':' . $key;
                    $params[$key] = $p;
                }
                $where .= ' AND do2.payment_id IN (' . implode(',', $placeholders) . ')';
            }
        }
        // search_total1/2 — 2系 L541-548
        if (!empty($filters['search_total1'])) {
            $where .= ' AND do2.payment_total >= :total1';
            $params['total1'] = (int) $filters['search_total1'];
        }
        if (!empty($filters['search_total2'])) {
            $where .= ' AND do2.payment_total <= :total2';
            $params['total2'] = (int) $filters['search_total2'];
        }
        // ===== 日付範囲の bind について (Issue #2001 の検証結果。2026-08-17 実測) =====
        //
        // 以下 3 組は 2系 `LC_Page_Admin_Order_Ex::buildQuery()` (L549-581/L582-600) と同じく
        // `date('Y-m-d 00:00:00')` / `date('Y-m-d 23:59:59')` の**オフセット無し文字列**を
        // `>=` / `<=` で bind する。境界(開始日0時 / 終了日23:59:59、両端含む)も2系と同一。
        //
        // ★これは「timestamptz にオフセット無し文字列を渡している」が**正しい**ケースなので、
        //   明示オフセット付き (`Y-m-d H:i:sP`) に変えないこと。根拠:
        //
        //   - コア `Eccube\Doctrine\EventSubscriber\InitSubscriber` は postConnect で
        //     `SET TIME ZONE 'UTC'` を打つが、`Customize\Doctrine\EventSubscriber\
        //     SessionTimeZoneSubscriber` (同じ postConnect / priority -100 = 後に実行) が
        //     `SET TIME ZONE 'Asia/Tokyo'` で上書きする (Issue #1484)。コンパイル済み DI
        //     コンテナの postConnect リスナ配列が dev/prod とも
        //     `InitSubscriber` → `SessionTimeZoneSubscriber` の順であることを確認済み。
        //   - その TZ 値と PHP の `date()` が使う TZ は**同一パラメータ**由来
        //     (`%timezone%` = `env(ECCUBE_TIMEZONE)` 既定 `Asia/Tokyo` → `Eccube\Kernel::boot()`
        //     の `date_default_timezone_set()`)。片方だけ動くことがないので乖離しない。
        //   - 実測 (brand_id=3 / お届け日 2026-07-01 の1日窓):
        //     セッション JST 1,667件 = 明示 `+09:00` 1,667件 (セッション UTC なら 1,757件)。
        //     Issue #2001 が懸念した件数ズレは**起きていない**。
        //
        // ★オフセット無しで良いのは列の型ではなくセッションTZに拠るので、逆に
        //   `timestamp without time zone` 列 (`dtb_teiki.next_shipping_date`。4系も naive) にも
        //   同じ書き方で足りる。混在させる必要はない。
        //   (明示オフセットが必要なのは `Customize\Repository\OrderRepository::
        //    getDashboardErrorCounts()` のようにセッションTZに依存させない方針を採った箇所だけ)

        // search_s/e_orderdate — 2系 L549-565 (create_date)
        if (!empty($filters['search_s_orderdate'])) {
            $where .= ' AND do2.create_date >= :s_orderdate';
            $params['s_orderdate'] = date('Y-m-d 00:00:00', strtotime((string) $filters['search_s_orderdate']));
        }
        if (!empty($filters['search_e_orderdate'])) {
            $where .= ' AND do2.create_date <= :e_orderdate';
            $params['e_orderdate'] = date('Y-m-d 23:59:59', strtotime((string) $filters['search_e_orderdate']));
        }
        // search_s/e_shippingdate — 2系 L566-581 (ds.shipping_date)
        if (!empty($filters['search_s_shippingdate'])) {
            $where .= ' AND ds.shipping_date >= :s_shippingdate';
            $params['s_shippingdate'] = date('Y-m-d 00:00:00', strtotime((string) $filters['search_s_shippingdate']));
        }
        if (!empty($filters['search_e_shippingdate'])) {
            $where .= ' AND ds.shipping_date <= :e_shippingdate';
            $params['e_shippingdate'] = date('Y-m-d 23:59:59', strtotime((string) $filters['search_e_shippingdate']));
        }
        // search_order_deliv (配送方法) — 2系 L501-504 `AND do2.deliv_id = ?`
        // ★4系コアで配送方法は dtb_order から dtb_shipping へ移っているので ds.delivery_id で絞る
        //   (dtb_order に delivery_id 列は存在しない)。
        if (!empty($filters['search_order_deliv'])) {
            $where .= ' AND ds.delivery_id = :deliv_id';
            $params['deliv_id'] = (int) $filters['search_order_deliv'];
        }
        // search_product_name (dtb_order_item EXISTS) — 2系 L461-464 (dtb_order_detail → dtb_order_item)
        if (!empty($filters['search_product_name'])) {
            $where .= ' AND EXISTS (SELECT 1 FROM dtb_order_item oi WHERE oi.order_id = do2.id AND oi.product_name LIKE :product_name)';
            $params['product_name'] = '%' . $this->escapeLike((string) $filters['search_product_name']) . '%';
        }

        // search_shipping_today — 2系 L497-500 (is_today_shipping = ? AND status IN (1,9001,8))
        // 当日出荷指定済みの「受注処理中/後払い審査OK/購入処理中」のみ表示
        if (isset($filters['search_shipping_today']) && $filters['search_shipping_today'] !== '') {
            $where .= ' AND do2.is_today_shipping = :is_today_shipping'
                    . ' AND do2.order_status_id IN (1, 9001, 8)';
            $params['is_today_shipping'] = (int) $filters['search_shipping_today'];
        }

        // ===== 拡張検索 (2系 L582-643 相当) =====

        // search_s/e_nextshippingdate — 2系 L582-600 (有効定期 dtb_teiki.next_shipping_date 範囲 EXISTS)
        if (!empty($filters['search_s_nextshippingdate'])) {
            $where .= ' AND EXISTS (SELECT 1 FROM dtb_teiki tk WHERE tk.customer_id = do2.customer_id '
                    . 'AND tk.del_flg = 0 AND tk.status = 1 AND tk.next_shipping_date >= :s_nextshipping)';
            $params['s_nextshipping'] = date('Y-m-d 00:00:00', strtotime((string) $filters['search_s_nextshippingdate']));
        }
        if (!empty($filters['search_e_nextshippingdate'])) {
            $where .= ' AND EXISTS (SELECT 1 FROM dtb_teiki tk WHERE tk.customer_id = do2.customer_id '
                    . 'AND tk.del_flg = 0 AND tk.status = 1 AND tk.next_shipping_date <= :e_nextshipping)';
            $params['e_nextshipping'] = date('Y-m-d 23:59:59', strtotime((string) $filters['search_e_nextshippingdate']));
        }

        // search_order_count — 2系 L602-605 (func_get_order_count(order_id, customer_id) = ?)
        // ★2系どおり関数呼び出しにする (4系 DB の public.func_get_order_count は 2系と同一定義:
        //   同顧客の id <= do2.id かつ order_status_id IN (1,5,8,9000,9001) かつ teiki_id IS NOT NULL の件数)。
        //   以前は相関サブクエリを直接展開していたが、プランナが該当行を「20件」と見積もり、
        //   dtb_customer 47万行 × 対象受注 (例 status=5 で 82万行) を総当たりする Nested Loop を選んで
        //   2026-10-13 本番で 60分走り続けた (pg_cancel_backend で停止)。
        //   関数呼び出しだと「対象受注を1回走査して行ごとに数える」計画になり、
        //   件数取得は 42秒 (2系本番の同じ検索 38.5秒と同水準、2026-10-13 実測)。
        //   ★右辺は bigint にキャストする。購入回数欄は 13桁まで通す (2系 TEL_LEN) ので、
        //     integer のまま比べると 10桁以上で「out of range for type integer」の 500 に戻る。
        if (!empty($filters['search_order_count'])) {
            $where .= ' AND func_get_order_count(do2.id, do2.customer_id) = CAST(:order_count AS bigint)';
            $params['order_count'] = (int) $filters['search_order_count'];
        }

        // search_order_status_ex — 2系 L606-624 (dtb_order_status の注文アイコン IN リスト EXISTS)
        $statusEx = $filters['search_order_status_ex'] ?? [];
        if (is_array($statusEx) && !empty($statusEx)) {
            $statusInts = array_values(array_filter(array_map('intval', $statusEx)));
            if (!empty($statusInts)) {
                $placeholders = [];
                foreach ($statusInts as $i => $s) {
                    $key = 'osx' . $i;
                    $placeholders[] = ':' . $key;
                    $params[$key] = $s;
                }
                $where .= ' AND EXISTS (SELECT 1 FROM dtb_order_status dos '
                        . 'WHERE dos.order_id = do2.id AND dos.status IN (' . implode(',', $placeholders) . '))';
            }
        }

        // search_customer_status_ex — 2系 L625-643 (dtb_customer_status の会員アイコン IN リスト EXISTS)
        $custStatusEx = $filters['search_customer_status_ex'] ?? [];
        if (is_array($custStatusEx) && !empty($custStatusEx)) {
            $cInts = array_values(array_filter(array_map('intval', $custStatusEx)));
            if (!empty($cInts)) {
                $placeholders = [];
                foreach ($cInts as $i => $s) {
                    $key = 'csx' . $i;
                    $placeholders[] = ':' . $key;
                    $params[$key] = $s;
                }
                $where .= ' AND EXISTS (SELECT 1 FROM dtb_customer_status dcs '
                        . 'WHERE dcs.customer_id = do2.customer_id AND dcs.status IN (' . implode(',', $placeholders) . '))';
            }
        }
    }

    /**
     * LIKE 用エスケープ. % と _ をリテラル化する (2系 SC_SelectSql_Ex::addSearchStr 相当).
     *
     * PostgreSQL のデフォルト ESCAPE 文字は '\' なので backslash で囲む。
     */
    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }

    /**
     * @return array<int, string> id => name
     */
    public function loadOrderStatusMaster(): array
    {
        $rows = $this->em->getConnection()->fetchAllAssociative(
            'SELECT id, name FROM mtb_order_status ORDER BY sort_no ASC, id ASC',
        );
        $map = [];
        foreach ($rows as $r) {
            $map[(int) $r['id']] = (string) $r['name'];
        }
        return $map;
    }

    /**
     * @return array<int, string> id => payment_method
     */
    public function loadPaymentMaster(): array
    {
        $rows = $this->em->getConnection()->fetchAllAssociative(
            'SELECT id, payment_method FROM dtb_payment ORDER BY id ASC',
        );
        $map = [];
        foreach ($rows as $r) {
            $map[(int) $r['id']] = (string) $r['payment_method'];
        }
        return $map;
    }

    /**
     * VAMOS(コンビニ後払い, payment_id=13) の与信ステータスマスタ (mtb_va_status)。
     * 受注一覧「オーソリ状況」列で memo08 → 表示名の変換に使う (2系 arrVaStatus 相当。
     * 2系 LC_Page_Admin_Order_Ex L57 getMasterData('mtb_va_status'))。
     *
     * memo08 は text 列で '101' 等の文字列で入るため、Twig 側の添字一致のためキーは文字列で返す。
     *
     * @return array<string, string> memo08(文字列) => ステータス名
     */
    public function loadVaStatusMaster(): array
    {
        $rows = $this->em->getConnection()->fetchAllAssociative(
            'SELECT id, name FROM mtb_va_status ORDER BY id ASC',
        );
        $map = [];
        foreach ($rows as $r) {
            $map[(string) $r['id']] = (string) $r['name'];
        }
        return $map;
    }

    /**
     * Paygent クレジットカード(payment_id=5) の処理種別 memo09 → 表示名。
     * 2系 mdl_paygent/include.php getDispKind() のカード系エントリ相当
     * (2系 LC_Page_Admin_Order_Ex L62 arrDispKind = getDispKind())。
     *
     * ★本店の Paygent(payment_id=5) は「クレジットカード」専用 (memo03=pgynt63199、CLAUDE.md
     *   決済実態表)。getDispKind() が持つキャリア決済/電子マネー/Yahoo!ウォレット/仮想口座/後払い/Paidy
     *   等のエントリは本店では発生し得ない。廃止済み決済向けコードを持ち込まない CLAUDE.md 方針に従い
     *   カード系のみを移植する。
     *   ・実データ(2026-07-24 実測)で payment_id=5 の memo09 に現れるのは 020/021/022/023/029 の5コード
     *     (+空・一部シリアライズ異常行)。
     *   ・0201/0291(オーソリ変更処理中/売上変更処理中)は 2系 getDispKind() のカード系に含まれる
     *     過渡ステータスで、実データには未出現だが完全踏襲のため登録しておく。
     *   定数値は 2系 include.php L129-135:
     *     PAYGENT_CREDIT='020' / PAYGENT_CREDIT_PROCESSING='0201' / PAYGENT_AUTH_CANCEL='021' /
     *     PAYGENT_CARD_COMMIT='022' / PAYGENT_CARD_COMMIT_CANCEL='023' /
     *     PAYGENT_CARD_COMMIT_REVICE='029' / PAYGENT_CARD_COMMIT_REVICE_PROCESSING='0291'。
     *
     * @return array<string, string> memo09(処理種別コード) => 表示名
     */
    public function getPaygentDispKind(): array
    {
        return [
            '020'  => 'オーソリ変更',
            '0201' => 'オーソリ変更処理中',
            '021'  => 'オーソリキャンセル',
            '022'  => '売上',
            '023'  => '売上キャンセル',
            '029'  => '売上変更',
            '0291' => '売上変更処理中',
        ];
    }

    /**
     * 当日出荷依頼フラグを更新 (2系 today_ship_ins / today_ship_cancel 移植).
     *
     * 2系 L289 / L314: dtb_order.is_today_shipping = 1 / 0 を SET。
     * brand_id スコープで他ブランド受注の誤操作を防ぐ。
     *
     * @return bool 更新件数 > 0 なら true
     */
    public function setTodayShipping(int $orderId, int $brandId, bool $enabled): bool
    {
        $affected = $this->em->getConnection()->executeStatement(
            'UPDATE dtb_order SET is_today_shipping = :flag, update_date = CURRENT_TIMESTAMP '
            . 'WHERE id = :id AND brand_id = :bid',
            [
                'flag' => $enabled ? 1 : 0,
                'id'   => $orderId,
                'bid'  => $brandId,
            ],
        );
        return $affected > 0;
    }

    /**
     * 配送方法マスタ (2系 LC_Page_Admin_Order_Ex::lfGetDeliv() L1235-1249 移植)。
     *
     * 2系: `SELECT * FROM dtb_deliv WHERE del_flg = 0 ORDER BY rank` → `deliv_id => service_name`。
     * 4系: dtb_deliv → dtb_delivery / del_flg=0 → visible = true / rank → sort_no。
     * ★2026-08-28 実測で3行 (6 ゆうパケット / 2 ゆうパック / 1 佐川急便) が
     *   id・service_name・並び順まで2系 dtb_deliv と一致している。
     *
     * 一覧の「出荷状況」セルの配送会社名表示と、検索条件「配送方法」の選択肢の両方で使う。
     *
     * @return array<int, string> delivery_id => service_name
     */
    public function loadDelivMaster(): array
    {
        $rows = $this->em->getConnection()->fetchAllAssociative(
            'SELECT id, service_name FROM dtb_delivery WHERE visible = true ORDER BY sort_no ASC, id ASC',
        );
        $map = [];
        foreach ($rows as $r) {
            $map[(int) $r['id']] = (string) $r['service_name'];
        }
        return $map;
    }

    /**
     * 定期状況マスタ (mtb_teiki_status)。2系 arrTeikiStatus 相当
     * (2系 LC_Page_Admin_Order_Ex L54 getMasterData('mtb_teiki_status'))。
     * 一覧「定期状況」セルで teiki_status → 表示名の変換に使う。
     *
     * @return array<int, string> id => name
     */
    public function loadTeikiStatusMaster(): array
    {
        $rows = $this->em->getConnection()->fetchAllAssociative(
            'SELECT id, name FROM mtb_teiki_status ORDER BY sort_no ASC, id ASC',
        );
        $map = [];
        foreach ($rows as $r) {
            $map[(int) $r['id']] = (string) $r['name'];
        }
        return $map;
    }

    /**
     * 注文アイコンマスター (mtb_order_status_ex)。
     * 検索フィルタ `search_order_status_ex` の選択肢として使う (PR #135 で投入)。
     *
     * @return array<int, string> id => name
     */
    public function loadOrderStatusExMaster(): array
    {
        $rows = $this->em->getConnection()->fetchAllAssociative(
            'SELECT id, name FROM mtb_order_status_ex ORDER BY sort_no ASC, id ASC',
        );
        $map = [];
        foreach ($rows as $r) {
            $map[(int) $r['id']] = (string) $r['name'];
        }
        return $map;
    }

    /**
     * 会員アイコンマスター (mtb_customer_status_ex)。
     * 検索フィルタ `search_customer_status_ex` の選択肢として使う (PR #135 で投入)。
     *
     * @return array<int, string> id => name
     */
    public function loadCustomerStatusExMaster(): array
    {
        $rows = $this->em->getConnection()->fetchAllAssociative(
            'SELECT id, name FROM mtb_customer_status_ex ORDER BY sort_no ASC, id ASC',
        );
        $map = [];
        foreach ($rows as $r) {
            $map[(int) $r['id']] = (string) $r['name'];
        }
        return $map;
    }
}
