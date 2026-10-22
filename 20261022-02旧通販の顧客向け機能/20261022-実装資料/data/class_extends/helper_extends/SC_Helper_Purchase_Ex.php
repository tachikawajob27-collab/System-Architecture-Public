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

require_once CLASS_REALDIR . 'helper/SC_Helper_Purchase.php';
require_once CLASS_REALDIR . 'helper/SC_Helper_Teiki_Renew.php';
/* amazon pay cv2 関連処理-----------------------*/
require_once(dirname(__FILE__) . '/../../module/amazon-pay-api-sdk-php-master/vendor/autoload.php');
/*-----------------------*/
require_once(dirname(__FILE__) . '/../../module/amazon-pay-sdk-php-master/format_phone_number.php');
require_once MODULE_REALDIR . "mdl_paygent/LC_Page_Mdl_Paygent_Helper_Ex.php";
require_once MODULE_REALDIR . 'mdl_paygent/include.php';
// require_once MODULE_REALDIR . 'mdl_nppro/define.php';
// require_once MDL_NPPRO_CLASSEX_DIR_PATH . 'helper_extends/SC_Helper_Mdl_NPPRO_Ex.php';
// require_once MODULE_REALDIR . 'mdl_nppro_rest/define.php';
// require_once MDL_NPPRO_REST_CLASSEX_DIR_PATH . 'helper_extends/SC_Helper_Mdl_NPPRO_Ex.php';
require_once MODULE_REALDIR . 'mdl_vamos/define.php';
require_once MDL_VAMOS_CLASSEX_DIR_PATH . 'helper_extends/SC_Helper_Mdl_VAMOS_Ex.php';

/**
 * 商品購入関連のヘルパークラス(拡張).
 *
 * LC_Helper_Purchase をカスタマイズする場合はこのクラスを編集する.
 *
 * @package Helper
 * @author Kentaro Ohkouchi
 * @version $Id$
 */
class SC_Helper_Purchase_Ex extends SC_Helper_Purchase
{
	function completeOrder($orderStatus = ORDER_NEW) {
		$orderId = parent::completeOrder($orderStatus);
		
		if ($orderStatus == ORDER_NEW) {
			parent::lfMapping($orderId);
		}
	}

	/**
	 * 受注.対応状況の更新
	 *
	 * 必ず呼び出し元でトランザクションブロックを開いておくこと。
	 *
	 * @param integer $orderId 注文番号
	 * @param integer|null $newStatus 対応状況 (null=変更無し)
	 * @param integer|null $newAddPoint 加算ポイント (null=変更無し)
	 * @param integer|null $newUsePoint 使用ポイント (null=変更無し)
	 * @param array $sqlval 更新後の値をリファレンスさせるためのパラメーター
	 * @return void
	 */
	function sfUpdateOrderStatus($orderId, $newStatus = null, $newAddPoint = null, $newUsePoint = null, &$sqlval = null) {
		parent::sfUpdateOrderStatus($orderId, $newStatus, $newAddPoint, $newUsePoint, $sqlval);
		// 決済モジュールを使用する場合（カードなど）のマッピング登録
		// 新規受付でバッチからの登録でない場合に実行
		if(
			( $newStatus == ORDER_NEW
			  || $newStatus == ORDER_PENDING
			//   || $newStatus == MDL_JACCS_ORDER_PRE_END
			//   || $newStatus == MDL_JACCS_ORDER_PENDING
			//   || $newStatus == MDL_JACCS_ORDER_NG
			//   || $newStatus == MDL_JACCS_ORDER_EDIT
			//   || $newStatus == MDL_JACCS_ORDER_ERROR
			//   || $newStatus == MDL_JACCS_ORDER_PENDING_MANUAL
			//   || $newStatus == MDL_JACCS_ORDER_NOW_ORDER_NG
			  || $newStatus == 9002
			  || $newStatus == 8000
			)
			&& $sqlval['device_type_id'] != "80" && $_POST['mode'] !== "edit"){
			parent::lfMapping($orderId);
		}
	}

