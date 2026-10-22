<?php

declare(strict_types=1);

namespace Customize\Service\Shipping;

/**
 * 【11月末まで】スクラブ 250g 出荷者への 200g 切替案内 (2系 cartDev `80c074e` 2026-09-17、PR #58。Issue #3779 #1)。
 *
 * 2系 `LC_Page_Admin_Shipping_Logi_Output_Ex::outputShippingWmsData()`:
 *   - L459  SELECT に `(SELECT COUNT(*) FROM dtb_order_detail dod INNER JOIN dtb_order_detail_option dodo2 ON …
 *           WHERE t1.order_id = dod.order_id AND dod.product_id IN (23,33) AND dodo2.option_detail_id = 1) AS is_scrub_250`
 *           を追加 (★`option_detail_id = 1` = ホワイトリリーだけ。2系 `c009e7d` 2026-09-24 で足された条件。
 *           旧 `80c074e` はリリー限定が無く、4系も 2026-09-28 までユズを数えていた)
 *   - L552-553 `$arrScrubInfoCustomer = []; $icon_scrubchange_info = 111;`
 *   - L1001-1010 `switch($brand_id) case 3:` の末尾 (IPコラボ Dボトル判定の**後**、`break` の直前):
 *       $is_ScrubInfo_2026 = (date('Y-m', strtotime($syuka_date)) <= '2026-11');
 *       if ($is_ScrubInfo_2026 && !empty($data["is_scrub_250"])) {
 *           if (strlen($order_icon) > 0) { $order_icon .= "　"; }
 *           $order_icon .= "スクラブ切替";
 *           $arrScrubInfoCustomer[] = (int)$data["customer_id"];
 *       }
 *   - L1317-1326 ループ後: `array_unique($arrScrubInfoCustomer)` ごとに `dtb_customer_status` へ
 *       status=111 を冪等 INSERT (IPコラボの 100〜103 と同じ形)
 *
 * ★4系の {@see WmsShippingCsvService::applyBrandRules()} はオイル/ウォッシュ/Dボトルで早期 return する
 *   ので、その中には置けない。2系はこの判定を case 3 の**素通り部分**に置いており IPコラボの結果に
 *   依存しないため、4系は `assembleRows()` で `applyBrandRules()` の**後**に brand 3 限定で当てる。
 * ★`date('Y-m', strtotime($syuka_date)) <= '2026-11'` は**文字列比較**。`$syuka_date` は
 *   `$arrParams["shipping_date"]` か `getShippingDate('repeate')` で常に入るが、万一空なら
 *   `strtotime('')=false` → `'1970-01'` で真になる。4系も `'' <= '2026-11'` が真になる形で同じ側に倒す。
 * ★product_id 23/33 は 2系 `dtb_products` の直値 (スクラブ 250g)。4系は同 id で移行済み
 *   (`transform_dtb_product.sql` は product_id をそのまま id に写す)。
 */
final class ScrubChangeNoticeRule
{
    /** 2系 L459 `dod.product_id IN (23,33)` = スクラブ 250g の product_id */
    public const SCRUB_250G_PRODUCT_IDS = [23, 33];

    /** 2系 L1002 `<= '2026-11'` (出荷月の 'Y-m' との文字列比較) */
    public const UNTIL_YEAR_MONTH = '2026-11';

    /** 2系 L553 `$icon_scrubchange_info = 111` (dtb_customer_status.status) */
    public const CUSTOMER_ICON = 111;

    /** 2系 L1008 出荷データ「注文アイコン」列へ足す文言 */
    public const ORDER_ICON_LABEL = 'スクラブ切替';

    /** 2系 L1006 区切り (全角スペース。クーリングオフ等の既存アイコン連結 = `appendCoolOffIcon()` と同じ) */
    private const SEPARATOR = '　';

    /**
     * @param string $shippingYearMonth 出荷日の 'Y-m' ({@see IpCollabBottleRule::shippingYearMonth()})。空なら 2系同様に期間内扱い
     * @param int    $scrub250Count     SQL 計算列 `is_scrub_250` (受注明細のうち 23/33 かつ香りがリリーの行数)
     */
    public static function isTarget(string $shippingYearMonth, int $scrub250Count): bool
    {
        return $shippingYearMonth <= self::UNTIL_YEAR_MONTH && $scrub250Count > 0;
    }

    /** 2系 L1005-1008 */
    public static function appendOrderIcon(string $orderIcon): string
    {
        if ($orderIcon !== '') {
            $orderIcon .= self::SEPARATOR;
        }

        return $orderIcon . self::ORDER_ICON_LABEL;
    }
}
