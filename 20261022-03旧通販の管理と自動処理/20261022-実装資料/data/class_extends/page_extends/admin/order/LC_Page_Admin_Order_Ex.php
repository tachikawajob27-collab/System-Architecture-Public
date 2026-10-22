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

require_once CLASS_REALDIR . 'pages/admin/order/LC_Page_Admin_Order.php';
require_once MODULE_REALDIR . 'mdl_paygent/include.php';
require_once(dirname(__FILE__) . '/../../../../module/amazon-pay-api-sdk-php-master/vendor/autoload.php');
require_once CLASS_EX_REALDIR . 'page_extends/admin/shipping/LC_Page_Admin_Shipping_Logi_Ex.php';
require_once CLASS_EX_REALDIR . 'page_extends/admin/shipping/LC_Page_Admin_Shipping_Logi_Output_Ex.php';

/**
 * 受注管理 のページクラス(拡張).
 *
 * LC_Page_Admin_Order をカスタマイズする場合はこのクラスを編集する.
 *
 * @package Page
 * @author EC-CUBE CO.,LTD.
 * @version $Id$
 */
class LC_Page_Admin_Order_Ex extends LC_Page_Admin_Order
{
	/**
	 * Page を初期化する.
	 *
	 * @return void
	 */
	function init()
	{
		parent::init();

		$masterData = new SC_DB_MasterData_Ex();
		$this->arrOrderIcon = $masterData->getMasterData('mtb_order_status_ex');
		$this->arrCustomerIcon = $masterData->getMasterData('mtb_customer_status_ex');
		$this->arrPref = $masterData->getMasterData('mtb_pref');
		$this->arrTeikiStatus = $masterData->getMasterData('mtb_teiki_status');
		$this->arrNpStatus = $masterData->getMasterData('mtb_np_status');
		$this->arrNpRestStatus = $masterData->getMasterData('mtb_np_rest_status');
		$this->arrVaStatus = $masterData->getMasterData('mtb_va_status');
		$this->arrNyukinStatus = $masterData->getMasterData('mtb_nyukin_status');
		$this->arrDeliv = $this->lfGetDeliv();

		// ペイジェント用項目
		$this->arrDispKind = getDispKind();
		// 当日出荷対象検索
		$this->arrShippingToday = array(1 => '対象');
// 件数確認用SQL
$this->search_cnt_table = 
<<< __EOF__
SELECT COUNT(*)
FROM dtb_order do2
INNER JOIN dtb_customer dc ON do2.customer_id = dc.customer_id
INNER JOIN dtb_shipping ds ON do2.order_id = ds.order_id
__EOF__;

// 明細検索用SQL
$this->search_table = 
<<< __EOF__
SELECT
	 do2.order_id
	,do2.customer_id
	,do2.teiki_id
	,do2.create_date
	,do2.payment_id
	,do2.deliv_id
	,do2.status
	,do2.payment_total
	,dc.name01 AS customer_name
	,dc.zip01 AS customer_zip
	,dc.pref AS customer_pref
	,dc.addr01 AS customer_addr01
	,dc.addr02 AS customer_addr02
	,dc.email AS customer_email
	,dc.tel01 AS customer_tel
	,ds.shipping_name01
	,ds.shipping_zip01
	,ds.shipping_pref
	,ds.shipping_addr01
	,ds.shipping_addr02
	,ds.shipping_date
	,ds.shipping_commit_date
	,do2.memo01
	,do2.memo02
	,do2.memo04
	,do2.memo06
	,do2.memo08
	,do2.memo09
	,ARRAY_TO_STRING(
		ARRAY
		(	SELECT (dod.product_name || func_get_order_product_option(dod.order_id, dod.order_detail_id) || ' × ' || dod.quantity || '個') ||
				CASE WHEN (dcs.num > 0)
						THEN '　(' || func_get_order_commit_num(do2.order_id, do2.customer_id, dod.product_id, do2.brand_id, 0) || ' / ' ||  dcs.num || ')'
					 ELSE ''
				END
			FROM dtb_order_detail dod
			LEFT JOIN dtb_course_shibari dcs ON dod.course_id = dcs.course_id AND dod.product_id = dcs.product_id
			WHERE dod.order_id = do2.order_id
			ORDER BY dod.order_detail_id
		), ', '
	 ) AS product_name
	,ARRAY_TO_STRING(ARRAY(SELECT mose.name FROM dtb_order_status dos INNER JOIN mtb_order_status_ex mose ON dos.status = mose.id WHERE do2.order_id = dos.order_id ORDER BY dos.status),', ') AS order_icon
	,ARRAY_TO_STRING(ARRAY(SELECT mcse.name FROM dtb_customer_status dcs INNER JOIN mtb_customer_status_ex mcse ON dcs.status = mcse.id WHERE do2.customer_id = dcs.customer_id ORDER BY dcs.status),', ') AS customer_icon
	,func_get_order_count(do2.order_id, do2.customer_id) AS order_count
	,ARRAY_TO_STRING(
		ARRAY
		(	SELECT dp.name || func_get_teiki_product_option(dt2.teiki_id, dt2.detail_id) || ' × ' || dt2.quantity || '個'
			FROM dtb_teiki dt2 
			INNER JOIN dtb_products dp ON dt2.product_id = dp.product_id 
			WHERE dt2.teiki_id = do2.teiki_id 
			AND dt2.status <> 3
			ORDER BY dt2.next_shipping_date, dt2.detail_id
		), ', '
	) AS teiki_product_name
	,(SELECT MIN(dt3.status) FROM dtb_teiki dt3 WHERE dt3.teiki_id = do2.teiki_id) AS teiki_status
	,(SELECT MIN(dt4.first_order_date) FROM dtb_teiki dt4 WHERE dt4.teiki_id = do2.teiki_id) AS teiki_order_date
	,do2.is_today_shipping
	,do2.nyukin_status
FROM
	dtb_order do2
INNER JOIN dtb_customer dc ON do2.customer_id = dc.customer_id
INNER JOIN dtb_shipping ds ON do2.order_id = ds.order_id
__EOF__;
	}

	/**
	 * Page のプロセス.
	 *
	 * @return void
	 */
	function process()
	{
		$this->action();
		$this->sendResponse();
		//parent::process();
	}

