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
 * コードモデル変更管理 のページクラス
 *
 * @package Page
 * @author EC-CUBE CO.,LTD.
 * @version $Id$
 */
class LC_Page_Admin_Model_Change_Ex extends LC_Page_Admin_Ex
{
	/**
	 * Page を初期化する.
	 *
	 * @return void
	 */
	function init()
	{
		parent::init();
		$this->tpl_mainpage = 'affiliate/model_change.tpl';
		$this->tpl_mainno = 'affiliate';
		$this->tpl_subno = 'model_change';
		$this->tpl_pager = 'pager.tpl';
		$this->tpl_maintitle = '広告管理';
		$this->tpl_subtitle = 'コードモデル変更管理';

		$masterData = new SC_DB_MasterData_Ex();
		$this->arrPageMax = $masterData->getMasterData('mtb_page_max');
		$this->arrDISP = $masterData->getMasterData('mtb_disp');

		// 商品カテゴリ一覧（セレクトボックス用）
		$objBrand = new SC_Helper_Brand_Ex();
		$this->arrCategory = $objBrand->getBrandCategoryList($_SESSION["brand_id"]);

		// 使用状況
		$this->arrStatus = array(0 => "使用中", 1 => "未使用");

		// ASP一覧
		$objAsp = new SC_Helper_Asp_Ex();
		$arrAsp = $objAsp->getList();
		// メディア一覧
		$objMedia = new SC_Helper_Media_Ex();
		$arrMedia = $objMedia->getList();
		// 媒体一覧
		$objBaitai = new SC_Helper_Baitai_Ex();
		$arrBaitai = $objBaitai->getList();

		// セレクトボックス用に整形
		list($this->arrAsp, $this->arrMedia, $this->arrBaitai) = $this->lfSetSelectHtml($arrAsp, $arrMedia, $arrBaitai);
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
					$where = 'dlc.del_flg = 0 AND dlc.brand_id = ?';
					$arrWhereVal = array($_SESSION["brand_id"]);
					foreach ($arrForm as $key => $val) {
						if ($val == "") {
							continue;
						}
						$this->buildQuery($key, $where, $arrWhereVal, $objFormParam);
					}
					$order = 'dlc.status, dlc.asp_id, dlc.media_id, dlc.baitai_id';
					// 検索処理
					$this->arrResults = $this->doSearch($where, $arrWhereVal, $order, $objFormParam);
				}
				break;
			case 'delete':
				// 削除
				$this->delete($_POST['id']);

				// 検索条件
				$arrForm = $objFormParam->getHashArray();
				$where = 'dlc.del_flg = 0 AND dlc.brand_id = ?';
				$arrWhereVal = array($_SESSION["brand_id"]);
				foreach ($arrForm as $key => $val) {
					if ($val == '') {
						continue;
					}
					$this->buildQuery($key, $where, $arrWhereVal, $objFormParam);
				}
				$order = 'dlc.status, dlc.asp_id, dlc.media_id, dlc.baitai_id';
				// 検索処理
				$this->arrResults = $this->doSearch($where, $arrWhereVal, $order,  $objFormParam);
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
		$objFormParam->addParam('ID', 'id', INT_LEN, 'n', array('NUM_CHECK', 'MAX_LENGTH_CHECK'));
		$objFormParam->addParam('商品カテゴリ', 'search_category_id', INT_LEN, 'n', array('NUM_CHECK', 'MAX_LENGTH_CHECK'));
		$objFormParam->addParam('ASP', 'search_asp_id', INT_LEN, 'n', array('NUM_CHECK', 'MAX_LENGTH_CHECK'));
		$objFormParam->addParam('メディア', 'search_media_id', INT_LEN, 'n', array('NUM_CHECK', 'MAX_LENGTH_CHECK'));
		$objFormParam->addParam('媒体', 'search_baitai_id', INT_LEN, 'n', array('NUM_CHECK', 'MAX_LENGTH_CHECK'));
		$objFormParam->addParam('期間（開始日）', 'search_start_date', STEXT_LEN, 'n', array('MAX_LENGTH_CHECK'));
		$objFormParam->addParam('期間（終了日）', 'search_end_date', STEXT_LEN, 'n', array('MAX_LENGTH_CHECK'));
		$objFormParam->addParam('使用状況', 'search_status', INT_LEN, 'n', array('NUM_CHECK', 'MAX_LENGTH_CHECK'));

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

