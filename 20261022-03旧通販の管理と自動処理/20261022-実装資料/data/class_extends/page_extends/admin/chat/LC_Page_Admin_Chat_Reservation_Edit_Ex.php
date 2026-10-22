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
require_once CLASS_EX_REALDIR . 'page_extends/admin/chat/LC_Page_Admin_Chat_Reservation_Ex.php';

/**
 * チャットアイコン更新/登録 のページクラス
 *
 * @package Page
 * @author EC-CUBE CO.,LTD.
 * @version $Id$
 */
class LC_Page_Admin_Chat_Reservation_Edit_Ex extends LC_Page_Admin_Chat_Reservation_Ex
{
	/**
	 * Page を初期化する.
	 *
	 * @return void
	 */
	function init()
	{
		parent::init();
		$this->tpl_mainpage = 'chat/reservation_edit.tpl';
		$this->tpl_mainno = 'chat';
		$this->tpl_subno = 'reservation';
		$this->tpl_pager = 'pager.tpl';
		$this->tpl_maintitle = 'チャット管理';
		$this->tpl_subtitle = 'シナリオ予約切替(登録・編集)';

		$masterData = new SC_DB_MasterData_Ex();
		$this->arrPageMax = $masterData->getMasterData('mtb_page_max');
		$this->arrDISP = $masterData->getMasterData('mtb_disp');

		$this->arrChangeKbn = array(0 => "全て", 1 => "個別");
		$this->arrReservationMode = array(0 => "即時", 1 => "予約");

		// 商品カテゴリ一覧（セレクトボックス用）
		$objBrand = new SC_Helper_Brand_Ex();
		$this->arrCategory = $objBrand->getBrandCategoryList($_SESSION["brand_id"]);

		// 予約時間帯
		for ($i = 0; $i <= 23; $i++) {
			$this->arrReservationTime[$i] = $i . "時";
		}
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
GC_Utils_Ex::gfPrintLog("mode：". $mode);
		switch ($mode) {
			case 'pre_edit':
				// 編集
				$arrReservation = $this->getReservation($_POST["id"]);
				if (empty($arrReservation)) {
					trigger_error('', E_USER_ERROR);
					break;
				}
				// 画面情報をセット
				$this->lfInitFormParam($objFormParam, $arrReservation, $mode);
				$arrForm = $objFormParam->getHashArray();

				// 情報取得
				$this->arrScenarioBf = $this->getChatScenario(0, $_SESSION["brand_id"], $arrForm["category_id"]);
				$this->arrScenarioAf = $this->getChatScenario(1, $_SESSION["brand_id"], $arrForm["category_id"]);
				$this->arrReservationModel = $this->getReservationModel($arrForm, $_SESSION["brand_id"]);
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
					$this->tpl_onload = "eccube.changeAction('./reservation.php'); eccube.setModeAndSubmit('search', '', ''); window.alert('登録完了しました！！'); return false;";
				} else {
					$this->arrScenarioBf = $this->getChatScenario(0, $_SESSION["brand_id"], $_POST["category_id"]);
					$this->arrScenarioAf = $this->getChatScenario(1, $_SESSION["brand_id"], $_POST["category_id"]);
					$this->arrReservationModel = $this->getReservationModel($_POST, $_SESSION["brand_id"]);
				}
				break;

			case 'select_category':
				// 商品カテゴリ選択
				$this->lfInitFormParam($objFormParam, $_POST, $mode);
				$this->arrScenarioBf = $this->getChatScenario(0, $_SESSION["brand_id"], $_POST["category_id"]);
				$arrForm = $objFormParam->getHashArray();
				break;

			case 'select_scenario_before':
				// 切替元シナリオ選択
				$this->lfInitFormParam($objFormParam, $_POST, $mode);
				$this->arrScenarioBf = $this->getChatScenario(0, $_SESSION["brand_id"], $_POST["category_id"]);
				$this->arrScenarioAf = $this->getChatScenario(1, $_SESSION["brand_id"], $_POST["category_id"]);
				$this->arrReservationModel = $this->getReservationModel($_POST, $_SESSION["brand_id"]);
				$arrForm = $objFormParam->getHashArray();
				break;

			default:
				$def_reservation_date = date("Y-m-d", strtotime("+1 hour"));
				$def_reservation_time = date("H", strtotime("+1 hour"));

				$arrForm = array("reservation_date" => $def_reservation_date, "reservation_time" => (int)$def_reservation_time);
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
		$objFormParam->addParam('商品カテゴリ', 'category_id', INT_LEN, 'n', array('EXIST_CHECK', 'NUM_CHECK', 'MAX_LENGTH_CHECK'));
		$objFormParam->addParam('切替元シナリオ', 'scenario_no_before', INT_LEN, 'n', array('EXIST_CHECK', 'NUM_CHECK', 'MAX_LENGTH_CHECK'));
		$objFormParam->addParam('切替先シナリオ', 'scenario_no_after', INT_LEN, 'n', array('EXIST_CHECK', 'NUM_CHECK', 'MAX_LENGTH_CHECK'));
		$objFormParam->addParam('切替区分', 'change_kbn', INT_LEN, 'n', array('EXIST_CHECK', 'NUM_CHECK', 'MAX_LENGTH_CHECK'));
		$objFormParam->addParam('予約モード', 'reservation_mode', INT_LEN, 'n', array('EXIST_CHECK', 'NUM_CHECK', 'MAX_LENGTH_CHECK'));

		$objFormParam->addParam('model_check', 'model_check');
		$objFormParam->addParam('reservation_date', 'reservation_date');
		$objFormParam->addParam('reservation_time', 'reservation_time');

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

		if (empty($arrErr)) {
			// それぞれモードにより必須確認
			if ($arrForm["change_kbn"] == "1") {
				// 切替区分「個別」の場合
				if (empty($arrForm["model_check"])) {
					$arrErr["model_check"] = "※ 切替区分「個別」の場合は、新規モデルを1つ以上選択してください。 <br />";
				}
			}
			if ($arrForm["reservation_mode"] == "1") {
				// 予約モード「予約」の場合
				if (empty($arrForm["reservation_date"]) || $arrForm["reservation_time"] == "") {
					$arrErr["reservation_mode"] = "※ 予約モード「予約」の場合は、予約日時を入力してください。 <br />";
				}

				// 現在から1時間後より前の指定は不可
				$chk_reservation_date = date("Y-m-d", strtotime("+1 hour"));
				$chk_reservation_time = (int)date("H", strtotime("+1 hour"));
				if ($arrForm["reservation_date"] < $chk_reservation_date) {
					// 過去日付の選択を不可
					$arrErr["reservation_mode"] = "※ 予約日に過去日付が指定されています。 <br />";
				}
				if ($arrForm["reservation_date"] == $chk_reservation_date && $arrForm["reservation_time"] < $chk_reservation_time) {
					// 当日1時間以内の選択は不可
					$arrErr["reservation_mode"] = "※ 予約時間は当日1時間以降を選択してください。 <br />";
				}
			}
		}
		return $arrErr;
	}