	// コンビニ後払い用の注文登録口
	function registerOrderNp($order_id, $arrParams) {
		$order_id = $this->registerOrder($order_id, $arrParams);
		//NP後払いの場合
		if ($arrParams['status'] == 9000 || $arrParams['status'] == 9001 || $arrParams['status'] == 9002 || $arrParams['status'] == 9003) {
			parent::lfMapping($order_id);
		}
	}

	/**
	 * 受注の決済変更処理
	 *
	 * @param integer $order_id 受注ID
	 * @param integer $payment_id 支払方法ID
	 * @throws Exception
	 */
	public function orderAuthoriChange($order_id, $payment_id) {
		$objQuery =& SC_Query_Ex::getSingletonInstance();

		$ret = false;
		$arrOrder = $this->getOrder($order_id);

		switch($payment_id) {
			// クレカ
			case 5:
				$ret = sfPaygentOrder("change_auth", $order_id, '', '', array());
				break;
			// // NP
			// case 6:
			// 	$objNp = new SC_Helper_Mdl_NPPRO_Ex();
			// 	if ($objNp->changeNpProCredit($order_id)) {
			// 		$ret = true;
			// 	}
			// 	break;
			// AmazonPay
			case 9:
				$config = $GLOBALS['amazon_payment_config'];
				$amazon_client = new Amazon\Pay\API\Client($config);

				$amazon_charge_id = $arrOrder['memo02'];

				/* 既存のオーソリをクローズ
				-----------------------*/
				if(!empty($amazon_charge_id)){
					// v1用の対応 => "A"を"C"にする
					$amazon_charge_id = substr_replace($amazon_charge_id, 'C', 20,1);

					$cancellation_reason = 'cancel';
					$cancel_payload = ['cancellationReason' => $cancellation_reason];

					$cancel_response_object = $amazon_client->cancelCharge($amazon_charge_id, $cancel_payload);
					$cancel_response = json_decode($cancel_response_object['response']);
					// キャンセル失敗
					if($cancel_response_object['status'] != 200 || $cancel_response->statusDetails->state != 'Canceled'){
						$code = strval($cancel_response->reasonCode);
						GC_Utils_Ex::gfPrintLog("AmazonPay オーソリエラー ReasonCode=$code order_id=$order_id");
					}
				}
				/*-----------------------*/

				/* 新規オーソリ
				-----------------------*/
				$amazon_charge_permission_id = $objQuery->getOne("select amazon_billing_agreement_id from dtb_customer where customer_id = ? and brand_id=?", array($arrOrder["customer_id"],$_SESSION["brand_id"]));
				$authorization_amount = $arrOrder['payment_total'];
				$currency_code = 'JPY';
				$capture_now = false;
				$can_handle_pending_authorization = false;
				$idempotency_key = substr(base_convert(hash('sha256', uniqid()), 16, 36), 0, 25); // 冪等キー

				$authorization_reference_id = "AS" . $order_id;
				$brand_name = $objQuery->getOne("select name from dtb_brand where id = ?", array($arrOrder["brand_id"]));

				$create_payload = [
					'chargePermissionId' => $amazon_charge_permission_id,
					'chargeAmount' => [
							'amount' => $authorization_amount,
							'currencyCode' => $currency_code
					],
					'captureNow' => $capture_now,
					'canHandlePendingAuthorization' => $can_handle_pending_authorization,
					'merchantMetadata' => [
						'merchantReferenceId' => $authorization_reference_id,
						'merchantStoreName' => $brand_name,
						'noteToBuyer' => 'Thank you for your order!'
					]
				];

				$create_headers = ['x-amz-pay-Idempotency-Key' => $idempotency_key];
				// オーソリ処理
				$create_response_object = $amazon_client->createCharge($create_payload, $create_headers);

				$create_response = json_decode($create_response_object['response']);

				$AuthorizationStatus = $create_response->statusDetails->state;
//				GC_Utils_Ex::gfPrintLog_r("Amazon authResult: ", $AuthorizationStatus);

				// 成功
				if($create_response_object['status'] == 201 && $AuthorizationStatus == 'Authorized'){
					$AmazonAuthorizationId = strval($create_response->chargeId); // 取引ID
					$objQuery->update("dtb_order", array('memo02' => $AmazonAuthorizationId),"order_id=? and brand_id=?",array($order_id,$_SESSION["brand_id"]));
					GC_Utils_Ex::gfPrintLog("AmazonPay AmazonAuthorizationId=$AmazonAuthorizationId order_id=$order_id");
					$ret = true;
				}
				// 失敗
				else{
					$code = strval($create_response->reasonCode);
					GC_Utils_Ex::gfPrintLog("AmazonPay オーソリエラー ReasonCode=$code order_id=$order_id");
					$objQuery->update("dtb_order", array('memo01' => 'AmazonPayError', 'memo02' => $code),"order_id=? and brand_id=?",array($order_id,$_SESSION["brand_id"]));
					$ret = false;
				}
				break;

			// VAMOS後払い
			case MDL_VAMOS_PAYMENT_ID:
				$objVa = new SC_Helper_Mdl_VAMOS_Ex();
				if ($objVa->changeVaCredit($order_id, '01')) {
					$ret = true;
				}
				break;
		}
		return $ret;
	}