	/**
	 * Page のアクション.
	 *
	 * @return void
	 */
	public function action()
	{
		$objFormParam = new SC_FormParam_Ex();
		$this->lfInitParam($objFormParam);
		$objFormParam->setParam($_POST);
		$objFormParam->convParam();
		$objFormParam->trimParam();
		$this->arrHidden = $objFormParam->getSearchArray();
		$this->arrForm = $objFormParam->getFormParamList();
		$objPurchase = new SC_Helper_Purchase_Ex();

		$mode = $this->getMode();
		switch ($mode) {
			case 'tele_ai_upload':
				$this->doUploadTeleAiCsv();
				break;
			case "csv":
			case 'today_ship_ins':
			case 'today_ship_cancel':
			case "csv_output_all":
			case "auth_entry_all":
			case "csv_output_invoice_all":
			case "search":
				$objFormParam->convParam();
				$objFormParam->trimParam();
				$this->arrErr = $this->lfCheckError($objFormParam);
				$arrParam = $objFormParam->getHashArray();

				if (count($this->arrErr) == 0) {
					$where = 'do2.del_flg = 0 AND do2.brand_id = ?';
					$arrWhereVal = array($_SESSION["brand_id"]);
					$is_search_param = false;
					foreach ($arrParam as $key => $val) {
						if ($val == '') {
							continue;
						}
						$this->buildQuery($key, $where, $arrWhereVal, $objFormParam);
					}
					$order = 'do2.order_id DESC, do2.update_date DESC';

					// 条件配列に何かしら追加があれば検索条件ありと判定する
					if (count($arrWhereVal) > 1) {
						$is_search_param = true;
					}

					/* -----------------------------------------------
					* 処理を実行
					* ----------------------------------------------- */
					if ($is_search_param) {
						switch ($mode) {
							// CSV出力
							case 'csv':
								break;

							case "csv_output_all":
								// WMS出荷データ出力
								if ($_SESSION["brand_id != 3"]) {
									$objShipping = new LC_Page_Admin_Shipping_Logi_Ex();
									$objShipping->reprint_flg = 0;
									if (isset($_POST["order_check"])) {
										// 配送会社を確認
										$order_ids = implode( ',',$_POST["order_check"]);
										$where = "t1.order_id IN (".$order_ids.")";
										$objShipping->doOutputWmsCSV($where);
										exit;
									}
								} else {
									$objShipping = new LC_Page_Admin_Shipping_Logi_Output_Ex();
									if (isset($_POST["order_check"])) {
										$arrParams["order_check"] = $_POST["order_check"];
										$arrParams["output_type"] = "9";
										$arrParams["shipping_date"] = date("Y-m-d");
										$objShipping->outputShippingWmsData($arrParams);
										exit;
									}
								}
								break;
							case "csv_output_invoice_all":
								// VAMOS請求書印字データ一括取得
								if ($_SESSION["brand_id != 3"]) {
									if (isset($_POST["order_check"])) {
										$order_ids = implode( ',',$_POST["order_check"]);
										GC_Utils_Ex::gfPrintLog(print_r($order_ids,true));
										$objShipping = new LC_Page_Admin_Shipping_Logi_Ex();
										$objShipping->doOutputInvoiceVamosCSV($order_ids);
										exit;
									}
								} else {
									if (isset($_POST["order_check"])) {
										$order_ids = implode( ',',$_POST["order_check"]);
										GC_Utils_Ex::gfPrintLog(print_r($order_ids,true));
										$arrParams["order_ids"] = $order_ids;
										$objShipping = new LC_Page_Admin_Shipping_Logi_Output_Ex();
										$objShipping->outputInvoiceVamosCSV($arrParams);
										exit;
									}
								}
								break;
							case "auth_entry_all":
								// 一括与信登録
								$order_ids = $_POST["order_check"];
								$arrErr = array();

								$objQuery = SC_Query_Ex::getSingletonInstance();
								foreach ($order_ids as $order_id) {
									$payment_id = $objQuery->getOne("SELECT payment_id FROM dtb_order WHERE order_id = ?", array($order_id));
									$ret = $objPurchase->orderAuthoriEntry($order_id, $payment_id);
									if (!$ret) {
										$arrErr[] = $order_id;
									}
								}
								if (empty($arrErr)) {
									// 与信登録正常完了
									$this->tpl_onload = "window.alert('与信登録が正常に完了しました。');";
								} else {
									$message = "与信登録が完了しました。\\n\\n";
									$message .= "▼失敗した注文番号一覧\\n";
									foreach($arrErr as $err) {
										$message .= "・".$err."\\n";
									}
									$this->tpl_onload = "window.alert('" . $message . "');";
								}
								break;

							// ▼当日出荷依頼関連 -----------------------------------------
							case 'today_ship_ins':
								// 当日出荷登録
								$objQuery = SC_Query_Ex::getSingletonInstance();
								$order_id = $_REQUEST["order_id"];
								$objQuery->update("dtb_order",array("is_today_shipping" => 1),"order_id = ?",array($order_id));
								$message = "当日出荷依頼に登録しました。";
								$this->tpl_onload = "window.alert('" . $message . "');";

								// 検索
								// 行数の取得
								$_POST["mode"] = "search";
								$this->tpl_linemax = $this->getNumberOfLines($where, $arrWhereVal);
								// ページ送りの処理
								$page_max = SC_Utils_Ex::sfGetSearchPageMax($objFormParam->getValue('search_page_max'));
								// ページ送りの取得
								$objNavi = new SC_PageNavi_Ex($this->arrHidden['search_pageno'],
															$this->tpl_linemax, $page_max,
															'fnNaviSearchPage', NAVI_PMAX);
								$this->arrPagenavi = $objNavi->arrPagenavi;

								// 検索結果の取得
								$this->arrResults = $this->findOrders($where, $arrWhereVal,
																	$page_max, $objNavi->start_row, $order);
								break;

							case 'today_ship_cancel':
								// 当日出荷削除
								$objQuery = SC_Query_Ex::getSingletonInstance();
								$order_id = $_REQUEST["order_id"];
								$objQuery->update("dtb_order",array("is_today_shipping" => 0),"order_id = ?",array($order_id));
								$message = "当日出荷依頼を解除しました。";
								$this->tpl_onload = "window.alert('" . $message . "');";

								// 検索
								// 行数の取得
								$_POST["mode"] = "search";
								$this->tpl_linemax = $this->getNumberOfLines($where, $arrWhereVal);
								// ページ送りの処理
								$page_max = SC_Utils_Ex::sfGetSearchPageMax($objFormParam->getValue('search_page_max'));
								// ページ送りの取得
								$objNavi = new SC_PageNavi_Ex($this->arrHidden['search_pageno'],
															$this->tpl_linemax, $page_max,
															'fnNaviSearchPage', NAVI_PMAX);
								$this->arrPagenavi = $objNavi->arrPagenavi;

								// 検索結果の取得
								$this->arrResults = $this->findOrders($where, $arrWhereVal,
																	$page_max, $objNavi->start_row, $order);
								break;
							// ▲当日出荷依頼関連 -----------------------------------------

							// 検索実行
							default:
								// 行数の取得
								$this->tpl_linemax = $this->getNumberOfLines($where, $arrWhereVal);
								// ページ送りの処理
								$page_max = SC_Utils_Ex::sfGetSearchPageMax($objFormParam->getValue('search_page_max'));
								// ページ送りの取得
								$objNavi = new SC_PageNavi_Ex($this->arrHidden['search_pageno'],
																$this->tpl_linemax, $page_max,
																'eccube.moveNaviPage', NAVI_PMAX);
								$this->arrPagenavi = $objNavi->arrPagenavi;
	
								// 検索結果の取得
								$this->arrResults = $this->findOrders($where, $arrWhereVal,
																		$page_max, $objNavi->start_row, $order);
								break;
						}
					} else {
						// 検索条件してなしはNGとする
						$this->tpl_linemax = 0;
						$this->tpl_onload = "window.alert('何か検索条件を指定して下さい！！');";
					}
				}
				break;
		}

		// 「検索条件をさらに表示する」が有効になっていない場合は、非表示の処理を追加する
		if ($this->arrForm['search_other_order_search_flg']['value'] != '1') {
			$this->tpl_onload .= "changeToggleSearchForm();";
		}
	}

	/**
	 * 入力内容のチェックを行う.
	 *
	 * @param  SC_FormParam $objFormParam SC_FormParam インスタンス
	 * @return array
	 */
	public function lfCheckError(&$objFormParam)
	{
		$objErr = new SC_CheckError_Ex($objFormParam->getHashArray());
		$objErr->arrErr = $objFormParam->checkError();

		// 相関チェック
		$objErr->doFunc(array('購入金額1', '購入金額2', 'search_total1', 'search_total2'), array('GREATER_CHECK'));

		// 受注日
		$objErr->doFunc(array('受注開始日', 'search_s_orderdate'), array('CHECK_DATE_FORMAT'));
		$objErr->doFunc(array('受注終了日', 'search_e_orderdate'), array('CHECK_DATE_FORMAT'));
		$objErr->doFunc(array('受注開始日', '受注終了日', 'search_s_orderdate', 'search_e_orderdate'), array('CHECK_DATE_TERM'));

		// お届け日
		$objErr->doFunc(array('お届け開始日', 'search_s_shippingdate'), array('CHECK_DATE_FORMAT'));
		$objErr->doFunc(array('お届け終了日', 'search_e_shippingdate'), array('CHECK_DATE_FORMAT'));
		$objErr->doFunc(array('お届け開始日', 'お届け終了日', 'search_s_shippingdate', 'search_e_shippingdate'), array('CHECK_DATE_TERM'));

		// 次回お届け日
		$objErr->doFunc(array('次回お届け開始日', 'search_s_nextshippingdate'), array('CHECK_DATE_FORMAT'));
		$objErr->doFunc(array('次回お届け終了日', 'search_e_nextshippingdate'), array('CHECK_DATE_FORMAT'));
		$objErr->doFunc(array('次回お届け開始日', 'お届け終了日', 'search_s_nextshippingdate', 'search_e_nextshippingdate'), array('CHECK_DATE_TERM'));

		return $objErr->arrErr;
	}