	/**
	 * DBにチャットアイコンデータを登録する
	 *
	 * @param  SC_UploadFile_Ex  $objUpFile   SC_UploadFileインスタンス
	 * @param  array   $arrList     フォーム入力パラメーター配列
	 * @return integer 登録ID
	 */
	public function lfRegister($arrList)
	{
		$objQuery = SC_Query_Ex::getSingletonInstance();
		$objDb = new SC_Helper_DB_Ex();

		// INSERTする値を作成する。
		$sqlval['scenario_no_before'] = $arrList['scenario_no_before'];
		$sqlval['change_kbn'] = $arrList['change_kbn'];
		$sqlval['scenario_no_after'] = $arrList['scenario_no_after'];
		$sqlval['reservation_mode'] = $arrList['reservation_mode'];
		$sqlval['status'] = 0;
		$sqlval['brand_id'] = $_SESSION["brand_id"];
		$sqlval['update_date'] = 'CURRENT_TIMESTAMP';
		$sqlval['updater_id'] = $_SESSION['member_id'];
		if ($arrList['reservation_mode'] == 1) {
			// 予約指定の場合
			$sqlval['reservation_date'] = $arrList['reservation_date'];
			$sqlval['reservation_time'] = $arrList['reservation_time'];
		} else {
			// 即時の場合
			$sqlval['reservation_date'] = null;
			$sqlval['reservation_time'] = null;
		}
		$objQuery->begin();

		// 新規登録(複製時を含む)
		if ($arrList['id'] == '') {
			$id = $objQuery->nextVal('dtb_chat_scenario_reservation_id');
			$sqlval['id'] = $id;
			$sqlval['create_date'] = 'CURRENT_TIMESTAMP';
			$sqlval['creater_id'] = $_SESSION['member_id'];

			// INSERTの実行
			$objQuery->insert('dtb_chat_scenario_reservation', $sqlval);
			$arrList['id'] = $id;
		// 更新
		} else {
			// UPDATEの実行
			$objQuery->update('dtb_chat_scenario_reservation', $sqlval, "id = ?", array($arrList['id']));
			// 個別選択情報を一時削除する
			$objQuery->delete("dtb_chat_scenario_reservation_model", "reservation_id = ?", array($arrList['id']));
		}

		// 個別選択情報を登録
		if ($arrList['change_kbn'] == 1) {
			// 選択モデルを登録
			foreach($arrList["model_check"] as $model_id) {
				$arrVal = array();
				$arrVal["reservation_id"] = $arrList['id'];
				$arrVal["model_id"] = $model_id;
				$arrVal["create_date"] = "CURRENT_TIMESTAMP";
				$objQuery->insert("dtb_chat_scenario_reservation_model", $arrVal);
			}
		}

		// 即時の場合シナリオ切替処理
		if ($arrList['reservation_mode'] == 0) {
			// 切替区分確認
			if ($arrList['change_kbn'] == 0) {
				// 全て
				$arrUpdate = array();
				$arrUpdate["scenario_no"] = $arrList['scenario_no_after'];
				$arrUpdate['update_date'] = 'CURRENT_TIMESTAMP';
				$objQuery->update("dtb_model", $arrUpdate, "del_flg = 0 AND is_chat = 0 AND brand_id = ? AND scenario_no = ?", array($_SESSION["brand_id"], $arrList["scenario_no_before"]));
			} else {
				// 個別
				$arrUpdate = array();
				$arrUpdate["scenario_no"] = $arrList['scenario_no_after'];
				$arrUpdate['update_date'] = 'CURRENT_TIMESTAMP';
				// 選択モデルのシナリオを更新
				foreach($arrList["model_check"] as $model_id) {
					$objQuery->update("dtb_model", $arrUpdate, "del_flg = 0 AND is_chat = 0 AND brand_id = ? AND scenario_no = ? AND id = ?", array($_SESSION["brand_id"], $arrList["scenario_no_before"], $model_id));
				}
			}
			// 予約テーブルの状態を完了に更新
			$objQuery->update('dtb_chat_scenario_reservation', array("status"=>1, "update_date" => "CURRENT_TIMESTAMP"), "id = ?", array($arrList['id']));
		}

		$objQuery->commit();
		return;
	}

