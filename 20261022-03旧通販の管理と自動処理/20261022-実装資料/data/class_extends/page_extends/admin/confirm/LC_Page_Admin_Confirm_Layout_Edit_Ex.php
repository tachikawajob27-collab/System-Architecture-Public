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
require_once CLASS_EX_REALDIR . 'page_extends/admin/confirm/LC_Page_Admin_Confirm_Layout_Ex.php';

/**
 * 確認表示管理（編集）
 *
 * @package Page
 * @author EC-CUBE CO.,LTD.
 * @version $Id$
 */
class LC_Page_Admin_Confirm_Layout_Edit_Ex extends LC_Page_Admin_Confirm_Layout_Ex
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
		$this->tpl_mainpage = 'confirm/layout_edit.tpl';
		$this->tpl_mainno = 'confirm';
		$this->tpl_subno = 'layout_edit';
		$this->tpl_pager = 'pager.tpl';
		$this->tpl_maintitle = '確認表示管理';
		$this->tpl_subtitle = '確認表示レイアウト(編集)';

		$masterData = new SC_DB_MasterData_Ex();
		$this->arrPageMax = $masterData->getMasterData('mtb_page_max');
		$this->arrDISP = $masterData->getMasterData('mtb_disp');

		// ブロックリスト
		$this->arrBlock = $this->getBlockList();
		$this->arrConfirm = array(0 => "確認ポップ", 1 => "確認画面", 2 => "チャット内包");
		// $this->arrDevice = array(0 => "SP", 1 => "PC");
		// $this->arrDefault = array(0 => "OFF", 1 => "ON");

		// ダミーモデル（システム用）
		$this->arrModel = $this->lfGetModelData();

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
				$arrForm = $this->lfGetLayout($objFormParam->getValue('id'));

				// レイアウト設定情報を取得
				$arrDetail = $this->lfGetLayoutDetail($objFormParam->getValue('id'));
				$this->arrLayoutDetail = array();
				foreach($arrDetail as $key => $data) {
					$this->arrLayoutDetail[$key] = $data;
					if (strpos($data["code"], "___MODEL-IMAGE-01___") !== false) {
						$this->arrLayoutDetail[$key]["code"] = str_replace("___MODEL-IMAGE-01___", "/cart/html/upload/save_image/".$this->arrModel["confirm_image_1"], $data["code"]);
					}
					if (strpos($data["code"], "___MODEL-IMAGE-02___") !== false) {
						$this->arrLayoutDetail[$key]["code"] = str_replace("___MODEL-IMAGE-02___", "/cart/html/upload/save_image/".$this->arrModel["confirm_image_2"], $data["code"]);
					}
					if (strpos($data["code"], "___MODEL-IMAGE-03___") !== false) {
						$this->arrLayoutDetail[$key]["code"] = str_replace("___MODEL-IMAGE-03___", "/cart/html/upload/save_image/".$this->arrModel["confirm_image_3"], $data["code"]);
					}
				}
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
			case "get_block":
				$block_id = $_POST["select_block"];
				// ブロック情報取得
				$arrBlock = $this->getBlock($block_id);

				// 送信配列
				$arrSend = array();
				$arrSend["id"] = $block_id;
				$arrSend["block_name"] = $arrBlock["name"];
				$arrSend["block_type"] = $arrBlock["block_type"];
				$arrSend["code"] = $arrBlock["code"];

				// フリー項目チェック
				if (strpos($arrBlock["code"], "___FREE") !== false) {
					$arrSend["is_free"] = 1;
				} else {
					$arrSend["is_free"] = 0;
				}

				// モデル参照ブロック
				$arrSend["is_model"] = 0;
				if (strpos($arrBlock["code"], "___MODEL-IMAGE-01___") !== false) {
					$arrSend["is_model"] = 1;
				}
				if (strpos($arrBlock["code"], "___MODEL-IMAGE-02___") !== false) {
					$arrSend["is_model"] = 2;
				}
				if (strpos($arrBlock["code"], "___MODEL-IMAGE-03___") !== false) {
					$arrSend["is_model"] = 3;
				}

				echo SC_Utils_Ex::jsonEncode($arrSend);
				exit;
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
				$objFormParam->addParam('レイアウト名', 'name', STEXT_LEN, 'KVa', array('EXIST_CHECK', 'SPTAB_CHECK', 'MAX_LENGTH_CHECK'));
				$objFormParam->addParam('レイアウト区分', 'is_confirm', INT_LEN, 'n', array('EXIST_CHECK', 'NUM_CHECK', 'MAX_LENGTH_CHECK'));
				// $objFormParam->addParam('デバイス', 'is_device', INT_LEN, 'n', array('EXIST_CHECK', 'NUM_CHECK', 'MAX_LENGTH_CHECK'));
				
				/* レイアウト設定 */
				$objFormParam->addParam('ブロック区分', 'block_id', INT_LEN, 'n', array('EXIST_CHECK', 'NUM_CHECK', 'MAX_LENGTH_CHECK'));
				$objFormParam->addParam('横幅', 'pro_width', INT_LEN, 'n', array('NUM_CHECK', 'MAX_LENGTH_CHECK'));
				$objFormParam->addParam('文字サイズ', 'pro_fontsize', INT_LEN, 'n', array('NUM_CHECK', 'MAX_LENGTH_CHECK'));
				$objFormParam->addParam('文字色', 'pro_color', STEXT_LEN, 'KVa', array('SPTAB_CHECK', 'MAX_LENGTH_CHECK'));
				$objFormParam->addParam('背景色', 'pro_bgcolor', STEXT_LEN, 'KVa', array('SPTAB_CHECK', 'MAX_LENGTH_CHECK'));

				$objFormParam->addParam('block_name', 'block_name');
				$objFormParam->addParam('block_type', 'block_type');
				$objFormParam->addParam('customize_code', 'customize_code');
				// $objFormParam->addParam('is_default', 'is_default');
				$objFormParam->addParam('is_flow', 'is_flow');
				$objFormParam->addParam('template', 'template');
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
	public function lfGetLayout($id) {
		$objQuery = SC_Query_Ex::getSingletonInstance();
		$sql = " SELECT dcl.*"
			 . " FROM dtb_confirm_layout dcl"
			 . " WHERE dcl.id = ?"
		;
		$arrRet = $objQuery->getAll($sql, array($id));
		return $arrRet[0];
	}
	public function lfGetLayoutDetail($id) {
		$objQuery = SC_Query_Ex::getSingletonInstance();
		$sql = " SELECT dcld.block_id, dcld.pro_width, dcld.pro_fontsize, dcld.pro_color, dcld.pro_bgcolor, dcb.name AS block_name, dcb.block_type, dcb.code"
			 . " FROM dtb_confirm_layout_detail dcld"
			 . " INNER JOIN dtb_confirm_block dcb ON dcld.block_id = dcb.id"
			 . " WHERE dcld.layout_id = ?"
			 . " ORDER BY dcld.rank"
		;
		return $objQuery->getAll($sql, array($id));

	}

	/**
	 * レイアウトブロック一覧取得
	 */
	public function getBlockList() {
		$objQuery = SC_Query_Ex::getSingletonInstance();
		$objQuery->setOrder("update_date DESC");
		return $objQuery->select("*", "dtb_confirm_block", "del_flg = 0");
	}

	/**
	 * 対象レイアウトブロック取得
	 */
	public function getBlock($id) {
		$objQuery = SC_Query_Ex::getSingletonInstance();
		return $objQuery->getRow("*", "dtb_confirm_block", "del_flg = 0 AND id = ?", array($id));
	}

	/**
	 * モデルデータを取得する
	 *
	 * @return string   モデルデータ配列
	 */
	public function lfGetModelData()
	{
		$objQuery = SC_Query_Ex::getSingletonInstance();
		$arrModel = array();

		// モデルデータ取得
		$col = '*';
		$table = "dtb_model";
		$where = 'brand_id = ? AND confirm_image_1 IS NOT NULL';
		$objQuery->setOrder('id');
		$objQuery->setLimit('1');
		$arrModel = $objQuery->select($col, $table, $where, array($_SESSION["brand_id"]));

		return $arrModel[0];
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

		// トランザクション
		$objQuery->begin();

		// 更新
		$sqlVal = array();
		$sqlVal["name"] = $arrList["name"];
		$sqlVal["is_confirm"] = $arrList["is_confirm"];
		// $sqlVal["is_device"] = $arrList["is_device"];
		// $sqlVal["is_default"] = $arrList["is_default"];
		$sqlVal["update_date"] = "CURRENT_TIMESTAMP";

		if (empty($id)) {
			// 新規
			$id = $objQuery->nextVal('dtb_confirm_layout_id');
			$sqlVal["id"] = $id;
			$sqlVal["brand_id"] = $_SESSION["brand_id"];
			if ($arrList["is_confirm"] == 1) {
				// 確認画面用テンプレ
				$sqlVal["template"] = "store/confirm_eccube.tpl";
			} else {
				// デフォルト
				$sqlVal["template"] = "store/confirm.tpl";
			}
			$sqlVal["is_flow"] = 0;
			$sqlVal["create_date"] = "CURRENT_TIMESTAMP";
			$objQuery->insert('dtb_confirm_layout', $sqlVal);
		} else {
			// 更新
			$where = 'id = ? AND brand_id = ?';
			$objQuery->update('dtb_confirm_layout', $sqlVal, $where, array($id, $_SESSION["brand_id"]));
		}

		// 元データを一度削除
		$where = 'layout_id = ?';
		$objQuery->delete('dtb_confirm_layout_detail', $where, array($id));

//GC_Utils_Ex::gfPrintLog_r("block_id：", $arrList["block_id"]);
		$rank = 1;
		$max = count($arrList["block_id"]);
		//for($i = 1; $i <= $max; $i++) {
		foreach ($arrList["block_id"] as $key => $value) {
			$sqlVal2 = array();
			$block_type = $arrList["block_type"][$key];
			$sqlVal2["block_id"] = $value;
			if ($block_type != 2) {
				$sqlVal2["pro_width"] = $arrList["pro_width"][$key];
				$sqlVal2["pro_fontsize"] = $arrList["pro_fontsize"][$key];
				$sqlVal2["pro_color"] = $arrList["pro_color"][$key];
				$sqlVal2["pro_bgcolor"] = $arrList["pro_bgcolor"][$key];
				$sqlVal2["customize_code"] = $arrList["customize_code"][$key];
			} else {
				// 画像関連はブロックからそのまま
				$sqlVal2["customize_code"] = $objQuery->getOne("SELECT code FROM dtb_confirm_block WHERE id = ?", array($sqlVal2["block_id"]));
			}
			$sqlVal2["rank"] = $rank;
			$sqlVal2["layout_id"] = $id;

			// 登録
			$objQuery->insert('dtb_confirm_layout_detail', $sqlVal2);
			$rank++;
		}
		$objQuery->commit();

		return $id;
	}
}
