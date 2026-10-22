<?php
/*
 * This file is part of EC-CUBE
 *
 * Copyright(c) EC-CUBE CO.,LTD. All Rights Reserved.
 *
 * http://www.ec-cube.co.jp/
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License
 * as published by the Free Software Foundation; either version 2
 * of the License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 59 Temple Place - Suite 330, Boston, MA  02111-1307, USA.
 */

/**
 * 管理CSV出力用ヘルパークラス.
 *
 * @package Helper
 * @author pineray
 * @version $Id:$
 */
class SC_Helper_Output_Ex
{

	protected $brand_id;

	/**
	 * インスタンス
	 */
	function __construct($brand_id = null) {
		if (empty($brand_id)) {
			$this->brand_id = $_SESSION["brand_id"];
		} else {
			$this->brand_id = $brand_id;
		}
	}

	/**
	 * 【日別媒体件数】日別ASPデータ出力
	 * 
	 * @param integer $sdate	開始日
	 * @param integer $edate	終了日
	 * @param array   $arrParam	画面情報
	 * @return array
	 */
	public function getRealtimeBaitaiToAspData($sdate, $edate, $arrParam) {
		$objQuery = SC_Query_Ex::getSingletonInstance();
		// 条件値設定
		$arrWhere = array($sdate, $edate, $this->brand_id);

		$add_where = "";
		// 検索条件
		if (!empty($arrParam["search_category"])) {
			// 商品カテゴリ
			$add_where .= " AND drs.category_id = ?";
			$arrWhere[] = $arrParam["search_category"];
		}
		if (!empty($arrParam["search_asp"])) {
			// ASP
			$add_where .= " AND drs.asp_id = ?";
			$arrWhere[] = $arrParam["search_asp"];
		}
		if (!empty($arrParam["search_media"])) {
			// メディア
			$add_where .= " AND drs.media_id = ?";
			$arrWhere[] = $arrParam["search_media"];
		}
		if (!empty($arrParam["search_baitai"])) {
			// 媒体
			$add_where .= " AND drs.baitai_id = ?";
			$arrWhere[] = $arrParam["search_baitai"];
		}

		// 取得SQL
		$sql = " SELECT drs.sales_date, drs.asp_id, drs.media_id, SUM(drs.total) AS total, (trunc((sum(drs.total) * (COALESCE(MAX(drsr.rate),100) * 0.01)))) AS total2"
			 . " FROM dtb_realtime_sales drs"
			 . " LEFT JOIN dtb_realtime_sales_rate drsr ON CAST(drs.sales_date AS date) >= drsr.sales_date_from AND CAST(drs.sales_date AS date) <= drsr.sales_date_to"
			 . " WHERE drs.sales_date >= ?"
			 . " AND drs.sales_date <= ?"
			 . " AND drs.brand_id = ?"
			 . $add_where
			 . " GROUP BY drs.sales_date, drs.asp_id, drs.media_id"
			 . " ORDER BY drs.sales_date, drs.asp_id, drs.media_id"
		;
		return $objQuery->getAll($sql, $arrWhere);
	}

	/**
	 * 【日別媒体件数】日別媒体データ出力
	 * 
	 * @param integer $sales_date	集計日
	 * @param integer $asp_id		ASP
	 * @param integer $media_id		メディア
	 * @param array   $arrParam		画面情報
	 * @return array
	 */
	function getRealtimeBaitaiToBaitaiData($sales_date, $asp_id, $media_id, $arrParam) {
		$objQuery = SC_Query_Ex::getSingletonInstance();
		// 条件値設定
		$arrWhere = array($sales_date, $this->brand_id, $asp_id, $media_id);

		$add_where = "";
		// 検索条件
		if (!empty($arrParam["search_category"])) {
			// 商品カテゴリ
			$add_where .= " AND drs.category_id = ?";
			$arrWhere[] = $arrParam["search_category"];
		}
		// if (!empty($arrParam["search_media"])) {
		// 	// メディア
		// 	$add_where .= " AND drs.media_id = ?";
		// 	$arrWhere[] = $arrParam["search_media"];
		// }
		if (!empty($arrParam["search_baitai"])) {
			// 媒体
			$add_where .= " AND drs.baitai_id = ?";
			$arrWhere[] = $arrParam["search_baitai"];
		}

		// 取得SQL
		$sql = " SELECT drs.sales_date, da.name AS asp_name, dm.name AS media_name, db.name AS baitai_name, sum(drs.total) AS cv, dat.cpa"
			 . " FROM dtb_realtime_sales drs "
			 . " INNER JOIN dtb_asp da ON drs.asp_id = da.id "
			 . " INNER JOIN dtb_media dm ON drs.media_id = dm.id"
			 . " INNER JOIN dtb_baitai db ON drs.baitai_id = db.id "
			 . " LEFT JOIN dtb_asp_total dat ON drs.sales_date = dat.date AND drs.asp_id = dat.asp_id AND drs.media_id = dat.media_id AND drs.baitai_id = dat.baitai_id"
			 . " WHERE drs.sales_date = ?"
			 . " AND drs.brand_id = ?"
			 . " AND drs.asp_id = ?"
			 . " AND drs.media_id = ?"
			 . $add_where
			 . " GROUP BY drs.sales_date, da.name, dm.name, db.name, drs.media_id, drs.baitai_id, dat.cpa"
			 . " ORDER BY drs.sales_date, da.name, cv DESC, drs.media_id, drs.baitai_id"
		;
		return $objQuery->getAll($sql, $arrWhere);
	}