	/**
	 * 選択可能シナリオリストを取得
	 */
	public function getChatScenario($mode, $brand_id, $category_id = null) {
		$objQuery = SC_Query_Ex::getSingletonInstance();
		
		$arrScenario = array();
		switch($mode) {
			// 切替元シナリオ
			case 0:
				$sql = " SELECT DISTINCT dm.scenario_no, dcsi.scenario_name, dcsi.category_id"
					 . " FROM dtb_model dm INNER JOIN dtb_chat_scenario_info dcsi ON dm.scenario_no = dcsi.scenario_no"
					 . " WHERE dm.del_flg = 0 AND dm.is_chat = 0 AND dm.brand_id = ? AND dcsi.category_id = ?"
					 . " ORDER BY dcsi.category_id, dm.scenario_no DESC"
				;
				$arrData = $objQuery->getAll($sql, array($brand_id, $category_id));
				foreach($arrData as $data) {
					$arrScenario[$data["scenario_no"]] = $data["scenario_no"] . " - " . $data["scenario_name"];
				}
				break;
			// 切替先シナリオ
			case 1:
				$sql = " SELECT dcsi.scenario_no, dcsi.scenario_name"
					 . " FROM dtb_chat_scenario_info dcsi"
					 . " WHERE dcsi.del_flg = 0 AND dcsi.brand_id = ? AND dcsi.category_id = ?"
					 . " ORDER BY dcsi.scenario_no DESC"
				;
				$arrData = $objQuery->getAll($sql, array($brand_id, $category_id));
				foreach($arrData as $data) {
					$arrScenario[$data["scenario_no"]] = $data["scenario_no"] . " - " . $data["scenario_name"];
				}
				break;
		}
		return $arrScenario;
	}

	/**
	 * 選択シナリオに紐付く新規モデルを取得
	 */
	public function getReservationModel($arrParams, $brand_id) {
		$objQuery = SC_Query_Ex::getSingletonInstance();

		$arrRet = array();
		if (empty($arrParams["id"])) {
			// 新規登録
			$sql = " SELECT dm.id, dm.name, dcsi.category_id"
				 . " FROM dtb_model dm INNER JOIN dtb_chat_scenario_info dcsi ON dm.scenario_no = dcsi.scenario_no"
				 . " WHERE dm.del_flg = 0 AND dm.is_chat = 0 AND dm.brand_id = ? AND dm.scenario_no = ?"
				 . " ORDER BY dm.id DESC"
			;
			$arrRet = $objQuery->getAll($sql, array($brand_id, $arrParams["scenario_no_before"]));
		}
		else {
			// 編集
			$sql = " SELECT dm.id, dm.name, dcsi.category_id, dcsrm.reservation_id"
				 . " FROM dtb_model dm INNER JOIN dtb_chat_scenario_info dcsi ON dm.scenario_no = dcsi.scenario_no"
				 . " LEFT JOIN dtb_chat_scenario_reservation_model dcsrm ON dm.id = dcsrm.model_id AND dcsrm.reservation_id = ?"
				 . " WHERE dm.del_flg = 0 AND dm.is_chat = 0 AND dm.brand_id = ? AND dm.scenario_no = ?"
				 . " ORDER BY dm.id DESC"
			;
			$arrRet = $objQuery->getAll($sql, array($arrParams["id"], $brand_id, $arrParams["scenario_no_before"]));
		}
		return $arrRet;
	}

	/**
	 * シナリオ切替予約情報を取得
	 */
	public function getReservation($id) {
		$objQuery = SC_Query_Ex::getSingletonInstance();
		return $objQuery->getRow("dcsr.*, dcsi.category_id", "dtb_chat_scenario_reservation dcsr INNER JOIN dtb_chat_scenario_info dcsi ON dcsr.scenario_no_before = dcsi.scenario_no", "dcsr.id = ?", array($id));
	}
}
