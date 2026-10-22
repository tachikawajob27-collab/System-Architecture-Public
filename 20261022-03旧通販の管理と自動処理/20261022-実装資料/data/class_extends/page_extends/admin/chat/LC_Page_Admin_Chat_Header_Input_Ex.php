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
 * チャットヘッダー更新/登録 のページクラス
 *
 * @package Page
 * @author EC-CUBE CO.,LTD.
 * @version $Id$
 */
class LC_Page_Admin_Chat_Header_Input_Ex extends LC_Page_Admin_Ex
{
	/**
	 * Page を初期化する.
	 *
	 * @return void
	 */
	function init()
	{
		parent::init();
		$this->tpl_mainpage = 'chat/header_input.tpl';
		$this->tpl_mainno = 'chat';
		$this->tpl_subno = 'header_input';
		$this->tpl_pager = 'pager.tpl';
		$this->tpl_maintitle = 'チャット管理';
		$this->tpl_subtitle = 'ヘッダー登録・編集';

		$masterData = new SC_DB_MasterData_Ex();
		$this->arrPageMax = $masterData->getMasterData('mtb_page_max');
		$this->arrDISP = $masterData->getMasterData('mtb_disp');
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

		$mode = $this->getMode();
		switch ($mode) {
			case 'pre_edit':
				// 編集
				// パラメーター初期化(商品ID)
				$this->lfInitFormParam($objFormParam, $_POST, $mode);
				// エラーチェック
				$this->arrErr = $objFormParam->checkError();
				if (count($this->arrErr) > 0) {
					trigger_error('', E_USER_ERROR);
				}

				// ID取得
				$id = $objFormParam->getValue('id');
				// データ取得
				$arrForm = $this->lfGetHeaderData($id);
				$arrForm["script_tag"] = htmlspecialchars($arrForm["script_tag"],ENT_QUOTES);
				break;

			case 'edit':
				// 登録確認
				$this->lfInitFormParam($objFormParam, $_POST, $mode);
				$arrForm = $objFormParam->getHashArray();

				// エラーチェック
				$this->arrErr = $this->lfCheckError_Edit($objFormParam, $arrForm);
				if (count($this->arrErr) == 0) {
					// DBへデータ登録
					$id = $this->lfRegister($arrForm);
					// 確認画面表示設定
					$this->tpl_mainpage = 'chat/complete.tpl';
				}
				break;

			default:
				$this->lfInitFormParam($objFormParam, $_POST, $mode);
				$objFormParam->setValue("script_tag", htmlspecialchars($_POST["script_tag"],ENT_QUOTES));
				$arrForm = $objFormParam->getHashArray();
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
		if ($mode == 'pre_edit') {
			$objFormParam->addParam('ID', 'id', INT_LEN, 'n', array('NUM_CHECK', 'MAX_LENGTH_CHECK'));
		} else {
			$objFormParam->addParam('ID', 'id', INT_LEN, 'n', array('NUM_CHECK', 'MAX_LENGTH_CHECK'));
			$objFormParam->addParam('ヘッダー名', 'name', STEXT_LEN, 'KVa', array('EXIST_CHECK', 'SPTAB_CHECK', 'MAX_LENGTH_CHECK'));
			$objFormParam->addParam('ヘッダータグ', 'tag', LLTEXT_LEN, 'KVa', array('EXIST_CHECK', 'SPTAB_CHECK', 'MAX_LENGTH_CHECK'));
			$objFormParam->addParam('シナリオ開始位置', 'view_top', INT_LEN, 'n', array('EXIST_CHECK', 'NUM_CHECK', 'MAX_LENGTH_CHECK'));
			$objFormParam->addParam('フォーム背景色', 'view_color', STEXT_LEN, 'KVa', array('EXIST_CHECK', 'SPTAB_CHECK', 'MAX_LENGTH_CHECK'), "#FFFFFF");
			$objFormParam->addParam('フッタータグ', 'foot_tag', LLTEXT_LEN, 'KVa', array('SPTAB_CHECK', 'MAX_LENGTH_CHECK'));
			$objFormParam->addParam('スクリプトタグ', 'script_tag', LLTEXT_LEN, 'KVa', array('SPTAB_CHECK', 'MAX_LENGTH_CHECK'));
		}

		$objFormParam->setParam($arrPost);
		$objFormParam->convParam();
	}

	/**
	 * フォーム入力パラメーターのエラーチェック
	 *
	 * @param  object $objFormParam SC_FormParamインスタンス
	 * @param  SC_UploadFile_Ex $objUpFile    SC_UploadFileインスタンス
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
	 * DBからチャットヘッダー情報を取得する
	 *
	 * @param  integer $id 
	 * @return string   ヘッダーデータ配列
	 */
	public function lfGetHeaderData($id)
	{
		$objQuery = SC_Query_Ex::getSingletonInstance();
		$arrResult = array();

		// 商品データ取得
		$col = '*';
		$table = "dtb_chat_header";
		$where = 'id = ? AND brand_id = ?';
		$objQuery->setLimit('1');
		$arrResult = $objQuery->select($col, $table, $where, array($id, $_SESSION["brand_id"]));
		return $arrResult[0];
	}

	/**
	 * DBにチャットヘッダーデータを登録する
	 *
	 * @param  array   $arrList     フォーム入力パラメーター配列
	 * @return integer 登録ID
	 */
	public function lfRegister($arrList)
	{
		$objQuery = SC_Query_Ex::getSingletonInstance();
		$objDb = new SC_Helper_DB_Ex();

		// INSERTする値を作成する。
		$sqlval['name'] = $arrList['name'];
		$sqlval['tag'] = $arrList['tag'];
		$sqlval['view_top'] = $arrList['view_top'];
		$sqlval['view_color'] = strtoupper($arrList['view_color']);
		$sqlval['foot_tag'] = $arrList['foot_tag'];
		$sqlval['script_tag'] = $arrList['script_tag'];
		$sqlval['update_date'] = 'CURRENT_TIMESTAMP';

		$objQuery->begin();

		// 新規登録(複製時を含む)
		if ($arrList['id'] == '') {
			$id = $objQuery->nextVal('dtb_chat_header_id');
			$sqlval['id'] = $id;
			$sqlval['brand_id'] = $_SESSION['brand_id'];

			// INSERTの実行
			$sqlval['create_date'] = 'CURRENT_TIMESTAMP';
			$objQuery->insert('dtb_chat_header', $sqlval);
			$arrList['id'] = $id;
		// 更新
		} else {
			$id = $arrList['id'];
			// UPDATEの実行
			$where = 'id = ? AND brand_id = ?';
			$objQuery->update('dtb_chat_header', $sqlval, $where, array($id, $_SESSION["brand_id"]));
		}
		$objQuery->commit();
		return $id;
	}

	/**
	 * アンカーハッシュ文字列を取得する
	 * アンカーキーをサニタイジングする
	 *
	 * @param  string $anchor_key フォーム入力パラメーターで受け取ったアンカーキー
	 * @return <type>
	 */
	public function getAnchorHash($anchor_key)
	{
		if ($anchor_key != '') {
			return "location.hash='#" . htmlspecialchars($anchor_key) . "'";
		} else {
			return '';
		}
	}
}