	/**
	 * 受注の決済登録処理
	 *
	 * @param integer $order_id 受注ID
	 * @param integer $payment_id 支払方法ID
	 * @throws Exception
	 */
	public function orderAuthoriEntry($order_id, $payment_id) {
		$objQuery =& SC_Query_Ex::getSingletonInstance();

		$ret = false;
		$arrOrder = $this->getOrder($order_id);

		switch($payment_id) {
			// クレカ
			case 5:
				$objPayjentHelper = new  LC_Page_Mdl_Paygent_Helper_Ex();
				$customer_card_id = $objQuery->getOne("select customer_card_id from dtb_customer where customer_id = ?", array($arrOrder["customer_id"]));
				$message = $objPayjentHelper->sfAuthPaygentCredit($arrOrder["order_id"],$arrOrder["customer_id"],$customer_card_id,$arrOrder['payment_total']);
				if ($message == "オーソリOK") {
					$ret = true;
				}
				break;

			// NP
			// case 6:
			// 	$objNp = new SC_Helper_Mdl_NPPRO_Ex();
			// 	if ($objNp->requestNpProCredit($order_id)) {
			// 		$ret = true;
			// 	}
			// 	break;

			// AmazonPay
			case 9:
				$config = $GLOBALS['amazon_payment_config'];
				$amazon_client = new Amazon\Pay\API\Client($config);

				$amazon_charge_permission_id = $objQuery->getOne("select amazon_billing_agreement_id from dtb_customer where customer_id = ?", array($arrOrder["customer_id"]));
				$authorization_amount = $arrOrder['payment_total'];
				$currency_code = 'JPY';
				$capture_now = false;
				$can_handle_pending_authorization = false;
				$idempotency_key = substr(base_convert(hash('sha256', uniqid()), 16, 36), 0, 25); // 冪等キー

				$authorization_reference_id = "AS" . $order_id;
				$brand_name = $objQuery->getOne("select name from dtb_brand where id = ?", array($arrOrder["brand_id"]));

				$create_payload = [
					'chargePermissionId' => $amazon_charge_permission_id,
					'chargeAmount' => [
							'amount' => $authorization_amount,
							'currencyCode' => $currency_code
					],
					'captureNow' => $capture_now,
					'canHandlePendingAuthorization' => $can_handle_pending_authorization,
					'merchantMetadata' => [
						'merchantReferenceId' => $authorization_reference_id,
						'merchantStoreName' => $brand_name,
						'noteToBuyer' => 'Thank you for your order!'
					]
				];

				$create_headers = ['x-amz-pay-Idempotency-Key' => $idempotency_key];
				// オーソリ処理
				$create_response_object = $amazon_client->createCharge($create_payload, $create_headers);

				$create_response = json_decode($create_response_object['response']);

				$AuthorizationStatus = $create_response->statusDetails->state;
//				GC_Utils_Ex::gfPrintLog_r("Amazon authResult: ", $AuthorizationStatus);

				// 成功
				if($create_response_object['status'] == 201 && $AuthorizationStatus == 'Authorized'){
					$AmazonAuthorizationId = strval($create_response->chargeId); // 取引ID
					$objQuery->update("dtb_order", array('memo02' => $AmazonAuthorizationId),"order_id=?",array($order_id));
					GC_Utils_Ex::gfPrintLog("AmazonPay AmazonAuthorizationId=$AmazonAuthorizationId order_id=$order_id");
					$ret = true;
					// $this->tpl_onload = "window.alert('AmazonPayオーソリ変更が完了しました');";
				}
				// 失敗
				else{
					$code = strval($create_response->reasonCode);
					GC_Utils_Ex::gfPrintLog("AmazonPay オーソリエラー ReasonCode=$code order_id=$order_id");
					$objQuery->update("dtb_order", array('memo01' => 'AmazonPayError', 'memo02' => $code),"order_id=?",array($order_id));
					$ret = false;
					// $this->tpl_onload = "window.alert('AmazonPayオーソリエラーが発生しました');";
				}
				break;

			// バモス後払い
			case MDL_VAMOS_PAYMENT_ID:
				$objVa = new SC_Helper_Mdl_VAMOS_Ex();
				if ($objVa->requestVaCredit($order_id, '01')) {
					$ret = true;
				}
				break;

			default:
				$ret = true;
				break;
		}
		return $ret;
	}

