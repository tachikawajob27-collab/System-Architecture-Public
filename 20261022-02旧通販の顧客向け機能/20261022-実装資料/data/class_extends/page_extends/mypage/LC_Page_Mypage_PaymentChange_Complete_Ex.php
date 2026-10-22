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
 * 登録内容変更完了 のページクラス(拡張).
 *
 *
 * @package Page
 * @author EC-CUBE CO.,LTD.
 * @version $Id$
 */
class LC_Page_Mypage_PaymentChange_Complete_Ex extends LC_Page_Ex
{
	/**
	 * ブランドサイトURL
	 */
	public $brandsite;

	/**
	 * Page を初期化する.
	 *
	 * @return void
	 */
	function init()
	{
		parent::init();
		$this->tpl_mainpage = 'mypage/payment_change_complete.tpl';
		$this->setTemplate('site_frame.tpl');
	}

	/**
	 * Page のプロセス.
	 *
	 * @return void
	 */
	function process()
	{
		parent::process();
		if (!empty($_SESSION["payment_change"])) {
			if ($_GET["mode"] == "complete") {
				// タイトル
				$this->tpl_subtitle = '支払方法変更（完了）';
				// 専用顧客アイコン付与
				$objQuery =& SC_Query_Ex::getSingletonInstance();
				$cnt = $objQuery->count("dtb_customer_status", "customer_id = ? AND status = 102", array($_SESSION["payment_change"]["customer"]["customer_id"]));
				if ($cnt == 0) {
					$arrUpdate = array();
					$arrUpdate["customer_id"] = $_SESSION["payment_change"]["customer"]["customer_id"];
					$arrUpdate["status"] = 102;
					$arrUpdate["create_date"] = "CURRENT_TIMESTAMP";
					$objQuery->insert("dtb_customer_status", $arrUpdate);
				}
				$this->brandsite = $objQuery->getOne("SELECT url FROM dtb_brand WHERE id = ?", array($_SESSION["payment_change"]["customer"]["brand_id"]));
				// セッション情報を削除
				unset($_SESSION["payment_change"]);
			} else {
				// タイトル
				$this->tpl_subtitle = '支払方法変更（失敗）';
			}
		} else {
			// セッションなしの場合はエラーページに遷移
			SC_Utils_Ex::sfDispSiteError(PAGE_ERROR);
			SC_Response_Ex::actionExit();
		}
		$this->sendResponse();
	}

	/**
	 * デストラクタ.
	 *
	 * @return void
	 */
	function destroy() {
		parent::destroy();
	}
}
