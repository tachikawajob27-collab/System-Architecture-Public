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
require_once CLASS_EX_REALDIR . 'page_extends/admin/confirm/LC_Page_Admin_Confirm_Layout_Block_Ex.php';

/**
 * レイアウトブロック管理（編集）
 *
 * @package Page
 * @author EC-CUBE CO.,LTD.
 * @version $Id$
 */
class LC_Page_Admin_Confirm_Layout_Block_Edit_Ex extends LC_Page_Admin_Confirm_Layout_Block_Ex
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
		$this->tpl_mainpage = 'confirm/block_edit.tpl';
		$this->tpl_mainno = 'confirm';
		$this->tpl_subno = 'block_edit';
		$this->tpl_pager = 'pager.tpl';
		$this->tpl_maintitle = '確認表示管理';
		$this->tpl_subtitle = 'レイアウトブロック管理(編集)';

		$masterData = new SC_DB_MasterData_Ex();
		$this->arrPageMax = $masterData->getMasterData('mtb_page_max');
		$this->arrDISP = $masterData->getMasterData('mtb_disp');
		$this->arrBlockKbn = array(0=>"テキスト",1=>"スクロール",2=>"画像");

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
		switch ($mode) {
			case "pre_edit":
				// パラメーター初期化
				$this->lfInitParam_Edit($mode, $objFormParam, $_POST);
				// エラーチェック
				$this->arrErr = $objFormParam->checkError();
				if (count($this->arrErr) > 0) {
					trigger_error('', E_USER_ERROR);
				}
				// 更新情報取得
				$arrForm = $this->lfGetBlock($objFormParam->getValue('id'));
				break;
			case "edit":
				$this->lfInitParam_Edit($mode, $objFormParam, $_POST);
				$arrForm = $objFormParam->getHashArray();
				// エラーチェック
				$this->arrErr = $objFormParam->checkError();
				if (count($this->arrErr) == 0) {
					// 更新処理
					$this->lfRegister($arrForm);
					$this->tpl_mainpage = 'confirm/complete.tpl';
				}
				break;
			default:
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
			case "pre_edit":
				$objFormParam->addParam('ID', 'id', INT_LEN, 'n', array('NUM_CHECK', 'MAX_LENGTH_CHECK'));
				$objFormParam->setParam($arrPost);
				$objFormParam->convParam();
				break;

			default:
				$objFormParam->addParam('ID', 'id', INT_LEN, 'n', array('NUM_CHECK', 'MAX_LENGTH_CHECK'));
				$objFormParam->addParam('ブロック名', 'name', STEXT_LEN, 'KVa', array('EXIST_CHECK', 'SPTAB_CHECK', 'MAX_LENGTH_CHECK'));
				$objFormParam->addParam('ブロック区分', 'block_type', INT_LEN, 'n', array('EXIST_CHECK', 'NUM_CHECK', 'MAX_LENGTH_CHECK'));
				$objFormParam->addParam('コード', 'code', LLTEXT_LEN, 'KVa', array('EXIST_CHECK', 'SPTAB_CHECK'));
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
		return $objErr->arrErr;
	}

	/**
	 * 
	 */
	public function lfGetBlock($id) {
		$objQuery = SC_Query_Ex::getSingletonInstance();
		$sql = " SELECT dcb.*"
			 . " FROM dtb_confirm_block dcb"
			 . " WHERE dcb.id = ?"
		;
		$arrRet = $objQuery->getAll($sql, array($id));
		return $arrRet[0];
	}

	/**
	 * ブロック定数一覧
	 */
	public function getBlockDefine() {
		$objQuery = SC_Query_Ex::getSingletonInstance();
		$objQuery->setOrder("type, id");
		return $objQuery->select("*", "dtb_confirm_block_define", "del_flg = 0");
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

		// 更新
		$sqlVal = array();
		$sqlVal["name"] = $arrList["name"];
		$sqlVal["block_type"] = $arrList["block_type"];
		$sqlVal["code"] = $arrList["code"];
		$sqlVal["update_date"] = "CURRENT_TIMESTAMP";

		if (empty($id)) {
			$id = $objQuery->nextVal('dtb_confirm_block_id');
			$sqlVal["id"] = $id;
			$sqlVal["create_date"] = "CURRENT_TIMESTAMP";
			$sqlVal["brand_id"] = $_SESSION["brand_id"];
			$objQuery->insert('dtb_confirm_block', $sqlVal);
		} else {
			// UPDATEの実行
			$where = 'id = ?';
			$objQuery->update('dtb_confirm_block', $sqlVal, $where, array($id));
		}
	}
}
