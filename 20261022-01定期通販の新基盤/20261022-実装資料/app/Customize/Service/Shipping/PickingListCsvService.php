<?php

declare(strict_types=1);

namespace Customize\Service\Shipping;

use Doctrine\DBAL\Connection;

/**
 * 出荷データ出力 - ピッキングリスト CSV 生成 Service.
 *
 * 2系: LC_Page_Admin_Shipping_Logi_Output_Ex::outputPickingList() を移植.
 * (data/class_extends/page_extends/admin/shipping/LC_Page_Admin_Shipping_Logi_Output_Ex.php:212-361)
 *
 * 出力仕様:
 *   - 26 列 (2系 CSV ヘッダーに準拠)
 *   - 対象: dtb_order.order_status_id IN (1, 9001) かつ dtb_shipping.shipping_date <= shipping_to
 *   - shipping_to = 今日 + shipping_num 日 (デフォルト 4 日先)
 *
 * icon01: dtb_customer_status.status=22 の顧客ステータス名 (mtb_customer_status_ex)
 * icon02: 複数ステータス (100-111,200,201,300,1,2) のスペース区切り名称列
 */
class PickingListCsvService
{
    /**
     * 「今日」の基準タイムゾーン (2系 `SC_Initial.php:488` の `date_default_timezone_set('Asia/Tokyo')` 相当).
     *
     * ★4系も **実行時の PHP 既定TZは既に `Asia/Tokyo`** である
     * (`Eccube\Kernel::boot()` L146 が `date_default_timezone_set('%timezone%')` を実行し、
     *  `%timezone%` = `env(ECCUBE_TIMEZONE)` の既定値 `Asia/Tokyo`。
     *  2026-08-28 実測: HTTP リクエストが書いた `var/log/dev/site-*.log` の行頭が `+09:00`)。
     * `docker exec ... php -r 'echo date_default_timezone_get();'` が `UTC` を返すのは
     * **カーネルを起動しない素の CLI プロセスの php.ini 既定値** (`date.timezone => UTC`) を
     * 見ているだけで、本 Service の実行時の値ではない。
     *
     * つまり現時点で日付がズレるバグは起きていない。それでも明示するのは、
     * 抽出上限日が **コア Kernel の1行に暗黙依存している** 状態を、
     * 兄弟の {@see WmsShippingCsvService::APP_TIMEZONE} と揃えて明示依存に変え、
     * 単体テストで固定できるようにするため (Issue #2823)。
     */
    private const APP_TIMEZONE = 'Asia/Tokyo';

    /** 2系 CSV ヘッダー (26 列). */
    public const CSV_HEADER = [
        '注文番号',
        '注文日',
        '商品名',
        '商品番号',
        '個数',
        '単価',
        '注文者名前',
        '注文者郵便番号',
        '注文者住所1：都道府県',
        '注文者住所2：都市区',
        '注文者住所3：町以降',
        '注文者電話番号',
        'メールアドレス',
        '決済方法',
        '配達指定日',
        '配送方法',
        '時間帯指定',
        '品代合計（税込）',
        '送料（税込）',
        '代引き手数料（税込）',
        '合計金額（税込）',
        '請求金額（税込）',
        '顧客ID',
        'アイコン1',
        'アイコン2',
        '購入回数',
    ];

    public function __construct(
        private readonly Connection $connection,
    ) {}

