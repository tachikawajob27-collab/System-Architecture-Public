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
require_once CLASS_EX_REALDIR . 'page_extends/LC_Page_Ex.php';

/**
 * ポイントサイトログイン のページクラス
 *
 * @package Page
 * @author EC-CUBE CO.,LTD.
 * @version $Id$
 */
class LC_Page_Point_Login_Ex extends LC_Page_Ex
{
	/**
	 * Page を初期化する.
	 *
	 * @return void
	 */
	function init()
	{
		// 主要なセッション情報をクリア
		SC_SiteSession_Ex::sfCrearOptionalSessionData();
		parent::init();
		$this->tpl_subtitle = 'ログイン';
		$this->tpl_mypageno = 'point';

		// ベースフレームをMYPAGE専用にする
		$this->setTemplate('mypage_frame.tpl');
	}

	/**
	 * Page のプロセス.
	 *
	 * @return void
	 */
	function process() {
		$this->action();
		$this->sendResponse();
	}

	/**
	 * 実処理
	 */
	public function action() {
		// パラメータチェック
		$uid = $_REQUEST["uid"];
		$brand_id = $_REQUEST["bid"];
		if(empty($uid) || empty($brand_id)){
			SC_Utils_Ex::sfDispSiteError("ページはがありません");
			exit;
		}
		$arrBrandData = SC_Helper_DB_Ex::sfGetBrandData($brand_id);
		$_SESSION["arrBrandData"] = $arrBrandData;

		//ログイン処理
		$objCustomer = new SC_Customer_Ex();
		if ($objCustomer->setLoginFromCode($uid,$brand_id)) {
			// リダイレクト先（ホワイトリスト管理）
			$allowed = array("exchange.php", "discount.php");
			$redirect = isset($_REQUEST["redirect"]) ? $_REQUEST["redirect"] : "";
			if (!empty($redirect) && in_array($redirect, $allowed, true)) {
				SC_Response_Ex::sendRedirect($redirect);
			} else {
				SC_Response_Ex::sendRedirect("exchange.php");
			}
			exit;
		} else {
			SC_Utils_Ex::sfDispSiteError(CUSTOMER_ERROR);
		}
		SC_Response_Ex::actionExit();
	}
}
