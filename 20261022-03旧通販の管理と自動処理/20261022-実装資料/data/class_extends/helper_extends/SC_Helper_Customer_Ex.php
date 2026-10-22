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

require_once CLASS_REALDIR . 'helper/SC_Helper_Customer.php';

/**
 * CSV関連のヘルパークラス(拡張).
 *
 * LC_Helper_Customer をカスタマイズする場合はこのクラスを編集する.
 *
 * @package Helper
 * @author EC-CUBE CO.,LTD.
 * @version $Id$
 */
class SC_Helper_Customer_Ex extends SC_Helper_Customer
{
	/**
	 * 会員検索パラメーター（カスタム）
	 *
	 * @param SC_FormParam $objFormParam SC_FormParam インスタンス
	 * @access public
	 * @return void
	 */
	public static function sfSetSearchParam(&$objFormParam)
	{
		parent::sfSetSearchParam($objFormParam);
		$objFormParam->addParam('登録日(開始)', 'search_start_date', 10, 'n', array('CHECK_DATE_FORMAT', 'MAX_LENGTH_CHECK'));
		$objFormParam->addParam('登録日(終了)', 'search_end_date', 10, 'n', array('CHECK_DATE_FORMAT', 'MAX_LENGTH_CHECK'));
		$objFormParam->addParam('市区町村', 'search_addr', STEXT_LEN, 'KVa', array('SPTAB_CHECK', 'MAX_LENGTH_CHECK'));
	}

	/**
	 * 会員登録時フォーム初期化（カスタム）
	 *
	 * @param SC_FormParam $objFormParam SC_FormParam インスタンス
	 * @param boolean      $isAdmin      true:管理者画面 false:会員向け
	 * @access public
	 * @return void
	 */
	public static function sfCustomerEntryParam(&$objFormParam, $isAdmin = false)
	{
		SC_Helper_Customer_Ex::sfCustomerCommonParam($objFormParam);
		SC_Helper_Customer_Ex::sfCustomerRegisterParam($objFormParam, $isAdmin);
		if ($isAdmin) {
			$objFormParam->addParam('会員ID', 'customer_id', INT_LEN, 'n', array('NUM_CHECK'));
			$objFormParam->addParam('SHOP用メモ', 'note', LTEXT_LEN, 'KVa', array('MAX_LENGTH_CHECK'));
			$objFormParam->addParam('所持ポイント', 'point', INT_LEN, 'n', array('EXIST_CHECK', 'NUM_CHECK'), 0);
		}
	}

	/**
	 * 会員・顧客・お届け先共通（カスタム）
	 *
	 * @param SC_FormParam $objFormParam SC_FormParam インスタンス
	 * @param string       $prefix       キー名にprefixを付ける場合に指定
	 * @access public
	 * @return void
	 */
	public static function sfCustomerCommonParam(&$objFormParam, $prefix = '')
	{
		$objFormParam->addParam('お名前', $prefix . 'name01', STEXT_LEN, 'aKV', array('EXIST_CHECK', 'NO_SPTAB', 'SPTAB_CHECK', 'MAX_LENGTH_CHECK'));
		$objFormParam->addParam('お名前(フリガナ)', $prefix . 'kana01', STEXT_LEN, 'CKV', array('EXIST_CHECK', 'NO_SPTAB', 'SPTAB_CHECK', 'MAX_LENGTH_CHECK'));
		$objFormParam->addParam('郵便番号', $prefix . 'zip01', 7, 'n', array('EXIST_CHECK', 'SPTAB_CHECK', 'NUM_CHECK', 'NUM_COUNT_CHECK'));
		$objFormParam->addParam('都道府県', $prefix . 'pref', INT_LEN, 'n', array('PREF_CHECK', 'EXIST_CHECK', 'NUM_CHECK'));
		$objFormParam->addParam('住所1', $prefix . 'addr01', MTEXT_LEN, 'aKV', array('EXIST_CHECK', 'SPTAB_CHECK', 'MAX_LENGTH_CHECK'));
		$objFormParam->addParam('住所2', $prefix . 'addr02', MTEXT_LEN, 'aKV', array('EXIST_CHECK', 'SPTAB_CHECK', 'MAX_LENGTH_CHECK'));
		$objFormParam->addParam('お電話番号', $prefix . 'tel01', TEL_LEN, 'n', array('EXIST_CHECK', 'SPTAB_CHECK', 'NUM_CHECK', 'MAX_LENGTH_CHECK'));
	}