    /**
     * ピッキングリスト CSV 行 (ヘッダー + データ) を生成する.
     *
     * @param int                    $brandId     対象ブランド
     * @param int|null               $shippingNum 今日から何日先までを対象とするか (未指定時 4 日)
     * @param int                    $outputType  出荷対象 0=全て / 1=新規のみ / 2=リピートのみ
     *                                            (2系 `$_REQUEST["search_saisou"]` 相当)
     * @param \DateTimeImmutable|null $now        「今日」の基準時刻 (テスト用。既定は JST の現在時刻)
     *
     * @return list<list<int|string>>
     */
    public function buildRows(
        int $brandId,
        ?int $shippingNum,
        int $outputType = 0,
        ?\DateTimeImmutable $now = null,
    ): array {
        $shippingNum = ($shippingNum !== null && $shippingNum > 0) ? $shippingNum : 4;
        $shippingTo  = self::calcShippingTo($shippingNum, $now);

        $rows = [self::CSV_HEADER];

        $sql = <<<'SQL'
            SELECT
                t1.id AS order_id,
                t1.create_date,
                CASE WHEN t6.num IS NULL THEN t4.product_name
                    ELSE t4.product_name || '  ' || (
                        SELECT SUM(od2.quantity)
                          FROM dtb_order o2
                          INNER JOIN dtb_order_item od2 ON o2.id = od2.order_id
                         WHERE o2.customer_id = t1.customer_id
                           AND od2.product_id = t6.product_id
                           AND o2.order_status_id IN (1, 5, 8, 9000, 9001, 8000, 8001)
                           AND o2.id <= t1.id
                    ) || '/' || t6.num || ' セット'
                END AS product_name,
                t4.product_code,
                t4.quantity,
                -- ★金額6列は trim_scale で末尾ゼロを落とす。4系の price / subtotal ほかは numeric(12,2) で
                --   素のままだと "5980.00"。2系は桁指定なしの numeric で "5980" と出る (WMS CSV で2系本番の
                --   出力と突き合わせて判明、2026-10-02。SalesResultCommand と同じ手当て)
                trim_scale(t4.price) AS price,
                t1.name01 AS order_name01,
                t1.postal_code AS order_zip01,
                (SELECT name FROM mtb_pref WHERE id = t1.pref_id) AS order_pref,
                t1.addr01 AS order_addr01,
                t1.addr02 AS order_addr02,
                t1.phone_number AS order_tel01,
                t1.email AS order_email,
                t1.payment_method,
                s.shipping_date,
                dt3.name AS deliv_name,
                -- Issue #993: (delivery_id, time_id) で突合(PR #590 の AND id = s.time_id は本体系では誤り)。
                (SELECT delivery_time FROM dtb_delivery_time WHERE delivery_id = s.delivery_id AND time_id = s.time_id) AS shipping_time,
                trim_scale(t1.subtotal) AS subtotal,
                trim_scale(t1.delivery_fee_total) AS deliv_fee,
                trim_scale(t1.charge) AS charge,
                trim_scale(t1.total) AS total,
                trim_scale(t1.payment_total) AS payment_total,
                t1.customer_id,
                (SELECT ms.name
                   FROM dtb_customer_status cs
                   JOIN mtb_customer_status_ex ms ON cs.status = ms.id
                  WHERE cs.customer_id = t1.customer_id
                    AND cs.status = 22) AS icon01,
                ARRAY_TO_STRING(ARRAY(
                    SELECT ms.name
                      FROM dtb_customer_status cs
                      JOIN mtb_customer_status_ex ms ON cs.status = ms.id
                     WHERE cs.customer_id = t1.customer_id
                       AND cs.status IN (100,101,102,103,104,105,106,107,108,109,110,111,200,201,300,1,2)
                ), ' ') AS icon02,
                (
                    SELECT COALESCE(COUNT(*), 1)
                      FROM dtb_order od
                      INNER JOIN dtb_order_item dod ON od.id = dod.order_id
                      INNER JOIN dtb_product pc ON dod.product_id = pc.id
                     WHERE od.order_status_id IN (1, 5, 8, 9000, 9001, 217)
                       AND od.customer_id = t1.customer_id
                       AND od.id <= t1.id
                       AND pc.category_id = t5.category_id
                ) AS order_count2
              FROM dtb_order t1
              INNER JOIN dtb_shipping s ON t1.id = s.order_id
              INNER JOIN dtb_delivery dt3 ON s.delivery_id = dt3.id
              INNER JOIN dtb_order_item t4 ON t1.id = t4.order_id
              LEFT JOIN dtb_course_shibari t6 ON t4.product_id = t6.product_id AND t4.course_id = t6.course_id
              INNER JOIN dtb_product t5 ON t4.product_id = t5.id
             WHERE t1.brand_id = :brand_id
               AND t1.order_status_id IN (1, 9001)
               AND s.shipping_date <= :shipping_to
            SQL;

        $sql .= self::saisouWhere($outputType) . ' ORDER BY order_count2, t1.id DESC';

        $stmt = $this->connection->executeQuery(
            $sql,
            ['brand_id' => $brandId, 'shipping_to' => $shippingTo],
        );

        foreach ($stmt->fetchAllAssociative() as $r) {
            $rows[] = [
                (string) $r['order_id'],
                $this->fmtDateTime($r['create_date']),
                (string) $r['product_name'],
                (string) $r['product_code'],
                (string) $r['quantity'],
                (string) $r['price'],
                (string) $r['order_name01'],
                (string) $r['order_zip01'],
                (string) ($r['order_pref'] ?? ''),
                (string) $r['order_addr01'],
                (string) ($r['order_addr02'] ?? ''),
                (string) $r['order_tel01'],
                (string) $r['order_email'],
                (string) $r['payment_method'],
                $this->fmtDate($r['shipping_date']),
                (string) $r['deliv_name'],
                (string) ($r['shipping_time'] ?? ''),
                (string) $r['subtotal'],
                (string) $r['deliv_fee'],
                (string) $r['charge'],
                (string) $r['total'],
                (string) $r['payment_total'],
                (string) $r['customer_id'],
                (string) ($r['icon01'] ?? ''),
                (string) ($r['icon02'] ?? ''),
                (string) $r['order_count2'],
            ];
        }

        return $rows;
    }

