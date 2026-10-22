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

require_once CLASS_EX_REALDIR . 'page_extends/admin/LC_Page_Admin_Ex.php';

/**
 * ABテスト管理 のページクラス(拡張).
 *
 *
 * @package Page
 * @author EC-CUBE CO.,LTD.
 * @version $Id$
 */
class LC_Page_Admin_Order_Penalty_Fee_Ex extends LC_Page_Admin_Ex
{
	/**
	 * Page を初期化する.
	 *
	 * @return void
	 */
	function init()
	{
		parent::init();
		$this->tpl_mainpage = 'order/penalty_fee.tpl';
		$this->tpl_mainno = 'order';
		$this->tpl_subno = 'penalty_fee';
		$this->tpl_pager = 'pager.tpl';
		$this->tpl_maintitle = '受注管理';
		$this->tpl_subtitle = '違約金管理';

		$masterData = new SC_DB_MasterData_Ex();
		$this->arrPageMax = $masterData->getMasterData('mtb_page_max');
		$this->arrDISP = $masterData->getMasterData('mtb_disp');

		// 対応状況（入金済みは使用できるか不明）
		$this->arrStatus = array(0 => "請求書発行待ち", 1 => "請求書発行済み", 2 => "入金済み", 3 => "キャンセル", 9 => "エラー");
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
	}

	/**
	 * Page のアクション.
	 *
	 * @return void
	 */
	public function action()
	{
		$objDb = new SC_Helper_DB_Ex();
		$objFormParam = new SC_FormParam_Ex();
		$objQuery = SC_Query_Ex::getSingletonInstance();

		// 画面情報
		$this->lfInitParam($objFormParam);
		$objFormParam->setParam($_POST);
		$objFormParam->convParam();
		$objFormParam->trimParam();
		$this->arrHidden = $objFormParam->getSearchArray();

		switch ($this->getMode()) {
			case 'search':
				// 検索
				$this->arrErr = $this->lfCheckError($objFormParam);
				if (count($this->arrErr) == 0) {
					$arrForm = $objFormParam->getHashArray();
					// 検索条件
					$where = 'dop.del_flg = 0 AND dop.brand_id = ?';
					$arrWhereVal = array($_SESSION["brand_id"]);
					foreach ($arrForm as $key => $val) {
						if ($val == "") {
							continue;
						}
						$this->buildQuery($key, $where, $arrWhereVal, $objFormParam, $objDb);
					}
					$order = 'dop.id DESC, dop.customer_id';

					// 検索処理
					$this->arrResults = $this->doSearch($where, $arrWhereVal, $order, $objFormParam);
				}
				break;
			default:
				break;
		}
		$this->arrForm = $objFormParam->getFormParamList();
	}

