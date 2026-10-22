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
require_once CLASS_EX_REALDIR . 'page_extends/admin/order/LC_Page_Admin_Order_Penalty_Fee_Ex.php';
require_once MODULE_REALDIR . 'mdl_vamos/define.php';
require_once MDL_VAMOS_CLASSEX_DIR_PATH . 'helper_extends/SC_Helper_Mdl_VAMOS_Ex.php';

/**
 * ABテスト設定登録 のページクラス
 *
 * @package Page
 * @author EC-CUBE CO.,LTD.
 * @version $Id$
 */
class LC_Page_Admin_Order_Penalty_Fee_Edit_Ex extends LC_Page_Admin_Order_Penalty_Fee_Ex
{
	/**
	 * Page を初期化する.
	 *
	 * @return void
	 */
	function init()
	{
		parent::init();
		$this->tpl_mainpage = 'order/penalty_fee_edit.tpl';
		$this->tpl_mainno = 'order';
		$this->tpl_subno = 'penalty_fee';
		$this->tpl_pager = 'pager.tpl';
		$this->tpl_maintitle = '受注管理';
		$this->tpl_subtitle = '違約金登録・編集';

		$masterData = new SC_DB_MasterData_Ex();
		$this->arrPref = $masterData->getMasterData('mtb_pref');
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
		$objFormParam = new SC_FormParam_Ex();
		// 検索パラメーター引き継ぎ
		$this->arrSearchHidden = $this->lfGetSearchParam($_POST);

		$mode = $this->getMode();
GC_Utils_Ex::gfPrintLog("mode：". $mode);
		switch ($mode) {
			case 'pre_edit':
				// 編集
				$arrPenaltyData = $this->getPenaltyData($_POST["id"]);
				if (empty($arrPenaltyData)) {
					trigger_error('', E_USER_ERROR);
					break;
				}
				// 画面情報をセット
				$this->lfInitFormParam($objFormParam, $arrPenaltyData, $mode);
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
					$ret = $this->lfRegister($arrForm);
					// 完了画面
					$this->tpl_mainpage = 'order/penalty_fee_complete.tpl';
					
				}
				break;

			case 'cancel':
				// 請求キャンセル
				$this->lfInitFormParam($objFormParam, $_POST, $mode);
				$arrForm = $objFormParam->getHashArray();
				$ret = $this->lfPenaltyFeeCancel($arrForm);
				// 完了画面
				$this->tpl_mainpage = 'order/penalty_fee_complete.tpl';
				break;

			case 'customer_search':
				// 顧客情報検索
				$this->lfInitFormParam($objFormParam, $_POST, $mode);
				$arrForm = $objFormParam->getHashArray();

				// 顧客情報を取得
				$objCustomer = new SC_Helper_Customer_Ex();
				$arrCustomer = $objCustomer->sfGetCustomerData($arrForm["customer_id"]);
				if (!empty($arrCustomer)) {
					// 最新の顧客情報をセット
					$arrForm["name"] = $arrCustomer["name01"];
					$arrForm["tel"] = $arrCustomer["tel01"];
					$arrForm["email"] = $arrCustomer["email"];
					$arrForm["zip"] = $arrCustomer["zip01"];
					$arrForm["pref"] = $arrCustomer["pref"];
					$arrForm["addr01"] = $arrCustomer["addr01"];
					$arrForm["addr02"] = $arrCustomer["addr02"];
				} else {
					// 顧客IDなし
					$this->arrErr["customer_id"] = "※ 顧客情報が見つかりませんでした。<br />";
				}
				break;

			default:
				// 請求書発行予定日（デフォルト当日）
				$arrForm = array("invoice_date" => date("Y-m-d"));
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
		$objFormParam->addParam('顧客ID', 'customer_id', INT_LEN, 'n', array('EXIST_CHECK', 'NUM_CHECK', 'MAX_LENGTH_CHECK'));
		$objFormParam->addParam('顧客名', 'name', LTEXT_LEN, 'KVCa', array('EXIST_CHECK', 'MAX_LENGTH_CHECK'));
		$objFormParam->addParam('郵便番号', 'zip', STEXT_LEN, 'KVCa', array('EXIST_CHECK', 'MAX_LENGTH_CHECK'));
		$objFormParam->addParam('都道府県', 'pref', INT_LEN, 'n', array('EXIST_CHECK','NUM_CHECK', 'MAX_LENGTH_CHECK'));
		$objFormParam->addParam('住所(市区町村)', 'addr01', LTEXT_LEN, 'KVCa', array('EXIST_CHECK', 'MAX_LENGTH_CHECK'));
		$objFormParam->addParam('住所(番地・建物名)', 'addr02', LTEXT_LEN, 'KVCa', array('EXIST_CHECK', 'MAX_LENGTH_CHECK'));
		$objFormParam->addParam('請求金額(税込)', 'payment_total', INT_LEN, 'n', array('EXIST_CHECK','NUM_CHECK', 'MAX_LENGTH_CHECK'));
		$objFormParam->addParam('請求書発行予定日', 'invoice_date', LTEXT_LEN, 'KVCa', array('EXIST_CHECK', 'MAX_LENGTH_CHECK'));
		$objFormParam->addParam('対応状況', 'status', INT_LEN, 'n', array('NUM_CHECK', 'MAX_LENGTH_CHECK'));

		$objFormParam->addParam('コメント', 'comment');
		$objFormParam->addParam('電話番号', 'tel');
		$objFormParam->addParam('メールアドレス', 'email');
		$objFormParam->addParam('penalty_order_id', 'penalty_order_id');
		$objFormParam->addParam('penalty_vamos_id', 'penalty_vamos_id');
		$objFormParam->addParam('authori_error', 'authori_error');

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
		$objVamos = new SC_Helper_Mdl_VAMOS_Ex();

		$ret = true;

		// INSERTする値を作成する。
		$sqlval['customer_id'] = $arrList['customer_id'];
		$sqlval['name'] = $arrList['name'];
		$sqlval['tel'] = $arrList['tel'];
		$sqlval['email'] = $arrList['email'];
		$sqlval['zip'] = $arrList['zip'];
		$sqlval['pref'] = $arrList['pref'];
		$sqlval['addr01'] = $arrList['addr01'];
		$sqlval['addr02'] = $arrList['addr02'];
		$sqlval['payment_total'] = (int)str_replace(",", "", $arrList['payment_total']);
		$sqlval['invoice_date'] = $arrList['invoice_date'];
		$sqlval['comment'] = $arrList['comment'];
		$sqlval['brand_id'] = $_SESSION["brand_id"];
		$sqlval['update_date'] = 'CURRENT_TIMESTAMP';
		$objQuery->begin();

		// 新規登録(複製時を含む)
		if ($arrList['id'] == '') {
			$id = $objQuery->nextVal('dtb_order_penalty_fee_id');
			$sqlval['id'] = $id;
			$sqlval['status'] = 0;
			$sqlval['create_date'] = 'CURRENT_TIMESTAMP';
			$sqlval['penalty_order_id'] = "PN" . $arrList['customer_id'] . $id;	// PN + 顧客ID + データID

			// INSERTの実行
			$objQuery->insert('dtb_order_penalty_fee', $sqlval);
			$arrList['id'] = $id;
		// 更新
		} else {
			// UPDATEの実行
			$objQuery->update('dtb_order_penalty_fee', $sqlval, "id = ?", array($arrList['id']));
		}
		$objQuery->commit();

		if (empty($arrList["status"])) {
			// 請求書発行予定日確認
			$today = date("Y/m/d");
			$target_day = $arrList['invoice_date'];
			if (strtotime($today) === strtotime($target_day)) {
				// 発行予定日を当日指定の場合はここでデータ登録
				$ret = $objVamos->requestPenaltyCredit($arrList['id']);
			}
		}

		return $ret;
	}

	/**
	 * 違約金請求キャンセル処理
	 */
	public function lfPenaltyFeeCancel($arrForm) {
		$objVamos = new SC_Helper_Mdl_VAMOS_Ex();
		$objVamos->cancelPenaltyCredit($arrForm['id']);
	}

	/**
	 * 違約金請求情報を取得
	 */
	public function getPenaltyData($id) {
		$objQuery = SC_Query_Ex::getSingletonInstance();
		$sql = " SELECT dop.id, dop.customer_id, dop.name, dop.tel, dop.email, dop.zip, dop.pref, dop.addr01, dop.addr02, dop.payment_total, dop.status, dop.comment, to_char(dop.invoice_date,'YYYY-MM-DD') AS invoice_date, to_char(dop.create_date,'YYYY-MM-DD') AS create_date, dop.penalty_order_id, dop.authori_error, dop.penalty_vamos_id"
			 . " FROM dtb_order_penalty_fee dop "
			 . " WHERE dop.id = ?"
		;
		$arrResult = $objQuery->getAll($sql, array($id));
		return $arrResult[0];
	}
}