    /**
     * 抽出上限日 (`s.shipping_date <= :shipping_to`) を JST 基準で組み立てる.
     *
     * 2系 `outputPickingList()` cartDev **L250** (チェックアウト中ブランチ基準):
     * `$shipping_to = date("Y-m-d 00:00:00", strtotime($arrParams["shipping_num"] . " day"));`
     * (2系は `+` を付けないが `strtotime("4 day")` は `"+4 day"` と等価)。
     * この値は L320 の `s.shipping_date <= ?` に bind される。
     *
     * ★戻り値を **オフセット無し文字列** のままにしているのは意図的。
     *   `dtb_shipping.shipping_date` は `timestamptz` で、オフセット無し文字列との比較は
     *   PostgreSQL の**セッションTZ**で解釈される。セッションTZは
     *   {@see \Customize\Doctrine\EventSubscriber\SessionTimeZoneSubscriber} が `Asia/Tokyo` に
     *   倒しており、`app/Customize` の生SQLの日付境界はすべてこれに依存している
     *   (`scripts/audit/verify_session_timezone_guard.php` が CI で機械検査)。
     *   ここで `format(\DateTimeInterface::ATOM)` 等の**明示オフセット付きに変えてはいけない**。
     */
    public static function calcShippingTo(int $shippingNum, ?\DateTimeImmutable $now = null): string
    {
        $base = ($now ?? new \DateTimeImmutable('now'))->setTimezone(new \DateTimeZone(self::APP_TIMEZONE));

        return $base->modify('+' . $shippingNum . ' day')->format('Y-m-d 00:00:00');
    }

    /**
     * 出荷対象 (新規のみ / リピートのみ) の絞込 (2系 `LC_Page_Admin_Shipping_Logi_Ex.php` L1815-1827)。
     *
     * ```php
     * if ($_REQUEST["search_saisou"] == 1) { if ($arrVal["check_cnt"] > 1)  { continue; } }  // 新規のみ
     * if ($_REQUEST["search_saisou"] == 2) { if ($arrVal["check_cnt"] == 1) { continue; } }  // リピートのみ
     * ```
     *
     * ★判定は **`dtb_order.is_first` ではなく `func_get_order_count2(order_id, 0)`**。
     *   2系は同じ「新規/リピート」でも画面ごとに別の基準を使っており、
     *   出荷データ出力 (`LC_Page_Admin_Shipping_Logi_Output_Ex.php` L1327-1331 →
     *   4系 `WmsShippingCsvService`) は `is_first` を使う。**取り違えないこと**。
     *   2系実データの定期受注 直近300件で両者は **9.3% 食い違う** (Issue #3498)。
     *
     * ★4系DBに `func_get_order_count2` は**存在しない** (Phase 3 で Repository 化された)。
     *   `OrderRepository::getOrderCount2()` と同じロジックを相関サブクエリで書き下している。
     *   行ごとに PHP から呼ぶと N+1 になるため、SQL 側で絞る
     *   (2系は全件 fetch してから PHP の `continue` で捨てているが、行の選択結果は同じ)。
     *
     * ★`teiki_id IS NULL` (非定期) は 2系関数の初期値 `intOrderCnt := 1` により **1** を返す。
     *   つまり非定期受注は「新規のみ」に含まれ「リピートのみ」からは外れる。
     */
    private static function saisouWhere(int $outputType): string
    {
        if ($outputType !== 1 && $outputType !== 2) {
            return '';
        }

        $checkCnt = <<<'SQL'
            (CASE WHEN t1.teiki_id IS NULL THEN 1 ELSE (
                SELECT COUNT(*)
                  FROM dtb_order o3
                 WHERE o3.order_status_id IN (1, 5, 8, 9000, 9001, 217, 8000, 8001)
                   AND o3.customer_id = t1.customer_id
                   AND o3.id         <= t1.id
                   AND o3.teiki_id    = t1.teiki_id
            ) END)
            SQL;

        // 新規のみ = check_cnt が 1 / リピートのみ = 1 以外
        return $outputType === 1
            ? ' AND ' . $checkCnt . ' = 1'
            : ' AND ' . $checkCnt . ' <> 1';
    }

    /** 2系 date('Y/m/d H:i:s', strtotime($x)) 相当. */
    private function fmtDateTime(mixed $x): string
    {
        if ($x === null || $x === '') {
            return '';
        }
        return (new \DateTimeImmutable((string) $x))->format('Y/m/d H:i:s');
    }

    /** 2系 date('Y/m/d', strtotime($x)) 相当 (shipping_date 用). */
    private function fmtDate(mixed $x): string
    {
        if ($x === null || $x === '') {
            return '';
        }
        return (new \DateTimeImmutable((string) $x))->format('Y/m/d');
    }
}