	/**
	 * 【違約金顧客データ】顧客データ出力
	 * 
	 * @param array   $arrParam		画面情報
	 * @return array
	 */
	function getPenaltyCustomerData($arrParam) {
		$objQuery = SC_Query_Ex::getSingletonInstance();

		// 顧客IDを分解
		$arrCustomerIds = explode("\n", $arrParam["search_customer_ids"]);
		$csv_customer_ids = implode(",", $arrCustomerIds);
		$arrWhere = array($csv_customer_ids);

		// 取得SQL
		$sql = " SELECT dc.customer_id, dc.zip01, dc.name01, (COALESCE(mp.name,'') || dc.addr01 || COALESCE(dc.addr02, '')) AS addr, dc.tel01"
			 . " FROM dtb_customer dc"
			 . " LEFT JOIN mtb_pref mp ON dc.pref = mp.id"
			 . " WHERE dc.customer_id IN (".$csv_customer_ids.")"
			 . " ORDER BY dc.customer_id"
		;
		return $objQuery->getAll($sql);
	}

	/**
	 * 【日別媒体件数】日別ASPデータ出力
	 * 
	 * @param integer $sdate	開始日
	 * @param integer $edate	終了日
	 * @param array   $arrParam	画面情報
	 * @return array
	 */
	public function getSpectrumData($sdate, $edate, $arrParam) {
		$objQuery = SC_Query_Ex::getSingletonInstance();

		// 条件値設定
		$arrWhere = array($sdate, $edate, $this->brand_id);

		// $add_where = "";
		// // 検索条件
		// if (!empty($arrParam["search_category"])) {
		// 	// 商品カテゴリ
		// 	$add_where .= " AND drs.category_id = ?";
		// 	$arrWhere[] = $arrParam["search_category"];
		// }
		// if (!empty($arrParam["search_asp"])) {
		// 	// ASP
		// 	$add_where .= " AND drs.asp_id = ?";
		// 	$arrWhere[] = $arrParam["search_asp"];
		// }

		$sql = " SELECT drs.sales_date, da.name AS asp, sum(drs.total) AS total"
			 . " FROM dtb_realtime_sales_43 drs "
			 . " INNER JOIN dtb_asp da ON drs.asp_id = da.id "
			 . " WHERE drs.sales_date BETWEEN ? AND ? "
			 . " AND drs.brand_id = ?"
			 . " GROUP BY drs.sales_date, da.name"
			 . " ORDER BY drs.sales_date, da.name"
		;
		return $objQuery->getAll($sql, $arrWhere);
	}