	/**
	 * 会員登録共通（カスタム）
	 *
	 * @param  SC_FormParam $objFormParam SC_FormParam インスタンス
	 * @param  boolean      $isAdmin      true:管理者画面 false:会員向け
	 * @param  boolean      $is_mypage    マイページの場合 true
	 * @param  string       $prefix       キー名にprefixを付ける場合に指定
	 * @return void
	 */
	public static function sfCustomerRegisterParam(&$objFormParam, $isAdmin = false, $is_mypage = false, $prefix = '')
	{
		$objFormParam->addParam('パスワード', $prefix . 'password', PASSWORD_MAX_LEN, '', array('EXIST_CHECK', 'SPTAB_CHECK', 'ALNUM_CHECK', 'MAX_LENGTH_CHECK'));
		$objFormParam->addParam('パスワード(確認)', $prefix . 'password02', PASSWORD_MAX_LEN, '', array('EXIST_CHECK', 'SPTAB_CHECK', 'ALNUM_CHECK', 'MAX_LENGTH_CHECK'), '', false);
		// $objFormParam->addParam('性別', $prefix . 'sex', INT_LEN, 'n', array('EXIST_CHECK', 'NUM_CHECK', 'MAX_LENGTH_CHECK'));
		// $objFormParam->addParam('職業', $prefix . 'job', INT_LEN, 'n', array('NUM_CHECK', 'MAX_LENGTH_CHECK'));
		// $objFormParam->addParam('年', $prefix . 'year', 4, 'n', array('NUM_CHECK', 'MAX_LENGTH_CHECK'), '', false);
		// $objFormParam->addParam('月', $prefix . 'month', 2, 'n', array('NUM_CHECK', 'MAX_LENGTH_CHECK'), '', false);
		// $objFormParam->addParam('日', $prefix . 'day', 2, 'n', array('NUM_CHECK', 'MAX_LENGTH_CHECK'), '', false);

		$objFormParam->addParam('メールアドレス', $prefix . 'email', null, 'a', array('NO_SPTAB', 'EXIST_CHECK', 'EMAIL_CHECK', 'SPTAB_CHECK', 'EMAIL_CHAR_CHECK'));
		$objFormParam->addParam('メールマガジン', $prefix . 'mailmaga_flg', INT_LEN, 'n', array('EXIST_CHECK', 'NUM_CHECK', 'MAX_LENGTH_CHECK'));
	}

	/**
	 * 会員エラーチェック共通（カスタム）
	 *
	 * @param SC_FormParam $objFormParam SC_FormParam インスタンス
	 * @param string       $prefix       キー名にprefixを付ける場合に指定
	 * @access public
	 * @return SC_CheckError_Ex エラー情報の配列
	 */
	public static function sfCustomerCommonErrorCheck(&$objFormParam, $prefix = '')
	{
		$objFormParam->convParam();
		$objFormParam->toLower($prefix . 'email');
		$objFormParam->toLower($prefix . 'email02');
		$arrParams = $objFormParam->getHashArray();

		// 入力データを渡す。
		$objErr = new SC_CheckError_Ex($arrParams);
		$objErr->arrErr = $objFormParam->checkError();
		return $objErr;
	}

	/**
	 * 会員情報の登録・編集処理を行う.
	 *
	 * @param array $array 登録するデータの配列（SC_FormParamのgetDbArrayの戻り値）
	 * @param array $customer_id nullの場合はinsert, 存在する場合はupdate
	 * @access public
	 * @return integer 登録編集したユーザーのcustomer_id
	 */
	public static function sfEditCustomerData($array, $customer_id = null) {
		$objQuery =& SC_Query_Ex::getSingletonInstance();
		$objQuery->begin();

		$array['update_date'] = 'CURRENT_TIMESTAMP';    // 更新日
		$array['brand_id'] = $_SESSION["brand_id"];

		// salt値の生成(insert時)または取得(update時)。
		if (is_numeric($customer_id)) {
			$salt = $objQuery->get('salt', 'dtb_customer', 'customer_id = ? ', array($customer_id));
			// 旧バージョン(2.11未満)からの移行を考慮
			if (empty($salt)) $old_version_flag = true;
		} else {
			$salt = SC_Utils_Ex::sfGetRandomString(10);
			$array['salt'] = $salt;
		}
		//-- パスワードの更新がある場合は暗号化
		if ($array['password'] == DEFAULT_PASSWORD or $array['password'] == '') {
			//更新しない
			unset($array['password']);
		} else {
			// 旧バージョン(2.11未満)からの移行を考慮
			if ($old_version_flag) {
				$is_password_updated = true;
				$salt = SC_Utils_Ex::sfGetRandomString(10);
				$array['salt'] = $salt;
			}
			$array['password'] = SC_Utils_Ex::sfGetHashString($array['password'], $salt, 1);
		}
		//-- 秘密の質問の更新がある場合は暗号化
		if ($array['reminder_answer'] == DEFAULT_PASSWORD or $array['reminder_answer'] == '') {
			//更新しない
			unset($array['reminder_answer']);

			// 旧バージョン(2.11未満)からの移行を考慮
			if ($old_version_flag && $is_password_updated) {
				// パスワードが更新される場合は、平文になっている秘密の質問を暗号化する
				$reminder_answer = $objQuery->get('reminder_answer', 'dtb_customer', 'customer_id = ? ', array($customer_id));
				$array['reminder_answer'] = SC_Utils_Ex::sfGetHashString($reminder_answer, $salt, 1);
			}
		} else {
			// 旧バージョン(2.11未満)からの移行を考慮
			if ($old_version_flag && !$is_password_updated) {
				// パスワードが更新されない場合は、平文のままにする
				unset($array['salt']);
			} else {
				$array['reminder_answer'] = SC_Utils_Ex::sfGetHashString($array['reminder_answer'], $salt, 1);
			}
		}
		//-- 編集登録実行
		if (is_numeric($customer_id)) {
			// 編集
			$objQuery->update('dtb_customer', $array, 'customer_id = ? ', array($customer_id));
		} else {
			// 新規登録
			// 会員ID
			if ($_SESSION['purchase_customer_id']) {
				$customer_id = $_SESSION['purchase_customer_id'];
			} else {
				$customer_id = $objQuery->nextVal('dtb_customer_customer_id');
			}
			$array['customer_id'] = $customer_id;
			// 作成日
			if (is_null($array['create_date'])) {
				$array['create_date'] = 'CURRENT_TIMESTAMP';
			}
			$objQuery->insert('dtb_customer', $array);
		}
		$objQuery->commit();
		return $customer_id;
	}