	/**
	 * 受注の与信取消処理
	 *
	 * @param integer $order_id 受注ID
	 * @param integer $payment_id 支払方法ID
	 * @throws Exception
	 */
	public function orderAuthoriCancel_Ex($order_id, $payment_id) {
		$objQuery =& SC_Query_Ex::getSingletonInstance();

		$ret = false;
		$arrOrder = $this->getOrder($order_id);

		switch($payment_id) {
			// クレカ
			case 5:
				$ret = sfPaygentOrder("auth_cancel", $order_id, '', '', array());
				break;

			// // NP
			// case 6:
			// 	$objNp = new SC_Helper_Mdl_NPPRO_Ex();
			// 	if ($objNp->cancelNpProCredit($order_id)) {
			// 		$ret = true;
			// 	}
			// 	break;

			// AmazonPay
			case 9:
				$config = $GLOBALS['amazon_payment_config'];
				$amazon_client = new Amazon\Pay\API\Client($config);
				
				$amazon_charge_id = $arrOrder['memo02'];
				if(!empty($amazon_charge_id)){
					// v1用の対応 => "A"を"C"にする
					$amazon_charge_id = substr_replace($amazon_charge_id, 'C', 20,1);

					$cancellation_reason = 'cancel';
					$cancel_payload = ['cancellationReason' => $cancellation_reason];

					$cancel_response_object = $amazon_client->cancelCharge($amazon_charge_id, $cancel_payload);
					$cancel_response = json_decode($cancel_response_object['response']);
					GC_Utils_Ex::gfPrintLog_r("Amazon authResult: ", $cancel_response);

					// 成功
					if($cancel_response_object['status'] == 200 && $cancel_response->statusDetails->state == 'Canceled'){
						$ret = true;
					}
					// 失敗
					else{
						$code = strval($cancel_response->reasonCode);
						GC_Utils_Ex::gfPrintLog("AmazonPay オーソリエラー ReasonCode=$code order_id=$order_id");
						$ret = false;
					}
				} else {
					$ret = false;
				}
				break;

			// // NP(REST)
			// case 11:
			// 	$objNpRest = new rest\SC_Helper_Mdl_NPPRO_Ex();
			// 	if ($objNpRest->cancelNpProCredit($order_id)) {
			// 		$ret = true;
			// 	}
			// 	break;

			// VAMOS後払い
			case MDL_VAMOS_PAYMENT_ID:
				$objVa = new SC_Helper_Mdl_VAMOS_Ex();
				if ($objVa->cancelVaCredit($order_id)) {
					$ret = true;
				}
				break;
		}
		return $ret;
	}