	/**
	 * パラメーター情報の初期化を行う.
	 *
	 * @param  SC_FormParam $objFormParam SC_FormParam インスタンス
	 * @return void
	 */
	public function lfInitParam(&$objFormParam)
	{
		// 検索条件
		$objFormParam->addParam('顧客ID', 'search_customer_id', INT_LEN, 'n', array('NUM_CHECK', 'MAX_LENGTH_CHECK'));
		$objFormParam->addParam('顧客名', 'search_customer_name', LTEXT_LEN, 'KVCa', array('MAX_LENGTH_CHECK'));
		$objFormParam->addParam('電話番号', 'search_tel', STEXT_LEN, 'KVCa', array('MAX_LENGTH_CHECK'));
		$objFormParam->addParam('住所', 'search_addr', LTEXT_LEN, 'KVCa', array('MAX_LENGTH_CHECK'));
		$objFormParam->addParam('請求金額From', 'search_total_from', INT_LEN, 'n', array('NUM_CHECK', 'MAX_LENGTH_CHECK'));
		$objFormParam->addParam('請求金額To', 'search_total_to', INT_LEN, 'n', array('NUM_CHECK', 'MAX_LENGTH_CHECK'));
		$objFormParam->addParam('登録日From', 'search_create_from', STEXT_LEN, 'KVCa', array('MAX_LENGTH_CHECK'));
		$objFormParam->addParam('登録日To', 'search_create_to', STEXT_LEN, 'KVCa', array('MAX_LENGTH_CHECK'));
		$objFormParam->addParam('請求書発行予定日From', 'search_invoice_from', STEXT_LEN, 'n', array('MAX_LENGTH_CHECK'));
		$objFormParam->addParam('請求書発行予定日To', 'search_invoice_to', STEXT_LEN, 'n', array('MAX_LENGTH_CHECK'));
		$objFormParam->addParam('対応状況', 'search_status', INT_LEN, 'n', array('NUM_CHECK', 'MAX_LENGTH_CHECK'));

		$objFormParam->addParam('ページ送り番号', 'search_pageno', INT_LEN, 'n', array('MAX_LENGTH_CHECK', 'NUM_CHECK'));
		$objFormParam->addParam('表示件数', 'search_page_max', INT_LEN, 'n', array('MAX_LENGTH_CHECK', 'NUM_CHECK'));
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

		// 範囲検索確認
		if (!empty($objFormParam->getValue('search_create_from')) && !empty($objFormParam->getValue('search_create_to'))) {
			// 登録日
			$from = date("Y-m-d", strtotime($objFormParam->getValue('search_create_from')));
			$to = date("Y-m-d", strtotime($objFormParam->getValue('search_create_to')));
			if ($from > $to) {
				$objErr->arrErr["search_create_from"] .= "※ 登録日の検索範囲指定が正しくありません。<br>";
			}
		}
		if (!empty($objFormParam->getValue('search_invoice_from')) && !empty($objFormParam->getValue('search_invoice_to'))) {
			// 請求書発行予定日
			$from = date("Y-m-d", strtotime($objFormParam->getValue('search_invoice_from')));
			$to = date("Y-m-d", strtotime($objFormParam->getValue('search_invoice_to')));
			if ($from > $to) {
				$objErr->arrErr["search_invoice_from"] .= "※ 請求書発行予定日の検索範囲指定が正しくありません。<br>";
			}
		}
		if (!empty($objFormParam->getValue('search_total_from')) && !empty($objFormParam->getValue('search_total_to'))) {
			// 請求金額
			$from = date("Y-m-d", strtotime($objFormParam->getValue('search_total_from')));
			$to = date("Y-m-d", strtotime($objFormParam->getValue('search_total_to')));
			if ($from > $to) {
				$objErr->arrErr["search_total_from"] .= "※ 請求金額の検索範囲指定が正しくありません。<br>";
			}
		}

		return $objErr->arrErr;
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
	 * @param  SC_FormParam $objDb        SC_Helper_DB_Ex インスタンス
	 * @return void
	 */
	public function buildQuery($key, &$where, &$arrValues, &$objFormParam, &$objDb)
	{
		$dbFactory = SC_DB_DBFactory_Ex::getInstance();
		switch ($key) {
			// 顧客ID
			case 'search_customer_id':
				$where .= ' AND dop.customer_id = ?';
				$arrValues[] = sprintf('%d', $objFormParam->getValue($key));
				break;
			// 顧客名
			case 'search_customer_name':
				$where .= " AND replace(replace(dop.name01,'　',' '),' ','') LIKE ?";
				$arrValues[] = sprintf('%%%s%%', preg_replace('/[ 　]/u', '', $objFormParam->getValue($key)));
				break;
			// 電話番号
			case 'search_tel':
				$where .= " AND dop.tel = ?";
				$arrValues[] = sprintf('%s', str_replace('-', '', $objFormParam->getValue($key)));
				break;
			// 住所
			case 'search_addr':
				$where .= " AND replace(replace(concat(mp.name, dop.addr01, dop.addr02),'　',' '),' ','') LIKE ?";
				$arrValues[] = sprintf('%%%s%%', preg_replace('/[ 　]/u', '', $objFormParam->getValue($key)));
				break;
			// 請求金額From・To
			case 'search_total_from':
				$where .= ' AND dop.payment_total >= ?';
				$arrValues[] = sprintf('%d', $objFormParam->getValue($key));
				break;
			case 'search_total_to':
				$where .= ' AND dop.payment_total <= ?';
				$arrValues[] = sprintf('%d', $objFormParam->getValue($key));
				break;
			// 登録日From・To
			case 'search_create_from':
				$sdate = date('Y-m-d 00:00:00', strtotime($objFormParam->getValue($key)));
				$where .= ' AND dop.create_date >= ?';
				$arrValues[] = $sdate;
				break;
			case 'search_create_to':
				$edate = date('Y-m-d 23:59:59', strtotime($objFormParam->getValue($key)));
				$where .= ' AND dop.create_date <= ?';
				$arrValues[] = $edate;
				break;
			// 請求書発行予定日From・To
			case 'search_invoice_from':
				$sdate = date('Y-m-d 00:00:00', strtotime($objFormParam->getValue($key)));
				$where .= ' AND dop.invoice_date >= ?';
				$arrValues[] = $sdate;
				break;
			case 'search_invoice_to':
				$edate = date('Y-m-d 23:59:59', strtotime($objFormParam->getValue($key)));
				$where .= ' AND dop.invoice_date <= ?';
				$arrValues[] = $edate;
				break;
			// 対応状況
			case 'search_status':
				$where .= ' AND dop.status = ?';
				$arrValues[] = sprintf('%d', $objFormParam->getValue($key));
				break;
			default:
				break;
		}
	}

	/**
	 * 検索
	 *
	 * @param  string $where	対象の WHERE 句
	 * @param  array  $arrWhere	対象の値
	 * @return void
	 */
	public function doSearch($where, $arrValues, $order, $objFormParam)
	{
		$objQuery = SC_Query_Ex::getSingletonInstance();
		// 行数の取得
		$this->tpl_linemax = $this->getNumberOfLines($where, $arrValues, $objQuery);
		// ページ送りの処理
		$page_max = SC_Utils_Ex::sfGetSearchPageMax($objFormParam->getValue('search_page_max'));
		// ページ送りの取得
		$objNavi = new SC_PageNavi_Ex($this->arrHidden['search_pageno'],
									  $this->tpl_linemax, $page_max,
									  'eccube.moveNaviPage', NAVI_PMAX);
		$this->arrPagenavi = $objNavi->arrPagenavi;

		// 読み込む列とテーブルの指定
		$sql = " SELECT dop.*, mp.name as pref_name FROM dtb_order_penalty_fee dop INNER JOIN mtb_pref mp ON dop.pref = mp.id WHERE " . $where . " ORDER BY " . $order;
		return $objQuery->getAll($sql, $arrValues);

		// 読み込む列とテーブルの指定
		// $col = "*";
		// $from = "dtb_chat_scenario_reservation";
		// $objQuery->setLimitOffset($page_max, $objNavi->start_row);
		// $objQuery->setOrder($order);
		// return $objQuery->select($col, $from, $where, $arrValues);
	}

	/**
	 * 検索結果の行数を取得する.
	 *
	 * @param  string  $where     検索条件の WHERE 句
	 * @param  array   $arrValues 検索条件のパラメーター
	 * @return integer 検索結果の行数
	 */
	public function getNumberOfLines($where, $arrValues, $objQuery)
	{
		return $objQuery->count('dtb_order_penalty_fee dop INNER JOIN mtb_pref mp ON dop.pref = mp.id', $where, $arrValues);
	}
}
