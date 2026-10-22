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
 * チャットアイコン更新/登録 のページクラス
 *
 * @package Page
 * @author EC-CUBE CO.,LTD.
 * @version $Id$
 */
class LC_Page_Admin_Chat_Icon_Input_Ex extends LC_Page_Admin_Ex
{
	/**
	 * Page を初期化する.
	 *
	 * @return void
	 */
	function init()
	{
		parent::init();
		$this->tpl_mainpage = 'chat/icon_input.tpl';
		$this->tpl_mainno = 'chat';
		$this->tpl_subno = 'icon_input';
		$this->tpl_pager = 'pager.tpl';
		$this->tpl_maintitle = 'チャット管理';
		$this->tpl_subtitle = 'アイコン登録・編集';

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

		// アップロードファイル情報の初期化
		$objUpFile = new SC_UploadFile_Ex(IMAGE_TEMP_REALDIR, IMAGE_SAVE_REALDIR);
		$this->lfInitFile($objUpFile);
		$objUpFile->setHiddenFileList($_POST);

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
				$arrForm = $this->lfGetIconData($id);
				// DBデータから画像ファイル名の読込
				$objUpFile->setDBFileList($arrForm);
				break;

			case 'edit':
				// 登録確認
				$this->lfInitFormParam($objFormParam, $_POST, $mode);
				$arrForm = $objFormParam->getHashArray();

				// エラーチェック
				$this->arrErr = $this->lfCheckError_Edit($objFormParam, $objUpFile, $arrForm);
				if (count($this->arrErr) == 0) {
					// 確認画面表示設定
					$this->tpl_mainpage = 'chat/icon_confirm.tpl';
				}
				break;

			case 'complete':
				// 登録完了
				$this->lfInitFormParam($objFormParam, $_POST, $mode);
				$arrForm = $objFormParam->getHashArray();

				// エラーチェック
				$this->arrErr = $this->lfCheckError_Edit($objFormParam, $objUpFile, $arrForm);
				if (count($this->arrErr) == 0) {
					// 登録処理
					// DBへデータ登録
					$id = $this->lfRegister($objUpFile, $arrForm);
					// 一時ファイルを本番ディレクトリに移動する
					$this->lfSaveUploadFiles($objUpFile, $id);
					$this->tpl_mainpage = 'chat/complete.tpl';
				}

			// 画像のアップロード
			case "upload_image":
			case 'delete_image':
				// 初期設定
				$this->lfInitFormParam_UploadImage($objFormParam);
				$this->lfInitFormParam($objFormParam, $_POST, $mode);
				$arrForm = $objFormParam->getHashArray();

				switch ($mode) {
					case 'upload_image':
						// ファイルを一時ディレクトリにアップロード
						$this->arrErr[$arrForm['image_key']] = $objUpFile->makeTempFile($arrForm['image_key'], IMAGE_RENAME);
						if ($this->arrErr[$arrForm['image_key']] == '') {
							// 縮小画像作成
							$this->lfSetScaleImage($objUpFile, $arrForm['image_key']);
						}
						break;
					case 'delete_image':
						// ファイル削除
						$this->lfDeleteTempFile($objUpFile, $arrForm['image_key']);
						break;
					default:
						break;
				}
				break;

			// 確認ページからの戻り
			case 'confirm_return':
				// パラメーター初期化
				$this->lfInitFormParam($objFormParam, $_POST, $mode);
				$arrForm = $objFormParam->getHashArray();
				// 入力画面表示設定
				$this->arrForm = $this->lfSetViewParam_InputPage($objUpFile, $arrForm);
				break;

			default:
				// 入力画面表示設定
				$arrForm = array();
				$this->arrForm = $this->lfSetViewParam_InputPage($objUpFile, $arrForm);
				break;
		}