	/**
	 * クロス購入時クレジットカード切替処理
	 */
	public function changeCrossellCredit($order_id, $customer_id) {
		$objQuery = SC_Query_Ex::getSingletonInstance();
		$objQuery->begin();
		// 顧客カードSEQの存在チェック
		$card_no = $objQuery->getOne("SELECT customer_card_id FROM dtb_customer WHERE customer_id = ?", array($customer_id));
		if (!empty($card_no)) {
			// クレカ支払い情報取得
			$arrPayment = $objQuery->getRow("*", "dtb_payment", "payment_id = ?", array(5));
			// カード情報保存出来ている
			$arrOrder = $objQuery->select("*", "dtb_order", "customer_id = ? AND is_first = 1 AND payment_id = 13 AND status IN (9000,9001,9002)", array($customer_id));
			foreach($arrOrder as $order) {
				// 購入金額、支払方法を更新（クレカ）
				$total = ((int)$order["payment_total"] - (int)$order["charge"]) + (int)$arrPayment["charge"];
				// 更新配列
				$arrUpdate = array();
				$arrUpdate["payment_id"] = $arrPayment["payment_id"];
				$arrUpdate["payment_method"] = $arrPayment["payment_method"];
				$arrUpdate["charge"] = (int)$arrPayment["charge"];
				$arrUpdate["total"] = $total;
				$arrUpdate["payment_total"] = $total;
				$arrUpdate["update_date"] = "CURRENT_TIMESTAMP";
				$objQuery->update("dtb_order", $arrUpdate, "order_id = ?", array($order["order_id"]));

				// クレカ新規与信
				$ret = $this->orderAuthoriEntry($order["order_id"], $arrPayment["payment_id"]);
				if (!$ret) {
					// 与信失敗の場合はコンビニに戻す
					$objQuery->rollback();
					GC_Utils_Ex::gfPrintLog("クレカ与信失敗のためロールバック [注文番号：". $order["order_id"]."]");
					return;
				} else {
					// 元与信をキャンセル
					$ret2 = $this->orderAuthoriCancel_Ex($order["order_id"], $order["payment_id"]);
					if (!$ret2) {
						GC_Utils_Ex::gfPrintLog("元与信キャンセル失敗 [注文番号：". $order["order_id"]."]");
					}
				}
			}
			// 次回お届け以降もクレカに変更
			$arrUpdate2 = array();
			$arrUpdate2["payment_id"] = $arrPayment["payment_id"];
			$arrUpdate2["payment_method"] = $arrPayment["payment_method"];
			$arrUpdate2["update_date"] = "CURRENT_TIMESTAMP";
			$objQuery->update("dtb_customer", $arrUpdate2, "customer_id = ?", array($customer_id));
		}
		$objQuery->commit();
		return;
	}