	/**
	 * カゴ落ち電話番号データ
	 */
	public function getCartoutData($sdate, $edate, $arrParam) {
		$objQuery = SC_Query_Ex::getSingletonInstance();

		// 条件値設定
		$arrWhere = array( $this->brand_id, $sdate, $edate);
		$add_where = "";
		// 検索条件
		if (!empty($arrParam["search_category"])) {
			// 商品カテゴリ
			$add_where .= " AND dci.category_id = ?";
			$arrWhere[] = $arrParam["search_category"];
		}

		$sql = " SELECT DISTINCT to_char(dci.create_date,'YYYY-MM-DD') AS target_date, to_char(dci.create_date,'HH24') AS target_time, dci.tel, dci.category_id, dci.referer"
			 . " FROM dtb_chat_incomplete dci"
			 . " WHERE dci.brand_id = ?"
			 . " AND dci.create_date BETWEEN ? AND ?"
			 . " AND LEFT(dci.tel,3) IN ('090','080','070')"
			 . " AND dci.order_id IS NULL"
			 . " AND dci.reject_flg = 0"
			 . $add_where
			 . " AND NOT EXISTS (SELECT NULL FROM dtb_customer dc WHERE dci.tel = dc.tel01 AND dci.brand_id = dc.brand_id)"
			 . " ORDER BY target_date, dci.category_id, dci.tel"
		;
		return $objQuery->getAll($sql, $arrWhere);
	}


	/**
	 * 【売上実績データ】月別売上実績データ出力
	 * 
	 * @param integer $sdate	開始日
	 * @param integer $edate	終了日
	 * @return array
	 */
	function getSalesAmountData($sdate, $edate) {
		$objQuery = SC_Query_Ex::getSingletonInstance();
		// 条件値設定
		$arrWhere = array($this->brand_id, $sdate, $edate);
		// 取得SQL
		$sql = " SELECT al.syuka_month, al.payment_method, SUM(CASE WHEN al.status = 5 THEN al.payment_total ELSE 0 END) AS payment_total, SUM(CASE WHEN al.status = 5 THEN al.payment_total WHEN al.status = 500 AND al.is_refond = 1 THEN FLOOR(al.payment_total * 0.8) ELSE 0 END) AS payment_total2"
			 . " FROM"
			 . " ("
			 . "	SELECT do2.order_id, do2.payment_total, do2.status, (SELECT COUNT(*) FROM dtb_order_status dos WHERE do2.order_id = dos.order_id AND dos.status = 51) AS is_refond, to_char(ds.shipping_commit_date,'YYYY年MM月') AS syuka_month, dp.payment_method"
			 . "	FROM dtb_order do2 "
			 . "	INNER JOIN dtb_shipping ds ON do2.order_id = ds.order_id INNER JOIN dtb_payment dp ON do2.payment_id = dp.payment_id"
			 . "	WHERE do2.del_flg = 0"
			 . "	AND do2.status IN (5,500)"
			 . "	AND do2.payment_total > 0"
			 . "	AND do2.brand_id = ?"
			 . "	AND ds.shipping_commit_date BETWEEN ? AND ?"
			 . " ) al"
			 . " GROUP BY syuka_month, payment_method"
			 . " ORDER BY syuka_month, payment_method"
		;
		return $objQuery->getAll($sql, $arrWhere);
	}

	/**
	 * 【月別商品出荷実績】月別商品出荷個数データ出力
	 * 
	 * @param integer $sdate	開始日
	 * @param integer $edate	終了日
	 * @return array
	 */
	function getDelivQuantityData($sdate, $edate) {
		$objQuery = SC_Query_Ex::getSingletonInstance();
		// 条件値設定
		$arrWhere = array($this->brand_id, $sdate, $edate);
		// 取得SQL
		$sql = " SELECT to_char(ds.shipping_commit_date,'YYYY年MM月') AS syuka_month, dc.category_name, SUM(dod.quantity) AS quantity"
			 . " FROM dtb_order do2 "
			 . " INNER JOIN dtb_order_detail dod ON do2.order_id = dod.order_id"
			 . " INNER JOIN dtb_shipping ds ON do2.order_id = ds.order_id"
			 . " INNER JOIN dtb_products dp ON dod.product_id = dp.product_id"
			 . " INNER JOIN dtb_category dc ON dp.category_id = dc.category_id"
			 . " WHERE do2.del_flg = 0"
			 . " AND do2.status = 5"
			 . " AND do2.brand_id = ?"
			 . " AND ds.shipping_commit_date BETWEEN ? AND ?"
			 . " AND dp.category_id NOT IN (14,15)"
			 . " GROUP BY syuka_month, dc.category_name"
			 . " ORDER BY syuka_month, dc.category_name"
		;
		return $objQuery->getAll($sql, $arrWhere);
	}