	/**
	 * パラメーター情報の初期化を行う.（カスタム）
	 *
	 * @param  SC_FormParam $objFormParam SC_FormParam インスタンス
	 * @return void
	*/
	public function lfInitParam(&$objFormParam)
	{
		$objFormParam->addParam('注文番号', 'search_order_id', INT_LEN, 'n', array('MAX_LENGTH_CHECK', 'NUM_CHECK'));
		$objFormParam->addParam('注文番号（対象顧客の全注文検索）', 'search_order_all', INT_LEN, 'n', array('MAX_LENGTH_CHECK', 'NUM_CHECK'));
		$objFormParam->addParam('顧客ID', 'search_customer_id', INT_LEN, 'n', array('MAX_LENGTH_CHECK', 'NUM_CHECK'));
		$objFormParam->addParam('対応状況', 'search_order_status', INT_LEN, 'n', array('MAX_LENGTH_CHECK', 'NUM_CHECK'));
		$objFormParam->addParam('配送方法', 'search_order_deliv', INT_LEN, 'n', array('MAX_LENGTH_CHECK', 'NUM_CHECK'));
		$objFormParam->addParam('顧客名', 'search_order_name', STEXT_LEN, 'KVa', array('MAX_LENGTH_CHECK'));
		$objFormParam->addParam('都道府県', 'search_order_pref', INT_LEN, 'n', array('MAX_LENGTH_CHECK', 'NUM_CHECK'));
		$objFormParam->addParam('市区町村', 'search_order_addr', STEXT_LEN, 'KVa', array('MAX_LENGTH_CHECK'));
		$objFormParam->addParam('メールアドレス', 'search_order_email', STEXT_LEN, 'KVa', array('MAX_LENGTH_CHECK'));
		$objFormParam->addParam('電話番号', 'search_order_tel', TEL_LEN, 'n', array('NUM_CHECK', 'MAX_LENGTH_CHECK'));
		$objFormParam->addParam('支払方法', 'search_payment_id', INT_LEN, 'n', array('MAX_LENGTH_CHECK', 'NUM_CHECK'));
		$objFormParam->addParam('注文アイコン', 'search_order_status_ex', INT_LEN, 'n', array('MAX_LENGTH_CHECK', 'NUM_CHECK'));
		$objFormParam->addParam('顧客アイコン', 'search_customer_status_ex', INT_LEN, 'n', array('MAX_LENGTH_CHECK', 'NUM_CHECK'));
		$objFormParam->addParam('購入金額1', 'search_total1', INT_LEN, 'n', array('MAX_LENGTH_CHECK', 'NUM_CHECK'));
		$objFormParam->addParam('購入金額2', 'search_total2', INT_LEN, 'n', array('MAX_LENGTH_CHECK', 'NUM_CHECK'));
		$objFormParam->addParam('購入回数', 'search_order_count', TEL_LEN, 'n', array('NUM_CHECK', 'MAX_LENGTH_CHECK'));
		$objFormParam->addParam('購入商品名', 'search_product_name', STEXT_LEN, 'KVa', array('MAX_LENGTH_CHECK'));
		$objFormParam->addParam('表示件数', 'search_page_max', INT_LEN, 'n', array('MAX_LENGTH_CHECK', 'NUM_CHECK'));
		// 受注日
		$objFormParam->addParam('受注日(開始)', 'search_s_orderdate', STEXT_LEN, 'KVa', array('MAX_LENGTH_CHECK'));
		$objFormParam->addParam('受注日(終了)', 'search_e_orderdate', STEXT_LEN, 'KVa', array('MAX_LENGTH_CHECK'));
		// お届け予定日
		$objFormParam->addParam('お届け予定日(開始)', 'search_s_shippingdate', STEXT_LEN, 'KVa', array('MAX_LENGTH_CHECK'));
		$objFormParam->addParam('お届け予定日(終了)', 'search_e_shippingdate', STEXT_LEN, 'KVa', array('MAX_LENGTH_CHECK'));
		// 次回お届け予定日
		$objFormParam->addParam('お届け予定日(開始)', 'search_s_nextshippingdate', STEXT_LEN, 'KVa', array('MAX_LENGTH_CHECK'));
		$objFormParam->addParam('お届け予定日(終了)', 'search_e_nextshippingdate', STEXT_LEN, 'KVa', array('MAX_LENGTH_CHECK'));
		
		$objFormParam->addParam('ページ送り番号', 'search_pageno', INT_LEN, 'n', array('MAX_LENGTH_CHECK', 'NUM_CHECK'));
		$objFormParam->addParam('受注ID', 'order_id', INT_LEN, 'n', array('MAX_LENGTH_CHECK', 'NUM_CHECK'));
		$objFormParam->addParam("検索条件をさらに表示する", "search_other_order_search_flg");

		$objFormParam->addParam("本日出荷対象", "search_shipping_today", INT_LEN, 'n', array("MAX_LENGTH_CHECK", "NUM_CHECK"));
	}

