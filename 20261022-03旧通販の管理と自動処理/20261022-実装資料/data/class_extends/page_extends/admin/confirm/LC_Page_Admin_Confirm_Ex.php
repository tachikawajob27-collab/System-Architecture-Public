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
 * 確認表示管理
 *
 * @package Page
 * @author EC-CUBE CO.,LTD.
 * @version $Id$
 */
class LC_Page_Admin_Confirm_Ex extends LC_Page_Admin_Ex
{
	/**
	 * Page を初期化する.
	 *
	 * @return void
	 */
	function init()
	{
		parent::init();
		parent::init();
		$this->tpl_mainpage = 'confirm/index.tpl';
		$this->tpl_mainno = 'confirm';
		$this->tpl_subno = 'index';
		$this->tpl_pager = 'pager.tpl';
		$this->tpl_maintitle = '確認表示管理';
		$this->tpl_subtitle = '確認表示管理';

		$masterData = new SC_DB_MasterData_Ex();
		$this->arrPageMax = $masterData->getMasterData('mtb_page_max');
		$this->arrDISP = $masterData->getMasterData('mtb_disp');

		// ASP、媒体、画面レイアウト一覧
		$objAsp = new SC_Helper_Asp_Ex();
		$objBaitai = new SC_Helper_Baitai_Ex();
		$arrAsp = $objAsp->getList();
		$arrBaitai = $objBaitai->getList();

		// セレクトボックス用に整形
		list($this->arrAsp, $this->arrBaitai, $this->arrLayout) = $this->lfSetSelectHtml($arrAsp, $arrBaitai);
		$this->arrConfirm = array(0 => "確認ポップ", 1 => "確認画面", 2 => "チャット内包");

		// 商品カテゴリ一覧（セレクトボックス用）
		$objBrand = new SC_Helper_Brand_Ex();
		$this->arrCategory = $objBrand->getBrandCategoryList($_SESSION["brand_id"]);

	}

	/**
	 * Page のプロセス.
	 *
	 * @return void
	 */
	public function process()
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
		$objFormParam = new SC_FormParam_Ex();

		// パラメーター情報の初期化
		$this->lfInitParam($objFormParam);
		$objFormParam->setParam($_POST);
		$this->arrHidden = $objFormParam->getSearchArray();
		$this->arrForm = $objFormParam->getFormParamList();

		switch ($this->getMode()) {
			case 'search':
				// 検索
				$objFormParam->convParam();
				$objFormParam->trimParam();
				$this->arrErr = $this->lfCheckError($objFormParam);
				$arrParam = $objFormParam->getHashArray();

				if (count($this->arrErr) == 0) {
					$where = 'dcs.del_flg = 0';
					$where .= ' AND dcs.brand_id = '.$_SESSION["brand_id"];
					$arrWhereVal = array();
					foreach ($arrParam as $key => $val) {
						if ($val == '') {
							continue;
						}
						$this->buildQuery($key, $where, $arrWhereVal, $objFormParam, $objDb);
					}
					$order = 'dcs.update_date DESC, dcs.id DESC';

					// 検索処理
					$this->arrResults = $this->doSearch($where, $arrWhereVal, $order,  $objFormParam);

					// デフォルト設定を取得
					$this->arrDefault = $this->doSearchDefault($_SESSION["brand_id"]);
				}
				break;
			case 'delete':
				$this->doDelete('id = ? AND brand_id = ?', array($objFormParam->getValue('id'), $_SESSION["brand_id"]));

				// 検索
				$objFormParam->convParam();
				$objFormParam->trimParam();
				$this->arrErr = $this->lfCheckError($objFormParam);
				$arrParam = $objFormParam->getHashArray();

				if (count($this->arrErr) == 0) {
					$where = 'dcs.del_flg = 0';
					$where .= ' AND dcs.brand_id = '.$_SESSION["brand_id"];
					$arrWhereVal = array();
					foreach ($arrParam as $key => $val) {
						if ($val == '') {
							continue;
						}
						$this->buildQuery($key, $where, $arrWhereVal, $objFormParam, $objDb);
					}
					$order = 'dcs.update_date DESC, dcs.id DESC';
					// 検索処理
					$this->arrResults = $this->doSearch($where, $arrWhereVal, $order,  $objFormParam);
					// デフォルト設定を取得
					$this->arrDefault = $this->doSearchDefault($_SESSION["brand_id"]);
				}
				break;
			default:
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
		$objFormParam->addParam('ASP', 'search_asp_id', INT_LEN, 'n', array('NUM_CHECK', 'MAX_LENGTH_CHECK'));
		$objFormParam->addParam('媒体', 'search_baitai_id', INT_LEN, 'n', array('NUM_CHECK', 'MAX_LENGTH_CHECK'));
		$objFormParam->addParam('確認表示区分（PC）', 'search_is_confirm_pc', INT_LEN, 'n', array('NUM_CHECK', 'MAX_LENGTH_CHECK'));
		$objFormParam->addParam('確認表示区分（SP）', 'search_is_confirm_sp', INT_LEN, 'n', array('NUM_CHECK', 'MAX_LENGTH_CHECK'));
		$objFormParam->addParam('ページ送り番号', 'search_pageno', INT_LEN, 'n', array('MAX_LENGTH_CHECK', 'NUM_CHECK'));
		$objFormParam->addParam('表示件数', 'search_page_max', INT_LEN, 'n', array('MAX_LENGTH_CHECK', 'NUM_CHECK'));

		$objFormParam->addParam('商品カテゴリ', 'search_category_id', INT_LEN, 'n', array('NUM_CHECK', 'MAX_LENGTH_CHECK'));
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
		$this->tpl_linemax = $this->getNumberOfLines($where, $arrValues);
		// ページ送りの処理
		$page_max = SC_Utils_Ex::sfGetSearchPageMax($objFormParam->getValue('search_page_max'));
		// ページ送りの取得
		$objNavi = new SC_PageNavi_Ex($this->arrHidden['search_pageno'],
									  $this->tpl_linemax, $page_max,
									  'eccube.moveNaviPage', NAVI_PMAX);
		$this->arrPagenavi = $objNavi->arrPagenavi;

		// 読み込む列とテーブルの指定
		$col = "dcs.*, da.name AS asp, db.name AS baitai";
		$from = "dtb_confirm_setting dcs"
			 . " INNER JOIN dtb_asp da ON dcs.asp_id = da.id AND dcs.brand_id = da.brand_id"
			 . " INNER JOIN dtb_baitai db ON dcs.baitai_id = db.id AND dcs.brand_id = db.brand_id"
		;
		$objQuery->setLimitOffset($page_max, $objNavi->start_row);
		$objQuery->setOrder($order);
		return $objQuery->select($col, $from, $where, $arrValues);
	}

