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
require_once CLASS_EX_REALDIR . 'page_extends/mypage/LC_Page_AbstractMypage_Ex.php';

/**
 * ポイント値引き完了 のページクラス
 *
 * @package Page
 * @author EC-CUBE CO.,LTD.
 * @version $Id$
 */
class LC_Page_Point_Discount_Complete_Ex extends LC_Page_AbstractMypage_Ex
{
	/**
	 * Page を初期化する.
	 *
	 * @return void
	 */
	function init()
	{
		parent::init();
		$this->tpl_subtitle = '定期商品ポイント割引';
		$this->tpl_mainpage = 'point/discount_complete.tpl';
		// ベースフレームをMYPAGE専用にする
		$this->setTemplate('mypage_frame.tpl');

		// 顧客情報
		$objCustomer = new SC_Customer_Ex();
		$this->customer_id = $objCustomer->getValue('customer_id');
		$this->customer_name = $objCustomer->getValue('name01');
	}

	/**
	 * Page のプロセス.
	 *
	 * @return void
	 */
	function process() {
		// $this->action();
		// $this->sendResponse();
		parent::process();
	}

	/**
	 * 実処理
	 */
	public function action() {
		// ポイント情報
		$objCustomerHelper = new SC_Helper_Customer_Ex();
		$this->customer_point = $objCustomerHelper->getCustomerPoint($this->customer_id);
	}

	/**
	 * 最短お届け予定日
	 */
	// public function getFirstNextShippingDate($customer_id) {
	// 	$objQuery = SC_Query_Ex::getSingletonInstance();
	// 	// 10日後の日付を取得
	// 	$target_date = date("Y-m-d 00:00:00", strtotime("+10 day"));
	// 	// 受注確認
	// 	$sql = "SELECT ds.shipping_date FROM dtb_order do2 INNER JOIN dtb_shipping ds ON do2.order_id = ds.order_id WHERE do2.customer_id = ? AND do2.status IN (1,9001) AND ds.shipping_date >= ? ORDER BY ds.shipping_date LIMIT 1";
	// 	$shipping_date = $objQuery->getOne($sql, array($customer_id, $target_date));
	// 	if (empty($shipping_date)) {
	// 		// 定期データを確認
	// 		$sql = " SELECT dt.next_shipping_date FROM dtb_teiki dt WHERE dt.customer_id = ? AND dt.status <> 3 ORDER BY dt.next_shipping_date";
	// 		$shipping_date = $objQuery->getOne($sql, array($customer_id));
	// 	}
	// 	return $shipping_date;
	// }
}