	/**
	 * クエリを構築する.
	 *
	 * 検索条件のキーに応じた WHERE 句と, クエリパラメーターを構築する.
	 * クエリパラメーターは, SC_FormParam の入力値から取得する.
	 *
	 * 構築内容は, 引数の $where 及び $arrValues にそれぞれ追加される.
	 *
	 * @param  string       $key          検索条件のキー
	 * @param  string       $where        構築する WHERE 句
	 * @param  array        $arrValues    構築するクエリパラメーター
	 * @param  SC_FormParam $objFormParam SC_FormParam インスタンス
	 * @return void
	 */
	public function buildQuery($key, &$where, &$arrValues, &$objFormParam)
	{
		$dbFactory = SC_DB_DBFactory_Ex::getInstance();
		switch ($key) {
			case 'search_product_name':
				$where .= ' AND EXISTS (SELECT NULL FROM dtb_order_detail od WHERE od.order_id = do2.order_id AND od.product_name LIKE ?)';
				$arrValues[] = sprintf('%%%s%%', $objFormParam->getValue($key));
				break;
			case 'search_order_name':
				$where .= " AND replace(replace(dc.name01,'　',' '),' ','') LIKE ?";
				$arrValues[] = sprintf('%%%s%%', preg_replace('/[ 　]/u', '', $objFormParam->getValue($key)));
				break;
			case 'search_order_id':
				$where .= ' AND do2.order_id = ?';
				$arrValues[] = sprintf('%d', $objFormParam->getValue($key));
				break;
			case 'search_order_all':
				$where .= ' AND do2.customer_id = (SELECT customer_id FROM dtb_order WHERE order_id = ?)';
				$arrValues[] = sprintf('%d', $objFormParam->getValue($key));
				break;
			case 'search_customer_id':
				$where .= ' AND do2.customer_id = ?';
				$arrValues[] = sprintf('%d', $objFormParam->getValue($key));
				break;
			case 'search_order_pref':
				$where .= ' AND dc.pref = ?';
				$arrValues[] = sprintf('%d', $objFormParam->getValue($key));
				break;
			case 'search_order_addr':
				$where .= " AND replace(replace(" . $dbFactory->concatColumn(array('dc.addr01', 'dc.addr02')) . ",'　',' '),' ','') LIKE ?";
				$arrValues[] = sprintf('%%%s%%', preg_replace('/[ 　]/u', '', $objFormParam->getValue($key)));
				break;
			case 'search_order_tel':
				$where .= " AND dc.tel01 LIKE ?";
				$arrValues[] = SC_SelectSql_Ex::addSearchStr(preg_replace('/[()-]+/', '', $objFormParam->getValue($key)));
				break;
			case 'search_order_email':
				$where .= ' AND dc.email LIKE ?';
				$arrValues[] = sprintf('%%%s%%', $objFormParam->getValue($key));
				break;
			case 'search_shipping_today':
				$where .= " AND do2.is_today_shipping = ? AND do2.status IN (1,9001,8)";
				$arrValues[] = sprintf('%d', $objFormParam->getValue($key));
				break;
			case 'search_order_deliv':
				$where .= ' AND do2.deliv_id = ?';
				$arrValues[] = sprintf('%d', $objFormParam->getValue($key));
				break;
			case 'search_payment_id':
				$tmp_where = '';
				foreach ($objFormParam->getValue($key) as $element) {
					if ($element != '') {
						if ($tmp_where == '') {
							$tmp_where .= ' AND (do2.payment_id = ?';
						} else {
							$tmp_where .= ' OR do2.payment_id = ?';
						}
						$arrValues[] = $element;
					}
				}

				if (!SC_Utils_Ex::isBlank($tmp_where)) {
					$tmp_where .= ')';
					$where .= " $tmp_where ";
				}
				break;
			case 'search_order_status':
					$tmp_where = '';
					foreach ($objFormParam->getValue($key) as $element) {
						if ($element != '') {
							if ($tmp_where == '') {
								$tmp_where .= ' AND (do2.status = ?';
							} else {
								$tmp_where .= ' OR do2.status = ?';
							}
							$arrValues[] = $element;
						}
					}
	
					if (!SC_Utils_Ex::isBlank($tmp_where)) {
						$tmp_where .= ')';
						$where .= " $tmp_where ";
					}
					break;
			case 'search_total1':
				$where .= ' AND do2.payment_total >= ?';
				$arrValues[] = sprintf('%d', $objFormParam->getValue($key));
				break;
			case 'search_total2':
				$where .= ' AND do2.payment_total <= ?';
				$arrValues[] = sprintf('%d', $objFormParam->getValue($key));
				break;
			case 'search_s_orderdate':
				// $date = SC_Utils_Ex::sfGetTimestamp($objFormParam->getValue('search_sorderyear'),
				// 									$objFormParam->getValue('search_sordermonth'),
				// 									$objFormParam->getValue('search_sorderday'));

				$date = date("Y-m-d 00:00:00", strtotime($objFormParam->getValue($key)));
				$where.= ' AND do2.create_date >= ?';
				$arrValues[] = $date;
				break;
			case 'search_e_orderdate':
				// $date = SC_Utils_Ex::sfGetTimestamp($objFormParam->getValue('search_eorderyear'),
				// 									$objFormParam->getValue('search_eordermonth'),
				// 									$objFormParam->getValue('search_eorderday'), true);
				$date = date("Y-m-d 23:59:59", strtotime($objFormParam->getValue($key)));
				$where.= ' AND do2.create_date <= ?';
				$arrValues[] = $date;
				break;
			case 'search_s_shippingdate':
				// $date = SC_Utils_Ex::sfGetTimestamp($objFormParam->getValue('search_supdateyear'),
				// 									$objFormParam->getValue('search_supdatemonth'),
				// 									$objFormParam->getValue('search_supdateday'));
				$date = date("Y-m-d 00:00:00", strtotime($objFormParam->getValue($key)));
				$where.= ' AND ds.shipping_date >= ?';
				$arrValues[] = $date;
				break;
			case 'search_e_shippingdate':
				// $date = SC_Utils_Ex::sfGetTimestamp($objFormParam->getValue('search_eupdateyear'),
				// 									$objFormParam->getValue('search_eupdatemonth'),
				// 									$objFormParam->getValue('search_eupdateday'), true);
				$date = date("Y-m-d 23:59:59", strtotime($objFormParam->getValue($key)));
				$where.= ' AND ds.shipping_date <= ?';
				$arrValues[] = $date;
				break;
			case 'search_s_nextshippingdate':
				$date = date("Y-m-d 00:00:00", strtotime($objFormParam->getValue($key)));
				$where.= " AND EXISTS"
						." (	SELECT * FROM dtb_teiki tk"
						."		WHERE tk.customer_id = do2.customer_id"
						."		AND (tk.del_flg = 0 AND tk.status = 1 AND tk.next_shipping_date >= ?)"
						." )"
				;
				$arrValues[] = $date;
				break;
			case 'search_e_nextshippingdate':
				$date = date("Y-m-d 23:59:59", strtotime($objFormParam->getValue($key)));
				$where.= " AND EXISTS"
						." (	SELECT * FROM dtb_teiki tk"
						."		WHERE tk.customer_id = do2.customer_id"
						."		AND (tk.del_flg = 0 AND tk.status = 1 AND tk.next_shipping_date <= ?)"
						." )"
				;
				$arrValues[] = $date;
				break;
			case 'search_order_count':
				$where.= ' AND func_get_order_count(do2.order_id, do2.customer_id) = ?';
				$arrValues[] = sprintf('%d', $objFormParam->getValue($key));
				break;
			case 'search_order_status_ex':
				$tmp_where = " AND EXISTS(SELECT NULL FROM dtb_order_status dos WHERE do2.order_id = dos.order_id AND dos.status IN (";
				$tmp_where2 = "";
				foreach ($objFormParam->getValue($key) as $element) {
					if ($element != '') {
						if ($tmp_where2 == '') {
							$tmp_where2 .= '?';
						} else {
							$tmp_where2 .= ',?';
						}
						$arrValues[] = $element;
					}
				}

				if (!SC_Utils_Ex::isBlank($tmp_where2)) {
					$tmp_where .= $tmp_where2.'))';
					$where .= " $tmp_where ";
				}
				break;
			case 'search_customer_status_ex':
				$tmp_where = " AND EXISTS(SELECT NULL FROM dtb_customer_status dcs WHERE do2.customer_id = dcs.customer_id AND dcs.status IN (";
				$tmp_where2 = "";
				foreach ($objFormParam->getValue($key) as $element) {
					if ($element != '') {
						if ($tmp_where2 == '') {
							$tmp_where2 .= '?';
						} else {
							$tmp_where2 .= ',?';
						}
						$arrValues[] = $element;
					}
				}

				if (!SC_Utils_Ex::isBlank($tmp_where2)) {
					$tmp_where .= $tmp_where2.'))';
					$where .= " $tmp_where ";
				}
				break;
			default:
				break;
		}
	}
		/**
     * TeleAI受注CSV一括取り込み（コース設定解析・商品ID抽出版）
     */
    public function doUploadTeleAiCsv()
    {
        GC_Utils_Ex::gfPrintLog("【TeleAI】インポート開始（コース設定解析版）");

        if (!isset($_FILES['csv_file']) || $_FILES['csv_file']['error'] != UPLOAD_ERR_OK) {
            SC_Utils_Ex::sfDispError(INVALID_FILE_NAME);
            return;
        }

        $fp = fopen($_FILES['csv_file']['tmp_name'], 'r');
        $objQuery = SC_Query_Ex::getSingletonInstance();

		$objPurchase = new SC_Helper_Purchase_Ex();
        $payment_map = array('後払い' => 13);
        
        if (property_exists($objQuery, 'ignore_error')) {
            $objQuery->ignore_error = true;
        }
        
        $objQuery->query("ROLLBACK");

        $brand_id = $_SESSION["brand_id"];
        $masterData = new SC_DB_MasterData_Ex();
        $arrPrefFlip = array_flip($masterData->getMasterData('mtb_pref'));

        $count = 0; $line = 0; $error_count = 0;
		$success_count = 0; $skip_count = 0; $error_count = 0;

		// シーケンス同期
		$objQuery->query("SELECT setval('dtb_order_order_id_seq', (SELECT MAX(order_id) FROM dtb_order))");
		$objQuery->query("SELECT setval('dtb_customer_customer_id_seq', (SELECT MAX(customer_id) FROM dtb_customer))");
		$objQuery->query("SELECT setval('dtb_order_detail_order_detail_id_seq', (SELECT MAX(order_detail_id) FROM dtb_order_detail))");
		$objQuery->query("SELECT setval('dtb_teiki_teiki_id_seq', (SELECT MAX(teiki_id) FROM dtb_teiki))");
		$objQuery->query("SELECT setval('dtb_teiki_detail_id_seq', (SELECT MAX(detail_id) FROM dtb_teiki))");

        while (($data = fgetcsv($fp, 10000, ",")) !== FALSE) {
            $line++;
            if ($line == 1) continue; 

            foreach ($data as $key => $val) {
                // SJIS-win (Windowsの機種依存文字含むSJIS) から UTF-8 へ変換
                // 第3引数に複数の候補を入れることで、検知の精度を上げます
                $data[$key] = mb_convert_encoding($val, 'UTF-8', 'SJIS-win, CP932, SJIS, UTF-8');
            }

            $objQuery->query("ROLLBACK");
            $objQuery->query("BEGIN"); 

			try {
                // --- 1. CSVデータ抽出とIDの事前発行 ---

                // $create_date = date("Y-m-d H:i:s", strtotime($data[1]));　着信日時は無視する
				$teleai_order_id = trim($data[0]);
				if ($teleai_order_id === '') {
					throw new Exception("必須項目エラー：注文ID(A列)が空です。");
				}
				// 重複チェックを追加
				$exists_count = $objQuery->getOne(
					"SELECT COUNT(*) FROM dtb_order WHERE teleai_order_id = ?", 
					array($teleai_order_id)
				);

				if ($exists_count > 0) {
					// 重複している場合は例外を投げて、catchブロックへ飛ばす
					throw new Exception("スキップ：teleai_order_id [{$teleai_order_id}] は既に登録済みです。");
				}
				$order_id    = $objQuery->nextVal('dtb_order_order_id');
				$create_date = date("Y-m-d H:i:s");
				$update_date = date("Y-m-d H:i:s");
                $zip01       = preg_replace('/[^0-9]/', '', $data[2]);
				if ($zip01=== '') {
					throw new Exception("必須項目エラー：注文ID(C列)が空です。");
				}
                $pref_name   = trim($data[3]);
				if ($pref_name === '') {
					throw new Exception("必須項目エラー：注文ID(C列)が空です。");
				}
                $pref_id     = isset($arrPrefFlip[$pref_name]) ? (int)$arrPrefFlip[$pref_name] : 0;
                
                // 住所の分割
                $full_address = trim($data[4]) . trim($data[5]);
				// 市区町村・番地が全くない場合例外スロー
                if ($full_address === '') {
                    throw new Exception("必須項目エラー：住所(E/F列)が空です。");
                }
                if (preg_match('/^(.*?[^\dー−])([\d０-９ー－−].*)$/u', $full_address, $matches)) {
                    $addr01 = trim($matches[1]); $addr02 = trim($matches[2]);
                } else {
                    $addr01 = $full_address; $addr02 = '';
                }
				// 分割の結果、addr01が空になってしまった場合例外スロー
                if ($addr01 === '') {
                    throw new Exception("必須項目エラー：住所1(addr01)を特定できません。");
                }
                // 1. まず請求先氏名（index 6）を取得
                $name01 = trim($data[6]);
                
                // 2. 請求先が空なら発送先氏名（index 13）を取得
                if (empty($name01)) {
                    $name01 = trim($data[13]);
                }
                
                // 3. それでも空なら、カナ（index 7）を最終手段として使う 不要？
                if (empty($name01)) {
                    $name01 = trim($data[7]);
                }
				if (empty($name01)) {
					throw new Exception("必須項目エラー：氏名が特定できません（漢字・カナともに空です）");
				}

                // --- カナ（kana01）の取得ロジック ---
                $kana01 = trim($data[7]);
                if (empty($kana01)) {
                    $kana01 = trim($data[14]); // 発送先カナ
                }
                $kana01 = trim($data[7]);
                $tel01  = preg_replace('/[^0-9]/', '', $data[8]);
				if ($tel01 === '') {
                    throw new Exception("必須項目エラー：電話番号を特定できません。");
                }

                $email  = trim($data[16]);
                
                if (empty($email)) {
                    $brand_pref  = $objQuery->getOne("SELECT code_prefix FROM dtb_brand WHERE id = ?", array($brand_id));
                    $mail_domain = $objQuery->getOne("SELECT mail_domain FROM dtb_baseinfo LIMIT 1");
                    $email       = SC_Utils_Ex::sfGetDummyAddress($mail_domain, $brand_pref);
                }

				// --- 支払い方法の判定（後払いの名称変換） ---
					// 1. まず共通の処理（CSVの文字取得と、マップからのID取得）を済ませる
				$pay_method_csv     = trim($data[17]);
				if ($pay_method_csv === '') {
                    throw new Exception("必須項目エラー：支払い方法(R列)が空です。");
                }
				$current_payment_id = isset($payment_map[$pay_method_csv]) ? $payment_map[$pay_method_csv] : 13; // マップになければ13
				$pay_method         = $pay_method_csv; // 基本はCSVの文字をそのまま使う

				// 2. 「後払い」の時だけ、名称を上書きする
				if ($pay_method_csv === '後払い') {
					$pay_method = 'コンビニ後払い';
					// IDは既に上の isset で 13 が入っているはずなので、上書き不要（もし不安ならここで代入してもOK）
				}

                // マップに存在すればそのID、なければデフォルトで13（後払い）
                $current_payment_id = isset($payment_map[$pay_method]) ? $payment_map[$pay_method] : 13;
                $deliv_method = trim($data[18]);
				if ($deliv_method === '') {
                    throw new Exception("必須項目エラー：配送方法(S列)が空です。");
                }
                $deliv_fee    = (float)$data[19];
				if ($deliv_fee === '') {
                    throw new Exception("必須項目エラー：送料（T列）が空です。");
                }
                $charge       = (int)$data[20];
				if ($charge === '') {
                    throw new Exception("必須項目エラー：手数料（U列）が空です。");
                }
				$total		  = (float)$data[21];
                $item_code    = trim($data[26]);
				if ($item_code === '') {
                    throw new Exception("必須項目エラー：商品コード(AA列)が空です。");
                }
                $price        = (float)$data[27];
                $quantity     = (int)$data[29];

				// --- 2. コース設定の解析と金額の再計算（② への対応） ---
                $code_parts    = explode('-', $item_code);
                $course_id     = (int)$code_parts[0];
                $opt_detail_id = isset($code_parts[1]) ? (int)$code_parts[1] : 0;

				// --- 2. 金額の再計算（② 小計の定義修正） ---
				$course_details_1st = $objQuery->select("*", "dtb_course_detail", "course_id = ? AND order_count = 1", array($course_id));

				$item_total_tax_exclude = 0; // 税抜の商品合計
				// --- 2. 計算規則（端数処理）をシステムから取得 ---
				$current_tax_rule = $objQuery->getOne("SELECT tax_rule FROM dtb_baseinfo");

				$item_total_tax_exclude = 0; // 税抜の商品合計
				$total_tax = 0;              // 消費税の合計
				$first_interval = 0;

				// コースに含まれる商品をループして税率ごとに計算
				foreach ($course_details_1st as $cd) {
					$target_pid = (int)$cd['product_id'];

					// 1. 商品マスタから tax_status (税率ID) を取得
					$tax_status_id = (int)$objQuery->getOne("SELECT tax_status FROM dtb_products WHERE product_id = ?", array($target_pid));

					// 2. mtb_taxrule から実際の税率（10 など）を取得
					$tax_rate_val = $objQuery->getOne("SELECT name FROM mtb_taxrule WHERE id = ?", array($tax_status_id));
					$current_rate = (int)$tax_rate_val / 100; // 10 -> 0.1

					// 3. 税抜単価 × 数量 を合算
					$item_price_exclude = $cd['price'] * $cd['quantity'];
					$item_total_tax_exclude += $item_price_exclude;

					// 4. 消費税額を合算（端数処理前）
					$total_tax += $item_price_exclude * $current_rate;
					// 0より大きい interval があればそれを採用する（上書き防止）
					if ((int)$cd['interval'] > 0) {
						$first_interval = (int)$cd['interval'];
					}
				}

				// 5. システムの計算規則（tax_rule）に従って端数処理（0:切り捨て 1:四捨五入 2:切り上げ）
				if ($current_tax_rule == 1) {
					$tax = round($total_tax);
				} elseif ($current_tax_rule == 2) {
					$tax = ceil($total_tax);
				} else {
					$tax = floor($total_tax);
				}

				// 6. 【指定の定義】小計 ＝ 税抜商品合計 ＋ 税
				$subtotal = $item_total_tax_exclude + $tax;

				// 7. 合計金額 ＝ 小計 ＋ 手数料 ＋ 送料
				$total = $subtotal + $charge + $deliv_fee;
                // 定期IDをセットで事前確保
                $teiki_id = null; $teiki_detail_id = null;
                $teiki_courses = array(26, 27, 29, 42); 
                if (in_array($course_id, $teiki_courses)) {
                    $teiki_id = $objQuery->nextVal('dtb_teiki_teiki_id');
                    $teiki_detail_id = $objQuery->nextVal('dtb_teiki_detail_id');
                }

                // コース設定から product_id を特定し、商品テーブルからマスターの名前を取得
                $course_row = $objQuery->getOne("SELECT main_setting FROM dtb_course WHERE id = ?", array($course_id));
                $settings   = explode('/', $course_row); 
                $product_id = isset($settings[1]) ? (int)$settings[1] : 0;

				// 商品IDを確定させる（これより下でクエリに使うため）
                $target_pid_final = (int)$product_id; 

				// 商品マスタから tax_status を取得
                $master_sql = "name, product_code, tax_status FROM dtb_products WHERE product_id = " . $target_pid_final;
                $res_master_row = $objQuery->getRow($master_sql);

                $tax_status_id = (int)$res_master_row['tax_status'];

                // --- 2. mtb_taxrule から税率をそのまま取得 ---
                $tax_rate_val = $objQuery->getOne("SELECT name FROM mtb_taxrule WHERE id = ?", array($tax_status_id));
                $current_tax_rate = (int)$tax_rate_val; // 型変換のみ実施

                // --- 3. dtb_baseinfo（アンダーバーなし）から計算規則をそのまま取得 ---
                $current_tax_rule = $objQuery->getOne("SELECT tax_rule FROM dtb_baseinfo");

                // $objPurchase はループ外で生成済みの SC_Helper_Purchase_Ex インスタンス
                $shipping_date_raw = $objPurchase->lfGetHolidayOfShippingDate(date("Y/m/d H:i:s"));
                $shipping_date     = date('Y-m-d', strtotime($shipping_date_raw));

                // --- 2. 【修正版】dtb_course_detail から配送間隔を取得 ---
                $interval_sql = "interval, interval_type FROM dtb_course_detail 
                                 WHERE course_id = {$course_id} 
                                 AND product_id = {$target_pid_final} 
                                 AND order_count = 1";
                
                $teiki_setting = $objQuery->getRow($interval_sql);

                $final_interval = (int)$teiki_setting['interval'];
                $final_type     = (int)$teiki_setting['interval_type'];

                // --- 3. 次回配送予定日の計算（⑤ への対応） ---
                // 起点を $create_date ではなく、算出した $shipping_date に変更
                $next_shipping_date = date('Y-m-d', strtotime($shipping_date . " +{$final_interval} days"));

                // --- 3. 商品名を取得する（既存の処理） ---
                $direct_sql = "name, product_code FROM dtb_products WHERE product_id = " . $target_pid_final;
                $res_master_row = $objQuery->getRow($direct_sql);

				// 1. SQL文を変数に入れず、かつ既存の変数名と被らない名前で結果を受ける
                $target_pid_final = (int)$product_id; 
                $direct_sql = "name, product_code FROM dtb_products WHERE product_id = " . $target_pid_final;
                
                // 第1引数にこの文字列を渡します
                $res_master_row = $objQuery->getRow($direct_sql);

                if (!$res_master_row) {
                    $res_master_row = array('name' => trim($data[25]), 'product_code' => $item_code);
                }

				// --- 3. 顧客登録・特定 ---
				$customer_id = $objQuery->nextVal('dtb_customer_customer_id');
                    $objQuery->insert("dtb_customer", array(
                        'customer_id' => $customer_id, 
                        'name01'      => $name01, 
                        'kana01'      => $kana01, 
                        'tel01'       => $tel01, 
                        'email'       => $email,
                        'zip01'       => $zip01, 
                        'pref'        => $pref_id, 
                        'addr01'      => $addr01, 
                        'addr02'      => $addr02, 
                        'brand_id'    => $brand_id,
                        'create_date' => $create_date, 
                        'update_date' => $update_date, 
                        'del_flg'     => 0,
                        'country_id'  => 392,
                        'status'      => 3,
						'reminder'    => 1,
                        'salt'        => SC_Utils_Ex::sfGetRandomString(10), 
                        'secret_key'  => SC_Helper_Customer::sfGetUniqSecretKey(),
						'buy_times'   => 1,
						'buy_total'	  => $total,
						'mailmaga_flg'=> 3,
						'payment_id'         => $current_payment_id,
                        'payment_method'     => $pay_method,   
                        'order_payment_id'   => $current_payment_id, 
						'model_first_charge' => $charge,
						'first_buy_date' => $create_date,
						'last_buy_date'  => $create_date,
                    ));
                    GC_Utils_Ex::gfPrintLog("【TeleAI】顧客新規登録成功: ID={$customer_id}");

				// --- 3.5 顧客コードのインサート
				// クラスをインスタンス化
				$objEdit = new LC_Page_Admin_Order_Edit_Ex();
				// メソッドを呼び出す
				$random_suffix = $objEdit->makeRandStr(8);
				$objQuery->insert("dtb_customer_code", array(
					'customer_id' => $customer_id,
					'code'		  => $order_id . $random_suffix,
					'create_date' => $create_date
				));
				GC_Utils_Ex::gfPrintLog("【TeleAI】顧客新規登録コード発行: ID={$customer_id}");

                // --- 4. 受注・明細・配送の登録 ---
                
                // 受注 (dtb_order)
                $objQuery->insert("dtb_order", array(
                    'order_id' => $order_id, 'teleai_order_id' => $teleai_order_id, 'customer_id' => $customer_id, 'order_name01' => $name01, 'order_kana01' => $kana01, 'order_email' => $email, 'order_tel01' => $tel01,
                    'order_zip01' => $zip01, 'order_pref' => $pref_id, 'order_addr01' => $addr01, 'order_addr02' => $addr02,
                    'payment_id' => $current_payment_id, 'payment_method' => $pay_method,
                    'deliv_id' => (strpos($deliv_method, 'ゆうパケット') !== false) ? 6 : 2, 
                    'deliv_fee' => $deliv_fee, 'charge' => $charge, 'subtotal' => $subtotal, 'total' => $total, 'tax' => $tax, 'payment_total' => $total, 
                    'status' => 1, 'teiki_id' => $teiki_id, 'brand_id' => $brand_id, 'asp_id' => 72, 'media_id' => 36, 'baitai_id' => 92, 
                    'create_date' => $create_date, 'update_date' => $update_date, 'device_type_id' => 80, 'is_first' => 1, 'is_thanks' => 1, 'del_flg' => 0
                ));

				// --- 4. 受注明細の登録（④ セットプラン対応のループ） ---
                
                foreach ($course_details_1st as $cd) {
                    $target_pid = (int)$cd['product_id'];
                    
                    // 明細IDを商品ごとに新しく発行
                    $order_detail_id = $objQuery->nextVal('dtb_order_detail_order_detail_id');

                    // 各商品のマスタ情報を取得
                    // --- 修正箇所：SELECT を削除 ---
                    $res_product = $objQuery->getRow("name, product_code, tax_status FROM dtb_products WHERE product_id = " . $target_pid);
                    
                    // 商品ごとの税率を取得
                    $tax_rate = (int)$objQuery->getOne("SELECT name FROM mtb_taxrule WHERE id = ?", array((int)$res_product['tax_status']));

                    // 明細テーブル（dtb_order_detail）へ登録
                    $objQuery->insert("dtb_order_detail", array(
                        'order_detail_id' => $order_detail_id,
                        'order_id'        => $order_id,
                        'product_id'      => $target_pid,
                        'product_name'    => $res_product['name'],
                        'product_code'    => $res_product['product_code'],
                        'price'           => $cd['price'],      // マスタの単価
                        'quantity'        => $cd['quantity'],   // マスタの数量
                        'tax_rate'        => $tax_rate,
                        'tax_rule'        => $current_tax_rule,
                        'teiki_detail_id' => $teiki_detail_id,
                        'course_id'       => $course_id
                    ));

				// 【重要】初回分の香り登録（38番シークレットプレゼント以外の場合のみ）
				// 【設定】香りオプションを自動登録する対象の商品ID
                // 12: オイル
                // 13: ウォッシュ
                // 41: リンクルエッセンス
                // ※これら以外（シークレットプレゼント等）は、たとえ名前やカッコ内に香り情報があっても無視します
                $target_product_ids = array(12, 13, 41);

                // $opt_detail_id があり、かつ「対象の商品ID」の場合のみ実行
                if ($opt_detail_id > 0 && in_array($target_pid, $target_product_ids)) {
                    
                    // DBからオプションIDを取得
                    // （例：商品が「オイル」で、選択肢が「ベルガモット＆ウッド」の場合、その紐付けIDを取得）
                    $option_id = $objQuery->getOne("SELECT option_id FROM dtb_products_option WHERE option_detail_id = ? AND del_flg = 0", array($opt_detail_id));
                    
                    if ($option_id) {
                        $objQuery->insert("dtb_order_detail_option", array(
                            'order_id'         => $order_id,
                            'order_detail_id'  => $order_detail_id,
                            'option_id'        => $option_id,
                            'option_detail_id' => $opt_detail_id,
                            'create_date'      => $create_date,
                        ));
                    }
					}
                }
				// --- 4. メイン商品の登録ループ終了 ---

				// --- 4.5 シークレットプレゼント(ID:60)を「1受注につき1回だけ」追加 ---
				$res_present = $objQuery->getRow("name, product_code, tax_status FROM dtb_products WHERE product_id = 60");

				if ($res_present) {
					// プレゼント用の明細IDを新しく発行
					$order_detail_id_60 = $objQuery->nextVal('dtb_order_detail_order_detail_id');
					
					$objQuery->insert("dtb_order_detail", array(
						'order_detail_id' => $order_detail_id_60,
						'order_id'        => $order_id,
						'product_id'      => 60,
						'product_name'    => $res_present['name'],
						'product_code'    => $res_present['product_code'],
						'price'           => 0,    // 単価0円
						'quantity'        => 1,    // 1個固定
						'tax_rate'        => 0,
						'tax_rule'        => $current_tax_rule,
						'course_id'       => $course_id
					));
					
					GC_Utils_Ex::gfPrintLog("【TeleAI】受注ID:{$order_id} にプレゼント(ID:60)を1件追加しました。");
				}

                // 配送 (dtb_shipping)
                $objQuery->insert("dtb_shipping", array(
                    'shipping_id' => 0, 'order_id' => $order_id, 'shipping_name01' => $name01, 'shipping_kana01' => $kana01, 'shipping_tel01' => $tel01,
                    'shipping_zip01' => $zip01, 'shipping_pref' => $pref_id, 'shipping_addr01' => $addr01, 'shipping_addr02' => $addr02,
                    'deliv_id' => (strpos($deliv_method, 'ゆうパケット') !== false) ? 6 : 2, 
                    'shipping_time' => $data[22], 'shipping_date' => $shipping_date,
                    'create_date' => $create_date, 'update_date' => $update_date, 'del_flg' => 0
                ));

				// --- 修正箇所： 与信失敗時に成果フラグ(is_thanks)のみを null にする ---
				if ($pay_method === 'コンビニ後払い') {
					$objVamos = new SC_Helper_Mdl_VAMOS_Ex();
					
					// 即時与信リクエストの実行
					$ret = $objVamos->requestVaCredit($order_id, null, null, true);
					
					// 与信結果が false だった場合のみ、成果フラグを削除
					if ($ret === false) {
						// ステータスやmemoの更新はシステムに任せ、成果除外のみ手動で行う
						$objQuery->update(
							"dtb_order", 
							array("is_thanks" => null), 
							"order_id = ?", 
							array($order_id)
						);
						
						GC_Utils_Ex::gfPrintLog("【TeleAI】与信失敗(false): is_thanksをnullに更新しました。 order_id={$order_id}");
					}
				}
				
				// --- 5. 定期登録 (⑥ 重複エラー回避版) ---
                if ($teiki_id) {
                    $course_details_2nd = $objQuery->select("*", "dtb_course_detail", "course_id = ? AND order_count = 2", array($course_id));

                    if (!empty($course_details_2nd)) {
                        foreach ($course_details_2nd as $cd2) {
                            $current_teiki_detail_id = $objQuery->nextVal('dtb_teiki_detail_id');
                            $target_pid_2nd = (int)$cd2['product_id'];

                            // 2回目のお届け日は「1回目の設定（25日）」で上書き計算
                            $next_date = date('Y-m-d', strtotime($shipping_date . " +{$first_interval} days"));

                            $objQuery->insert("dtb_teiki", array(
                                'teiki_id'           => $teiki_id,
                                'detail_id'          => $current_teiki_detail_id,
                                'customer_id'        => $customer_id,
                                'product_id'         => $target_pid_2nd,
                                'quantity'           => $cd2['quantity'],
                                'deliv_id'           => (strpos(trim($data[18]), 'ゆうパケット') !== false) ? 6 : 2,
                                'next_shipping_date' => $next_date,       // ここが 2026/03/02 になる
                                'interval'           => (int)$cd2['interval'], // ここに 50 が入る
                                'first_order_id'     => $order_id,
                                'first_order_date'   => $create_date,
                                'interval_type'      => $cd2['interval_type'],
                                'status'             => 1,
                                'brand_id'           => $brand_id,
                                'course_id'          => $course_id,
                                'create_date'        => $create_date,
                                'update_date'        => $update_date,
                                'del_flg'            => 0
                            ));
							// --- ⑦ 定期オプション（シークレットプレゼント除外ロジック込） ---
							// 【重要】定期分の香り登録（38番シークレットプレゼント以外の場合のみ）
							// ※ ここで使う変数 $target_pid_2nd と、登録先テーブル dtb_teiki_option に注目！
							if ($opt_detail_id > 0 && $target_pid_2nd !== 38) {
								$opt_id_2 = $objQuery->getOne("SELECT option_id FROM dtb_products_option WHERE option_detail_id = ? AND del_flg = 0", array($opt_detail_id));
								if ($opt_id_2) {
									$objQuery->insert("dtb_teiki_option", array(
										'teiki_id'         => $teiki_id,
										'teiki_detail_id'  => $current_teiki_detail_id,
										'option_id'        => $opt_id_2,
										'option_detail_id' => $opt_detail_id,
										'create_date'      => $create_date
									));
								}
							}
						}
					} else {
						GC_Utils_Ex::gfPrintLog("【TeleAI】警告：コースID {$course_id} の2回目設定が見つかりません。");
					}
				}

                $objQuery->query("COMMIT");
				GC_Utils_Ex::gfPrintLog("【TeleAI Success】[注文ID: {$teleai_order_id}]");
                $success_count++;

			} catch (Exception $e) {
					$objQuery->rollback();
					$msg = $e->getMessage();
					if (strpos($msg, '既に登録済み') !== false) {
							// --- 重複スキップ時もログを出す ---
							$skip_count++;
							GC_Utils_Ex::gfPrintLog("【TeleAI Skip】[注文ID: {$teleai_order_id}] " . $msg);
					} else {
						$error_count++;
						GC_Utils_Ex::gfPrintLog("【TeleAI Error】[注文ID: {$teleai_order_id}] " . $msg);
					}
			}
		} // ← whileループ終了

		// --- 全件終わってからリダイレクト ---
		$url = "index.php?mode=complete&success={$success_count}&skip={$skip_count}&error={$error_count}";
		SC_Response_Ex::sendRedirect($url);
		exit;
    }