	/**
	 * デフォルト情報を取得
	 */
	public function doSearchDefault($brand_id) {
		$objQuery = SC_Query_Ex::getSingletonInstance();
		return $objQuery->getAll("SELECT * FROM dtb_confirm_default WHERE brand_id = ? ORDER BY category_id", array($brand_id));
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
			// ASP
			case 'search_asp_id':
				$where .= ' AND dcs.asp_id = ?';
				$arrValues[] = sprintf('%d', $objFormParam->getValue($key));
				break;
			// 媒体
			case 'search_baitai_id':
				$where .= ' AND dcs.baitai_id = ?';
				$arrValues[] = sprintf('%d', $objFormParam->getValue($key));
				break;
			// 確認表示区分（PC）
			case 'search_is_confirm_pc':
				$where .= ' AND dcs.is_confirm_pc = ?';
				$arrValues[] = sprintf('%d', $objFormParam->getValue($key));
				break;
			// 確認表示区分（SP）
			case 'search_is_confirm_sp':
				$where .= ' AND dcs.is_confirm_sp = ?';
				$arrValues[] = sprintf('%d', $objFormParam->getValue($key));
				break;
			// 商品カテゴリ
			case 'search_category_id':
				$where .= ' AND dcs.category_id = ?';
				$arrValues[] = sprintf('%d', $objFormParam->getValue($key));
				break;
			default:
				break;
		}
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

		return $objQuery->count('dtb_confirm_setting dcs', $where, $arrValues);
	}

	/**
	 * コースを削除（物理削除）
	 *
	 * @param  string $where    削除対象の WHERE 句
	 * @param  array  $arrParam 削除対象の値
	 * @return void
	 */
	public function doDelete($where, $arrParam = array())
	{
		$objQuery = SC_Query_Ex::getSingletonInstance();
		$objQuery->begin();
		$objQuery->delete('dtb_confirm_setting', $where, $arrParam);
		$objQuery->commit();
	}

	/**
	 * それぞれの配列からセレクトボックス表示用配列を作成
	 * @param array $arrAsp		ASP一覧
	 * @param array $arrBaitai	媒体一覧
	 */
	public function lfSetSelectHtml($arrAsp, $arrBaitai) {
		// ASP
		$arrAspSelect = array();
		foreach($arrAsp as $data) {
			$arrAspSelect[$data["id"]] = $data["name"];
		}
		// 媒体
		$arrBaitaiSelect = array();
		foreach($arrBaitai as $data) {
			$arrBaitaiSelect[$data["id"]] = $data["name"];
		}
		// 画面レイアウト
		$arrLayoutSelect = array();
		$objQuery = SC_Query_Ex::getSingletonInstance();
		$arrLayout = $objQuery->getAll("SELECT * FROM dtb_confirm_layout WHERE del_flg = 0 AND brand_id = ?", array($_SESSION["brand_id"]));
		foreach($arrLayout as $data) {
			$arrLayoutSelect[$data["id"]] = $data["name"];
		}

		return array($arrAspSelect, $arrBaitaiSelect, $arrLayoutSelect);
	}
}
