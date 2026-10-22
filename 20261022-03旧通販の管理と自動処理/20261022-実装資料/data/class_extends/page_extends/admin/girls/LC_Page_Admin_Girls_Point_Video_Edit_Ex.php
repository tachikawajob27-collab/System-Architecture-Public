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

require_once CLASS_EX_REALDIR . 'page_extends/admin/girls/LC_Page_Admin_Girls_Point_Video_Ex.php';

/**
 * ガールズポイント管理（動画登録・編集）
 *
 * @package Page
 * @author EC-CUBE CO.,LTD.
 * @version $Id$
 */
class LC_Page_Admin_Girls_Point_Video_Edit_Ex extends LC_Page_Admin_Girls_Point_Video_Ex
{
	/**
	 * Page を初期化する.
	 *
	 * @return void
	 */
	function init()
	{
		parent::init();
		$this->tpl_mainpage = 'girls/point_video_edit.tpl';
		$this->tpl_mainno = 'girls';
		$this->tpl_subno = 'point';
		$this->tpl_subno_point = 'video';
		$this->tpl_pager = 'pager.tpl';
		$this->tpl_maintitle = 'ガールズ管理';
		$this->tpl_subtitle = 'ポイント管理（動画登録）';

		$masterData = new SC_DB_MasterData_Ex();
		$this->arrPageMax = $masterData->getMasterData('mtb_page_max');
		$this->arrDISP = $masterData->getMasterData('mtb_disp');

		// ガール一覧
		$this->getGirlsList();

		ini_set("max_input_time", "-1");
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
		$mode = $this->getMode();
		switch ($mode) {
			case "pre_edit":
				// 編集
				$this->lfInitParam_Edit($mode, $objFormParam, $_POST);
				$arrForm = array();
				$arrForm = $this->lfGetPointVideoData($objFormParam->getValue('id'));
				$arrForm["upload_video_name"] = $arrForm["video_path"];
				$arrForm["upload_image_name"] = $arrForm["thumbnail"];
				$arrForm["upload_gif_name"] = $arrForm["thumbnail_gif"];
				break;

			case 'edit':
				$this->lfInitParam_Edit($mode, $objFormParam, $_POST);
				$arrForm = $objFormParam->getHashArray();
				$arrForm["video_path"] = $arrForm["upload_video_name"];
				$arrForm["thumbnail"] = $arrForm["upload_image_name"];
				$arrForm["thumbnail_gif"] = $arrForm["upload_gif_name"];

				// エラーチェック
				$this->arrErr = $this->lfCheckError($objFormParam);
				if (count($this->arrErr) == 0) {
					// 登録
					$this->lfRegister($arrForm);
					$this->tpl_onload = "window.alert('登録完了しました！！'); eccube.changeAction('./point_video.php'); eccube.setModeAndSubmit('back', '', ''); return false;";
				}
				break;
				
			default:
				$arrForm = array();
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
			case "copy":
				$objFormParam->addParam('ID', 'id', INT_LEN, 'n', array('NUM_CHECK', 'MAX_LENGTH_CHECK'));
				$objFormParam->setParam($arrPost);
				$objFormParam->convParam();
				break;

			default:
				$objFormParam->addParam('id', 'id');
				$objFormParam->addParam('担当ガール', 'girls_id', INT_LEN, 'n', array('EXIST_CHECK', 'NUM_CHECK', 'MAX_LENGTH_CHECK'));
				$objFormParam->addParam('動画名', 'video_name', STEXT_LEN, 'KVa', array('EXIST_CHECK', 'SPTAB_CHECK', 'MAX_LENGTH_CHECK'));
				$objFormParam->addParam('購入ポイント', 'point', INT_LEN, 'n', array('EXIST_CHECK', 'NUM_CHECK', 'MAX_LENGTH_CHECK'));
				$objFormParam->addParam('動画説明文', 'description', LLTEXT_LEN, 'KVa', array('EXIST_CHECK', 'SPTAB_CHECK', 'MAX_LENGTH_CHECK'));

				$objFormParam->addParam('アップロード動画', 'upload_video_name', LLTEXT_LEN, 'KVa', array('EXIST_CHECK'));
				$objFormParam->addParam('サムネイル', 'upload_image_name', LLTEXT_LEN, 'KVa', array('EXIST_CHECK'));
				$objFormParam->addParam('サムネイル(gif)', 'upload_gif_name');
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
	 * ガールズ一覧取得
	 */
	public function getGirlsList() {
		$objQuery = SC_Query_Ex::getSingletonInstance();
		$objQuery->setOrder("id");
		$arrRet = $objQuery->select("id, name", "dtb_girls", "del_flg = 0 AND brand_id = ?", array($_SESSION["brand_id"]));
		$this->arrGirls = array();
		foreach($arrRet as $data) {
			$this->arrGirls[$data["id"]] = $data["name"];
		}
	}

	/**
	 * DBからポイント動画データを取得する
	 *
	 * @param  integer $id
	 * @return array   データ配列
	 */
	public function lfGetPointVideoData($id)
	{
		$objQuery = SC_Query_Ex::getSingletonInstance();
		$arrModel = array();

		// ポイント動画データ取得
		$col = '*';
		$table = "dtb_girls_point_video";
		$where = 'id = ?';
		$arrModel = $objQuery->select($col, $table, $where, array($id));
		return $arrModel[0];
	}

	/**
	 * DBにデータを登録する
	 *
	 * @param  array   $arrList     フォーム入力パラメーター配列
	 * @return integer 登録コースID
	 */
	public function lfRegister($arrList)
	{
		$objQuery = SC_Query_Ex::getSingletonInstance();

		// INSERTする値を作成する。
		$sqlVal = array();
		$sqlVal['video_name'] = $arrList['video_name'];
		$sqlVal['video_path'] = $arrList['upload_video_name'];
		$sqlVal['description'] = $arrList['description'];
		$sqlVal['thumbnail'] = $arrList['upload_image_name'];
		$sqlVal['thumbnail_gif'] = $arrList['upload_gif_name'];
		$sqlVal['point'] = $arrList['point'];
		$sqlVal['girls_id'] = $arrList['girls_id'];
		$sqlVal['del_flg'] = 0;
		$sqlVal['update_date'] = 'CURRENT_TIMESTAMP';

		$objQuery->begin();
		if ($arrList["id"] == "") {
			// 新規
			$id = $objQuery->nextVal('dtb_girls_point_video_id');
			$sqlVal['id'] = $id;
			// INSERTの実行
			$sqlVal['create_date'] = 'CURRENT_TIMESTAMP';
			$objQuery->insert('dtb_girls_point_video', $sqlVal);

			// 移動ファイルリスト
			$arrFileList = array();
			if (!empty($arrList['upload_video_name'])) {$arrFileList[] = $arrList['upload_video_name'];}
			if (!empty($arrList['upload_image_name'])) {$arrFileList[] = $arrList['upload_image_name'];}
			if (!empty($arrList['upload_gif_name'])) {$arrFileList[] = $arrList['upload_gif_name'];}
			// 本保存先へファイルの移動
			$this->lfMoveFile($arrFileList);
		} else {
			// 更新
			$arrRet = $this->lfGetPointVideoData($arrList["id"]);

			// UPDATEの実行
			$where = 'id = ?';
			$objQuery->update('dtb_girls_point_video', $sqlVal, $where, array($arrList["id"]));

			// 移動、削除ファイルリスト
			$arrFileList = array();
			$arrDeleteList = array();
			// 変更点をチェック
			if ($arrRet["video_path"] != $arrList['upload_video_name']) {
				if (!empty($arrList['upload_video_name'])) {$arrFileList[] = $arrList['upload_video_name'];}
				if (!empty($arrRet["video_path"])) {$arrDeleteList[] = $arrRet["video_path"];}
			}
			if ($arrRet["thumbnail"] != $arrList['upload_image_name']) {
				if (!empty($arrList['upload_image_name'])) {$arrFileList[] = $arrList['upload_image_name'];}
				if (!empty($arrRet["thumbnail"])) {$arrDeleteList[] = $arrRet["thumbnail"];}
			}
			if ($arrRet["thumbnail_gif"] != $arrList['upload_gif_name']) {
				if (!empty($arrList['upload_gif_name'])) {$arrFileList[] = $arrList['upload_gif_name'];}
				if (!empty($arrRet["thumbnail_gif"])) {$arrDeleteList[] = $arrRet["thumbnail_gif"];}
			}
			// 本保存先へファイルの移動
			$this->lfMoveFile($arrFileList);
			// 変更前ファイルの削除
			$this->lfDeleteFile($arrDeleteList);
		}
		$objQuery->commit();
	}

	/**
	 * ファイル移動処理
	 * 
	 * @param array $arrFileList  移動ファイルリスト
	 * @return void
	 */
	public function lfMoveFile($arrFileList) {
GC_Utils_Ex::gfPrintLog_r("arrFileList：", $arrFileList);
		$temp_dir = IMAGE_TEMP_REALDIR;
		$save_dir = GIRLS_SAVE_REALDIR;
		foreach ($arrFileList as $filename) {
			$from_path = $temp_dir.$filename;
			$to_path = $save_dir.$filename;
GC_Utils_Ex::gfPrintLog("from_path：". $from_path);
GC_Utils_Ex::gfPrintLog("to_path：". $to_path);
			if (file_exists($from_path) && file_exists($save_dir)) {
				// TEMP → 保存先
				if (copy($from_path, $to_path)) {
					// TEMPファイル削除
					unlink($from_path);
				}
			}
		}
	}
	
	/**
	 * ファイル削除処理
	 * 
	 * @param array $arrFileList  削除ファイルリスト
	 * @return void
	 */
	public function lfDeleteFile($arrFileList) {
		$save_dir = GIRLS_SAVE_REALDIR;
		foreach ($arrFileList as $filename) {
			$path = $save_dir.$filename;
			if (file_exists($path)) {
				unlink($path);
			}
		}
	}
}