	/**
	 * 検索結果の行数を取得する.
	 *
	 * @param  string  $where     検索条件の WHERE 句
	 * @param  array   $arrValues 検索条件のパラメーター
	 * @return integer 検索結果の行数
	 */
	public function getNumberOfLines($where, $arrValues)
	{
		$objQuery = SC_Query_Ex::getSingletonInstance();
		$select = $this->search_cnt_table . " WHERE " . $where;
		return $objQuery->getOne($select, $arrValues);
	}

	/**
	 * 受注を検索する.
	 *
	 * @param  string  $where     検索条件の WHERE 句
	 * @param  array   $arrValues 検索条件のパラメーター
	 * @param  integer $limit     表示件数
	 * @param  integer $offset    開始件数
	 * @param  string  $order     検索結果の並び順
	 * @return array   受注の検索結果
	 */
	public function findOrders($where, $arrValues, $limit, $offset, $order)
	{
		$objPurchase = new SC_Helper_Purchase_Ex();
		$objQuery = SC_Query_Ex::getSingletonInstance();
		if ($limit != 0) {
			$objQuery->setLimitOffset($limit, $offset);
		}

		$select = $this->search_table . " WHERE " . $where . " ORDER BY " . $order;
GC_Utils_Ex::gfPrintLog("SQL：". $select);
		$arrResults = $objQuery->getAll($select, $arrValues);

		// AmazonPayのみ抜粋した配列を作成
		$arrAmazon = array();
		$arrAmazon = array_keys(array_column($arrResults, 'payment_id','order_id'),9);
//GC_Utils_Ex::gfPrintLog_r("arrAmazon：", $arrAmazon);

		$this->arrAmazonStatus = array();
		foreach ($arrAmazon as $key => $order_id) {
			$arrOrder = $objPurchase->getOrder($order_id);
			$this->arrAmazonStatus[$order_id] = $this->getAmazonpayStatus($arrOrder);
		}
		unset($arrAmazon);
		unset($arrOrder);

		return $arrResults;
	}

