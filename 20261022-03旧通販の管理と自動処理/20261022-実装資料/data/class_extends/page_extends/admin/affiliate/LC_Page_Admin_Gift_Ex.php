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
 * おまけ管理画面（一覧／検索）
 */
class LC_Page_Admin_Gift_Ex extends LC_Page_Admin_Ex
{
	// 検索結果配列
	public $arrResults = array();

	// 検索フォームのエラー
	public $arrErr = array();

	// 検索条件のフォームデータ
	public $arrForm = array();

	// hiddenで渡す検索条件
	public $arrHidden = array();

	// プレゼント商品（カテゴリID=14）の配列
	public $arrPresentProducts = array();

	/**
	 * Page初期化
	 */
	public function init()
	{
		parent::init();

		$this->tpl_mainpage  = 'affiliate/gift.tpl';
		$this->tpl_mainno    = 'affiliate';
		$this->tpl_subno     = 'gift';
		$this->tpl_pager     = 'pager.tpl';
		$this->tpl_maintitle = '広告管理';
		$this->tpl_subtitle  = 'おまけ管理';

		// ページ最大件数マスタ
		$masterData = new SC_DB_MasterData_Ex();
		$this->arrPageMax = $masterData->getMasterData('mtb_page_max');

		// 商品カテゴリ一覧（他で使う可能性があるため取得は残す）
		$objBrand = new SC_Helper_Brand_Ex();
		$this->arrCategory = $objBrand->getBrandCategoryList($_SESSION["brand_id"]);

		$this->arrStatus = array(0 => "使用中", 1 => "未使用");

		// プレゼント商品一覧（カテゴリID=14 を取得）
		$this->arrPresentProducts = $this->lfGetPresentProducts();
	}

	/**
	 * Pageの処理
	 */
	public function process()
	{
		$this->action();
		$this->sendResponse();
	}

	/**
	 * アクション
	 */
	public function action()
	{
		$objFormParam = new SC_FormParam_Ex();
		$objQuery     = SC_Query_Ex::getSingletonInstance();

		// パラメータ初期化
		$this->lfInitParam($objFormParam);

		// POST -> FormParam
		$objFormParam->setParam($_POST);
		$objFormParam->convParam();
		$objFormParam->trimParam();

		// hidden 用
		$this->arrHidden = $objFormParam->getSearchArray();

		// テンプレ用
		$arrForm = $objFormParam->getHashArray();

	 switch ($this->getMode()) {
		 case 'search':
			// POSTデータをコピーして検索用キーに変換
			$searchPost = $_POST;
			if (isset($searchPost['offer_name'])) {
				$searchPost['search_offer_name'] = $searchPost['offer_name'];
			}
			if (isset($searchPost['parameter_key'])) {
				$searchPost['search_parameter_key'] = $searchPost['parameter_key'];
			}

			// FormParam にセット
			$objFormParam->setParam($searchPost);
			$objFormParam->convParam();
			$objFormParam->trimParam();

			// hidden 用
			$this->arrHidden = $objFormParam->getSearchArray();

			// 入力チェック
			$this->arrErr = $this->lfCheckError($objFormParam);

			if (count($this->arrErr) === 0) {
				// FormParam から検索条件配列を取得
				$arrParam = $objFormParam->getHashArray();

				// WHERE 句の初期化
				$where = 'dlt_flg = 0';
				$arrWhereVal = [];

				// 検索条件をWHEREに反映
				if (!empty($arrParam['search_offer_name'])) {
					$where .= " AND dcsg.offer_name LIKE ?";
					$arrWhereVal[] = '%' . $arrParam['search_offer_name'] . '%';
				}
				if (!empty($arrParam['search_product_name'])) {
					$where .= " AND dp.name LIKE ?";
					$arrWhereVal[] = '%' . $arrParam['search_product_name'] . '%';
				}
				if (!empty($arrParam['search_category_id'])) {
					$where .= " AND dcsg.category_id = ?";
					$arrWhereVal[] = $arrParam['search_category_id'];
				}
				if (isset($arrParam['search_apply_type']) && $arrParam['search_apply_type'] !== '') {
					// "0" も "1" もここを通る
					$where .= " AND dcsg.apply_type = ?";
					$arrWhereVal[] = $arrParam['search_apply_type'];
				}
				// 現在使用中のおまけ
				if (!empty($arrParam["search_is_omake"])) {
					$today = date("Y-m-d");
					$where .= " AND (dcsg.start_date <= ? AND (dcsg.end_date IS NULL OR dcsg.end_date >= ?))";
					$arrWhereVal[] = $today;
					$arrWhereVal[] = $today;
				}

				// 空文字（""）なら何もしない → 全件表示

				// 行数取得
				$this->tpl_linemax = $this->getNumberOfLines($where, $arrWhereVal, $objQuery);

				// ページ送り
				$page_max = SC_Utils_Ex::sfGetSearchPageMax($objFormParam->getValue('search_page_max'));
				$pageno   = $this->arrHidden['search_pageno'] ?? 1;
				$objNavi  = new SC_PageNavi_Ex($pageno, $this->tpl_linemax, $page_max, 'eccube.moveNaviPage', NAVI_PMAX);
				// ソート順の設定
				$objQuery->setOrder("dcsg.apply_type ASC, dcsg.start_date DESC, dcsg.end_date ASC, dcsg.id ASC");

				// 検索実行
				$objQuery->setlimitoffset($page_max, $objNavi->start_row);
				$this->arrResults = $objQuery->select('dcsg.*, dp.name AS product_name', 'dtb_chat_scenario_gift dcsg INNER JOIN dtb_products dp ON dcsg.gift_id = dp.product_id', $where, $arrWhereVal);
				$this->arrForm = $objFormParam->getFormParamList();
			}
	 break;
		case 'delete':
			$id = $_POST['id'] ?? null;
			if ($id) {
				$objQuery->update(
					'dtb_chat_scenario_gift',
					array('dlt_flg' => 1, 'updated_at' => 'NOW()'),
					'id = ?',
					array($id)
				);
			}
			break;

		default:
			// 初期表示は検索しない
			break;
		}

		// テンプレ表示用
		$this->arrForm = $objFormParam->getFormParamList();

		if (!is_array($this->arrResults)) {
			$this->arrResults = array();
		}
	}

