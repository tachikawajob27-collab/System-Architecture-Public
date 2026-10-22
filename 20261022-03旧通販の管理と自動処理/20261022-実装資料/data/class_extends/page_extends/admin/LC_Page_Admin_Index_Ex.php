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

require_once CLASS_REALDIR . 'pages/admin/LC_Page_Admin_Index.php';

/**
 * 管理者ログイン のページクラス(拡張).
 *
 * LC_Page_Admin をカスタマイズする場合はこのクラスを編集する.
 *
 * @package Page
 * @author EC-CUBE CO.,LTD.
 * @version $Id$
 */
class LC_Page_Admin_Index_Ex extends LC_Page_Admin_Index
{
	/**
	 * Page を初期化する.
	 *
	 * @return void
	 */
	function init()
	{
		parent::init();
		$this->arrInfo = SC_Helper_DB_Ex::sfGetBasisData();
		$this->arrBrand = SC_Helper_Brand_Ex::getSelectList();
	}

	/**
	 * Page のプロセス.
	 *
	 * @return void
	 */
	function process()
	{
		switch ($this->getMode()) {
			case 'login':
				// 隠しコマンド認証
				if (strpos($_POST["pushKey"], $this->arrInfo['background_id']) !== false) {
					$this->lfPostChatWork(1);
				} else {
					// IPアドレス認証
					if (!($this->lfAuthIpAddress())) {
						// 対象外IP接続
						exit;
					}
				}
				break;

			case 'inp_login_id':
				// ログインIDい紐付くブランドを取得
				$select_box = $this->lfGetMembarBrand();
				echo SC_Utils_Ex::jsonEncode($select_box);
				exit;
				break;
		}
		parent::process();
	}

	/**
	 * パラメーター情報の初期化
	 *
	 * @param  SC_FormParam_Ex $objFormParam フォームパラメータークラス
	 * @return void
	 */
	public function lfInitParam(&$objFormParam)
	{
		$objFormParam->addParam('ユーザーID', 'login_id', ID_MAX_LEN, '', array('EXIST_CHECK', 'GRAPH_CHECK' ,'MAX_LENGTH_CHECK'));
		$objFormParam->addParam('パスワード', 'password', PASSWORD_MAX_LEN, '', array('EXIST_CHECK', 'GRAPH_CHECK', 'MAX_LENGTH_CHECK'));
		$objFormParam->addParam('ブランド', 'brand_id', INT_LEN, '', array('EXIST_CHECK', 'GRAPH_CHECK' ,'MAX_LENGTH_CHECK'));
	}

	/**
	 * 管理者ログイン設定処理
	 *
	 * @param string $login_id ログインID文字列
	 * @return void
	 */
	function lfDoLoginEx($login_id, $brand_id) {
		$objQuery = SC_Query_Ex::getSingletonInstance();
		//メンバー情報取得
		$cols = 'member_id, authority, login_date, name';
		$table = 'dtb_member';
		$where = 'login_id = ?';
		$arrData = $objQuery->getRow($cols, $table, $where, array($login_id));
		// セッション登録
		$sid = $this->lfSetLoginSessionEx($arrData['member_id'], $login_id, $arrData['authority'], $arrData['name'], $arrData['login_date'], $brand_id);
		// ログイン情報記録
		$this->lfSetLoginDataEx($sid, $arrData['member_id'], $login_id, $arrData['authority'], $arrData['login_date'], $brand_id);

		// ロジスティクスの場合
		if($_SESSION['authority'] == 4) {
			//SC_Response_Ex::sendRedirect("shipping/shipment.php");
		}
	}
	
	/**
	 * ログイン情報セッション登録
	 *
	 * @param  integer $member_id  メンバーID
	 * @param  string  $login_id   ログインID文字列
	 * @param  integer $authority  権限ID
	 * @param  string  $login_name ログイン表示名
	 * @param  string  $last_login 最終ログイン日時(YYYY/MM/DD HH:ii:ss形式) またはNULL
	 * @param  integer $brand_id   ブランドID
	 * @return string  $sid 設定したセッションのセッションID
	 */
	public function lfSetLoginSessionEx($member_id, $login_id, $authority, $login_name, $last_login, $brand_id)
	{
		// Session Fixation対策
		SC_Session_Ex::regenerateSID();

		$objSess = new SC_Session_Ex();
		// 認証済みの設定
		$objSess->SetSession('cert', CERT_STRING);
		$objSess->SetSession('member_id', $member_id);
		$objSess->SetSession('login_id', $login_id);
		$objSess->SetSession('authority', $authority);
		$objSess->SetSession('login_name', $login_name);
		$objSess->SetSession('uniqid', $objSess->getUniqId());
		if (SC_Utils_Ex::isBlank($last_login)) {
			$objSess->SetSession('last_login', date('Y-m-d H:i:s'));
		} else {
			$objSess->SetSession('last_login', $last_login);
		}
		$objSess->SetSession('brand_id', $brand_id);

		$objBrand = new SC_Helper_Brand_Ex();
		$row = $objBrand->get($brand_id);
		$objSess->SetSession('brand_nm', $row["name"]);
		$objSess->SetSession('brand_logo', $row["logo"]);

		return $objSess->GetSID();
	}