	/**
	 * 配送方法一覧を取得
	 *
	 * @return array   配送方法配列
	 */
	public function lfGetDeliv() {
		$objQuery = SC_Query_Ex::getSingletonInstance();
		$objQuery->setOrder("rank");
		$arrData = $objQuery->select("*", "dtb_deliv", "del_flg = 0");

		$arrDeliv = array();
		foreach($arrData as $data) {
			$arrDeliv[$data["deliv_id"]] = $data["service_name"];
		}

		unset($arrData);
		unset($data);
		return $arrDeliv;
	}

	// AmazonPayステータス
	function getAmazonpayStatus($row) {
		$retStatus = "";

		/* amazon pay cv2 関連の処理
		-----------------------*/
		$config = $GLOBALS['amazon_payment_config'];
		$amazon_client = new Amazon\Pay\API\Client($config);
		$amazon_charge_permission_id = $row['memo01']; // 注文ID
		$amazon_charge_id= $row['memo02']; // 取引ID

		$amazon_capture_id = $row['memo04'];
		$amazon_refund_id = $row['memo06'];

		// v1用の対応 => 21番目の文字を文字「A」から文字「C」に変更
		$amazon_charge_id = substr_replace($amazon_charge_id, 'C', 20,1);

		// ステータス取得
		if($amazon_capture_id){
			$amazon_get_id = $amazon_capture_id;
		}
		else{
			$amazon_get_id = $amazon_charge_id;
		}

		$amazon_response_object = $amazon_client->getCharge($amazon_get_id);

		$amazon_response = json_decode($amazon_response_object['response']);

		$amazonpay_status = strval($amazon_response->statusDetails->state);
		$code = strval($amazon_response->statusDetails->reasonCode);

		$amazonpay_status = $this->statusAmazonPay($amazonpay_status,$amazon_capture_id,$amazon_refund_id,$amazon_response);

		if($amazonpay_status == 'Closed'){
			if (!empty($amazon_refund_id)){
				$refund_response_object = $amazon_client->getRefund($amazon_refund_id);
				$refund_response = json_decode($refund_response_object['response']);

				$amazonpay_refund_status = strval($refund_response->statusDetails->state);
				$amazonpay_refund_status = $this->refundStatusAmazonPay($amazonpay_refund_status);

				if ($amazonpay_refund_status == "Completed") {
					$retStatus = "返金完了";
				} else if ($amazonpay_refund_status == "Pending") {
					$retStatus = "返金処理中";
				}
				else{
					$retStatus = $amazonpay_refund_status;
				}
			}
			else if(empty($amazon_capture_id)){
				$retStatus = "オーソリ取消済み";
			}
			else{
				$retStatus = $amazonpay_status;
			}
		}
		else{
			if ($amazon_charge_permission_id == "AmazonPayError") {
				$retStatus = "オーソリ失敗";
			} else {
				$retStatus = $amazonpay_status;
			}
		}


		/*-----------------------*/
		//GC_Utils_Ex::gfPrintLog("retStatus status=$retStatus");

		// メモリ解放
		unset($amazon_authorization_id);
		unset($amazon_capture_id);
		unset($amazon_refund_id);
		unset($requestParameters);
		unset($responseObject);
		unset($result);
		unset($amazonpay_status);

		return $retStatus;
	}

