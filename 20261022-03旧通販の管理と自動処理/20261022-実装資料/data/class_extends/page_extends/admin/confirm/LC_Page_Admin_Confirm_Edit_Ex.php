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
require_once CLASS_EX_REALDIR . 'page_extends/admin/confirm/LC_Page_Admin_Confirm_Ex.php';

/**
 * 確認表示管理（編集）
 *
 * @package Page
 * @author EC-CUBE CO.,LTD.
 * @version $Id$
 */
class LC_Page_Admin_Confirm_Edit_Ex extends LC_Page_Admin_Confirm_Ex
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
		$this->tpl_mainpage = 'confirm/edit.tpl';
		$this->tpl_mainno = 'confirm';
		$this->tpl_subno = 'edit';
		$this->tpl_pager = 'pager.tpl';
		$this->tpl_maintitle = '確認表示管理';
		$this->tpl_subtitle = '確認表示制御(編集)';

		$masterData = new SC_DB_MasterData_Ex();
		$this->arrPageMax = $masterData->getMasterData('mtb_page_max');
		$this->arrDISP = $masterData->getMasterData('mtb_disp');

		// ASP、媒体一覧
		$objAsp = new SC_Helper_Asp_Ex();
		$objBaitai = new SC_Helper_Baitai_Ex();
		$arrAsp = $objAsp->getList();
		$arrBaitai = $objBaitai->getList();
		$arrLayout = $this->getLayoutList();
		// セレクトボックス用に整形
		list($this->arrAsp, $this->arrBaitai, $this->arrLayoutAll, $this->arrLayoutDispOnly, $this->arrLayoutConfirm) = $this->lfSetSelectHtml_Edit($arrAsp, $arrBaitai, $arrLayout);
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
		// 検索パラメーター引き継ぎ
		$this->arrSearchHidden = $this->lfGetSearchParam($_POST);

		$arrForm = array();
		$mode = $this->getMode();