	/**
	 * 予約受注制御
	 */
	public function lfOrderReservation($order_id) {
		$objQuery = SC_Query_Ex::getSingletonInstance();
		$objRenew = new SC_Helper_Teiki_Renew();

		// 2025/1/16〜の予約対応
		// 予約対象
		// $target_category_id = 12;
		// $target_option_id = 2;
		// $target_option_detail_id = 3;
		// $reservatio_start_date = '2025-04-01 00:00:00';
		// $shipping_date = '2025-04-14 00:00:00';
		// $next_shipping_date = '2025-05-09 00:00:00';
		// $reservatio_end_date = '2025-04-09 15:59:59';

		// 2025/5/1〜の予約対応（ムスクペアー）
		$target_category_id = 12;
		$target_option_id = 2;
		$target_option_detail_id = 4;
		$reservatio_start_date = '2025-05-01 00:00:00';
		$reservatio_end_date = '2025-05-12 23:59:59';
		$shipping_date = '2025-05-17 00:00:00';
		$next_shipping_date = '2025-06-11 00:00:00';

		// $arrSms = array();
		// 予約対象チェック
		$sql = " SELECT do2.order_id, do2.customer_id, do2.teiki_id"
			 . " FROM dtb_order do2 "
			 . " WHERE do2.order_id = ?"
			 . " AND do2.status <> 218"
			 . " AND do2.create_date >= ?"
			 . " AND do2.create_date <= ?"
			 . " AND EXISTS "
			 . " (	SELECT NULL "
			 . " 	FROM dtb_order_detail dod "
			 . "	INNER JOIN dtb_products dp ON dod.product_id = dp.product_id"
			 . "	INNER JOIN dtb_order_detail_option dodo ON dod.order_id = dodo.order_id AND dod.order_detail_id = dodo.order_detail_id "
			 . "	WHERE do2.order_id = dod.order_id AND dp.category_id = ? AND dodo.option_id = ? AND dodo.option_detail_id = ?"
			 . " )"
		;
		$arrTarget = $objQuery->getAll($sql, array($order_id, $reservatio_start_date, $reservatio_end_date, $target_category_id, $target_option_id, $target_option_detail_id));
		foreach($arrTarget as $data) {
			// 注文の対応状況を「保留(予約)」に変更
			// $objQuery->update("dtb_order", array("status"=>217, "update_date"=>"CURRENT_TIMESTAMP"), "order_id = ?", array($data["order_id"]));
			// お届け予定日を更新
			$objQuery->update("dtb_shipping", array("shipping_date"=>$shipping_date, "update_date"=>"CURRENT_TIMESTAMP"), "order_id = ?", array($data["order_id"]));
			// 予約注文アイコンを付与
			$cnt = $objQuery->getOne("SELECT COUNT(*) FROM dtb_order_status WHERE order_id = ? AND status = 36", array($data["order_id"]));
			if ($cnt == 0) {
				$objQuery->insert("dtb_order_status", array("order_id"=>$data["order_id"], "status"=>36, "create_date"=>"CURRENT_TIMESTAMP"));
			}
			// 定期データを次回お届け日を変更
			$arrTeiki = $objQuery->getAll("SELECT * FROM dtb_teiki WHERE teiki_id = ? AND status <> 3 ORDER BY detail_id", array($data["teiki_id"]));
			$note = $arrTeiki[0]["note"];
			foreach($arrTeiki as $teiki) {
				$date = new DateTime();
				if(!empty($note)){
					$note .= PHP_EOL;
				}
				$comment = "商品ID：".$teiki["product_id"]."の次回お届け日を".date("Y/m/d", strtotime($next_shipping_date))."に設定";
				$note .= $date->format('Y/m/d H:i ') .":". $comment . $objRenew->sfGetSupportName();
				// if(!empty($note)){
				// 	$note .= PHP_EOL;
				// }
				// $comment = "商品ID：".$teiki["product_id"]."を予約期間のため休止ステータスに変更";
				// $note .= $date->format('Y/m/d H:i ') .":". $comment . $objRenew->sfGetSupportName();

				// 定期休止
				$objQuery->update("dtb_teiki", array("note" => $note, "update_date" => "current_timestamp"), "teiki_id = ?", array($data["teiki_id"]));
				$objQuery->update("dtb_teiki", array("next_shipping_date"=>$next_shipping_date), "teiki_id = ? AND detail_id = ?", array($data["teiki_id"], $teiki["detail_id"]));
				// $objQuery->update("dtb_teiki", array("status" => 2, "next_shipping_date"=>$next_shipping_date), "teiki_id = ? AND detail_id = ?", array($data["teiki_id"], $teiki["detail_id"]));
			}
			// $arrSms[] = $data;
		}

		// // 予約メール送信確認
		// if (!empty($arrSms)) {
		// 	$mailHelper = new SC_Helper_Mail();
		// 	foreach($arrSms as $row) {
		// 		$cnt = $objQuery->getOne("SELECT COUNT(*) FROM dtb_sms_send_history WHERE customer_id = ? AND sms_id = 19", array($row["customer_id"]));
		// 		if ($cnt == 0) {
		// 			// 予約SMS送信
		// 			$mailHelper->sfOrderSendSms(19, $row["order_id"]);
		// 		}
		// 	}
		// }

		// // 予約対象
		// $target_category_id = 12;
		// $target_option_id = 2;
		// $target_option_detail_id = 1;
		// $reservatio_start_date = '2024-07-25 00:00:00';
		// $shipping_date = '2024-09-09 00:00:00';
		// $next_shipping_date = '2024-10-04 00:00:00';

		// // 予約対象チェック
		// $sql = " SELECT do2.order_id, do2.customer_id, do2.teiki_id"
		// 	 . " FROM dtb_order do2 "
		// 	 . " WHERE do2.order_id = ?"
		// 	 . " AND do2.status <> 218"
		// 	 . " AND do2.create_date >= ?"
		// 	 . " AND EXISTS "
		// 	 . " (	SELECT NULL "
		// 	 . " 	FROM dtb_order_detail dod "
		// 	 . "	INNER JOIN dtb_products dp ON dod.product_id = dp.product_id"
		// 	 . "	INNER JOIN dtb_order_detail_option dodo ON dod.order_id = dodo.order_id AND dod.order_detail_id = dodo.order_detail_id "
		// 	 . "	WHERE do2.order_id = dod.order_id AND dp.category_id = ? AND dodo.option_id = ? AND dodo.option_detail_id = ?"
		// 	 . " )"
		// ;
		// $arrTarget = $objQuery->getAll($sql, array($order_id, $reservatio_start_date, $target_category_id, $target_option_id, $target_option_detail_id));
		// foreach($arrTarget as $data) {
		// 	// 注文の対応状況を「保留(予約)」に変更
		// 	// $objQuery->update("dtb_order", array("status"=>217, "update_date"=>"CURRENT_TIMESTAMP"), "order_id = ?", array($data["order_id"]));
		// 	// お届け予定日を更新
		// 	$objQuery->update("dtb_shipping", array("shipping_date"=>$shipping_date, "update_date"=>"CURRENT_TIMESTAMP"), "order_id = ?", array($data["order_id"]));
		// 	// 予約注文アイコンを付与
		// 	$cnt = $objQuery->getOne("SELECT COUNT(*) FROM dtb_order_status WHERE order_id = ? AND status = 36", array($data["order_id"]));
		// 	if ($cnt == 0) {
		// 		$objQuery->insert("dtb_order_status", array("order_id"=>$data["order_id"], "status"=>36, "create_date"=>"CURRENT_TIMESTAMP"));
		// 	}
		// 	// 定期データを次回お届け日を変更
		// 	$arrTeiki = $objQuery->getAll("SELECT * FROM dtb_teiki WHERE teiki_id = ? AND status <> 3 ORDER BY detail_id", array($data["teiki_id"]));
		// 	$note = $arrTeiki[0]["note"];
		// 	foreach($arrTeiki as $teiki) {
		// 		$date = new DateTime();
		// 		if(!empty($note)){
		// 			$note .= PHP_EOL;
		// 		}
		// 		$comment = "商品ID：".$teiki["product_id"]."の次回お届け日を".date("Y/m/d", strtotime($next_shipping_date))."に設定";
		// 		$note .= $date->format('Y/m/d H:i ') .":". $comment . $objRenew->sfGetSupportName();
		// 		// if(!empty($note)){
		// 		// 	$note .= PHP_EOL;
		// 		// }
		// 		// $comment = "商品ID：".$teiki["product_id"]."を予約期間のため休止ステータスに変更";
		// 		// $note .= $date->format('Y/m/d H:i ') .":". $comment . $objRenew->sfGetSupportName();

		// 		// 定期休止
		// 		$objQuery->update("dtb_teiki", array("note" => $note, "update_date" => "current_timestamp"), "teiki_id = ?", array($data["teiki_id"]));
		// 		$objQuery->update("dtb_teiki", array("next_shipping_date"=>$next_shipping_date), "teiki_id = ? AND detail_id = ?", array($data["teiki_id"], $teiki["detail_id"]));
		// 		// $objQuery->update("dtb_teiki", array("status" => 2, "next_shipping_date"=>$next_shipping_date), "teiki_id = ? AND detail_id = ?", array($data["teiki_id"], $teiki["detail_id"]));
		// 	}
		// }

		// // 予約対象
		// $target_category_id2 = 12;
		// $target_option_id2 = 2;
		// $target_option_detail_id2 = 2;
		// $reservatio_start_date2 = '2024-08-09 10:00:00';
		// $shipping_date2 = '2024-09-09 00:00:00';
		// $next_shipping_date2 = '2024-10-04 00:00:00';

		// // 予約対象チェック
		// $sql = " SELECT do2.order_id, do2.customer_id, do2.teiki_id"
		// 	 . " FROM dtb_order do2 "
		// 	 . " WHERE do2.order_id = ?"
		// 	 . " AND do2.status <> 218"
		// 	 . " AND do2.create_date >= ?"
		// 	 . " AND EXISTS "
		// 	 . " (	SELECT NULL "
		// 	 . " 	FROM dtb_order_detail dod "
		// 	 . "	INNER JOIN dtb_products dp ON dod.product_id = dp.product_id"
		// 	 . "	INNER JOIN dtb_order_detail_option dodo ON dod.order_id = dodo.order_id AND dod.order_detail_id = dodo.order_detail_id "
		// 	 . "	WHERE do2.order_id = dod.order_id AND dp.category_id = ? AND dodo.option_id = ? AND dodo.option_detail_id = ?"
		// 	 . " )"
		// ;
		// $arrTarget = $objQuery->getAll($sql, array($order_id, $reservatio_start_date2, $target_category_id2, $target_option_id2, $target_option_detail_id2));
		// foreach($arrTarget as $data) {
		// 	// 注文の対応状況を「保留(予約)」に変更
		// 	// $objQuery->update("dtb_order", array("status"=>217, "update_date"=>"CURRENT_TIMESTAMP"), "order_id = ?", array($data["order_id"]));
		// 	// お届け予定日を更新
		// 	$objQuery->update("dtb_shipping", array("shipping_date"=>$shipping_date2, "update_date"=>"CURRENT_TIMESTAMP"), "order_id = ?", array($data["order_id"]));
		// 	// 予約注文アイコンを付与
		// 	$cnt = $objQuery->getOne("SELECT COUNT(*) FROM dtb_order_status WHERE order_id = ? AND status = 36", array($data["order_id"]));
		// 	if ($cnt == 0) {
		// 		$objQuery->insert("dtb_order_status", array("order_id"=>$data["order_id"], "status"=>36, "create_date"=>"CURRENT_TIMESTAMP"));
		// 	}
		// 	// 定期データを次回お届け日を変更
		// 	$arrTeiki = $objQuery->getAll("SELECT * FROM dtb_teiki WHERE teiki_id = ? AND status <> 3 ORDER BY detail_id", array($data["teiki_id"]));
		// 	$note = $arrTeiki[0]["note"];
		// 	foreach($arrTeiki as $teiki) {
		// 		$date = new DateTime();
		// 		if(!empty($note)){
		// 			$note .= PHP_EOL;
		// 		}
		// 		$comment = "商品ID：".$teiki["product_id"]."の次回お届け日を".date("Y/m/d", strtotime($next_shipping_date2))."に設定";
		// 		$note .= $date->format('Y/m/d H:i ') .":". $comment . $objRenew->sfGetSupportName();
		// 		// if(!empty($note)){
		// 		// 	$note .= PHP_EOL;
		// 		// }
		// 		// $comment = "商品ID：".$teiki["product_id"]."を予約期間のため休止ステータスに変更";
		// 		// $note .= $date->format('Y/m/d H:i ') .":". $comment . $objRenew->sfGetSupportName();

		// 		// 定期休止
		// 		$objQuery->update("dtb_teiki", array("note" => $note, "update_date" => "current_timestamp"), "teiki_id = ?", array($data["teiki_id"]));
		// 		$objQuery->update("dtb_teiki", array("next_shipping_date"=>$next_shipping_date2), "teiki_id = ? AND detail_id = ?", array($data["teiki_id"], $teiki["detail_id"]));
		// 		// $objQuery->update("dtb_teiki", array("status" => 2, "next_shipping_date"=>$next_shipping_date2), "teiki_id = ? AND detail_id = ?", array($data["teiki_id"], $teiki["detail_id"]));
		// 	}
		// }
	}

	/**
	 * 配送業者IDからお届け時間の配列を取得する.
	 *
	 * @param integer $deliv_id 配送業者ID
	 * @return array お届け時間の配列
	 */
	function getDelivTime($deliv_id) {
		$objQuery =& SC_Query_Ex::getSingletonInstance();
		$objQuery->setOrder('time_id');
		$results = $objQuery->select('time_id, deliv_time',
									 'dtb_delivtime',
									 'deliv_id = ?', array($deliv_id));
		$arrDelivTime = array();
		foreach ($results as $val) {
			$arrDelivTime[$val['time_id']] = $val['deliv_time'];
		}
		return $arrDelivTime;
	}
}