	/* amazon pay cv2 関連の処理-----------------------*/

	/*---------------------
	* ステータスチェック
	* ---------------------
	* AmazonCaptureIdを使用したGet Chargeの場合
	* ------------------------
	* Pending = CaptureInitiated
	* Closed = Canceled
	* Declined = Declined
	* 
	* CV1 Authorizationの状態とCV2 Chargeの状態の対応は次の通りです:
	* ------------------------
	* Pending = AuthorizationInitiated
	* Open = Authorized
	* Closed = ReasonCodeがMaxAmountCapturedの場合はCaptured, その他の全てのReasonCodeの場合は、Canceled
	* Declined = Declined
	* -----------------------*/
	function statusAmazonPay($status,$amazon_capture_id = null,$amazon_refund_id = null,$amazon_response){
		if($amazon_capture_id && !$amazon_refund_id){
			if($status == 'CaptureInitiated'){
				$status = 'Pending';
			}
			else if($status == 'Captured'){
				// $status = 'Completed';
				$status = 'Closed';
			}
			else if($status == 'Canceled'){
				$status = 'Closed';
			}
			else{
				$status = 'Declined';
			}
		}
		else{
			if($status == 'AuthorizationInitiated'){
				$status = 'Pending';
			}
			else if($status == 'Declined'){
				$status = 'Declined';
			}
			// 必要なければ削除する
			// else if(($status == 'Captured' && $amazon_response->statusDetails->reasonCode == 'MaxAmountCaptured') || ($status == 'Canceled' && $amazon_response->statusDetails->reasonCode != 'MaxAmountCaptured')){
			//     $status = 'Closed';
			// }
			else if($status == 'Captured' || $status == 'Canceled'){
				$status = 'Closed';
			}
			else{
				$status = 'Open';
			}
		}

		return $status;
	}

	// ステータスを変更
	/*---------------------
	* Get RefundDetailsでのステータスを取得した場合
	*
	* CV1 Authorizationの状態とCV2 Chargeの状態の対応は次の通りです:
	* Pending = RefundInitiated
	* Completed = Refunded
	* Declined = Declined
	* -----------------------*/
	function refundStatusAmazonPay($status = null){
		if($status == 'RefundInitiated'){
			$status = 'Pending';
		}
		else if($status == 'Refunded'){
			$status = 'Completed';
		}
		else{
			$status = 'Declined';
		}
		return $status;
	}
	/*-----------------------*/
}
