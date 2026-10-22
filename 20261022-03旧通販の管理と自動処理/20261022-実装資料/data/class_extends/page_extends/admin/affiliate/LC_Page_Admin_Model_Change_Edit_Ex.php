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
require_once CLASS_EX_REALDIR . 'page_extends/admin/affiliate/LC_Page_Admin_Model_Change_Ex.php';

/**
 * コードモデル変更（登録/編集） のページクラス
 *
 * @package Page
 * @author EC-CUBE CO.,LTD.
 */
class LC_Page_Admin_Model_Change_Edit_Ex extends LC_Page_Admin_Model_Change_Ex
{
	/**
	 * Page を初期化する.
	 *
	 * @return void
	 */
	function init()
	{
		parent::init();
		$this->tpl_mainpage = 'affiliate/model_change_edit.tpl';
		$this->tpl_mainno = 'affiliate';
		$this->tpl_subno = 'model_change';
		$this->tpl_pager = 'pager.tpl';
		$this->tpl_maintitle = '広告管理';
		$this->tpl_subtitle = 'コードモデル変更（登録/編集）';

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

		// LP一覧
		$this->arrLps = $this->lfGetLps($_SESSION["brand_id"]);

		// 親から情報取得
		list($this->arrAsp, $this->arrMedia, $this->arrBaitai) = parent::lfSetSelectHtml($arrAsp, $arrMedia, $arrBaitai);
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
		$objFormParam = new SC_FormParam_Ex();
		// 検索パラメーター引き継ぎ
		$this->arrSearchHidden = $this->lfGetSearchParam($_POST);
		$mode = $this->getMode();

		switch ($mode) {
			case 'pre_edit':
				// 編集
				$arrModelChange = $this->getModelChange($_POST["id"]);
				if (empty($arrModelChange)) {
					trigger_error('', E_USER_ERROR);
					break;
				}
				// 画面情報をセット
				$this->lfInitFormParam($objFormParam, $arrModelChange, $mode);
				$arrForm = $objFormParam->getHashArray();
				break;

			case 'edit':
				// 登録完了
				$this->lfInitFormParam($objFormParam, $_POST, $mode);
				$arrForm = $objFormParam->getHashArray();

				// エラーチェック
				$this->arrErr = $this->lfCheckError_Edit($objFormParam, $arrForm);
GC_Utils_Ex::gfPrintLog("arrErr：". serialize($this->arrErr));
				if (count($this->arrErr) == 0) {
					// 登録処理
					// DBへデータ登録
GC_Utils_Ex::gfPrintLog("arrForm：". serialize($arrForm));
					$id = $this->lfRegister($arrForm);
					// 完了画面
					$this->tpl_mainpage = 'affiliate/model_change_complete.tpl';
					
				}
				break;

			case "model_select":
				// 初期設定
				$this->lfInitFormParam($objFormParam, $_POST, $mode);
				$arrForm = $objFormParam->getHashArray();
				$target = $_POST["select_target"];
				$model_id = $_POST["select_model_id"];
				// モデル情報を取得
				$arrModel = $this->lfGetModelData($model_id);
				// 新規モデル・コース情報を格納
				if ($target == "model") {
					$arrForm["model_id"] = $arrModel["id"];
					$arrForm["model_name"] = $arrModel["name"];
				} else {
					$arrForm["model_id2"] = $arrModel["id"];
					$arrForm["model_name2"] = $arrModel["name"];
				}
				break;

			default:
				$arrForm = array("start_date" => date("Y-m-d"));
				break;
		}
		$this->arrForm = $arrForm;
	}

