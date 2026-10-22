<?php

declare(strict_types=1);

namespace Customize\Service;

use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\Request;

/**
 * サンクス画面アクセス履歴登録 (2系 LC_Page_Store_Complete::lfAccessThanks_register 移植).
 *
 * 2系: data/class/pages/store/LC_Page_Store_Complete.php L1568-1605
 *
 * `dtb_access_thanks` テーブルにアクセス履歴 (顧客ID / サイト区分 / referer / IP /
 * device_type_id / brand_id / category_id) を登録する。TotalCrossService が
 * このデータを集計して管理画面「売上集計 > クロス集計」の upsell_pv / cross_pv を出力する。
 *
 * UNIQUE 制約 `(customer_id, site_type, brand_id, category_id)` により、
 * 同一顧客・同一サイト区分・同一ブランド・同一カテゴリの重複登録は防止される
 * (2系 L1591 の COUNT(*) チェックと同等)。
 *
 * サイト区分 (site_type):
 *   - 0  : アップセル (初回オファー表示)
 *   - 1  : クロスセル (追加オファー表示)
 *   - 99 : 購入完了 (最終サンクスページ)
 */
class AccessThanksLogService
{
    /** アップセル (dispCrosssell=true, isCrosssellComplete=false) */
    public const SITE_TYPE_UPSELL = 0;

    /** クロスセル (dispCrosssell=true, isCrosssellComplete=true) */
    public const SITE_TYPE_CROSSSELL = 1;

    /** 購入完了 (dispCrosssell=false) */
    public const SITE_TYPE_THANKS = 99;

    /**
     * `dtb_access_thanks.referer` / `.ip` の列長 (4系 `varchar(255)`、`\d` 実測)。
     *
     * ★2系の同列は `character varying`(長さ無制限) で、実データの referer は最大 **918文字**・
     *   255字超が **153行** (2系実測 2026-08-28、referer 非NULL 838,156行の 0.018%)。
     *   4系の列は 255 なので、切り詰めずに INSERT すると PostgreSQL が
     *   `value too long for type character varying(255)` を投げ、**購入完了画面が500になる**。
     *   この経路は従来 Path A(実測 0.20%)からしか通らず埋もれていたが、Issue #2940 で
     *   Path B(99.80%)からも通るようになるため顕在化する。
     *
     * ★切り詰めはデータ移行と同じ扱い。`scripts/data_migration/setbased/transform_dtb_access_thanks.sql`
     *   が `LEFT(a.referer, 255)` で同じ処理をしており(4系実測で ちょうど255字の行が145件ある =
     *   切られた行)、列を `text` へ広げる対応は同 SQL のコメントで**移行のスコープ外**と明記されている。
     *   本サービスもその判断に合わせる。referer は集計に使われない
     *   ({@see TotalCrossService} は customer_id と site_type だけで数える)。
     */
    private const COLUMN_MAX_LENGTH = 255;

    public function __construct(
        private readonly Connection $connection,
    ) {}