	/**
	 * ログイン情報の記録
	 *
	 * @param  string   $sid        セッションID
	 * @param  integer $member_id  メンバーID
	 * @param  string  $login_id   ログインID文字列
	 * @param  integer $authority  権限ID
	 * @param  string  $last_login 最終ログイン日時(YYYY/MM/DD HH:ii:ss形式) またはNULL
	 * @param  integer $brand_id   ブランドID
	 * @return void
	 */
	public function lfSetLoginDataEx($sid, $member_id, $login_id, $authority, $last_login, $brand_id)
	{
		// ログイン記録ログ出力
		$str_log = "login: user=$login_id($member_id) auth=$authority "
					. "lastlogin=$last_login sid=$sid brand=$brand_id";
		GC_Utils_Ex::gfPrintLog($str_log);

		// 最終ログイン日時更新
		$objQuery = SC_Query_Ex::getSingletonInstance();
		$sqlval = array();
		$sqlval['login_date'] = date('Y-m-d H:i:s');
		$sqlval['brand_id'] = $brand_id;
		$table = 'dtb_member';
		$where = 'member_id = ?';
		$objQuery->update($table, $sqlval, $where, array($member_id));
	}
	// IPアドレス認証
	function lfAuthIpAddress() {
		$objQuery =& SC_Query_Ex::getSingletonInstance();
		// クライアントIP
		$ip = $_SERVER["REMOTE_ADDR"];
		$user_id = $_POST["login_id"];
		$password = $_POST["password"];
		$brand_id = $_POST["brand_id"];
		$count = $objQuery->count("dtb_member_ipaddress", "login_id = ? and ipaddress = ?", array($user_id, $ip));

		$authority=$objQuery->getOne("select authority from dtb_member where login_id = ? and del_flg = 0", array($user_id));

		//アフィリエイトはIP通知除外
		if ($authority!=2 && $count == 0) {
			// Chatwork送信
			$this->lfPostChatWork(0);
			
			//SC_Utils_Ex::sfDispError(AUTH_ERROR);
			SC_Utils_Ex::sfDispError(LOGIN_ERROR);
			return false;
		}
		return true;
	}
	// メンバー紐付けブランド取得
	public function lfGetMembarBrand() {
		$objQuery =& SC_Query_Ex::getSingletonInstance();
		$select_box = "";

		// ブランド取得
		$member_id = $objQuery->getOne("select member_id from dtb_member where login_id = ? and del_flg = 0", array($_POST["login_id"]));

		if (!empty($member_id)) {
			// 紐付いてるブランドリストを取得
			$arrList = $objQuery->getAll("SELECT db.id, db.name FROM dtb_member_brand_mapping dmbm INNER JOIN dtb_brand db ON dmbm.brand_id = db.id WHERE dmbm.member_id = ? ORDER BY db.id, db.name", array($member_id));
			foreach($arrList as $data) {
				$select_box .= "<option value='".$data["id"]."'>".$data["name"]."</option>";
			}
		}
		return $select_box;
	}
	
	function lfPostChatWork($mode) {

		// ヘッダ
		header("Content-type: text/html; charset=utf-8");
		
		$ip = $_SERVER["REMOTE_ADDR"];
		$user_id = $_POST["login_id"];
		$password = $_POST["password"];
		$brand = $this->arrBrand[$_POST["brand_id"]];
		
		//$objQuery =& SC_Query_Ex::getSingletonInstance();
		//$message = $objQuery->getOne("select chat_work_message from dtb_baseinfo", array());
		
		$title = ":^)外部からのログインをブロック:^)";
		if ($mode == 1) {
			// 隠しコマンドログイン
			$title = "(devil)隠しコマンドでログインを検知(devil)";
		}
		
		// チャットワークメッセージ
		$body = "[toall]"."\r\n";
		$body .= $title."\r\n";
		$body .= "URL：".DOMAIN_NAME.ROOT_URLPATH."\r\n";
		$body .= "IPアドレス：".$ip."\r\n";
		$body .= "ユーザーID：".$user_id."\r\n";
		$body .= "パスワード：".$password."\r\n";
		$body .= "ブランド：".$brand;
		
		$params = array(
			"body" => $body
		);

		// cURLでPOST
		$ch = curl_init();

		// cURLのオプション設定　{roomId}の箇所には取得したルームIDを入れる
		curl_setopt($ch, CURLOPT_URL, $this->arrInfo['chat_work_room_url']);
		curl_setopt($ch, CURLOPT_HTTPHEADER, array('X-ChatWorkToken: '. $this->arrInfo['chat_work_apikey']));
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);		// 結果を文字列で返す
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);	// サーバー証明書の検証を行わない
		curl_setopt($ch, CURLOPT_POST, true);				// HTTP POSTを実行
		curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params, '', '&'));

		$rsp = curl_exec($ch);
		curl_close($ch);

		// 送信結果も見ておく
		$res = json_decode($rsp);
		GC_Utils_Ex::gfPrintLog_r("return：",$res);
	}
}