	/**
	 * パラメーター情報の初期化
	 *
	 * @param  SC_FormParam_Ex $objFormParam SC_FormParamインスタンス
	 * @param  array  $arrPost      $_POSTデータ
	 * @param  integer  $mode      モード
	 * @return void
	 */
	public function lfInitFormParam(&$objFormParam, $arrPost, $mode)
	{
		$objFormParam->addParam('ID', 'id', INT_LEN, 'n', array('NUM_CHECK', 'MAX_LENGTH_CHECK'));
		$objFormParam->addParam('使用状況', 'status', INT_LEN, 'n', array('EXIST_CHECK', 'NUM_CHECK', 'MAX_LENGTH_CHECK'));
		$objFormParam->addParam('商品カテゴリ', 'category_id', INT_LEN, 'n', array('EXIST_CHECK', 'NUM_CHECK', 'MAX_LENGTH_CHECK'));
		$objFormParam->addParam('ASP', 'asp_id', INT_LEN, 'n', array('EXIST_CHECK', 'NUM_CHECK', 'MAX_LENGTH_CHECK'));
		$objFormParam->addParam('メディア', 'media_id', INT_LEN, 'n', array('EXIST_CHECK', 'NUM_CHECK', 'MAX_LENGTH_CHECK'));
		$objFormParam->addParam('媒体', 'baitai_id', INT_LEN, 'n', array('EXIST_CHECK', 'NUM_CHECK', 'MAX_LENGTH_CHECK'));
		$objFormParam->addParam('開始日', 'start_date', STEXT_LEN, 'KVa', array('EXIST_CHECK', 'MAX_LENGTH_CHECK'));
		$objFormParam->addParam('終了日', 'end_date', STEXT_LEN, 'KVa', array('MAX_LENGTH_CHECK'));
		$objFormParam->addParam('新規モデル①', 'model_id', INT_LEN, 'n', array('EXIST_CHECK', 'NUM_CHECK', 'MAX_LENGTH_CHECK'));
		$objFormParam->addParam('新規モデル②', 'model_id2', INT_LEN, 'n', array('NUM_CHECK', 'MAX_LENGTH_CHECK'));

		$objFormParam->addParam('切替LP画像(クラス名)', 'lp_target_class', STEXT_LEN, 'KVa', array('MAX_LENGTH_CHECK'));
		$objFormParam->addParam('切替LP画像(画像パス)', 'lp_change_image_path', LTEXT_LEN, 'KVa', array('MAX_LENGTH_CHECK'));

		$objFormParam->addParam('model_name', 'model_name');
		$objFormParam->addParam('model_name2', 'model_name2');
		
		$objFormParam->addParam('LPデザイン', 'lp_id', INT_LEN, 'n', array('NUM_CHECK', 'MAX_LENGTH_CHECK'));
		// $objFormParam->addParam('memo', 'memo');

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
	 * フォーム入力パラメーターのエラーチェック
	 *
	 * @param  object $objFormParam SC_FormParamインスタンス
	 * @param  array  $arrForm      フォーム入力パラメーター配列
	 * @return array  エラー情報を格納した連想配列
	 */
	public function lfCheckError_Edit(&$objFormParam, $arrForm)
	{
		$objErr = new SC_CheckError_Ex($arrForm);
		$arrErr = array();
		// 入力パラメーターチェック
		$arrErr = $objFormParam->checkError();

		// 日付チェック
		$today = date("Y-m-d");
		if ($arrForm["id"] != "") {
			// 編集
			if (!empty($arrForm["end_date"]) && $arrForm["end_date"] < $today) {
				$arrErr["end_date"] = "終了日は当日以降の日付を選択してください。<br />";
			}
			if (!empty($arrForm["end_date"]) && $arrForm["end_date"] < $arrForm["start_date"]) {
				$arrErr["end_date"] = "終了日は開始日より前日付を選択してください。<br />";
			}
		} else {
			// 登録
			if (!empty($arrForm["start_date"]) && $arrForm["start_date"] < $today) {
				$arrErr["start_date"] = "開始日は当日以降の日付を選択してください。<br />";
			}
			if (!empty($arrForm["end_date"]) && $arrForm["end_date"] <= $today) {
				$arrErr["end_date"] = "終了日は当日以降の日付を選択してください。<br />";
			}
		}

		return $arrErr;
	}

	/**
	 * ABテスト設定情報を登録
	 *
	 * @param  array   $arrList     フォーム入力パラメーター配列
	 * @return integer 登録ID
	 */
	public function lfRegister($arrList)
	{
		$objQuery = SC_Query_Ex::getSingletonInstance();
		$objDb = new SC_Helper_DB_Ex();

		// INSERTする値を作成する。
		$sqlval['category_id'] = $arrList['category_id'];
		$sqlval['asp_id'] = $arrList['asp_id'];
		$sqlval['media_id'] = $arrList['media_id'];
		$sqlval['baitai_id'] = $arrList['baitai_id'];
		$sqlval['start_date'] = $arrList['start_date'];
		$sqlval['end_date'] = $arrList['end_date'];
		$sqlval['status'] = $arrList['status'];
		$sqlval['model_id'] = $arrList['model_id'];
		$sqlval['model_id2'] = $arrList['model_id2'];
		$sqlval['lp_target_class'] = $arrList['lp_target_class'];
		$sqlval['lp_change_image_path'] = $arrList['lp_change_image_path'];
		$sqlval['lp_id'] = $arrList['lp_id'];
		$sqlval['brand_id'] = $_SESSION["brand_id"];
		$sqlval['update_date'] = 'CURRENT_TIMESTAMP';
		if (empty($arrList['end_date']) && $arrList['status'] == 1) {
			// 未使用に変更した場合
			$sqlval['end_date'] = "CURRENT_TIMESTAMP";
		}
		if (!empty($arrList['end_date']) && $arrList['status'] == 0) {
			// 使用中に変更した場合
			$sqlval['end_date'] = NULL;
		}
		$objQuery->begin();

		// 新規登録(複製時を含む)
		if ($arrList['id'] == '') {
			$id = $objQuery->nextVal('dtb_lps_code_model_change_id');
			$sqlval['id'] = $id;
			$sqlval['create_date'] = 'CURRENT_TIMESTAMP';
			// INSERTの実行
			$objQuery->insert('dtb_lps_code_model_change', $sqlval);
			$arrList['id'] = $id;
		// 更新
		} else {
			// UPDATEの実行
			$objQuery->update('dtb_lps_code_model_change', $sqlval, "id = ?", array($arrList['id']));
		}
		$objQuery->commit();

		return;
	}

	/**
	 * コードモデル変更情報取得
	 */
	public function getModelChange($id) {
		$objQuery = SC_Query_Ex::getSingletonInstance();
		$sql = " SELECT dlc.*, "
			 . " (SELECT dm.name FROM dtb_model dm WHERE dlc.model_id = dm.id) AS model_name,"
			 . " (SELECT dm2.name FROM dtb_model dm2 WHERE dlc.model_id2 = dm2.id) AS model_name2"
			 . " FROM dtb_lps_code_model_change dlc"
			 . " WHERE dlc.id = ?"
		;
		$arrResult = $objQuery->getAll($sql, array($id));
		return $arrResult[0];
	}

		/**
	 * DBからモデルデータを取得する
	 *
	 * @param  integer $id モデルID
	 * @return string   モデルデータ配列
	 */
	public function lfGetModelData($id) {
		$objQuery = SC_Query_Ex::getSingletonInstance();
		return $objQuery->getRow("*", "dtb_model", "id = ?", array($id));
	}

	/**
	 * LPデザイン情報の取得
	 */
	public function lfGetLps($brand_id) {
		$objQuery =& SC_Query_Ex::getSingletonInstance();
		$sql = " SELECT DISTINCT dlc.lp_id, dl.name, func_get_lpcode_category(dlc.adcode) AS category_id, dl.status"
			 . " FROM dtb_lps_code dlc INNER JOIN dtb_lps dl ON dlc.lp_id = dl.id"
			 . " WHERE dlc.del_flg = 0 AND dlc.brand_id = ? AND dl.del_flg = 0 AND dl.status = 0"
			 . " ORDER BY category_id, dl.status, dlc.lp_id DESC"
		;
		return $objQuery->getAll($sql, array($brand_id));
	}
}