GC_Utils_Ex::gfPrintLog("mode：". $mode);
		switch ($mode) {
			case "pre_edit":
			case "def_edit":
				// パラメーター初期化
				$this->lfInitParam_Edit($mode, $objFormParam, $_POST);
				$arrForm = $objFormParam->getHashArray();
GC_Utils_Ex::gfPrintLog("id：". $arrForm["id"]);
GC_Utils_Ex::gfPrintLog("id2：". $_POST["id"]);
				// エラーチェック
				$this->arrErr = $this->lfCheckError($objFormParam);
				if (count($this->arrErr) > 0) {
					trigger_error('', E_USER_ERROR);
				}
				// 更新情報取得
				$arrForm = $this->lfGetConfirm($arrForm);
				break;
			case "edit":
				$this->lfInitParam_Edit($mode, $objFormParam, $_POST);
				$arrForm = $objFormParam->getHashArray();
				// エラーチェック
				$this->arrErr = $this->lfCheckError($objFormParam);
				if (count($this->arrErr) == 0) {
					// 更新処理
					$this->lfRegister($arrForm);
					$this->tpl_mainpage = 'confirm/complete.tpl';
				}
				break;
			default:
				// 初期はデフォルト設定にする
				// if ($_GET[""])
				// $arrLayoutDefault = $this->lfGetLayoutDefault();
				// foreach($arrLayoutDefault as $key => $value) {
				// 	$arrForm[$key] = $value;
				// }
				break;
		}
		$this->arrForm = $arrForm;
	}

	/**
	 * パラメーター情報の初期化を行う.
	 *
	 * @param  SC_FormParam $objFormParam SC_FormParam インスタンス
	 * @return void
	 */
	public function lfInitParam_Edit($mode, &$objFormParam, $arrPost)
	{
		switch($mode) {
			case "def_edit":
				$objFormParam->addParam('ID', 'id', INT_LEN, 'n', array('NUM_CHECK', 'MAX_LENGTH_CHECK'), 0);
				$objFormParam->addParam('商品カテゴリ', 'category_id', INT_LEN, 'n', array('NUM_CHECK', 'MAX_LENGTH_CHECK'));
				$objFormParam->setParam($arrPost);
				$objFormParam->convParam();
				break;

			case "pre_edit":
				$objFormParam->addParam('ID', 'id', INT_LEN, 'n', array('NUM_CHECK', 'MAX_LENGTH_CHECK'));
				$objFormParam->setParam($arrPost);
				$objFormParam->convParam();
				break;

			default:
				$objFormParam->addParam('ID', 'id', INT_LEN, 'n', array('NUM_CHECK', 'MAX_LENGTH_CHECK'));
				$objFormParam->addParam('【新規】確認レイアウト（PC）', 'layout_id_pc', INT_LEN, 'n', array('EXIST_CHECK', 'NUM_CHECK', 'MAX_LENGTH_CHECK'));
				$objFormParam->addParam('【新規】確認レイアウト（SP）', 'layout_id_sp', INT_LEN, 'n', array('EXIST_CHECK', 'NUM_CHECK', 'MAX_LENGTH_CHECK'));
				$objFormParam->addParam('【AmazonPay】確認レイアウト（PC）', 'layout_amazon_id_pc', INT_LEN, 'n', array('EXIST_CHECK', 'NUM_CHECK', 'MAX_LENGTH_CHECK'));
				$objFormParam->addParam('【AmazonPay】確認レイアウト（SP）', 'layout_amazon_id_sp', INT_LEN, 'n', array('EXIST_CHECK', 'NUM_CHECK', 'MAX_LENGTH_CHECK'));
				$objFormParam->addParam('【アップセル】確認レイアウト（PC）', 'layout_thanks_id_pc', INT_LEN, 'n', array('EXIST_CHECK', 'NUM_CHECK', 'MAX_LENGTH_CHECK'));
				$objFormParam->addParam('【アップセル】確認レイアウト（SP）', 'layout_thanks_id_sp', INT_LEN, 'n', array('EXIST_CHECK', 'NUM_CHECK', 'MAX_LENGTH_CHECK'));
				$objFormParam->addParam('【クロスセル】確認レイアウト（PC）', 'layout_crossell_id_pc', INT_LEN, 'n', array('EXIST_CHECK', 'NUM_CHECK', 'MAX_LENGTH_CHECK'));
				$objFormParam->addParam('【クロスセル】確認レイアウト（SP）', 'layout_crossell_id_sp', INT_LEN, 'n', array('EXIST_CHECK', 'NUM_CHECK', 'MAX_LENGTH_CHECK'));

				// 70代以上
				// $objFormParam->addParam('【新規(70代以上)】確認レイアウト（PC）', 'layout_overage_id_pc', INT_LEN, 'n', array('EXIST_CHECK', 'NUM_CHECK', 'MAX_LENGTH_CHECK'));
				// $objFormParam->addParam('【新規(70代以上)】確認レイアウト（SP）', 'layout_overage_id_sp', INT_LEN, 'n', array('EXIST_CHECK', 'NUM_CHECK', 'MAX_LENGTH_CHECK'));
				// $objFormParam->addParam('【AmazonPay(70代以上)】確認レイアウト（PC）', 'layout_amazon_overage_id_pc', INT_LEN, 'n', array('EXIST_CHECK', 'NUM_CHECK', 'MAX_LENGTH_CHECK'));
				// $objFormParam->addParam('【AmazonPay(70代以上)】確認レイアウト（SP）', 'layout_amazon_overage_id_sp', INT_LEN, 'n', array('EXIST_CHECK', 'NUM_CHECK', 'MAX_LENGTH_CHECK'));
				// $objFormParam->addParam('【アップセル(70代以上)】確認レイアウト（PC）', 'layout_thanks_overage_id_pc', INT_LEN, 'n', array('EXIST_CHECK', 'NUM_CHECK', 'MAX_LENGTH_CHECK'));
				// $objFormParam->addParam('【アップセル(70代以上)】確認レイアウト（SP）', 'layout_thanks_overage_id_sp', INT_LEN, 'n', array('EXIST_CHECK', 'NUM_CHECK', 'MAX_LENGTH_CHECK'));
				// $objFormParam->addParam('【クロスセル(70代以上)】確認レイアウト（PC）', 'layout_crossell_overage_id_pc', INT_LEN, 'n', array('EXIST_CHECK', 'NUM_CHECK', 'MAX_LENGTH_CHECK'));
				// $objFormParam->addParam('【クロスセル(70代以上)】確認レイアウト（SP）', 'layout_crossell_overage_id_sp', INT_LEN, 'n', array('EXIST_CHECK', 'NUM_CHECK', 'MAX_LENGTH_CHECK'));

				// 基本情報
				$objFormParam->addParam('ASP', 'asp_id', INT_LEN, 'n', array('NUM_CHECK', 'MAX_LENGTH_CHECK'));
				$objFormParam->addParam('媒体', 'baitai_id', INT_LEN, 'n', array('NUM_CHECK', 'MAX_LENGTH_CHECK'));
				$objFormParam->addParam('商品カテゴリ', 'category_id', INT_LEN, 'n', array('EXIST_CHECK', 'NUM_CHECK', 'MAX_LENGTH_CHECK'));

				$objFormParam->addParam('df_layout_id_pc', 'df_layout_id_pc');
				$objFormParam->addParam('df_layout_id_sp', 'df_layout_id_sp');
				$objFormParam->addParam('df_layout_amazon_id_pc', 'df_layout_amazon_id_pc');
				$objFormParam->addParam('df_layout_amazon_id_sp', 'df_layout_amazon_id_sp');
				$objFormParam->addParam('df_layout_thanks_id_pc', 'df_layout_thanks_id_pc');
				$objFormParam->addParam('df_layout_thanks_id_sp', 'df_layout_thanks_id_sp');
				break;
		}

		$objFormParam->setParam($arrPost);
		$objFormParam->convParam();
	}

	/**
	 * 検索パラメーター引き継ぎ用配列取得
	 *
	 * @param  array $arrPost $_POSTデータ
	 * @return array 検索パラメーター配列
	 */
	public function lfGetSearchParam($arrPost)
	{
		$arrSearchParam = array();
		$objFormParam = new SC_FormParam_Ex();

		parent::lfInitParam($objFormParam);
		$objFormParam->setParam($arrPost);
		$arrSearchParam = $objFormParam->getSearchArray();

		return $arrSearchParam;
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
		$arrError = $objErr->arrErr;

		$arrForm = $objFormParam->getHashArray();
		if ($arrForm["id"] == "" && $_GET["reg"] == 1) {
			// ID情報が空の場合
			if ($arrForm["asp_id"] == "") {
				$arrError["asp_id"] = "※ASPは必須項目です。<br>";
			}
			if ($arrForm["baitai_id"] == "") {
				$arrError["baitai_id"] = "※媒体は必須項目です。<br>";
			}
		}
		return $arrError;
	}

	/**
	 * 対象の確認画面設定情報を取得
	 */
	public function lfGetConfirm($arrForm) {
		$objQuery = SC_Query_Ex::getSingletonInstance();
		if ($arrForm["id"] > 0) {
			// 各ASP設定
			$sql = " SELECT dcs.*, da.name AS asp, db.name AS baitai"
				. " FROM dtb_confirm_setting dcs"
				. " INNER JOIN dtb_asp da ON dcs.asp_id = da.id AND dcs.brand_id = da.brand_id"
				. " INNER JOIN dtb_baitai db ON dcs.baitai_id = db.id AND dcs.brand_id = db.brand_id"
				. " WHERE dcs.id = ?"
			;
			$arrRet = $objQuery->getAll($sql, array($arrForm["id"]));
		} else {
			// 共通設定
			$sql = " SELECT *, 0 AS id FROM dtb_confirm_default WHERE brand_id = ? AND category_id = ?";
			$arrRet = $objQuery->getAll($sql, array($_SESSION["brand_id"], $arrForm["category_id"]));
		}
		return $arrRet[0];
	}

	/**
	 * 確認レイアウト一覧取得
	 */
	public function getLayoutList() {
		$objQuery = SC_Query_Ex::getSingletonInstance();
		$objQuery->setOrder("update_date DESC");
		return $objQuery->select("*", "dtb_confirm_layout", "del_flg = 0 AND brand_id = ?", array($_SESSION["brand_id"]));
	}

	/**
	 * DBにデータを登録する
	 *
	 * @param  array   $arrList     フォーム入力パラメーター配列
	 */
	public function lfRegister($arrList)
	{
		$objQuery = SC_Query_Ex::getSingletonInstance();
		$id = $arrList["id"];

		$objQuery->begin();

		$sqlVal = array();
		$sqlVal["category_id"] = $arrList["category_id"];
		$sqlVal["layout_id_pc"] = $arrList["layout_id_pc"];
		$sqlVal["layout_id_sp"] = $arrList["layout_id_sp"];
		$sqlVal["layout_amazon_id_pc"] = $arrList["layout_amazon_id_pc"];
		$sqlVal["layout_amazon_id_sp"] = $arrList["layout_amazon_id_sp"];
		$sqlVal["layout_thanks_id_pc"] = $arrList["layout_thanks_id_pc"];
		$sqlVal["layout_thanks_id_sp"] = $arrList["layout_thanks_id_sp"];
		$sqlVal["layout_crossell_id_pc"] = $arrList["layout_crossell_id_pc"];
		$sqlVal["layout_crossell_id_sp"] = $arrList["layout_crossell_id_sp"];
		$sqlVal["layout_overage_id_pc"] = $arrList["layout_id_pc"];
		$sqlVal["layout_overage_id_sp"] = $arrList["layout_id_sp"];
		$sqlVal["layout_amazon_overage_id_pc"] = $arrList["layout_amazon_id_pc"];
		$sqlVal["layout_amazon_overage_id_sp"] = $arrList["layout_amazon_id_sp"];
		$sqlVal["layout_thanks_overage_id_pc"] = $arrList["layout_thanks_id_pc"];
		$sqlVal["layout_thanks_overage_id_sp"] = $arrList["layout_thankse_id_sp"];
		$sqlVal["layout_crossell_overage_id_pc"] = $arrList["layout_crossell_id_pc"];
		$sqlVal["layout_crossell_overage_id_sp"] = $arrList["layout_crossell_id_sp"];
		$sqlVal["update_date"] = "CURRENT_TIMESTAMP";

		if ($id == 0 || $_GET["reg"] == 0) {
			// 共通設定
			$cnt = $objQuery->getOne("SELECT COUNT(*) FROM dtb_confirm_default WHERE brand_id = ? AND category_id = ?", array($_SESSION["brand_id"], $arrList["category_id"]));
			if ($cnt == 0) {
				// 新規
				$sqlVal["brand_id"] = $_SESSION["brand_id"];
				$sqlVal["create_date"] = "CURRENT_TIMESTAMP";
				$objQuery->insert('dtb_confirm_default', $sqlVal);
			} else {
				// 更新
				$objQuery->update('dtb_confirm_default', $sqlVal, "brand_id = ? AND category_id = ?", array($_SESSION["brand_id"], $arrList["category_id"]));
			}
		}
		else if (!empty($id)) {
			// 各ASP設定（更新）
			$where = 'id = ? AND brand_id = ?';
			$objQuery->update('dtb_confirm_setting', $sqlVal, $where, array($id, $_SESSION["brand_id"]));
		}
		else {
			// 新規作成
			$sqlVal["id"] = $objQuery->nextVal('dtb_confirm_setting_id');
			$sqlVal["asp_id"] = $arrList["asp_id"];
			$sqlVal["baitai_id"] = $arrList["baitai_id"];
			$sqlVal["brand_id"] = $_SESSION["brand_id"];
			$sqlVal["create_date"] = "CURRENT_TIMESTAMP";
			$objQuery->insert('dtb_confirm_setting', $sqlVal);
		}

		// $sqlVal2 = array();
		// $sqlVal2["layout_id_pc"] = $arrList["df_layout_id_pc"];
		// $sqlVal2["layout_id_sp"] = $arrList["df_layout_id_sp"];
		// $sqlVal2["layout_amazon_id_pc"] = $arrList["df_layout_amazon_id_pc"];
		// $sqlVal2["layout_amazon_id_sp"] = $arrList["df_layout_amazon_id_sp"];
		// $sqlVal2["layout_thanks_id_pc"] = $arrList["df_layout_thanks_id_pc"];
		// $sqlVal2["layout_thanks_id_sp"] = $arrList["df_layout_thanks_id_sp"];
		// $sqlVal2["update_date"] = "CURRENT_TIMESTAMP";

		// $cnt = $objQuery->getOne("SELECT COUNT(*) FROM dtb_confirm_default WHERE brand_id = ?", array($_SESSION["brand_id"]));
		// if ($cnt == 0) {
		// 	$sqlVal2["brand_id"] = $_SESSION["brand_id"];
		// 	$sqlVal2["create_date"] = "CURRENT_TIMESTAMP";
		// 	$objQuery->insert('dtb_confirm_default', $sqlVal2);
		// } else {
		// 	// UPDATEの実行
		// 	$where = 'brand_id = ?';
		// 	$objQuery->update('dtb_confirm_default', $sqlVal2, $where, array($_SESSION["brand_id"]));
		// }

		$objQuery->commit();
	}

	/**
	 * それぞれの配列からセレクトボックス表示用配列を作成
	 * @param array $arrAsp		ASP一覧
	 * @param array $arrBaitai	媒体一覧
	 */
	public function lfSetSelectHtml_Edit($arrAsp, $arrBaitai, $arrLayout) {
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
		// 確認画面レイアウト
		$arrLayoutSelect = array();
		$arrLayoutSelect2 = array();
		$arrLayoutSelect3 = array();
		foreach($arrLayout as $data) {
			$id = $data["id"];
			// 全て
			$arrLayoutSelect[$id] = $data["name"];
			// 確認画面のみ
			if ($data["is_confirm"] == 1) {
				$arrLayoutSelect2[$id] = $data["name"];
			}
			// チャット内包以外
			if ($data["is_confirm"] != 2) {
				$arrLayoutSelect3[$id] = $data["name"];
			}
		}
		return array($arrAspSelect, $arrBaitaiSelect, $arrLayoutSelect, $arrLayoutSelect2, $arrLayoutSelect3);
	}

	/**
	 * デフォルトのレイアウト設定情報を取得
	 */
	public function lfGetLayoutDefault() {
		$objQuery = SC_Query_Ex::getSingletonInstance();
		return $objQuery->getRow("layout_id_pc,layout_id_sp,layout_amazon_id_pc,layout_amazon_id_sp,layout_thanks_id_pc,layout_thanks_id_sp,layout_crossell_id_pc,layout_crossell_id_sp,layout_overage_id_pc,layout_overage_id_sp,layout_amazon_overage_id_pc,layout_amazon_overage_id_sp,layout_thanks_overage_id_pc,layout_thanks_overage_id_sp,layout_crossell_overage_id_pc,layout_crossell_overage_id_sp,category_id", "dtb_confirm_default", "brand_id = ?", array($_SESSION["brand_id"]));
	}
}