		// 期間集計範囲確認
		if (!isset($objErr->arrErr["search_start_date"]) && !isset($objErr->arrErr["search_end_date"])) {
			$from = date("Y-m-d", strtotime($objFormParam->getValue('search_start_date')));
			$to = date("Y-m-d", strtotime($objFormParam->getValue('search_end_date')));
			if (!empty($objFormParam->getValue('search_start_date')) &&
				!empty($objFormParam->getValue('search_end_date')) &&
				$from > $to
			) {
				$objErr->arrErr["search_start_date"] = "※ 期間の日付指定が正しくありません。<br>";
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
	 * @return void
	 */
	public function buildQuery($key, &$where, &$arrValues, &$objFormParam)
	{
		switch ($key) {
			// 商品カテゴリ
			case 'search_category_id':
				$where .= ' AND dlc.category_id = ?';
				$arrValues[] = sprintf('%d', $objFormParam->getValue($key));
				break;
			// ASP
			case 'search_asp_id':
				$where .= ' AND dlc.asp_id = ?';
				$arrValues[] = sprintf('%d', $objFormParam->getValue($key));
				break;
			// メディア
			case 'search_media_id':
				$where .= ' AND dlc.media_id = ?';
				$arrValues[] = sprintf('%d', $objFormParam->getValue($key));
				break;
			// 媒体
			case 'search_baitai_id':
				$where .= ' AND dlc.baitai_id = ?';
				$arrValues[] = sprintf('%d', $objFormParam->getValue($key));
				break;
			// 期間From
			case 'search_start_date':
				$sdate = date('Y-m-d 00:00:00', strtotime($objFormParam->getValue($key)));
				$where .= ' AND dlc.start_date = ?';
				$arrValues[] = $sdate;
				break;
			// 期間To
			case 'search_end_date':
				$edate = date('Y-m-d 23:59:59', strtotime($objFormParam->getValue($key)));
				$where .= ' AND dlc.end_date = ?';
				$arrValues[] = $edate;
				break;
			// 使用状況
			case 'search_status':
				$where .= ' AND dlc.status = ?';
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
		$sql = " SELECT dlc.*, dm.name AS model_name, dm2.name as model_name2, dl.name AS lp_name"
			 . " FROM dtb_lps_code_model_change dlc "
			 . " LEFT JOIN dtb_lps dl ON dlc.lp_id = dl.id"
			 . " LEFT JOIN dtb_model dm ON dm.id = dlc.model_id"
			 . " LEFT JOIN dtb_model dm2 ON dm2.id = dlc.model_id2"
			 . " WHERE " . $where
			 . " ORDER BY " . $order
		;
		return $objQuery->getAll($sql, $arrValues);
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
		return $objQuery->count('dtb_lps_code_model_change dlc', $where, $arrValues);
	}

	/**
	 * 削除処理（論理削除）
	 * 
	 * @param integer $id	管理ID
	 * @return void
	 */
	public function delete($id) {
		$objQuery =& SC_Query_Ex::getSingletonInstance();
		$sql_val['del_flg'] = 1;
		$sql_val['update_date'] = 'CURRENT_TIMESTAMP';
		$where = 'id = ?';
		$objQuery->update('dtb_lps_code_model_change', $sql_val, $where, array($id));
	}

	/**
	 * それぞれの配列からセレクトボックス表示用配列を作成
	 * @param array $arrAsp		ASP一覧
	 * @param array $arrMedia	メディア一覧
	 * @param array $arrBaitai	媒体一覧
	 */
	public function lfSetSelectHtml($arrAsp, $arrMedia, $arrBaitai) {
		// ASP
		$arrAspSelect = array();
		foreach($arrAsp as $data) {
			$arrAspSelect[$data["id"]] = $data["name"];
		}
		// メディア
		$arrMediaSelect = array();
		foreach($arrMedia as $data) {
			$arrMediaSelect[$data["id"]] = $data["name"];
		}
		// 媒体
		$arrBaitaiSelect = array();
		foreach($arrBaitai as $data) {
			$arrBaitaiSelect[$data["id"]] = $data["name"];
		}
		return array($arrAspSelect, $arrMediaSelect, $arrBaitaiSelect);
	}


	/**
	 * デストラクタ.
	 *
	 * @return void
	 */
	function destroy() {
		parent::destroy();
	}
}