	/**
	 * 成果注文データ
	 */
	public function getResultOrderData($sdate, $edate, $arrParam) {
		$objQuery = SC_Query_Ex::getSingletonInstance();
		// 条件値設定
		$arrWhere = array($sdate, $edate, $this->brand_id);

		$add_where = "";
		// 検索条件
		if (!empty($arrParam["search_category"])) {
			// 商品カテゴリ
			if (!empty($add_where)) {$add_where .= " AND ";} else {$add_where .= " WHERE ";}
			$add_where .= "func_get_order_main_category(al.order_id) = ?";
			$arrWhere[] = $arrParam["search_category"];
		}
		if (!empty($arrParam["search_asp"])) {
			// ASP
			if (!empty($add_where)) {$add_where .= " AND ";} else {$add_where .= " WHERE ";}
			$add_where .= "al.asp_id = ?";
			$arrWhere[] = $arrParam["search_asp"];
		}
		if (!empty($arrParam["search_media"])) {
			// メディア
			if (!empty($add_where)) {$add_where .= " AND ";} else {$add_where .= " WHERE ";}
			$add_where .= "al.media_id = ?";
			$arrWhere[] = $arrParam["search_media"];
		}
		if (!empty($arrParam["search_baitai"])) {
			// 媒体
			if (!empty($add_where)) {$add_where .= " AND ";} else {$add_where .= " WHERE ";}
			$add_where .= "al.baitai_id = ?";
			$arrWhere[] = $arrParam["search_baitai"];
		}

		$sql = " SELECT"
			 . "	TO_CHAR(al.create_date,'YYYY/MM/DD HH24:MI') AS order_date,"
			 . "	al.order_id,"
			 . "	da.name AS asp,"
			 . "	dm.name AS media,"
			 . "	db.name AS baitai,"
			 . "	dca.category_name,"
			 . "	(SELECT dcs.name FROM dtb_course dcs INNER JOIN dtb_order_detail dod ON dcs.id = dod.course_id WHERE al.order_id = dod.order_id ORDER BY dod.order_detail_id LIMIT 1) AS course,"
			 . "	dp.payment_method,"
			 . "	al.is_login,"
			 . "	al.is_upsell_cancel,"
			 . "	dcsinfo.scenario_name AS abtest_scenario_name,"
			 . "	dlps.name AS lp_design_name,"
			 . "	CASE WHEN al.offer_id IS NOT NULL THEN 1 ELSE 0 END AS is_omake,"
			 . "	(SELECT dp.name FROM dtb_products dp INNER JOIN dtb_chat_scenario_gift dcsg ON dp.product_id = dcsg.gift_id WHERE dcsg.id = al.offer_id ) AS omake_name,"
			 . "	al.name01 AS customer_name"
			 . " FROM"
			 . " (	SELECT do2.create_date, do2.order_id, do2.asp_id, do2.media_id, do2.baitai_id, func_get_order_course_main_category(do2.order_id) AS category_id, dc.order_payment_id, do2.is_login, do2.is_upsell_cancel, do2.abtest_scenario_no, do2.adcode, dc.name01,"
			 . "	(SELECT dcsgm.offer_id FROM dtb_chat_scenario_gift_mapping dcsgm INNER JOIN dtb_chat_scenario_gift dcsg ON dcsgm.offer_id = dcsg.id WHERE dcsgm.asp_id = do2.asp_id AND dcsgm.baitai_id = do2.baitai_id AND dcsg.start_date <= CAST(to_char(do2.create_date,'YYYY-MM-DD') AS DATE) AND dcsg.end_date >= CAST(to_char(do2.create_date,'YYYY-MM-DD') AS DATE) AND dcsg.dlt_flg = 0 AND dcsg.category_id = func_get_order_course_main_category(do2.order_id)) AS offer_id"
			 . "	FROM dtb_order do2 INNER JOIN dtb_customer dc ON do2.customer_id = dc.customer_id"
			 . "	WHERE do2.del_flg = 0 AND do2.order_name01 NOT LIKE '%テスト%'"
			 . "	AND do2.create_date BETWEEN ? AND ?  AND do2.brand_id = ? AND do2.is_thanks = 1"
			 . "	AND NOT EXISTS (SELECT * FROM dtb_order_status dos WHERE do2.order_id = dos.order_id AND dos.status IN (601,602,603))"
			 . " ) al"
			 . " INNER JOIN dtb_asp da ON al.asp_id = da.id INNER JOIN dtb_media dm ON al.media_id = dm.id INNER JOIN dtb_baitai db ON al.baitai_id = db.id "
			 . " INNER JOIN dtb_payment dp ON al.order_payment_id = dp.payment_id INNER JOIN dtb_category dca ON al.category_id = dca.category_id LEFT JOIN dtb_chat_scenario_info dcsinfo ON al.abtest_scenario_no = dcsinfo.scenario_no LEFT JOIN dtb_lps_code dlps_code ON al.adcode = dlps_code.adcode LEFT JOIN dtb_lps dlps ON dlps_code.lp_id = dlps.id" 
			 . $add_where
			 . " ORDER BY order_date, al.order_id"
		;
		return $objQuery->getAll($sql, $arrWhere);
	}