	/**
	 * 入力チェック
	 */
	private function lfCheckError($objFormParam)
	{
		$arrErr = $objFormParam->checkError();
		if (!is_array($arrErr)) {
			$arrErr = array();
		}

		// 日付チェック（検索用パラメータ）
		// $params = $objFormParam->getHashArray();
		// if (!empty($params['search_start_date']) && !empty($params['search_end_date'])) {
		// 	$s = strtotime($params['search_start_date']);
		// 	$e = strtotime($params['search_end_date']);
		// 	if ($s === false || $e === false) {
		// 		$arrErr['search_start_date'] = '日付形式が不正です。';
		// 	} elseif ($s > $e) {
		// 		$arrErr['search_end_date'] = '終了日は開始日より後の日付を指定してください。';
		// 	}
		// }

		return $arrErr;
	}

	/**
	 * フォームパラメータ初期化
	 * （検索用パラメータと登録で受け取る最低限のパラメータを定義）
	 */
	public function lfInitParam(&$objFormParam)
	{
		// $objFormParam->addParam('パラメータキー', 'search_parameter_key', 'STEXT_LEN', '', array('MAX_LENGTH_CHECK'));
		$objFormParam->addParam('オファー名', 'search_offer_name', 'STEXT_LEN', '', array('MAX_LENGTH_CHECK'));
		$objFormParam->addParam('おまけ商品名', 'search_product_name', 'STEXT_LEN', '', array('MAX_LENGTH_CHECK'));
		$objFormParam->addParam('商品カテゴリ', 'search_category_id', INT_LEN, 'n', array('NUM_CHECK', 'MAX_LENGTH_CHECK'));
		$objFormParam->addParam('使用状況', 'search_apply_type', INT_LEN, 'n', array('NUM_CHECK', 'MAX_LENGTH_CHECK'));
		$objFormParam->addParam('使用中のおまけ', 'search_is_omake', INT_LEN, 'n', array('NUM_CHECK', 'MAX_LENGTH_CHECK'));

		$objFormParam->addParam('ページ送り番号', 'search_pageno', INT_LEN, 'n', array('MAX_LENGTH_CHECK', 'NUM_CHECK'));
		$objFormParam->addParam('表示件数', 'search_page_max', INT_LEN, 'n', array('MAX_LENGTH_CHECK', 'NUM_CHECK'));
	}

	/**
	 * プレゼント商品（カテゴリID=14）の取得
	 * 
	 */
	protected function lfGetPresentProducts()
	{
		$objQuery = SC_Query_Ex::getSingletonInstance();
		$sql = "SELECT product_id, name FROM dtb_products WHERE category_id = ? AND del_flg = 0 ORDER BY name";
		$arrRet = $objQuery->getAll($sql, array(14));
		if (!is_array($arrRet)) $arrRet = array();
		return $arrRet;
	}
	public function getNumberOfLines($where, $arrWhereVal, &$objQuery)
	{
		return $objQuery->count(
			'dtb_chat_scenario_gift dcsg INNER JOIN dtb_products dp ON dcsg.gift_id = dp.product_id',
			$where,
			$arrWhereVal
		);
	}
}
