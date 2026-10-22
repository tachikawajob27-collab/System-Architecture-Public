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
 * ポイント値引き のページクラス
 *
 * @package Page
 * @author EC-CUBE CO.,LTD.
 * @version $Id$
 */
class LC_Page_Point_Discount_Ex extends LC_Page_AbstractMypage_Ex
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
		$this->tpl_mainpage = 'point/discount.tpl';
		$this->tpl_pager = 'pager.tpl';
		// ベースフレームをMYPAGE専用にする
		$this->setTemplate('mypage_frame.tpl');

		$objDb = new SC_Helper_DB_Ex();
		$this->arrBaseInfo = $objDb->sfGetBasisData();

		// 顧客情報
		$objCustomer = new SC_Customer_Ex();
		$this->customer_id = $objCustomer->getValue('customer_id');
		$this->customer_name = $objCustomer->getValue('name01');

		// ポイント情報
		$objCustomerHelper = new SC_Helper_Customer_Ex();
		$this->customer_point = $objCustomerHelper->getCustomerPoint($this->customer_id);
		$this->customer_point_expiration_date = $objCustomerHelper->getCustomerPointExpirationDate($this->customer_id);

		// 値引きリスト
		$this->arrDiscountList = array(
			500 => "500円OFF",
			1000 => "1,000円OFF",
			1500 => "1,500円OFF",
			2000 => "2,000円OFF"
		);
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
		$objCustomerHelper = new SC_Helper_Customer_Ex();

		// 処理分岐
		switch($this->getMode()) {
			case "discount":
				// 値引き処理
				$id = $this->discountTeiki($this->customer_id, $_POST);
				SC_Response_Ex::sendRedirect('discount_complete.php?id='.$id);
				SC_Response_Ex::actionExit();
				break;
		}
		// 最短お届け予定日を取得
		$this->arrResults = $objCustomerHelper->getShortestNextDate($this->customer_id);
		if (!empty($this->arrResults)) {
			// 定期 or 受注
			if (isset($this->arrResults[0]["order_id"])) {
				// 受注
				$this->order_id = $this->arrResults[0]["order_id"];
				$this->next_shipping_date = $this->arrResults[0]["shipping_date"];
				$this->total = $this->arrResults[0]["total"];
				$this->charge = $this->arrResults[0]["charge"];
				$this->payment_total = $this->arrResults[0]["payment_total"];
			} else {
				// 定期
				$this->teiki_id = $this->arrResults[0]["teiki_id"];
				$this->next_shipping_date = $this->arrResults[0]["next_shipping_date"];
				$this->charge = $this->arrResults[0]["charge"];
				$subtotal = 0;
				foreach($this->arrResults as $data) {
					$subtotal += (SC_Utils_Ex::sfCalcIncTax($data["price"], (int)$data["tax_rate"], $this->arrBaseInfo["tax_rule"]) * $data["quantity"]);
				}
				$this->total = $subtotal;
				$this->payment_total = $subtotal + $this->arrResults[0]["charge"];
			}
		}
		// 次回値引き適用済み確認
		$this->discount_point = $objCustomerHelper->getDiscountPoint($this->customer_id);
	}

	/**
     * ポイント値引き処理
     */
    public function discountTeiki($customer_id, $post) {
        $objQuery = SC_Query_Ex::getSingletonInstance();
        $objPurchase = new SC_Helper_Purchase_Ex();

        // 連打対策：同一会員による同一内容の二重処理をブロック
        if (!empty($post["order_id"])) {
            // 受注IDがある場合
            $where = "customer_id = ? AND point_category = 4 AND point_date > (CURRENT_TIMESTAMP - INTERVAL '10 second') AND order_id = ?";
            $arrValues = array($customer_id, $post["order_id"]);
        } else {
            // 受注IDがない（空）の場合
            $where = "customer_id = ? AND point_category = 4 AND point_date > (CURRENT_TIMESTAMP - INTERVAL '10 second') AND order_id IS NULL";
            $arrValues = array($customer_id);
        }

        $is_exists = $objQuery->count("dtb_customer_point_history", $where, $arrValues);
        if ($is_exists > 0) {
            $existing_id = $objQuery->getOne("SELECT id FROM dtb_customer_point_history WHERE $where ORDER BY id DESC LIMIT 1", $arrValues);
            return $existing_id;
        }

        // トランザクション
        $objQuery->begin();
        // ポイント値引き履歴登録
        $arrPointHistory = array();
        $id = $objQuery->nextval("dtb_customer_point_history_id");
        $arrPointHistory["id"] = $id;
        $arrPointHistory["customer_id"] = $customer_id;
        $arrPointHistory["is_fugo"] = 1;
        $arrPointHistory["point"] = $post["select_discount"];
        $arrPointHistory["point_category"] = 4;
        $arrPointHistory["point_date"] = "CURRENT_TIMESTAMP";
        $arrPointHistory["is_purchase"] = 0;
        $arrPointHistory["order_id"] = $post["order_id"];
        $objQuery->insert("dtb_customer_point_history", $arrPointHistory);

        // 受注ありの場合
        if (!empty($post["order_id"])) {
            // 値引き適用する
            $arrUpdates = [];
            $arrOrder = $objPurchase->getOrder($post["order_id"]);
            $arrUpdates["discount"] = $post["select_discount"];
            $arrUpdates["total"] = $arrOrder["total"] - $post["select_discount"];
            $arrUpdates["payment_total"] = $arrOrder["payment_total"] - $post["select_discount"];
            $objQuery->update("dtb_order", $arrUpdates, "order_id = ?", array($post["order_id"]));
            // 与信変更
            $objPurchase->orderAuthoriChange($post["order_id"], $arrOrder["payment_id"]);
        }
        // コミット
        $objQuery->commit();
        return $id;
    }
}
