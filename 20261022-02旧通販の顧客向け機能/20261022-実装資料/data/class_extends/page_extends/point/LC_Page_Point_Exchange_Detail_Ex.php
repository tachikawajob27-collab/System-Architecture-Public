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
 * ポイント交換明細 のページクラス
 *
 * @package Page
 * @author EC-CUBE CO.,LTD.
 * @version $Id$
 */
class LC_Page_Point_Exchange_Detail_Ex extends LC_Page_AbstractMypage_Ex
{
	/**
	 * Page を初期化する.
	 *
	 * @return void
	 */
	function init()
	{
		parent::init();
		$this->tpl_subtitle = '交換商品明細';
		$this->tpl_mainpage = 'point/exchange_detail.tpl';
		// ベースフレームをMYPAGE専用にする
		$this->setTemplate('mypage_frame.tpl');

		// 顧客情報
		$objCustomer = new SC_Customer_Ex();
		$this->customer_id = $objCustomer->getValue('customer_id');
		$this->customer_name = $objCustomer->getValue('name01');

		// ポイント情報
		$objCustomerHelper = new SC_Helper_Customer_Ex();
		$this->customer_point = $objCustomerHelper->getCustomerPoint($this->customer_id);
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
		$objCustomer = new SC_Customer_Ex();
		$objCustomerHelper = new SC_Helper_Customer_Ex();
		// 交換品ID
		$this->exchange_id = $_REQUEST["exchange_id"];

		// 処理分岐
		switch($this->getMode()) {
			case "exchange":
				// 交換処理
				$id = $this->exchangeProduct($this->customer_id, $this->exchange_id, $_POST);
				SC_Response_Ex::sendRedirect('exchange_complete.php?id='.$id);
				SC_Response_Ex::actionExit();
				break;
		}
		// 交換商品情報取得
		list($this->arrDetail, $this->arrDetailOption) = $this->getPointExchangeProduct($this->exchange_id);

		// 次回最短お届け予定日を取得
		$this->next_shipping_date = $this->getFirstNextShippingDate($this->customer_id);
	}

	/**
	 * 交換商品一覧
	 */
	public function getPointExchangeProduct($exchange_id) {
		$objQuery = SC_Query_Ex::getSingletonInstance();
		$arrResult = array();
		$arrResultOption = array();

		$sql = " SELECT dpel.*, dp.product_image, dp.name_official as product_name"
			 . " FROM dtb_point_exchange_list dpel"
			 . " INNER JOIN dtb_products dp ON dpel.product_id = dp.product_id"
			 . " WHERE dpel.del_flg = 0 AND dpel.id = ?"
		;
		// 交換品データ取得
		$arrData = $objQuery->getAll($sql, array($exchange_id));
		if (!empty($arrData)) {
			// 単体行
			$arrResult = $arrData[0];

			// オプション存在確認
			$sql = " SELECT dpelo.*, dpo.option_detail_name"
				 . " FROM dtb_point_exchange_list_option dpelo INNER JOIN dtb_products_option dpo ON dpelo.option_id = dpo.option_id AND dpelo.option_detail_id = dpo.option_detail_id "
				 . " WHERE dpelo.exchange_id = ?"
				 . " ORDER BY dpelo.option_detail_id "
			;
			$arrResultOption = $objQuery->getAll($sql, array($exchange_id));
		}
		return array($arrResult, $arrResultOption);
	}

	/**
	 * 最短お届け予定日
	 */
	public function getFirstNextShippingDate($customer_id) {
		$objQuery = SC_Query_Ex::getSingletonInstance();
		// 10日後の日付を取得
		$target_date = date("Y-m-d 00:00:00", strtotime("+10 day"));
		// 受注確認
		$sql = "SELECT ds.shipping_date FROM dtb_order do2 INNER JOIN dtb_shipping ds ON do2.order_id = ds.order_id WHERE do2.customer_id = ? AND do2.status IN (1,9001) AND ds.shipping_date >= ? ORDER BY ds.shipping_date LIMIT 1";
		$shipping_date = $objQuery->getOne($sql, array($customer_id, $target_date));
		if (empty($shipping_date)) {
			// 定期データを確認
			$sql = " SELECT dt.next_shipping_date FROM dtb_teiki dt WHERE dt.customer_id = ? AND dt.status <> 3 ORDER BY dt.next_shipping_date";
			$shipping_date = $objQuery->getOne($sql, array($customer_id));
		}
		return $shipping_date;
	}

	/**
	 * ポイント交換登録
	 */
	public function exchangeProduct($customer_id, $exchange_id, $post) {
		$objQuery = SC_Query_Ex::getSingletonInstance();

		// 連打対策：同一会員による同一内容の二重処理をブロック
		$where = "customer_id = ? AND point_category = 3 AND exchange_id = ? AND point_date > (CURRENT_TIMESTAMP - INTERVAL '10 second')";
		$arrValues = array($customer_id, $exchange_id);
		
		$is_exists = $objQuery->count("dtb_customer_point_history", $where, $arrValues);
		if ($is_exists > 0) {
			// すでに処理済みの場合は、既存の履歴IDを返す
			$existing_id = $objQuery->getOne("SELECT id FROM dtb_customer_point_history WHERE $where ORDER BY id DESC LIMIT 1", $arrValues);
			return $existing_id;
		}

		// トランザクション
		$objQuery->begin();
		// ポイント交換履歴登録
		$arrPointHistory = array();
		$id = $objQuery->nextval("dtb_customer_point_history_id");
		$arrPointHistory["id"] = $id;
		$arrPointHistory["customer_id"] = $customer_id;
		$arrPointHistory["is_fugo"] = 1;
		$arrPointHistory["point"] = $post["required_point"];
		$arrPointHistory["point_category"] = 3;
		$arrPointHistory["point_date"] = "CURRENT_TIMESTAMP";
		$arrPointHistory["exchange_id"] = $exchange_id;
		$arrPointHistory["is_purchase"] = 0;
		if (!empty($post["option_id"])) {
			$arrPointHistory["exchange_option_id"] = $post["option_id"];
			$arrPointHistory["exchange_option_detail_id"] = $post["point_option"];
		}
		$objQuery->insert("dtb_customer_point_history", $arrPointHistory);
		// コミット
		$objQuery->commit();
		return $id;
	}
}