    /**
     * サンクス画面アクセス履歴を登録 (2系 lfAccessThanks_register L1568-1605 相当).
     *
     * @param bool $isShowThanks 2系 dispCrosssell (クロスセル/アップセル UI を表示するか)
     * @param bool $isShowUpsell 2系 isCrosssellComplete (crosssell_flg セッションが既にセット済か)
     */
    public function register(
        int $customerId,
        int $brandId,
        int $categoryId,
        bool $isShowThanks,
        bool $isShowUpsell,
        ?string $referer,
        ?string $ip,
        ?int $deviceTypeId,
    ): void {
        // サイト区分判定 (2系 L1575-1588)
        $siteType = $this->resolveSiteType($isShowThanks, $isShowUpsell);

        // UNIQUE チェック (2系 L1591-1592)
        $count = $this->connection->fetchOne(
            'SELECT COUNT(*) FROM dtb_access_thanks
              WHERE customer_id = :cid
                AND site_type   = :st
                AND brand_id    = :bid
                AND category_id = :catid',
            [
                'cid'   => $customerId,
                'st'    => $siteType,
                'bid'   => $brandId,
                'catid' => $categoryId,
            ],
        );

        if ((int) $count > 0) {
            return;
        }

        // INSERT (2系 L1594-1603)
        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO dtb_access_thanks
                    (access_date, customer_id, site_type, referer, ip,
                     device_type_id, brand_id, category_id)
                VALUES
                    (CURRENT_TIMESTAMP, :cid, :st, :ref, :ip,
                     :dt, :bid, :catid)
            SQL,
            [
                'cid'   => $customerId,
                'st'    => $siteType,
                // ★列長で切り詰める。切らないと 2系実測 0.018% の長い referer で
                //   INSERT が例外になり購入完了画面が500になる ({@see self::COLUMN_MAX_LENGTH})
                'ref'   => $this->truncate($referer),
                'ip'    => $this->truncate($ip),
                'dt'    => $deviceTypeId,
                'bid'   => $brandId,
                'catid' => $categoryId,
            ],
        );
    }

    /**
     * `varchar(255)` 列へ入れるために切り詰める ({@see self::COLUMN_MAX_LENGTH})。
     * PostgreSQL の `varchar(n)` は**文字数**で数えるため `mb_substr` を使う。
     */
    private function truncate(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return mb_substr($value, 0, self::COLUMN_MAX_LENGTH);
    }

    /**
     * 受注1件ぶんのサンクスPVを登録する。`register()` の呼び出しに必要な
     * 「引数ガード + category_id 解決 + device_type_id 推定 + referer/IP 採取」を
     * まとめたもの (2系 `lfAccessThanks_register()` 呼び出し1行に相当)。
     *
     * `StoreCompleteController`(Path B = 確認画面経由、実測 99.80%) と
     * `StoreCrosssellController`(Path A = 引き上げ直行、実測 0.20%) の両方から呼ぶ。
     * 元は StoreCrosssellController の private メソッドだったが、Issue #2940 で
     * Path B からも必要になったため本 Service へ引き上げた (複製を避けるため)。
     *
     * ## customer_id / brand_id / category_id が解決できない場合はスキップする
     *
     * 2系は値を検査せず INSERT するため NULL 行が実際に溜まっている
     * (2系実測 2026-08-28、`dtb_access_thanks` 全 956,115行):
     *   - `customer_id IS NULL` … 246,369行 (25.8%)
     *   - `brand_id IS NULL`    … 196,649行 (20.6%)
     *   - `category_id IS NULL` … 198,887行 (20.8%)
     * ただし集計側 {@see TotalCrossService} の upsell_pv / cross_pv は
     * `dat.customer_id = do2.customer_id` で突き合わせるため、**customer_id が NULL の行は
     * 集計に1件も寄与しない**。かつ `customer_id` が入っている行に限れば
     * `category_id IS NULL` は 322行 (0.045%)・`brand_id IS NULL` は **0行** なので、
     * スキップで失われる集計値は実質ゼロ。
     * さらに UNIQUE `(customer_id, site_type, brand_id, category_id)` は NULL を含む行を
     * 重複判定できない (PostgreSQL の NULL は互いに相異なる) ため、NULL 行は完了画面を
     * 再読込するたびに増える。よって 2系のこの挙動は踏襲しない。
     *
     * @param int|null $categoryId 2系 `$_SESSION["category_id"]` 相当。null/0 のときは
     *                             受注の先頭商品カテゴリで補完する
     *                             ({@see self::resolveCategoryIdFromOrder()})
     * @param bool $isShowThanks 2系 dispCrosssell
     * @param bool $isShowUpsell 2系 isCrosssellComplete
     */
    public function registerForOrder(
        Request $request,
        int $customerId,
        int $brandId,
        int $orderId,
        bool $isShowThanks,
        bool $isShowUpsell,
        ?int $categoryId = null,
    ): void {
        if ($customerId <= 0 || $brandId <= 0 || $orderId <= 0) {
            return;
        }

        if ($categoryId === null || $categoryId <= 0) {
            $categoryId = $this->resolveCategoryIdFromOrder($orderId);
        }
        if ($categoryId === null) {
            return;
        }

        $this->register(
            customerId:   $customerId,
            brandId:      $brandId,
            categoryId:   $categoryId,
            isShowThanks: $isShowThanks,
            isShowUpsell: $isShowUpsell,
            referer:      $request->headers->get('referer'),
            ip:           $request->getClientIp(),
            deviceTypeId: $this->detectDeviceType($request),
        );
    }

    /**
     * 注文 ID から category_id を解決.
     *
     * 2系 は `$_SESSION["category_id"]` を利用していたが、4系 StoreCrosssellController
     * のセッション構造には category_id が含まれないため、TotalCrossService と同じ SQL
     * (dtb_order_item → dtb_product LIMIT 1) で先頭商品のカテゴリを取得する。
     *
     * @return int|null 該当なしの場合 null
     */
    public function resolveCategoryIdFromOrder(int $orderId): ?int
    {
        $categoryId = $this->connection->fetchOne(
            <<<'SQL'
                SELECT dp.category_id
                  FROM dtb_order_item oi
                  INNER JOIN dtb_product dp ON oi.product_id = dp.id
                 WHERE oi.order_id = :oid
                 ORDER BY oi.id ASC
                 LIMIT 1
            SQL,
            ['oid' => $orderId],
        );

        return $categoryId !== false && $categoryId !== null ? (int) $categoryId : null;
    }

    /**
     * User-Agent から device_type_id を推定 (2系 SC_Display::detectDevice 相当).
     *
     * 2系 mtb_constants: DEVICE_TYPE_SMARTPHONE=2 / DEVICE_TYPE_PC=10 (4系 mtb_device_type と同一値)。
     * 2系 SC_SmartphoneUserAgent::isSmartphone() は "TabletはPC扱い" と明記されており、
     * タブレット/iPadを含めスマートフォン以外は全てPC(10)に分類する。TB専用区分は存在しない。
     *
     * 戻り値: 10=PC / 2=SP
     */
    public function detectDeviceType(Request $request): int
    {
        $ua = strtolower((string) $request->headers->get('User-Agent', ''));
        $isTablet = str_contains($ua, 'ipad') || str_contains($ua, 'tablet');
        if (!$isTablet && (str_contains($ua, 'iphone') || str_contains($ua, 'android') || str_contains($ua, 'mobile'))) {
            return 2; // SP
        }
        return 10; // PC (タブレット含む)
    }

    /**
     * @internal 2系 L1575-1588 の site_type 判定
     */
    private function resolveSiteType(bool $isShowThanks, bool $isShowUpsell): int
    {
        if (!$isShowThanks) {
            return self::SITE_TYPE_THANKS;
        }
        return $isShowUpsell ? self::SITE_TYPE_CROSSSELL : self::SITE_TYPE_UPSELL;
    }
}