		// アップロードファイル情報取得(Hidden用)
		$arrHidden = $objUpFile->getHiddenFileList();
		$arrForm["arrHidden"] = (array) $arrHidden;
		// 画像ファイル表示用データ取得
		$arrForm['arrFile'] = $objUpFile->getFormFileList();
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
			$objFormParam->addParam('アイコンID', 'id', INT_LEN, 'n', array('NUM_CHECK', 'MAX_LENGTH_CHECK'));
		} else {
			$objFormParam->addParam('アイコンID', 'id', INT_LEN, 'n', array('NUM_CHECK', 'MAX_LENGTH_CHECK'));
			$objFormParam->addParam('アイコン名', 'name', STEXT_LEN, 'KVa', array('EXIST_CHECK', 'SPTAB_CHECK', 'MAX_LENGTH_CHECK'));

			$objFormParam->addParam('temp_chat_icon', 'temp_chat_icon');
			$objFormParam->addParam('save_chat_icon', 'save_chat_icon');
		}

		$objFormParam->setParam($arrPost);
		$objFormParam->convParam();
	}

	/**
	 * パラメーター情報の初期化
	 * - 画像ファイルアップロードモード
	 *
	 * @param  SC_FormParam_Ex $objFormParam SC_FormParamインスタンス
	 * @return void
	 */
	public function lfInitFormParam_UploadImage(&$objFormParam)
	{
		$objFormParam->addParam('image_key', 'image_key', '', '', array());
	}

	/**
	 * アップロードファイルパラメーター情報の初期化
	 * - 画像ファイル用
	 *
	 * @param  SC_UploadFile_Ex $objUpFile SC_UploadFileインスタンス
	 * @return void
	 */
	public function lfInitFile(&$objUpFile)
	{
		$objUpFile->addFile('チャットアイコン', 'chat_icon', array('jpg', 'gif', 'png'), IMAGE_SIZE, false, SMALL_IMAGE_WIDTH, SMALL_IMAGE_HEIGHT);
	}

	/**
	 * フォーム入力パラメーターのエラーチェック
	 *
	 * @param  object $objFormParam SC_FormParamインスタンス
	 * @param  SC_UploadFile_Ex $objUpFile    SC_UploadFileインスタンス
	 * @param  array  $arrForm      フォーム入力パラメーター配列
	 * @return array  エラー情報を格納した連想配列
	 */
	public function lfCheckError_Edit(&$objFormParam, &$objUpFile, $arrForm)
	{
		$objErr = new SC_CheckError_Ex($arrForm);
		$arrErr = array();

		// 入力パラメーターチェック
		$arrErr = $objFormParam->checkError();

		// アップロードファイル必須チェック
		$arrErr = array_merge((array) $arrErr, (array) $objUpFile->checkExists());
		$arrErr = array_merge((array) $arrErr, (array) $objErr->arrErr);

		return $arrErr;
	}

	/**
	 * 表示用フォームパラメーター取得
	 * - 入力画面
	 *
	 * @param  SC_UploadFile_Ex $objUpFile   SC_UploadFileインスタンス
	 * @param  array  $arrForm     フォーム入力パラメーター配列
	 * @return array  表示用フォームパラメーター配列
	 */
	public function lfSetViewParam_InputPage(&$objUpFile, &$arrForm)
	{
		// アップロードファイル情報取得(Hidden用)
		$arrHidden = $objUpFile->getHiddenFileList();
		$arrForm["arrHidden"] = (array) $arrHidden;

		// 画像ファイル表示用データ取得
		$arrForm['arrFile'] = $objUpFile->getFormFileList();

		return $arrForm;
	}

	/**
	 * 縮小した画像をセットする
	 *
	 * @param  SC_UploadFile_Ex $objUpFile SC_UploadFileインスタンス
	 * @param  string $image_key 画像ファイルキー
	 * @return void
	 */
	public function lfSetScaleImage(&$objUpFile, $image_key)
	{
		switch ($image_key) {
		case 'chat_icon':
			// チャットアイコン
			$this->lfMakeScaleImage($objUpFile, $image_key, 'chat_icon_list');
			break;
		default:
			break;
		}
	}

	/**
	 * 縮小画像生成
	 *
	 * @param  object  $objUpFile SC_UploadFileインスタンス
	 * @param  string  $from_key  元画像ファイルキー
	 * @param  string  $to_key    縮小画像ファイルキー
	 * @param  boolean $forced
	 * @return void
	 */
	public function lfMakeScaleImage(&$objUpFile, $from_key, $to_key, $forced = false)
	{
		$arrImageKey = array_flip($objUpFile->keyname);
		$from_path = '';

		if ($objUpFile->temp_file[$arrImageKey[$from_key]]) {
			$from_path = $objUpFile->temp_dir . $objUpFile->temp_file[$arrImageKey[$from_key]];
		} elseif ($objUpFile->save_file[$arrImageKey[$from_key]]) {
			$from_path = $objUpFile->save_dir . $objUpFile->save_file[$arrImageKey[$from_key]];
		}

		if (file_exists($from_path)) {
			// 生成先の画像サイズを取得
			$to_w = $objUpFile->width[$arrImageKey[$to_key]];
			$to_h = $objUpFile->height[$arrImageKey[$to_key]];

			if ($forced) {
				$objUpFile->save_file[$arrImageKey[$to_key]] = '';
			}

			if (empty($objUpFile->temp_file[$arrImageKey[$to_key]])
				&& empty($objUpFile->save_file[$arrImageKey[$to_key]])
			) {
				// リネームする際は、自動生成される画像名に一意となるように、Suffixを付ける
				$dst_file = $objUpFile->lfGetTmpImageName(IMAGE_RENAME, '', $objUpFile->temp_file[$arrImageKey[$from_key]]) . $this->lfGetAddSuffix($to_key);
				$path = $objUpFile->makeThumb($from_path, $to_w, $to_h, $dst_file);
				$objUpFile->temp_file[$arrImageKey[$to_key]] = basename($path);
			}
		}
	}

	/**
	 * アップロードファイルパラメーター情報から削除
	 * 一時ディレクトリに保存されている実ファイルも削除する
	 *
	 * @param  SC_UploadFile_Ex $objUpFile SC_UploadFileインスタンス
	 * @param  string $image_key 画像ファイルキー
	 * @return void
	 */
	public function lfDeleteTempFile(&$objUpFile, $image_key)
	{
		// TODO: SC_UploadFile::deleteFileの画像削除条件見直し要
		$arrTempFile = $objUpFile->temp_file;
		$arrKeyName = $objUpFile->keyname;

		foreach ($arrKeyName as $key => $keyname) {
			if ($keyname != $image_key) continue;

			if (!empty($arrTempFile[$key])) {
				$temp_file = $arrTempFile[$key];
				$arrTempFile[$key] = '';

				if (!in_array($temp_file, $arrTempFile)) {
					$objUpFile->deleteFile($image_key);
				} else {
					$objUpFile->temp_file[$key] = '';
					$objUpFile->save_file[$key] = '';
				}
			} else {
				$objUpFile->temp_file[$key] = '';
				$objUpFile->save_file[$key] = '';
			}
		}
	}

	/**
	 * アップロードファイルを保存する
	 *
	 * @param  object  $objUpFile   SC_UploadFileインスタンス
	 * @param  integer $id  ID
	 * @return void
	 */
	public function lfSaveUploadFiles(&$objUpFile, $id)
	{
		// TODO: SC_UploadFile::moveTempFileの画像削除条件見直し要
		$objImage = new SC_Image_Ex($objUpFile->temp_dir);
		$arrKeyName = $objUpFile->keyname;
		$arrTempFile = $objUpFile->temp_file;
		$arrSaveFile = $objUpFile->save_file;
		$arrImageKey = array();
		foreach ($arrTempFile as $key => $temp_file) {
			if ($temp_file) {
				$objImage->moveTempImage($temp_file, $objUpFile->save_dir);
				$arrImageKey[] = $arrKeyName[$key];
				if (!empty($arrSaveFile[$key])
					&& !$this->lfHasSameProductImage($id, $arrImageKey, $arrSaveFile[$key], $objUpFile)
					&& !in_array($temp_file, $arrSaveFile)
				) {
					$objImage->deleteImage($arrSaveFile[$key], $objUpFile->save_dir);
				}
				// WEBサーバーに画像をコピー
				SC_Utils_Ex::remoteFileSend(1, 0, $objUpFile->save_dir.$temp_file);
			}
		}
	}

	/**
	 * 同名画像ファイル登録の有無を確認する.
	 *
	 * 画像ファイルの削除可否判定用。
	 * 同名ファイルの登録がある場合には画像ファイルの削除を行わない。
	 * 戻り値： 同名ファイル有り(true) 同名ファイル無し(false)
	 *
	 * @param  string  $girl_id      ガールID
	 * @param  string  $arrImageKey     対象としない画像カラム名
	 * @param  string  $image_file_name 画像ファイル名
	 * @param  SC_UploadFile_Ex $objUpFile SC_UploadFileインスタンス
	 * @return boolean
	 */
	public function lfHasSameProductImage($girl_id, $arrImageKey, $image_file_name, $objUpFile)
	{
		if (!SC_Utils_Ex::sfIsInt($girl_id)) return false;
		if (!$arrImageKey) return false;
		if (!$image_file_name) return false;

		$arrWhere = array();
		$sqlval = array('0', $girl_id);
		foreach ($arrImageKey as $image_key) {
			$arrWhere[] = "{$image_key} = ?";
			$sqlval[] = $image_file_name;
		}
		$where = implode(' OR ', $arrWhere);
		$where = "del_flg = ? AND ((id <> ? AND ({$where}))";

		$arrKeyName = $objUpFile->keyname;
		foreach ($arrKeyName as $key => $keyname) {
			if (in_array($keyname, $arrImageKey)) continue;
			$where .= " OR {$keyname} = ?";
			$sqlval[] = $image_file_name;
		}
		$where .= ')';

		$objQuery = SC_Query_Ex::getSingletonInstance();
		$exists = $objQuery->exists('dtb_chat_icon', $where, $sqlval);

		return $exists;
	}

	/**
	 * DBからチャットアイコンデータを取得する
	 *
	 * @param  integer $id 
	 * @return string   チャットアイコンデータ配列
	 */
	public function lfGetIconData($id)
	{
		$objQuery = SC_Query_Ex::getSingletonInstance();
		$arrResult = array();

		// 商品データ取得
		$col = '*';
		$table = "dtb_chat_icon";
		$where = 'id = ? AND brand_id = ?';
		$objQuery->setLimit('1');
		$arrResult = $objQuery->select($col, $table, $where, array($id, $_SESSION["brand_id"]));
		return $arrResult[0];
	}

	/**
	 * DBにチャットアイコンデータを登録する
	 *
	 * @param  SC_UploadFile_Ex  $objUpFile   SC_UploadFileインスタンス
	 * @param  array   $arrList     フォーム入力パラメーター配列
	 * @return integer 登録ID
	 */
	public function lfRegister(&$objUpFile, $arrList)
	{
		$objQuery = SC_Query_Ex::getSingletonInstance();
		$objDb = new SC_Helper_DB_Ex();

		// INSERTする値を作成する。
		$sqlval['name'] = $arrList['name'];
		$sqlval['update_date'] = 'CURRENT_TIMESTAMP';
		$arrRet = $objUpFile->getDBFileList();
		$sqlval = array_merge($sqlval, $arrRet);

		$objQuery->begin();

		// 新規登録(複製時を含む)
		if ($arrList['id'] == '') {
			$id = $objQuery->nextVal('dtb_chat_icon_id');
			$sqlval['id'] = $id;
			$sqlval['brand_id'] = $_SESSION['brand_id'];

			// INSERTの実行
			$sqlval['create_date'] = 'CURRENT_TIMESTAMP';
			$objQuery->insert('dtb_chat_icon', $sqlval);
			$arrList['id'] = $id;
		// 更新
		} else {
			$id = $arrList['id'];
			// 削除要求のあった既存ファイルの削除
			$arrRet = $this->lfGetIconData($arrList['id']);
			// TODO: SC_UploadFile::deleteDBFileの画像削除条件見直し要
			$objImage = new SC_Image_Ex($objUpFile->temp_dir);
			$arrKeyName = $objUpFile->keyname;
			$arrSaveFile = $objUpFile->save_file;
			$arrImageKey = array();
			foreach ($arrKeyName as $key => $keyname) {
				if ($arrRet[$keyname] && !$arrSaveFile[$key]) {
					$arrImageKey[] = $keyname;
					$has_same_image = $this->lfHasSameProductImage($arrList['id'], $arrImageKey, $arrRet[$keyname], $objUpFile);
					if (!$has_same_image) {
						$objImage->deleteImage($arrRet[$keyname], $objUpFile->save_dir);
					}
				}
			}
			// UPDATEの実行
			$where = 'id = ? AND brand_id = ?';
			$objQuery->update('dtb_chat_icon', $sqlval, $where, array($id, $_SESSION["brand_id"]));
		}
		$objQuery->commit();
		return $id;
	}

	/**
	 * リネームする際は、自動生成される画像名に一意となるように、Suffixを付ける
	 *
	 * @param  string $to_key
	 * @return string
	 */
	public function lfGetAddSuffix($to_key)
	{
		if ( IMAGE_RENAME === true) return;

		// 自動生成される画像名
		$dist_name = '';
		switch ($to_key) {
		case 'chat_icon_list':
			$dist_name = '_s';
			break;
		default:
			$arrRet = explode('sub_image', $to_key);
			$dist_name = '_sub' .$arrRet[1];
			break;
		}

		return $dist_name;
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