	/**
	 * 注文情報の変更処理を行う.
	 *
	 * @param array $array2 登録するデータの配列
	 * @param array $customer_id nullの場合はinsert, 存在する場合はupdate
	 * @access public
	 * @return void
	 */
	public static function sfEditCustomerOrderData($array2, $customer_id) {
		$objQuery =& SC_Query_Ex::getSingletonInstance();
		$objQuery->begin();
		$array2['update_date'] = 'CURRENT_TIMESTAMP';    // 更新日
		//-- 届け先変更
		if (is_numeric($customer_id)) {
			// 編集
			$objQuery->update('dtb_order', $array2, 'customer_id = ? ', array($customer_id));
		}
		$objQuery->commit();
	}

	/**
	 * 配送情報の変更処理を行う.
	 *
	 * @param array $arrOrder_id 対象の注文番号リスト
	 * @param array $array2 登録するデータの配列
	 * @access public
	 * @return void
	 */
	public static function sfEditShipping($arrOrder_id,$array3) {
		$objQuery =& SC_Query_Ex::getSingletonInstance();
		$objQuery->begin();
		$array3['update_date'] = 'CURRENT_TIMESTAMP';    // 更新日
		foreach ($arrOrder_id as $order) {
			$objQuery->update('dtb_shipping', $array3, 'order_id = ? ', array($order["order_id"]));
		}
		$objQuery->commit();
	}

	/**
	 * 顧客アイコン情報を取得
	 *
	 * @param integer $customer_id 顧客ID
	 * @access public
	 * @return array 顧客アイコン配列
	 */
	public static function sfGetCustomerStatus($customer_id) {
		$objQuery =& SC_Query_Ex::getSingletonInstance();
		$objQuery->setOrder("status");
		return $objQuery->select("status", "dtb_customer_status", "customer_id = ?", array($customer_id));
	}

	/**
	 * パスワード更新
	 */
	public function registerPassword($arrForm, $is_reset = false) {
		$objQuery =& SC_Query_Ex::getSingletonInstance();
		$objQuery->begin();
		// パスワード更新
		$arrPass = array();
		if ($is_reset) {
			$arrPass["password"] = password_hash($arrForm["tel01"], PASSWORD_DEFAULT);
			$arrPass["login_date"] = null;
		} else {
			$arrPass["password"] = password_hash($arrForm["password"], PASSWORD_DEFAULT);
		}
		$arrPass["update_date"] = "CURRENT_TIMESTAMP";

		$cnt = $objQuery->count("dtb_customer_password", "customer_id = ?", array($arrForm["customer_id"]));
		if ($cnt == 0) {
			// 登録
			$arrPass["customer_id"] = $arrForm["customer_id"];
			$arrPass["create_date"] = "CURRENT_TIMESTAMP";
			$objQuery->insert("dtb_customer_password", $arrPass);
		} else {
			// 更新
			$objQuery->update("dtb_customer_password", $arrPass, "customer_id = ?", array($arrForm["customer_id"]));
		}
		$objQuery->commit();
	}
}