	/**
	 * オープラックス取込データ
	 */
	public function getOpluxData($sdate, $edate, $arrParam) {
		$objQuery = SC_Query_Ex::getSingletonInstance();
		// 条件値設定
		$arrWhere = array($this->brand_id, $sdate, $edate);

		$add_where = "";
		// 検索条件
		if (!empty($arrParam["search_category"])) {
			// 商品カテゴリ
			$add_where .= " AND func_get_order_course_main_category(do2.order_id) = ?";
			$arrWhere[] = $arrParam["search_category"];
		}
		if (!empty($arrParam["search_asp"])) {
			// ASP
			$add_where .= " AND do2.asp_id = ?";
			$arrWhere[] = $arrParam["search_asp"];
		}
		if (!empty($arrParam["search_media"])) {
			// メディア
			$add_where .= " AND do2.media_id = ?";
			$arrWhere[] = $arrParam["search_media"];
		}
		if (!empty($arrParam["search_baitai"])) {
			// 媒体
			$add_where .= " AND do2.baitai_id = ?";
			$arrWhere[] = $arrParam["search_baitai"];
		}

		$sql = " SELECT"
			 . "	(SELECT dc.name FROM dtb_course dc INNER JOIN dtb_order_detail dod ON dc.id = dod.course_id WHERE dod.order_id = do2.order_id ORDER BY dod.order_detail_id LIMIT 1) AS course,"
			 . "	(SELECT dod.product_name || func_get_order_product_option(dod.order_id, dod.order_detail_id) from dtb_order_detail dod where dod.order_id = do2.order_id order by dod.order_detail_id limit 1) AS product_name,"
			 . "	do2.customer_id,"
			 . "	do2.order_name01,"
			 . "	do2.order_zip01,"
			 . "	mp.name AS pref,"
			 . "	do2.order_addr01,"
			 . "	do2.order_addr02,"
			 . "	do2.order_tel01,"
			 . "	do2.order_email,"
			 . "	dp.payment_method,"
			 . "	mos.name AS status,"
			 . "	da.name AS asp,"
			 . "	db.name AS baitai,"
			 . "	do2.order_id,"
			 . "	TO_CHAR(do2.create_date, 'YYYY/MM/DD HH24:MI') as order_date,"
			 . "	do2.payment_total,"
			 . "	(select dod.product_name || func_get_order_product_option(dod.order_id, dod.order_detail_id) from dtb_order_detail dod where dod.order_id = do2.order_id order by dod.order_detail_id limit 1) as product_name1,"
			 . "	(select dod.quantity from dtb_order_detail dod where dod.order_id = do2.order_id order by dod.order_detail_id limit 1) as quantity1,"
			 . "	(select dod.product_name || func_get_order_product_option(dod.order_id, dod.order_detail_id) from dtb_order_detail dod where dod.order_id = do2.order_id order by dod.order_detail_id offset 1 limit 1) as product_name2,"
			 . "	(select dod.quantity from dtb_order_detail dod where dod.order_id = do2.order_id order by dod.order_detail_id offset 1 limit 1) as quantity2,"
			 . "	(select dod.product_name || func_get_order_product_option(dod.order_id, dod.order_detail_id) from dtb_order_detail dod where dod.order_id = do2.order_id order by dod.order_detail_id offset 2 limit 1) as product_name3,"
			 . "	(select dod.quantity from dtb_order_detail dod where dod.order_id = do2.order_id order by dod.order_detail_id offset 2 limit 1) as quantity3"
			 . " FROM dtb_order do2"
			 . " INNER JOIN dtb_customer dcs ON do2.customer_id = dcs.customer_id"
			 . " INNER JOIN dtb_asp da ON do2.asp_id = da.id"
			 . " INNER JOIN dtb_baitai db ON do2.baitai_id  = db.id"
			 . " INNER JOIN dtb_payment dp ON dcs.order_payment_id = dp.payment_id"
			 . " INNER JOIN mtb_order_status mos ON do2.status = mos.id"
			 . " INNER JOIN mtb_pref mp ON do2.order_pref = mp.id"
			 . " WHERE do2.del_flg = 0 AND do2.order_name01 NOT LIKE '%テスト%' AND do2.brand_id = ?"
			 . " AND (do2.is_thanks = 1 OR (do2.is_thanks IS NULL AND do2.is_rest_ng_flg = 1) OR (do2.is_thanks IS NULL AND do2.status = 218))"
			 . " AND do2.create_date BETWEEN ? AND ?"
			 . $add_where
			 . " ORDER BY do2.order_id"
		;
		return $objQuery->getAll($sql, $arrWhere);
	}

