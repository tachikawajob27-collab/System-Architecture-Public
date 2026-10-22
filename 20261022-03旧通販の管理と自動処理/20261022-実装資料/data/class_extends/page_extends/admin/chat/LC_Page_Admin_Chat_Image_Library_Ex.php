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
 * チャット管理 のページクラス(拡張).
 *
 *
 * @package Page
 * @author EC-CUBE CO.,LTD.
 * @version $Id$
 */
class LC_Page_Admin_Chat_Image_Library_Ex extends LC_Page_Admin_Ex
{
	/**
	 * Page を初期化する.
	 *
	 * @return void
	 */
	function init()
	{
		parent::init();
		$this->tpl_mainpage = 'chat/image_library.tpl';
		$this->tpl_mainno = 'chat';
		$this->tpl_subno = 'image_library';
		$this->tpl_pager = 'pager.tpl';
		$this->tpl_maintitle = 'チャット管理';
		$this->tpl_subtitle = '画像管理';

		$masterData = new SC_DB_MasterData_Ex();
		$this->arrPageMax = $masterData->getMasterData('mtb_page_max');
		$this->arrDISP = $masterData->getMasterData('mtb_disp');

		// 商品カテゴリ一覧（セレクトボックス用）
		$objBrand = new SC_Helper_Brand_Ex();
		$this->arrCategory = $objBrand->getBrandCategoryList($_SESSION["brand_id"]);
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
		$this->arrForm = $objFormParam->getFormParamList();

		switch ($this->getMode()) {
			case 'search':
				// 検索
				$this->arrErr = $this->lfCheckError($objFormParam);

				if (count($this->arrErr) == 0) {
					$arrForm = $objFormParam->getHashArray();
					// 検索条件
					$where = ' brand_id = '.$_SESSION["brand_id"];
					$arrWhereVal = array();
					foreach ($arrForm as $key => $val) {
						$this->buildQuery($key, $where, $arrWhereVal, $objFormParam, $objDb);
					}
					$order = 'update_date DESC, id DESC';

					// 検索処理
					$this->arrResults = $this->doSearch($where, $arrWhereVal, $order, $objFormParam);
				}
				break;
			case 'delete':
				// 削除
				$this->delete($post['id']);

				// 検索条件
				$where = ' AND brand_id = '.$_SESSION["brand_id"];
				$arrWhereVal = array();
				foreach ($this->arrForm as $key => $val) {
					if ($val == '') {
						continue;
					}
					$this->buildQuery($key, $where, $arrWhereVal, $objFormParam, $objDb);
				}
				$order = 'update_date DESC, id DESC';

				// 検索処理
				$this->arrResults = $this->doSearch($where, $arrWhereVal, $order,  $objFormParam);
				break;
		}
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
		$objFormParam->addParam('商品カテゴリ', 'search_category_id', INT_LEN, 'n', array('NUM_CHECK', 'MAX_LENGTH_CHECK'));
		$objFormParam->addParam('削除表示', 'search_delete', INT_LEN, 'n', array('NUM_CHECK', 'MAX_LENGTH_CHECK'));
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
			// 商品カテゴリ
			case 'search_category_id':
				if (!empty($objFormParam->getValue($key))) {
					$where .= ' AND category_id = ?';
					$arrValues[] = sprintf('%d', $objFormParam->getValue($key));
				}
				break;
			// 削除表示
			case 'search_delete':
				if (empty($objFormParam->getValue($key))) {
					$where .= ' AND del_flg = 0';
				}
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
		$col = "*";
		$from = "dtb_image_library";
		$objQuery->setLimitOffset($page_max, $objNavi->start_row);
		$objQuery->setOrder($order);
		return $objQuery->select($col, $from, $where, $arrValues);
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
		return $objQuery->count('dtb_image_library', $where, $arrValues);
	}

	/**
	 * アイコン削除（論理削除）
	 * 
	 * @param integer $id	アイコン管理ID
	 * @return void
	 */
	public function delete($id) {
		$objQuery =& SC_Query_Ex::getSingletonInstance();
		$sql_val['del_flg'] = 1;
		$sql_val['update_date'] = 'CURRENT_TIMESTAMP';
		$where = 'id = ?';
		$objQuery->update('dtb_image_library', $sql_val, $where, array($id));
	}
}
