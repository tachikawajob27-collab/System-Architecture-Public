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
require_once MODULE_REALDIR . 'mdl_paygent/include.php';
require_once MODULE_REALDIR . 'mdl_paygent/LC_Page_Mdl_Paygent_Helper_Ex.php';
require_once DATA_REALDIR . 'module/amazon-pay-api-sdk-php-master/vendor/autoload.php';
// require_once MODULE_REALDIR . 'mdl_nppro/define.php';
// require_once MODULE_REALDIR . 'mdl_nppro_rest/define.php';
require_once MODULE_REALDIR . 'mdl_vamos/define.php';
// require_once MDL_NPPRO_CLASSEX_DIR_PATH . 'helper_extends/SC_Helper_Mdl_NPPRO_Ex.php';
// require_once MDL_NPPRO_REST_CLASSEX_DIR_PATH . 'helper_extends/SC_Helper_Mdl_NPPRO_Ex.php';
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
	/**
	 * 受注詳細を取得する.
	 *
	 * @param  integer $order_id         受注ID
	 * @param  boolean $has_order_status 対応状況, 入金日も含める場合 true
	 * @return array   受注詳細の配列
	 */
	public static function getOrderDetail($order_id, $has_order_status = true)
	{
		$objQuery = SC_Query_Ex::getSingletonInstance();

		$sql = " SELECT"
			 . "	 dod.*"
			 . "	,dp.category_id"
			 . "	,dp.tax_status"
			 . "	,dc.name AS course_name"
			 . "	,dc.is_cross"
			//  . "	,dp.is_teiki"
			 . "	,dcs.num AS shibari_num"
			 . "	,func_get_category_commit_num(do2.customer_id, dp.category_id, do2.brand_id) AS category_commit_num"
			 . "	,func_get_order_product_option(dod.order_id, dod.order_detail_id) AS product_option_name"
			 . " FROM"
			 . "	dtb_order_detail dod"
			 . " INNER JOIN dtb_order do2 ON dod.order_id = do2.order_id"
			 . " INNER JOIN dtb_products dp ON dod.product_id = dp.product_id"
			 . " LEFT JOIN dtb_course dc ON dod.course_id = dc.id"
			 . " LEFT JOIN dtb_course_shibari dcs ON dod.course_id = dcs.course_id AND dod.product_id = dcs.product_id"
			 . " WHERE dod.order_id = ?"
			 . " ORDER BY dod.order_detail_id"
		;
		return $objQuery->getAll($sql, array($order_id));
	}

	/**
	 * 配送先情報を取得する.
	 *
	 * @param  integer $order_id         受注ID
	 * @return array   配送先情報の配列
	 */
	public function getShipping($order_id)
	{
		$objQuery = SC_Query_Ex::getSingletonInstance();
		$objQuery->setOrder("shipping_id");
		return $objQuery->select("*", "dtb_shipping", "order_id = ?", array($order_id));
	}

	/**
	 * 受注詳細商品オプション情報を取得する.
	 *
	 * @param  integer $order_id           受注ID
	 * @param  integer $order_detail_id    受注明細ID
	 * @return array   受注詳細オプションの配列
	 */
	public static function getOrderDetailOption($order_id, $order_detail_id)
	{
		$objQuery = SC_Query_Ex::getSingletonInstance();
		$objQuery->setOrder("option_id");
		return $objQuery->select("*", "dtb_order_detail_option", "order_id = ? AND order_detail_id = ?", array($order_id, $order_detail_id));
	}

	/**
	 * 注文アイコン情報を取得する.
	 *
	 * @param  integer $order_id         受注ID
	 * @return array   注文アイコン情報の配列
	 */
	public function getOrderStatusEx($order_id)
	{
		$objQuery = SC_Query_Ex::getSingletonInstance();
		$objQuery->setOrder("status");
		return $objQuery->select("*", "dtb_order_status", "order_id = ?", array($order_id));
	}

	/**
	 * 指定日時から最短お届け日を算出する
	 *
	 * @param  timestamp $target_date	対象日時
	 * @param  timestamp $shipping_date	指定お届け日
	 * @return string  最短お届け日
	 */
	public function lfGetHolidayOfShippingDate($target_date, $shipping_date = null) {
		$objQuery =& SC_Query_Ex::getSingletonInstance();
		$create_date = $target_date;
		$arrData = array();

		// お届け日計算（祝日考慮）
		if (empty($shipping_date)) {
			// 曜日加算日数を取得
			$week_interval = $this->lfGetWeekInterval($create_date);
		
			// 祝日確認
			$week_interval += 1;
			$work_date = strtotime("+".$week_interval." day", strtotime($create_date));
			$is_Loop = true;
			do{
				// 日付を月と日に分割
				$month = date("n", $work_date);
				$day = date("j", $work_date);
				$yobi = date("w", $work_date);
				
				// 祝日テーブル参照
				$cnt = $objQuery->count("dtb_holiday", "month = ? AND day = ? AND del_flg = 0", array($month, $day));
				if ($cnt > 0) {
					// 祝日
					$work_date = strtotime("+1 day", $work_date);
					if ($yobi == 0 || $yobi == 6) {
						// 土日の祝日は無視
						continue;
					} else {
						$week_interval += 1;
					}
				} else {
					$is_Loop = false;
				}
			}while($is_Loop);
			
			// 出荷予定の間隔＋3日でお届け日を計算
			$week_interval += 3;
			$shipping_date = date("Y/m/d", strtotime("+".$week_interval." day", strtotime($create_date)));
		}
		
		return $shipping_date;
	}

	/**
	 * 指定日時から出荷予定加算日数を取得
	 *
	 * @param  timestamp $create_date	対象日時
	 * @return string  加算日数
	 */
	public function lfGetWeekInterval($create_date) {
		$objQuery =& SC_Query_Ex::getSingletonInstance();
		$interval = 0;
		
		// 現在日時・曜日を取得
		$order_yobi = date("w", strtotime($create_date));
		$order_time = date("Hi", strtotime($create_date));
		$month = date("n", strtotime($create_date));
		$day = date("j", strtotime($create_date));

		// 今日が祝日か確認
		$holiday_cnt = $objQuery->count("dtb_holiday", "month = ? AND day = ? AND del_flg = 0", array($month, $day));

		// 出荷リミット時間を取得
		$limit_time = (int)$objQuery->getOne("SELECT syuka_border_time FROM dtb_baseinfo");

		// 曜日分岐
		switch($order_yobi) {
			case 4:
				// 木曜日
				if ($holiday_cnt > 0) {
					// 休日の場合
					$interval += 3;
				} else {
					if ($order_time > $limit_time) {
						// 出荷リミット時間を超えている場合(+3日)
						$interval += 3;
					}
				}
				break;

			case 5:
				// 金曜日
				if ($holiday_cnt > 0) {
					// 休日の場合
					$interval += 3;
				} else {
					if ($order_time > $limit_time) {
						// 出荷リミット時間を超えている場合(+3日)
						$interval += 3;
					} else {
						// 出荷リミット時間を超えていない場合(+2日)
						$interval += 2;
					}
				}
				break;

			case 6:
				// 土曜日(+2日)
				$interval += 2;
				break;

			case 0:
				// 日曜日(+1日)
				$interval += 1;
				break;

			default:
				if ($holiday_cnt > 0) {
					$interval += 1;
				} else {
					if ($order_time > $limit_time) {
						// 出荷リミット時間を超えている場合(+1日)
						$interval += 1;
					}
				}
				break;
		}
		
//GC_Utils_Ex::gfPrintLog("インターバル①:". $interval);
		
		// 基本出力日後、祝日計算
		$work_date = strtotime("+".$interval." day", strtotime($create_date));
		$is_Loop = true;
		do{
			// 日付を月と日に分割
			$month = date("n", $work_date);
			$day = date("j", $work_date);
			$yobi = date("w", $work_date);
			
			// 祝日テーブル参照
			$cnt = $objQuery->count("dtb_holiday", "month = ? AND day = ? AND del_flg = 0", array($month, $day));
			if ($cnt > 0) {
				// 祝日
				$work_date = strtotime("+1 day", $work_date);
				if ($yobi == 0 || $yobi == 6) {
					// 土日の祝日は無視
					continue;
				} else {
					$interval += 1;
				}
			} else {
				$is_Loop = false;
			}
		}while($is_Loop);

//GC_Utils_Ex::gfPrintLog("インターバル②:". $interval);
		return $interval;
	}

	/**
	 * 受注の売上取消処理
	 *
	 * @param integer $order_id 受注ID
	 * @param integer $payment_id 支払方法ID
	 * @throws Exception
	 */
	public function orderCaptureCancel($order_id, $payment_id) {
		$objQuery =& SC_Query_Ex::getSingletonInstance();

		$ret = false;
		$arrOrder = $this->getOrder($order_id);

		switch($payment_id) {
			// クレカ
			case 5:
				$ret = sfPaygentOrder("card_commit_cancel", $order_id, '', '', array());
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
				
				$amazon_charge_id = $arrOrder['memo04'];
				$refund_amount = $arrOrder['payment_total'];
				$currency_code = 'JPY';
				$idempotency_key = substr(base_convert(hash('sha256', uniqid()), 16, 36), 0, 25); // 冪等キー

				$refund_payload = [
					'chargeId' => $amazon_charge_id,
					'refundAmount' => [
						'amount' => $refund_amount,
						'currencyCode' => $currency_code
					]
				];

				$refund_headers = ['x-amz-pay-Idempotency-Key' => $idempotency_key];
				$refund_response_object = $amazon_client->createRefund($refund_payload, $refund_headers);
				$refund_response = json_decode($refund_response_object['response']);

				if(($refund_response_object['status'] == 201 || $refund_response_object['status'] == 200) && $refund_response->statusDetails->state == 'RefundInitiated'){
					$amazon_refund_id = strval($refund_response->refundId);
					$status = strval($refund_response->statusDetails->state);
					$objQuery->update("dtb_order", ['memo05' => $status,'memo06' => $amazon_refund_id,'update_date' => 'CURRENT_TIMESTAMP'],"order_id=?",[$order_id]);
					GC_Utils_Ex::gfPrintLog("AmazonPay status=$status order_id=$order_id");
					$ret = true;
				}
				else{
					$code = strval($refund_response->reasonCode);
					GC_Utils_Ex::gfPrintLog("AmazonPayエラー ReasonCode=$code order_id=$order_id");
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
	 * 受注の与信取消処理
	 *
	 * @param integer $order_id 受注ID
	 * @param integer $payment_id 支払方法ID
	 * @throws Exception
	 */
	public function orderAuthoriCancel($order_id, $payment_id) {
		$objQuery =& SC_Query_Ex::getSingletonInstance();

		$ret = true;
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
				$amazon_charge_permission_id = $objQuery->getOne("select amazon_billing_agreement_id from dtb_customer where customer_id = ? and brand_id=?", array($arrOrder["customer_id"],$arrOrder["brand_id"]));
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
				GC_Utils_Ex::gfPrintLog_r("Amazon authResult: ", $AuthorizationStatus);

				// 成功
				if($create_response_object['status'] == 201 && $AuthorizationStatus == 'Authorized'){
					$AmazonAuthorizationId = strval($create_response->chargeId); // 取引ID
					$objQuery->update("dtb_order", array('memo02' => $AmazonAuthorizationId),"order_id=? and brand_id=?",array($order_id,$arrOrder["brand_id"]));
					GC_Utils_Ex::gfPrintLog("AmazonPay AmazonAuthorizationId=$AmazonAuthorizationId order_id=$order_id");
					$ret = true;
				}
				// 失敗
				else{
					$code = strval($create_response->reasonCode);
					GC_Utils_Ex::gfPrintLog("AmazonPay オーソリエラー ReasonCode=$code order_id=$order_id");
					$objQuery->update("dtb_order", array('memo01' => 'AmazonPayError', 'memo02' => $code),"order_id=? and brand_id=?",array($order_id,$arrOrder["brand_id"]));
					$ret = false;
				}
				break;

			// // NP(REST)
			// case 11:
			// 	$objNpRest = new rest\SC_Helper_Mdl_NPPRO_Ex();
			// 	if ($objNpRest->changeNpProCredit($order_id)) {
			// 		$ret = true;
			// 	}
			// 	break;

			// VAMOS後払い
			case MDL_VAMOS_PAYMENT_ID:
				$objVa = new SC_Helper_Mdl_VAMOS_Ex();
				if ($objVa->changeVaCredit($order_id)) {
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
				$customer_card_id = $objQuery->getOne("select customer_card_id from dtb_customer where customer_id = ? and brand_id=?", array($arrOrder["customer_id"],$arrOrder["brand_id"]));
				$message = $objPayjentHelper->sfAuthPaygentCredit($arrOrder["order_id"],$arrOrder["customer_id"],$customer_card_id,$arrOrder['payment_total']);
				if ($message == "オーソリOK") {
					$ret = true;
				}
				break;

			// // NP
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

				$amazon_charge_permission_id = $objQuery->getOne("select amazon_billing_agreement_id from dtb_customer where customer_id = ? and brand_id=?", array($arrOrder["customer_id"],$arrOrder["brand_id"]));
				$authorization_amount = $arrOrder['payment_total'];
				$currency_code = 'JPY';
				$capture_now = false;
				$can_handle_pending_authorization = false;
				$idempotency_key = substr(base_convert(hash('sha256', uniqid()), 16, 36), 0, 25); // 冪等キー
				$brand_name = $objQuery->getOne("select name from dtb_brand where id = ?", array($arrOrder["brand_id"]));

				$authorization_reference_id = "AS" . $order_id;
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
				GC_Utils_Ex::gfPrintLog_r("Amazon authResult: ", $AuthorizationStatus);

				// 成功
				if($create_response_object['status'] == 201 && $AuthorizationStatus == 'Authorized'){
					$AmazonAuthorizationId = strval($create_response->chargeId); // 取引ID
					$objQuery->update("dtb_order", array('memo02' => $AmazonAuthorizationId),"order_id=? and brand_id=?",array($order_id,$arrOrder["brand_id"]));
					GC_Utils_Ex::gfPrintLog("AmazonPay AmazonAuthorizationId=$AmazonAuthorizationId order_id=$order_id");
					$ret = true;
					// $this->tpl_onload = "window.alert('AmazonPayオーソリ変更が完了しました');";
				}
				// 失敗
				else{
					$code = strval($create_response->reasonCode);
					GC_Utils_Ex::gfPrintLog("AmazonPay オーソリエラー ReasonCode=$code order_id=$order_id");
					$objQuery->update("dtb_order", array('memo01' => 'AmazonPayError', 'memo02' => $code),"order_id=? and brand_id=?",array($order_id,$arrOrder["brand_id"]));
					$ret = false;
					// $this->tpl_onload = "window.alert('AmazonPayオーソリエラーが発生しました');";
				}
				break;

			// VAMOS後払い
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
	 * NP変更案内対象顧客かチェック
	 */
	public function lfCheckChangeNotiesNp($customer_id) {
		$objCustomer = new SC_Helper_Customer_Ex();

		// チェックアイコン
		$arrCheckIcon = array(996,997);

		// 顧客アイコンリストを取得
		$arrCustomerStatus = $objCustomer->sfGetCustomerStatus($customer_id);
		foreach($arrCustomerStatus as $data) {
			if (in_array($data["status"], $arrCheckIcon)) {
				// 切り替え案内対象者＝手数料無料
				return true;
			}
		}
		return false;
	}

	/**
	 * 対象注文商品の重量を計算し配送方法を求める処理
	 */
	public function lfChangeOrderShippingMethod($order_id, $is_update = true) {
		$objQuery = SC_Query_Ex::getSingletonInstance();
		// ショップ共通設定取得
		$objDb = new SC_Helper_DB_Ex();
		$arrBaseInfo = $objDb->sfGetBasisData(true);
		// 重量計算SQL
		$sql = " SELECT SUM(CEIL(COALESCE(dp.weight, 1) * COALESCE(dcc.weight_rate, 1)) * dod.quantity)"
			 . " FROM dtb_order_detail dod INNER JOIN dtb_products dp ON dod.product_id = dp.product_id "
			 . " LEFT JOIN dtb_category_container dcc ON dcc.category_id = dp.category_id AND dcc.container_id = dod.container_id"
			 . " WHERE dod.order_id = ?"
		;
		$weight = $objQuery->getOne($sql, array($order_id));

// GC_Utils_Ex::gfPrintLog("border_weight: ". $arrBaseInfo["border_weight"]);
// GC_Utils_Ex::gfPrintLog("weight: ". $weight);
		// 重量比較
		if ($arrBaseInfo["border_weight_sagawa"] <= $weight) {
			// 佐川基準値を上回った場合は「佐川急便」
			$deliv_id = 1;
		} else if ($arrBaseInfo["border_weight"] < $weight) {
			// 郵政基準値以上であれば「ゆうパック」
			$deliv_id = 2;
		} else {
			// 郵政基準値以内であれば「ゆうパケット」
			$deliv_id = 6;
			// 支払方法も確認
			$payment_id = $objQuery->getOne("SELECT payment_id FROM dtb_order WHERE order_id = ?", array($order_id));
			if ($payment_id == 4) {
				// 代金引換の場合は強制的に「ゆうパック」
				$deliv_id = 2;
			}
			// else {
			// 	// イレギュラー対応（ホワイトリリー緊急容器によりゆうパックに強制変更）
			// 	$sql = " SELECT COUNT(*)"
			// 		 . " FROM dtb_order do2"
			// 		 . " WHERE do2.order_id = ?"
			// 		 . " AND EXISTS"
			// 		 . " (	SELECT NULL"
			// 		 . "	FROM dtb_order_detail dod"
			// 		 . "	INNER JOIN dtb_order_detail_option dodo ON dod.order_id = dodo.order_id AND dod.order_detail_id = dodo.order_detail_id"
			// 		 . "	WHERE dod.order_id = do2.order_id"
			// 		 . "	AND dodo.option_id = 2 AND dodo.option_detail_id = 1"
			// 		 . " )"
			// 	;
			// 	$cnt = $objQuery->getOne($sql, array($order_id));
			// 	if ($cnt > 0) {
			// 		// ホワイトリリー受注なので、ゆうパックに変更
			// 		$deliv_id = 2;
			// 	}
			// }
		}

		if ($is_update) {
			// 受注＆配送テーブルに更新
			$arrUpdValues = array();
			$arrUpdValues["deliv_id"] = $deliv_id;
			$arrUpdValues["update_date"] = "CURRENT_TIMESTAMP";
			$objQuery->update("dtb_order", $arrUpdValues, "order_id = ?", array($order_id));
			$objQuery->update("dtb_shipping", $arrUpdValues, "order_id = ?", array($order_id));
		}
		return $deliv_id;
	}

	/**
	 * 対象注文商品の重量を計算し配送方法を求める処理（イレギュラー）
	 */
	public function lfChangeOrderShippingMethod_Irregular($order_id, $is_update = true) {
		$objQuery = SC_Query_Ex::getSingletonInstance();
		// ショップ共通設定取得
		$objDb = new SC_Helper_DB_Ex();
		$arrBaseInfo = $objDb->sfGetBasisData(true);
		// 重量計算SQL
		$sql = " SELECT SUM(CEIL(COALESCE(dp.weight, 1) * COALESCE(dcc.weight_rate, 1)) * dod.quantity)"
			 . " FROM dtb_order_detail dod INNER JOIN dtb_products dp ON dod.product_id = dp.product_id "
			 . " LEFT JOIN dtb_category_container dcc ON dcc.category_id = dp.category_id AND dcc.container_id = dod.container_id"
			 . " WHERE dod.order_id = ?"
		;
		$weight = $objQuery->getOne($sql, array($order_id));

// GC_Utils_Ex::gfPrintLog("border_weight: ". $arrBaseInfo["border_weight"]);
// GC_Utils_Ex::gfPrintLog("weight: ". $weight);
		// 重量比較
		if ($arrBaseInfo["border_weight_sagawa"] <= $weight) {
			// 佐川基準値を上回った場合は「佐川急便」
			$deliv_id = 1;
		} else if ($arrBaseInfo["border_weight"] < $weight) {
			// 郵政基準値以上であれば「ゆうパック」
			$deliv_id = 2;
		} else {
			// 郵政基準値以内であれば「ゆうパケット」
			$deliv_id = 6;
			// 支払方法も確認
			$payment_id = $objQuery->getOne("SELECT payment_id FROM dtb_order WHERE order_id = ?", array($order_id));
			if ($payment_id == 4) {
				// 代金引換の場合は強制的に「ゆうパック」
				$deliv_id = 2;
			}
			else {
				// イレギュラー対応（ホワイトリリー緊急容器によりゆうパックに強制変更）
				$sql = " SELECT COUNT(*)"
					 . " FROM dtb_order do2"
					 . " WHERE do2.order_id = ?"
					 . " AND EXISTS"
					 . " (	SELECT NULL"
					 . "	FROM dtb_order_detail dod"
					 . "	INNER JOIN dtb_order_detail_option dodo ON dod.order_id = dodo.order_id AND dod.order_detail_id = dodo.order_detail_id"
					 . "	WHERE dod.order_id = do2.order_id"
					 . "	AND dodo.option_id = 2 AND dodo.option_detail_id = 1"
					 . " )"
				;
				$cnt = $objQuery->getOne($sql, array($order_id));
				if ($cnt > 0) {
					// ホワイトリリー受注なので、ゆうパックに変更
					$deliv_id = 2;
				}
			}
		}

		if ($is_update) {
			// 受注＆配送テーブルに更新
			$arrUpdValues = array();
			$arrUpdValues["deliv_id"] = $deliv_id;
			$arrUpdValues["update_date"] = "CURRENT_TIMESTAMP";
			$objQuery->update("dtb_order", $arrUpdValues, "order_id = ?", array($order_id));
			$objQuery->update("dtb_shipping", $arrUpdValues, "order_id = ?", array($order_id));
		}
		return $deliv_id;
	}
	
	// 初回手数料チェック
	public function getFirstDeferredCharge($customer_id) {
		$objQuery = SC_Query_Ex::getSingletonInstance();
		// 初回後払い手数料が220円だったのか確認
		$sql = " SELECT do2.charge FROM dtb_order do2 WHERE do2.del_flg = 0 AND do2.customer_id = ? AND do2.is_first = 1 AND do2.payment_id IN (?,?) AND do2.teiki_id IS NOT NULL ORDER BY do2.order_id LIMIT 1";
		return $objQuery->getOne($sql, array($customer_id, MDL_VAMOS_PAYMENT_ID, 4));
	}
}