	/**
	 * かご落ちOBキャンセルデータ取得
	 */
	public function getResultCancelObData($sdate, $edate, $arrParam) {
		$objQuery = SC_Query_Ex::getSingletonInstance();
		// 条件値設定
		$arrWhere = array($this->brand_id, $sdate, $edate);

		$add_where = "";
		// 検索条件
		if (!empty($arrParam["search_category"])) {
			// 商品カテゴリ
			$add_where .= " AND func_get_order_course_main_category(do2.order_id) = ?";
			$arrWhere[] = $arrParam["search_category"];
		}
		if (!empty($arrParam["search_asp"])) {
			// ASP
			$add_where .= " AND do2.asp_id = ?";
			$arrWhere[] = $arrParam["search_asp"];
		}

		$sql = " SELECT TO_CHAR(do2.create_date, 'YYYY/MM/DD') AS order_date, do2.order_id, do2.customer_id, da.name AS asp, dc.name01, dc.zip01, mp.name AS pref, dc.addr01, dc.addr02, dc.tel01, do2.status,
				 (SELECT mstr.name FROM dtb_teiki dt INNER JOIN mtb_stop_teiki_reason mstr ON dt.cancel_teiki_reason_id = mstr.id WHERE dt.teiki_id = do2.teiki_id AND dt.status = 3 AND EXISTS (SELECT NULL FROM dtb_order_detail dod WHERE dod.order_id = do2.order_id AND dt.detail_id = dod.teiki_detail_id ) LIMIT 1) AS teiki_reason
				 FROM dtb_order do2
				 INNER JOIN dtb_customer dc ON do2.customer_id = dc.customer_id 
				 INNER JOIN mtb_pref mp ON dc.pref = mp.id
				 INNER JOIN dtb_asp da ON do2.asp_id = da.id
				 WHERE do2.del_flg = 0 AND do2.brand_id = ? AND do2.status IN (3,218,500) AND do2.baitai_id = 93 AND do2.create_date BETWEEN ? AND ?"
		;
		$sql .= $add_where . " ORDER BY order_date, order_id";
		return $objQuery->getAll($sql, $arrWhere);
	}

	/**
	 * 佐川引き取り交換データ取得
	 */
	public function lfOutputSagawaExchangeData($sdate, $edate, $arrParam) {
		$objQuery = SC_Query_Ex::getSingletonInstance();
		// 条件値設定
		$arrWhere = array($sdate, $edate);
		$sql = "SELECT to_char(dos.create_date, 'YYYY/MM/DD') AS entry_date, dos.order_id,
					ARRAY_TO_STRING(
						ARRAY(	SELECT (dod.product_name || func_get_order_product_option(dod.order_id, dod.order_detail_id) || ' × ' || dod.quantity || '個')
								FROM dtb_order_detail dod WHERE dod.order_id = dos.order_id ORDER BY dod.order_detail_id
						), ', '
					) AS product_name
				FROM dtb_order_status dos INNER JOIN mtb_order_status_ex mose ON dos.status = mose.id
				WHERE dos.create_date BETWEEN ? AND ? AND dos.status = 64
				ORDER BY entry_date, order_id"
		;
		return $objQuery->getAll($sql, $arrWhere);
	}
}
