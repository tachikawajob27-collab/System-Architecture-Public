<?php

//require_once(dirname(__FILE__) . "/../../downloads/module/mdl_paygent/LC_Page_Mdl_Paygent_Helper.php");

/**
 * 会員情報の登録・編集・検索ヘルパークラス.
 *
 *
 * @package Helper
 * @author LLL
 * @version $Id: SC_Helper_Teiki.php  $
 */
/*
 * UPDATE DATE     :2019/05/27
 * UPDATE COMMENT  :発送日とお届け日の間隔の固定
 * UPDATE CODE     :@create001
 *
 * UPDATE DATE     :2019/07/03
 * UPDATE COMMENT  :定期情報及び、顧客対応履歴に変更を加えた際に、自動で対応した人の名前を表示
 * UPDATE CODE     :@create002
 *
 * UPDATE DATE     :2019/09/11
 * UPDATE COMMENT  :回数カウントだけされる受注を作成
 * UPDATE CODE     :@create003
 *
 * UPDATE DATE     :2019/10/03
 * UPDATE COMMENT  :購入回数カウントのステータスに「顧客都合返品」を追加
 * UPDATE CODE     :@create004
 */
class SC_Helper_Teiki_Renew {

	/**
	 * 定期コース管理テーブルにデータを登録する。
	 * @todo オンライン登録用に重複チェック関数を作成
	 *
	 * @param integer $order_id 注文ID
	 * @access public
	 * @return integer 登録編集したユーザーのcustomer_id
	 */
	function sfRegistTeikiData($order_id, $interval = 1, $interval_type = '0') {
		$objQuery =& SC_Query_Ex::getSingletonInstance();

		// LITバグ修正
		if(empty($interval)) $interval = 1;
		if(empty($interval_type)) $interval_type = '0';
		
		$arrOrderData = $objQuery->getRow("order_id, customer_id, payment_id, payment_method, deliv_check, deliv_id, create_date", "dtb_order", "order_id = ?", array($order_id));
		$arrOrderDetailData = $objQuery->getRow("product_id, product_class_id, product_code, quantity", "dtb_order_detail", "order_id = ?", array($order_id));
		// deliv_checkがNULLの場合は-1(会員住所)を設定
		if(empty($arrOrderData["deliv_check"])) $arrOrderData["deliv_check"] = -1;
		
		$count = $objQuery->getOne("select count(*) from dtb_teiki_order_mapping where order_id =? ", array($arrOrderData["order_id"]));
		
		if($count > 0){
			$msg = "注文ID:".$order_id."は既に定期コース登録済みです。";
			GC_Utils_Ex::gfPrintLog($msg);
			return $msg;
		}

		$arrShippingData = $objQuery->getRow("time_id, shipping_date", "dtb_shipping", "order_id = ?", array($order_id));
		
		// カラム名の補正
		$arrOrderData['order_date'] = $arrOrderData['create_date'];
		unset ($arrOrderData['create_date']);

		$arrShippingData['last_shipping_date'] = $arrShippingData['shipping_date'];
		if (!empty($arrShippingData['shipping_date'])) {
			$last_shipping_date = strtotime($arrShippingData['shipping_date']);
			$next_shipping_date = strtotime("+" . $interval . " month", $last_shipping_date);
			$arrShippingData['next_shipping_date'] = date("Y/m/d", $next_shipping_date);
		} else {
			// 配送指定なしの場合は２日後のお届けと仮定する。
			$last_shipping_date = strtotime("+2 day");
			$next_shipping_date = strtotime("+".$interval." month", $last_shipping_date);
			$arrShippingData['next_shipping_date'] = date("Y/m/d", $next_shipping_date);
		}
		unset ($arrShippingData['shipping_date']);
		unset ($arrOrderDetailData['product_code']);
		 
		$sqlval = array();
		foreach ($arrOrderData as $key=>$val) {
			$sqlval[$key]=$val;
		}
		foreach ($arrOrderDetailData as $key=>$val) {
			$sqlval[$key]=$val;
		}
		
		foreach ($arrShippingData as $key=>$val) {
			$sqlval[$key]=$val;
		}
		
		$sqlval['update_date'] = 'CURRENT_TIMESTAMP';
		
		// カード払いの場合で顧客カードIDがあればセット
		if ($arrOrderData['payment_id'] == CREDIT_PAYMENT_ID) {
			$cardSeq = SC_Helper_Teiki::sfGetCustomerCardIdFromDtbOrder($arrOrderData['customer_id']);
			if(!empty($cardSeq)){
				$sqlval['customer_card_id'] = $cardSeq;
			}
		}
		
		// お届け間隔の設定
		$sqlval['interval'] = $interval;
		$sqlval['interval_type'] = $interval_type;

		$sqlval['teiki_id'] = $objQuery->nextVal('dtb_teiki_teiki_id_seq');
		$objQuery->insert("dtb_teiki", $sqlval);
		$teiki_id = $objQuery->getOne("select teiki_id from dtb_teiki where order_id = ?",array($order_id));
		$objQuery->insert("dtb_teiki_order_mapping", array('order_id' => $order_id, 'teiki_id' => $teiki_id));
		
		return "定期コース管理テーブルに正常に登録されました。";
	}
	
	function sfCountTeikiByCustomerId($customer_id) {
		$objQuery =& SC_Query_Ex::getSingletonInstance();
		return $objQuery->getOne("select count(*) from dtb_teiki where customer_id = ?", array($customer_id));
	}
		
	function sfGetTeikiIdByOrderId($order_id) {
		$objQuery =& SC_Query_Ex::getSingletonInstance();
		$teiki_id= $objQuery->getOne("select teiki_id from dtb_teiki_order_mapping where order_id = ? and exists (select null from dtb_teiki where teiki_id = dtb_teiki_order_mapping.teiki_id and del_flg = 0)", array($order_id));
		if(!isset($teiki_id)){
			$teiki_id= $objQuery->getOne("select t1.teiki_id from dtb_teiki t1, dtb_order t2 where t1.del_flg = 0 and t2.customer_id = t1.customer_id and t2.order_id = ?", array($order_id));
		}
		return $teiki_id;
	}
	
	// 定期サブIDを取得
	function sfGetTeikiSubId($teiki_id, $product_id) {
		$objQuery =& SC_Query_Ex::getSingletonInstance();
		$teiki_sub_id = 0;
		// メインを検索
		$cnt = $objQuery->count("dtb_teiki", "teiki_id = ? AND product_id = ?", array($teiki_id, $product_id));
		if ($cnt == 0) {
			// サブを検索
			$teiki_sub_id = $objQuery->getOne("SELECT teiki_sub_id FROM dtb_teiki_sub WHERE teiki_id = ? AND product_id = ?", array($teiki_id, $product_id));
		}
		return $teiki_sub_id;
	}
	
	function sfGetNextshippingdateByOrderId($order_id) {
		$objQuery =& SC_Query_Ex::getSingletonInstance();
		$next_shipping_date= $objQuery->getOne("select next_shipping_date from dtb_teiki,dtb_teiki_order_mapping where dtb_teiki_order_mapping.teiki_id = dtb_teiki.teiki_id and dtb_teiki_order_mapping.order_id = ? and exists (select null from dtb_teiki where teiki_id = dtb_teiki_order_mapping.teiki_id and del_flg = 0)", array($order_id));
		if(!isset($next_shipping_date)){
			$teiki_id= $objQuery->getOne("select t1.next_shipping_date from dtb_teiki t1, dtb_order t2 where t1.del_flg = 0 and t2.customer_id = t1.customer_id and t2.order_id = ?", array($order_id));
		}
		return $next_shipping_date;
	}
	
	function sfGetTeikiIdByOrderIdOrCustomerId($order_id, $customer_id) {
		$objQuery =& SC_Query_Ex::getSingletonInstance();
		$teiki_id = $objQuery->getOne("select teiki_id from dtb_teiki_order_mapping where order_id = ?", array($order_id));
		return $teiki_id;
	}
	
	function sfGetTeikiInfoByTeikiId($teiki_id, $teiki_sub_id = null, $order_id = null) {
		$objQuery =& SC_Query_Ex::getSingletonInstance();
		if (empty($teiki_sub_id)) {
			$arrTeiki = $objQuery->getRow("*", "dtb_teiki", "teiki_id = ?", array($teiki_id));
			if (isset($arrTeiki)) {
				$arrTeiki['teiki_count'] = (empty($arrTeiki['order_count'])) ? 0: $arrTeiki['order_count'];
				//---------------UPDATE CODE @create003 START----------
				//---------------UPDATE CODE @create004 START----------
				$where = <<< __EOF__
				t1.del_flg = 0
				and t1.status in (1,5,8,9000,9001)
				and t1.order_id = t2.order_id
				and t2.teiki_id = ?
__EOF__;
				//---------------UPDATE CODE @create004 E N D----------
				//---------------UPDATE CODE @create003 E N D----------

				$arrTeiki['teiki_count'] += $objQuery->count("dtb_order t1, dtb_teiki_order_mapping t2", $where, array($teiki_id));
			}
		} else {
			$arrTeiki = $objQuery->getRow("*", "dtb_teiki_sub", "teiki_id = ? and teiki_sub_id = ?", array($teiki_id, $teiki_sub_id));
			if (isset($arrTeiki)) {
				$arrTeiki['teiki_count'] = (empty($arrTeiki['order_count'])) ? 0: $arrTeiki['order_count'];
				//---------------UPDATE CODE @create003 START----------
				//---------------UPDATE CODE @create004 START----------
				$where = <<< __EOF__
				t1.del_flg = 0
				and t1.status in (1,5,8,9000,9001)
				and t1.order_id = t2.order_id
				and t2.teiki_id = ?
__EOF__;
				//---------------UPDATE CODE @create004 E N D----------
				//---------------UPDATE CODE @create003 E N D----------

				$arrTeiki['teiki_count'] += $objQuery->count("dtb_order t1, dtb_teiki_order_mapping t2", $where, array($teiki_id));
			}
		}
		return $arrTeiki;
	}

	function sfIsTeikiOrder($order_id) {
		$objQuery =& SC_Query_Ex::getSingletonInstance();
		$teiki_id = $objQuery->getOne("select teiki_id from dtb_teiki_order_mapping where order_id = ?", array($order_id));
		return (isset($teiki_id));
	}
	
	function sfIsTeikiProduct($order_id) {
		$objQuery =& SC_Query_Ex::getSingletonInstance();
		$teiki_flg = $objQuery->getOne("select t2.teiki_flg from dtb_order_detail t1, dtb_products t2 where t1.order_id = ? and t1.product_id = t2.product_id", array($order_id));
		if ($teiki_flg == 0) {
			$teiki_id = $objQuery->getOne("select teiki_id from dtb_teiki_order_mapping where order_id=?", array($order_id));
			if($teiki_id > 0) $teiki_flg = 1;
		}
		return ($teiki_flg != 0);
	}
	
	function sfIsTeikiProductByProductId($product_id) {
		$objQuery =& SC_Query_Ex::getSingletonInstance();
		$teiki_flg = $objQuery->getOne("select teiki_flg from dtb_products where product_id = ?", array($product_id));
		return ($teiki_flg != 0);        
	}
	
	function sfRegistCard($customer_id, $payment_id, $payment_method, $cardSeq) {
		$objQuery =& SC_Query_Ex::getSingletonInstance();
		$objQuery->update("dtb_customer", array('customer_card_id' => $cardSeq, 'payment_id' => $payment_id , 'payment_method' => $payment_method, 'update_date' => 'current_timestamp'), "customer_id = ?",array($customer_id));
	}
	
	function sfGetCustomerCardId($customer_id) {
		$objQuery =& SC_Query_Ex::getSingletonInstance();
		$cardSeq = $objQuery->getOne("select customer_card_id from dtb_teiki where customer_id = ?", array($customer_id));
		return $cardSeq;
	}

	function sfTeikiCount($teiki_id, $order_count) {
		if(empty($teiki_id)){
			return "エラー：定期IDがNULLです";
		}
		if(!is_numeric($order_count)){
			return "エラー：半角数字を入力してください。";
		}
		$objQuery =& SC_Query_Ex::getSingletonInstance();
		$note = $objQuery->getOne("select note from dtb_teiki where teiki_id = ?", array($teiki_id));
		if(!empty($note)){
			$note .= PHP_EOL;
		}
		if(empty($comment) || $comment == ""){
			$comment = "購入回数の初期値を{$order_count}に変更";
		}
		//---------------UPDATE CODE @create002 START----------
		$date = new DateTime();
		$note .= $date->format('Y/m/d H:i ') .":". $comment . SC_Helper_Teiki::sfGetSupportName() . PHP_EOL;
		//---------------UPDATE CODE @create002 E N D----------
		$objQuery->update("dtb_teiki", array("order_count" => $order_count, "note" => $note, "update_date" => "current_timestamp"), "teiki_id = ?", array($teiki_id));
	
		$message = "定期ID:".$teiki_id."の購入回数の初期値を{$order_count}に変更しました。";
				  
		return $message;
	}
	
	function sfPendingTeiki($teiki_id, $teiki_sub_id, $comment) {
		if(empty($teiki_id)){
			return "エラー：定期IDがNULLです";
		}
		$objQuery =& SC_Query_Ex::getSingletonInstance();
		// 定期商品ID
		$product_id = SC_Helper_Teiki_Renew::lfGetTeikiProduct($objQuery, $teiki_id, $teiki_sub_id);
		$note = $objQuery->getOne("select note from dtb_teiki where teiki_id = ?", array($teiki_id));
		if(!empty($note)){
			$note .= PHP_EOL;
		}
		if(empty($comment) || $comment == ""){
			$comment = "【商品ID：{$product_id}】　休止ステータスに変更";
		}

		//---------------UPDATE CODE @create002 START----------
		$date = new DateTime();
		$note .= $date->format('Y/m/d H:i ') .":". $comment . SC_Helper_Teiki::sfGetSupportName() . PHP_EOL;
		//---------------UPDATE CODE @create002 E N D----------
		
		if ($teiki_sub_id > 0) {
			// サブ定期商品
			$objQuery->update("dtb_teiki", array("note" => $note, "update_date" => "current_timestamp"), "teiki_id = ?", array($teiki_id));
			$objQuery->update("dtb_teiki_sub", array("status" => 2, "update_date" => "current_timestamp"), "teiki_id = ? AND teiki_sub_id = ?", array($teiki_id, $teiki_sub_id));
		} else {
			// メイン定期商品
			$objQuery->update("dtb_teiki", array("status" => 2, "note" => $note, "update_date" => "current_timestamp"), "teiki_id = ?", array($teiki_id));
		}

		$message = "商品ID:".$product_id."を休止ステータスに変更しました。";

		return $message;
	}
	
	// 定期商品解約処理
	function sfStopTeiki($teiki_id, $teiki_detail_id, $comment, $stop_teiki_reason_id = null) {
		if(empty($teiki_id)){
			return "エラー：定期IDがNULLです";
		}
		$objQuery =& SC_Query_Ex::getSingletonInstance();
		$product_id = SC_Helper_Teiki_Renew::lfGetTeikiProduct($objQuery, $teiki_id, $teiki_sub_id);
		$note = $objQuery->getOne("select note from dtb_teiki where teiki_id = ?", array($teiki_id));
		if(!empty($note)){
			$note .= PHP_EOL;
		}
		if(empty($comment) || $comment == ""){
			$comment = "商品ID：".$product_id."を解約ステータスに変更";
		}
		//---------------UPDATE CODE @create002 START----------
		$date = new DateTime();
		$note .= $date->format('Y/m/d H:i ') .":[解約]". $comment . SC_Helper_Teiki::sfGetSupportName() . PHP_EOL;
		//---------------UPDATE CODE @create002 E N D----------
		
		if (!empty($stop_teiki_reason_id)) {
			// 解約理由
			$stop_teiki_reason = $objQuery->getOne("SELECT name FROM mtb_stop_teiki_reason WHERE id =? ", array($stop_teiki_reason_id));
			if (is_null($stop_teiki_reason)) $stop_teiki_reason = "指定なし";
			$comment = "商品ID：{$product_id}の解約理由を".$stop_teiki_reason."に設定";
			$name = (!empty($_SESSION['login_id'])) ? $_SESSION['login_id'] : "SYSTEM";
			$date = new DateTime();
			$note .= $date->format('Y/m/d H:i ') .":". $comment . SC_Helper_Teiki::sfGetSupportName() . PHP_EOL;
		} else {
			$stop_teiki_reason_id = null;
		}

		$objQuery->update("dtb_teiki", array("status" => 3, "cancel_teiki_reason_id" => $stop_teiki_reason_id, "note" => $note, "update_date" => "current_timestamp", "cancel_date" => "current_timestamp"), "teiki_id = ? and teiki_detail_id = ?", array($teiki_id,$teiki_detail_id));
		
		$message = "商品ID:".$product_id."を解約ステータスに変更しました。";

		return $message;
	}

	function sfStopTeikiAll($teiki_id, $comment) {
		if(empty($teiki_id)){
			return "エラー：定期IDがNULLです";
		}
		$objQuery =& SC_Query_Ex::getSingletonInstance();
		$note = $objQuery->getOne("select note from dtb_teiki where teiki_id = ?", array($teiki_id));
		if(!empty($note)){
			$note .= PHP_EOL;
		}
		if(empty($comment) || $comment == ""){
			$comment = "解約ステータスに変更";
		}
		//---------------UPDATE CODE @create002 START----------
		$date = new DateTime();
		$note .= $date->format('Y/m/d H:i ') .":[解約]". $comment . SC_Helper_Teiki::sfGetSupportName() . PHP_EOL;
		//---------------UPDATE CODE @create002 E N D----------
		$objQuery->update("dtb_teiki", array("status" => 3, "note" => $note, "update_date" => "current_timestamp", "cancel_date" => "current_timestamp"), "teiki_id = ?", array($teiki_id));
		
		$message = "定期ID:".$teiki_id."を解約ステータスに変更しました。";

		return $message;
	}

	function sfDeleteTeiki($teiki_id, $comment) {
		if(empty($teiki_id)){
			return "エラー：定期IDがNULLです";
		}
		$objQuery =& SC_Query_Ex::getSingletonInstance();
		$note = $objQuery->getOne("select note from dtb_teiki where teiki_id = ?", array($teiki_id));
		if(!empty($note)){
			$note .= PHP_EOL;
		}
		if(empty($comment) || $comment == ""){
			$comment = "削除";
		}
		//---------------UPDATE CODE @create002 START----------
		$date = new DateTime();
		$note .= $date->format('Y/m/d H:i ') .":". $comment . SC_Helper_Teiki::sfGetSupportName() . PHP_EOL;
		//---------------UPDATE CODE @create002 E N D----------
		$objQuery->update("dtb_teiki", array("del_flg" => 1, "note" => $note, "update_date" => "current_timestamp"), "teiki_id = ?", array($teiki_id));
		 
		$message = "定期ID:".$teiki_id."を削除しました。";
		 
		return $message;
	}

	// 次回お届け日の更新
	function sfRestartTeiki($teiki_id, $teiki_sub_id, $next_shipping_date) {
		if(empty($teiki_id)){
			return "エラー：定期IDがNULLです";
		}
		if(empty($next_shipping_date)){
			return "エラー：次回お届け日が空です";
		}
		
		$objQuery =& SC_Query_Ex::getSingletonInstance();
		$product_id = SC_Helper_Teiki_Renew::lfGetTeikiProduct($objQuery, $teiki_id, $teiki_sub_id);
		$note = $objQuery->getOne("select note from dtb_teiki where teiki_id = ?", array($teiki_id));
		
		if(!empty($note)){
			$note .= PHP_EOL;
		}
		$comment = "商品ID：".$product_id."の次回お届け日を".$next_shipping_date."に設定";
		//---------------UPDATE CODE @create002 START----------
		$date = new DateTime();
		$note .= $date->format('Y/m/d H:i ') .":". $comment . SC_Helper_Teiki::sfGetSupportName() . PHP_EOL;
		//---------------UPDATE CODE @create002 E N D----------
		
		if ($teiki_sub_id > 0) {
			// サブ
			$objQuery->update("dtb_teiki", array("note" => $note, "update_date" => "current_timestamp"), "teiki_id = ?", array($teiki_id));
			$objQuery->update("dtb_teiki_sub", array("status" => 1, "next_shipping_date" => $next_shipping_date, "update_date" => "current_timestamp"), "teiki_id = ? and teiki_sub_id = ? and (status = 1 or status = 2)", array($teiki_id, $teiki_sub_id));
		} else {
			// メイン
			$objQuery->update("dtb_teiki", array("status" => 1, "note" => $note, "next_shipping_date" => $next_shipping_date, "update_date" => "current_timestamp"), "teiki_id = ? and (status = 1 or status = 2)", array($teiki_id));
		}
		
		$message = "商品ID:".$product_id."の次回お届け日を".$next_shipping_date."に設定しました。";
		return $message;
	}

	function sfResetQuantity($teiki_id,$teiki_sub_id,$quantity) {
		if(empty($teiki_id)){
			return "エラー：定期IDがNULLです";
		}
		if(empty($quantity)){
			return "エラー：次回お届け日が空です";
		}
		
		$objQuery =& SC_Query_Ex::getSingletonInstance();
		$product_id = SC_Helper_Teiki_Renew::lfGetTeikiProduct($objQuery, $teiki_id, $teiki_sub_id);
		$note = $objQuery->getOne("select note from dtb_teiki where teiki_id = ?", array($teiki_id));
		
		if(!empty($note)){
			$note .= PHP_EOL;
		}
		$comment = "商品ID：".$product_id."の数量を".$quantity."に設定";
		//---------------UPDATE CODE @create002 START----------
		$date = new DateTime();
		$note .= $date->format('Y/m/d H:i ') .":". $comment . SC_Helper_Teiki::sfGetSupportName() . PHP_EOL;
		//---------------UPDATE CODE @create002 E N D----------
		
		if ($teiki_sub_id > 0) {
			// サブ
			$objQuery->update("dtb_teiki", array("note" => $note, "update_date" => "current_timestamp"), "teiki_id = ?", array($teiki_id));
			$objQuery->update("dtb_teiki_sub", array("status" => 1, "quantity" => $quantity, "update_date" => "current_timestamp"), "teiki_id = ? and teiki_sub_id = ?", array($teiki_id, $teiki_sub_id));
		} else {
			// メイン
			$objQuery->update("dtb_teiki", array("status" => 1, "note" => $note, "quantity" => $quantity, "update_date" => "current_timestamp"), "teiki_id = ?", array($teiki_id));
		}
		
		$message = "商品ID:".$product_id."の数量を".$quantity."に設定しました。";
		return $message;
	}

	//---------------UPDATE CODE @create001 START----------
	// 次回発送日変更に伴う、次回お届け日の更新
	function sfRestartTeikiCommitDate($teiki_id, $next_commit_date) {
		if(empty($teiki_id)){
			return "エラー：定期IDがNULLです";
		}
		if(empty($next_commit_date)){
			return "エラー：次回発送日が空です";
		}
		$objQuery =& SC_Query_Ex::getSingletonInstance();
		$note = $objQuery->getOne("select note from dtb_teiki where teiki_id = ?", array($teiki_id));

		if(!empty($note)){
			$note .= PHP_EOL;
		}

		// 次回お届け日の取得
		$dt = new DateTime($next_commit_date);
		list($next_shipping_date_year, $next_shipping_date_month, $next_shipping_date_day) = SC_Helper_DB_Ex::sfGetBusinessDate($dt->format("Y"), $dt->format("m"), $dt->format("d"), DEFAULT_SHIPPING_OFFSET_DAY, 1);
		$next_shipping_date = $next_shipping_date_year."/".$next_shipping_date_month."/".$next_shipping_date_day;

		$comment = "次回発送日を".$dt->format("Y/m/d")."へ変更したため､次回配送予定日を".$next_shipping_date."に設定";
		//---------------UPDATE CODE @create002 START----------
		$date = new DateTime();
		$note .= $date->format('Y/m/d H:i ') .":". $comment . SC_Helper_Teiki::sfGetSupportName() . PHP_EOL;
		//---------------UPDATE CODE @create002 E N D----------

		$objQuery->update("dtb_teiki", array("status" => 1, "note" => $note
				, "next_shipping_date" => $next_shipping_date
				, "update_date" => "current_timestamp"), "teiki_id = ? and (status = 1 or status = 2)", array($teiki_id));

		$message = "定期ID:".$teiki_id."の次回配送予定日を".$next_shipping_date."に設定しました。";

		return $message;
	}

	// 発送日一括変更
	function sfSetBulkChange($teiki_id, $flg) {
		if(empty($teiki_id)){
			return "エラー：定期IDがNULLです";
		}

		$objQuery =& SC_Query_Ex::getSingletonInstance();

		$objQuery->update("dtb_teiki", array("teiki_not_bulk_change" => $flg
				, "update_date" => "current_timestamp"), "teiki_id = ?", array($teiki_id));

		$message = "定期ID:".$teiki_id."の発送日一括変更を更新しました。";

		return $message;
	}
	//---------------UPDATE CODE @create001 E N D----------

	function sfDelivTimeTeiki($teiki_id, $teiki_sub_id, $time_id) {
		if(empty($teiki_id)){
			return "エラー：定期IDがNULLです";
		}
		$objQuery =& SC_Query_Ex::getSingletonInstance();
		$product_id = SC_Helper_Teiki_Renew::lfGetTeikiProduct($objQuery, $teiki_id, $teiki_sub_id);
		$note = $objQuery->getOne("select note from dtb_teiki where teiki_id = ?", array($teiki_id));
		
		if(!empty($note)){
			$note .= PHP_EOL;
		}
		$comment = "商品ID：".$product_id."の配送時間を".$time_id."に設定";
		//---------------UPDATE CODE @create002 START----------
		$date = new DateTime();
		$note .= $date->format('Y/m/d H:i ') .":". $comment . SC_Helper_Teiki::sfGetSupportName() . PHP_EOL;
		//---------------UPDATE CODE @create002 E N D----------
		
		if ($teiki_sub_id > 0) {
			$objQuery->update("dtb_teiki", array("note" => $note, "update_date" => "current_timestamp"), "teiki_id = ?", array($teiki_id));
			$objQuery->update("dtb_teiki_sub", array("deliv_time_id" => $time_id, "update_date" => "current_timestamp"), "teiki_id = ? and teiki_sub_id = ?", array($teiki_id, $teiki_sub_id));
		} else {
			//$objQuery->update("dtb_teiki", array("status" => 1, "note" => $note, "time_id" => $time_id, "update_date" => "current_timestamp"), "teiki_id = ?", array($teiki_id));
			$objQuery->update("dtb_teiki", array("note" => $note, "deliv_time_id" => $time_id, "update_date" => "current_timestamp"), "teiki_id = ?", array($teiki_id));
		}
		$message = "定期ID:".$teiki_id."の配送時間を".$time_id."に設定しました。";
		return $message;
	}

	function sfChargeTeiki($teiki_id, $charge) {
		if(empty($teiki_id)){
			return "エラー：定期IDがNULLです";
		}
		if(!isset($charge)){
			return "エラー：値引き額が空です";
		}
		$objQuery =& SC_Query_Ex::getSingletonInstance();
		$note = $objQuery->getOne("select note from dtb_teiki where teiki_id = ?", array($teiki_id));
		
		if(!empty($note)){
			$note .= PHP_EOL;
		}
		$comment = "手数料".$charge."円を設定";
		//---------------UPDATE CODE @create002 START----------
		$date = new DateTime();
		$note .= $date->format('Y/m/d H:i ') .":". $comment . SC_Helper_Teiki::sfGetSupportName() . PHP_EOL;
		//---------------UPDATE CODE @create002 E N D----------
		
		$objQuery->update("dtb_teiki", array("note" => $note, "charge" => $charge
				, "update_date" => "current_timestamp"), "teiki_id = ?", array($teiki_id));
		
		$message = "定期ID:".$teiki_id."の次回手数料を".$charge."に設定しました。";
				
		return $message;
	}
	
	// 次回値引き設定
	function sfDiscountTeiki($teiki_id, $teiki_sub_id, $discount) {
		if(empty($teiki_id)){
			return "エラー：定期IDがNULLです";
		}
		if(!isset($discount)){
			return "エラー：値引き額が空です";
		}
		$objQuery =& SC_Query_Ex::getSingletonInstance();
		$product_id = SC_Helper_Teiki_Renew::lfGetTeikiProduct($objQuery, $teiki_id, $teiki_sub_id);
		$note = $objQuery->getOne("select note from dtb_teiki where teiki_id = ?", array($teiki_id));
		
		if(!empty($note)){
			$note .= PHP_EOL;
		}
		$comment = "商品ID：".$product_id."の次回値引き額を".$discount."円に設定";
		//---------------UPDATE CODE @create002 START----------
		$date = new DateTime();
		$note .= $date->format('Y/m/d H:i ') .":". $comment . SC_Helper_Teiki::sfGetSupportName() . PHP_EOL;
		//---------------UPDATE CODE @create002 E N D----------
		
		if ($teiki_sub_id > 0) {
			$objQuery->update("dtb_teiki", array("note" => $note, "update_date" => "current_timestamp"), "teiki_id = ?", array($teiki_id));
			$objQuery->update("dtb_teiki_sub", array("discount" => $discount, "update_date" => "current_timestamp"), "teiki_id = ? and teiki_sub_id = ?", array($teiki_id, $teiki_sub_id));
		} else {
			$objQuery->update("dtb_teiki", array("note" => $note, "discount" => $discount, "update_date" => "current_timestamp"), "teiki_id = ?", array($teiki_id));
		}
		$message = "商品ID:".$product_id."の次回値引き額を".$discount."に設定しました。";
		return $message;
	}

	// 配送方法変更処理
	function sfDelivTeiki($teiki_id, $teiki_sub_id, $deliv_id, $is_manual = 0) {
		if(empty($teiki_id)){
			return "エラー：定期IDがNULLです";
		}
		if(empty($deliv_id)){
			return "エラー：支払方法IDが空です";
		}
		$objQuery =& SC_Query_Ex::getSingletonInstance();
		$product_id = SC_Helper_Teiki_Renew::lfGetTeikiProduct($objQuery, $teiki_id, $teiki_sub_id);
		$note = $objQuery->getOne("select note from dtb_teiki where teiki_id = ?", array($teiki_id));
		$deliv_nm = $objQuery->getOne("select service_name from dtb_deliv where deliv_id = ?", array($deliv_id));
		
		if(!empty($note)){
			$note .= PHP_EOL;
		}
		$comment = "商品ID：".$product_id."の配送方法を「".$deliv_nm."」に設定";
		//---------------UPDATE CODE @create002 START----------
		$date = new DateTime();
		$note .= $date->format('Y/m/d H:i ') .":". $comment . SC_Helper_Teiki::sfGetSupportName() . PHP_EOL;
		//---------------UPDATE CODE @create002 E N D----------
		
		if ($deliv_id == 6) {
			// ゆうパケの場合は指定しないフラグにする
			$is_manual = 0;
		}
		
		$objQuery->update("dtb_teiki", array("note" => $note, "deliv_id" => $deliv_id, "is_deliv_manual"=>$is_manual, "update_date" => "current_timestamp"), "teiki_id = ?", array($teiki_id));

		$message = "商品ID:".$product_id."の配送方法を「".$deliv_nm."」に設定しました。";
				
		return $message;
	}

	function sfEtc1Teiki($teiki_id, $etc1) {
		if(empty($teiki_id)){
			return "エラー：定期IDがNULLです";
		}
		$objQuery =& SC_Query_Ex::getSingletonInstance();
		$note = $objQuery->getOne("select note from dtb_teiki where teiki_id = ?", array($teiki_id));
		 
		if(!empty($note)){
			$note .= PHP_EOL;
		}
		$comment = "納品書備考１行目を「".$etc1."」に設定";
		//---------------UPDATE CODE @create002 START----------
		$date = new DateTime();
		$note .= date("Y/m/d") .":". $comment . SC_Helper_Teiki::sfGetSupportName() . PHP_EOL;
		//---------------UPDATE CODE @create002 E N D----------
		 
		$objQuery->update("dtb_teiki", array("note" => $note, "etc1" => $etc1
				, "update_date" => "current_timestamp"), "teiki_id = ?", array($teiki_id));
		 
		$message = "定期ID:".$teiki_id."の納品書備考１行目を「".$etc1."」に設定しました。";
	
		return $message;
	}
	function sfDelivNoteTeiki($teiki_id, $deliv_note) {
		if(empty($teiki_id)){
			return "エラー：定期IDがNULLです";
		}
		$objQuery =& SC_Query_Ex::getSingletonInstance();
		$note = $objQuery->getOne("select note from dtb_teiki where teiki_id = ?", array($teiki_id));
		 
		if(!empty($note)){
			$note .= PHP_EOL;
		}
		$comment = "配送業者への連絡事項を「".$deliv_note."」に設定";
		//---------------UPDATE CODE @create002 START----------
		$date = new DateTime();
		$note .= $date->format('Y/m/d H:i ') .":". $comment . SC_Helper_Teiki::sfGetSupportName() . PHP_EOL;
		//---------------UPDATE CODE @create002 E N D----------
		 
		$objQuery->update("dtb_teiki", array("note" => $note, "deliv_note" => $deliv_note
				, "update_date" => "current_timestamp"), "teiki_id = ?", array($teiki_id));
		 
		$message = "定期ID:".$teiki_id."の配送業者への連絡事項を「".$deliv_note."」に設定しました。";
	
		return $message;
	}

	// お支払い方法の変更
	function sfPaymentTeiki($customer_id, $payment_id) {
		if(empty($customer_id)){
			return "エラー：顧客IDがNULLです";
		}
		if(empty($payment_id)){
			return "エラー：支払方法IDが空です";
		}
		$objQuery =& SC_Query_Ex::getSingletonInstance();
		$arrTeiki = $objQuery->getAll("SELECT * FROM dtb_teiki WHERE customer_id = ?", array($customer_id));
		$payment_method = $objQuery->getOne("select payment_method from dtb_payment where payment_id = ?", array($payment_id));
		
		foreach($arrTeiki AS $rows) {
			$teiki_id = $rows["teiki_id"];
			$note = $rows["note"];
			
			if(!empty($note)){
				$note .= PHP_EOL;
			}
			$comment = "【定期】支払方法を「".$payment_method."」に設定";
			//---------------UPDATE CODE @create002 START----------
			$date = new DateTime();
			$note .= date("Y/m/d H:i ") .":". $comment . SC_Helper_Teiki::sfGetSupportName() . PHP_EOL;
			//---------------UPDATE CODE @create002 E N D----------
			
			$objQuery->update("dtb_teiki", array("note" => $note, "update_date" => "current_timestamp"), "teiki_id = ?", array($teiki_id));
			$objQuery->update("dtb_customer", array("payment_id" => $payment_id, "payment_method" => $payment_method, "update_date" => "current_timestamp"), "customer_id = ?", array($customer_id));
		}
		$message = "【定期】支払方法を「".$payment_method."」に設定しました。";
		return $message;
	}
	
	function sfSetTeikiProductId($teiki_id, $teiki_sub_id, $product_id) {
		if(empty($teiki_id)){
			return "エラー：定期IDがNULLです";
		}
		if(empty($product_id)){
			return "エラー：商品IDが空です";
		}
		$objQuery =& SC_Query_Ex::getSingletonInstance();
		// 定期商品ID
		$product_id_before = SC_Helper_Teiki_Renew::lfGetTeikiProduct($objQuery, $teiki_id, $teiki_sub_id);
		$note = $objQuery->getOne("select note from dtb_teiki where teiki_id = ?", array($teiki_id));
		 
		if(!empty($note)){
			$note .= PHP_EOL;
		}
		$comment = "商品IDを".$product_id_before."から".$product_id."に変更";
		//---------------UPDATE CODE @create002 START----------
		$date = new DateTime();
		$note .= $date->format('Y/m/d H:i ') .":". $comment . SC_Helper_Teiki::sfGetSupportName() . PHP_EOL;
		//---------------UPDATE CODE @create002 E N D----------

		if ($teiki_sub_id > 0) {
			// 定期サブ商品
			$objQuery->update("dtb_teiki", array("note" => $note, "update_date" => "current_timestamp"), "teiki_id = ?", array($teiki_id));
			$objQuery->update("dtb_teiki_sub", array("product_id" => $product_id, "update_date" => "current_timestamp"), "teiki_id = ? AND teiki_sub_id = ?", array($teiki_id, $teiki_sub_id));
		} else {
			// 定期メイン商品
			$product_class_id = SC_Product_Ex::getProductClassIdByQuantity($product_id, 1);
			$objQuery->update("dtb_teiki", array("note" => $note, "product_id" => $product_id,"product_class_id" => $product_class_id
					, "update_date" => "current_timestamp"), "teiki_id = ?", array($teiki_id));
		}
			
		$message = "商品IDを".$product_id_before."から".$product_id."に変更しました。";
	
		return $message;
	}
	
	function sfSetTeikiSubAdd($customer_id, $product_id, $onetime_flg = 0, $arrOptions = null) {
		$objQuery =& SC_Query_Ex::getSingletonInstance();
		$objProduct = new SC_Product_Ex();
		
		$where_tk = "tk.del_flg = 0 AND tk.customer_id = {$customer_id}";
		$where_ts = "ts.del_flg = 0 AND tk.customer_id = {$customer_id}";
		$arrTeiki = SC_Helper_Teiki_Renew::lfGetTeikiProductInfo($where_tk, $where_ts);
		if (count($arrTeiki) > 0) {
			$teiki_id = $arrTeiki[0]["teiki_id"];
			GC_Utils_Ex::gfPrintLog("sfSetTeikiSubAdd teiki_id:{$teiki_id},product_id:{$product_id}");
			
			if(empty($customer_id)){
				GC_Utils_Ex::gfPrintLog("sfSetTeikiSubAdd teiki_id:{$teiki_id} エラー：定期IDがNULLです");
				return "エラー：定期IDがNULLです";
			}
			if(empty($product_id)){
				GC_Utils_Ex::gfPrintLog("sfSetTeikiSubAdd teiki_id:{$teiki_id},product_id:{$product_id} エラー：商品IDが空です");
				return "エラー：商品IDが空です";
			}

			// カテゴリID
			$category_id = $objProduct->getProductCategories($product_id);
			$category_name = $objProduct->getProductCategories($product_id, true);
			$message = "";
			$teiki_sub_id = 0;

			if ($onetime_flg == 0) {
				// 定期商品追加時はチェック（次回のみはチェックしない）
				foreach($arrTeiki as $row) {
					if ($row["product_id"] == $product_id) {
						$message = "【エラー】「商品ID：".$product_id."」は既に存在しています。";
					}
					if ($teiki_sub_id < $row["teiki_sub_id"]) {
						$teiki_sub_id = $row["teiki_sub_id"];
					}
				}
			}
			if (strlen($message) > 0) {
				return $message;
			} else {
				$note = $objQuery->getOne("select note from dtb_teiki where teiki_id = ?", array($teiki_id));
				if(!empty($note)){
					$note .= PHP_EOL;
				}
				
				$comment = "商品:".$product_id."-".$category_name." を追加";
				//---------------UPDATE CODE @create002 START----------
				$date = new DateTime();
				$note .= $date->format('Y/m/d H:i ') .":". $comment . SC_Helper_Teiki::sfGetSupportName() . PHP_EOL;
				//---------------UPDATE CODE @create002 E N D----------
				
				$teiki_flg = $objQuery->getOne("select teiki_flg from dtb_products where product_id = ?", array($product_id));
				// 定期更新
				$objQuery->update("dtb_teiki", array("note" => $note, "update_date" => "current_timestamp"), "teiki_id = ?", array($teiki_id));
				// 定期サブを追加
				$arrVal = array();
				$arrVal["teiki_id"] = $arrTeiki[0]["teiki_id"];
				$sub_id = $objQuery->getOne("SELECT MAX(teiki_sub_id) FROM dtb_teiki_sub WHERE teiki_id = ?", array($arrTeiki[0]["teiki_id"]));
				$sub_id = $sub_id + 1;
				$arrVal["teiki_sub_id"] = $sub_id;
				$arrVal["product_id"] = $product_id;
				$arrVal["next_shipping_date"] = $arrTeiki[0]["next_shipping_date"];
				$arrVal["interval"] = $arrTeiki[0]["interval"];
				$arrVal["interval_type"] = $arrTeiki[0]["interval_type"];
				$arrVal["del_flg"] = 0;
				$arrVal["quantity"] = 1;
				$arrVal["status"] = 1;
				$arrVal["discount"] = 0;
				$arrVal["onetime_flg"] = $onetime_flg;
				if(empty($teiki_flg)) $arrVal["onetime_flg"] = 1;
				$arrVal["create_date"] = "current_timestamp";
				$arrVal["update_date"] = "current_timestamp";
				if ($category_id == 21) {
					$arrVal["deliv_id"] = 1;
				} else {
					$arrVal["deliv_id"] = 6;
				}
				// オプション項目はデフォルト0で登録
				$arrVal["container_flg"] = 0;
				$arrVal["flavor"] = 0;
				$arrVal["texture"] = 0;
				$objQuery->insert("dtb_teiki_sub", $arrVal);

				// 商品コード取得
				$product_code = $objQuery->getOne("SELECT product_code FROM dtb_products_class WHERE product_id = ?", array($product_id));
				$shikibetsu_id = substr($product_code, 0, 3);
				
				if (!empty($arrOptions)) {
					// オプション設定がある場合
					SC_Helper_Teiki_Renew::sfTeikiOptionSetting($teiki_id, $sub_id, $product_id, $shikibetsu_id, $arrOptions);
				}
				
				$message = "商品：".$product_id."-".$category_name."を定期に追加しました。";
				GC_Utils_Ex::gfPrintLog("sfSetTeikiSubAdd teiki_id:{$teiki_id},product_id:{$product_id} message={$message}");
				return $message;
			}
		}
	}

	function sfSetTeikiSubDelete($teiki_id, $product_id) {
		if(empty($teiki_id)){
			return "エラー：定期IDがNULLです";
		}
		if(empty($product_id)){
			return "エラー：商品IDが空です";
		}
		$objQuery =& SC_Query_Ex::getSingletonInstance();
		$note = $objQuery->getOne("select note from dtb_teiki where teiki_id = ?", array($teiki_id));

		if(!empty($note)){
			$note .= PHP_EOL;
		}
		$comment = "セット商品ID:".$product_id."を削除";
		//---------------UPDATE CODE @create002 START----------
		$date = new DateTime();
		$note .= $date->format('Y/m/d H:i ') .":". $comment . SC_Helper_Teiki::sfGetSupportName() . PHP_EOL;
		//---------------UPDATE CODE @create002 E N D----------

		$objQuery->update("dtb_teiki", array("note" => $note, "update_date" => "current_timestamp"), "teiki_id = ?", array($teiki_id));
		$objQuery->delete("dtb_teiki_sub", "teiki_id = ? and product_id = ?", array($teiki_id, $product_id));
		$message = "定期ID:".$teiki_id."のセット商品ID:".$product_id."を削除しました。";

		return $message;
	}

	function sfSetTeikiSubQuantity($teiki_id, $teiki_sub_id, $quantity) {
		if(empty($teiki_id)){
			return "エラー：定期IDがNULLです";
		}
		if(empty($product_id)){
			return "エラー：商品IDが空です";
		}
		$objQuery =& SC_Query_Ex::getSingletonInstance();
		// 定期商品ID
		$product_id = SC_Helper_Teiki_Renew::lfGetTeikiProduct($objQuery, $teiki_id, $teiki_sub_id);
		$note = $objQuery->getOne("select note from dtb_teiki where teiki_id = ?", array($teiki_id));

		if(!empty($note)){
			$note .= PHP_EOL;
		}
		$comment = "商品ID:".$product_id."の数量を".$quantity."個に変更";
		//---------------UPDATE CODE @create002 START----------
		$date = new DateTime();
		$note .= $date->format('Y/m/d H:i ') .":". $comment . SC_Helper_Teiki::sfGetSupportName() . PHP_EOL;
		//---------------UPDATE CODE @create002 E N D----------

		if ($teiki_sub_id > 0) {
			$objQuery->update("dtb_teiki", array("note" => $note, "update_date" => "current_timestamp"), "teiki_id = ?", array($teiki_id));
			$objQuery->update("dtb_teiki_sub", array("quantity" => $quantity, "update_date" => "current_timestamp"), "teiki_id = ? and teiki_sub_id = ?", array($teiki_id, $teiki_sub_id));
		} else {
			$objQuery->update("dtb_teiki", array("quantity" => $quantity, "note" => $note, "update_date" => "current_timestamp"), "teiki_id = ?", array($teiki_id));
		}
		
		$message = "商品ID:".$product_id."の数量を".$quantity."に変更しました。";

		return $message;
	}
	function sfSetTeikiSubLpid($teiki_id, $product_id, $lp_id) {
		if(empty($teiki_id)){
			return "エラー：定期IDがNULLです";
		}
		if(empty($product_id)){
			return "エラー：商品IDが空です";
		}
		$objQuery =& SC_Query_Ex::getSingletonInstance();
		$note = $objQuery->getOne("select note from dtb_teiki where teiki_id = ?", array($teiki_id));

		if(!empty($note)){
			$note .= PHP_EOL;
		}
		$comment = "セット商品ID:".$product_id."のLPIDを".$lp_id."に変更";
		//---------------UPDATE CODE @create002 START----------
		$date = new DateTime();
		$note .= $date->format('Y/m/d H:i ') .":". $comment . SC_Helper_Teiki::sfGetSupportName() . PHP_EOL;
		//---------------UPDATE CODE @create002 E N D----------

		$objQuery->update("dtb_teiki", array("note" => $note, "update_date" => "current_timestamp"), "teiki_id = ?", array($teiki_id));
		$objQuery->update("dtb_teiki_sub", array("lp_id" => $lp_id), "teiki_id = ? and product_id = ?", array($teiki_id, $product_id));
		$message = "定期ID:".$teiki_id."のセット商品ID:".$product_id."のLPIDを変更しました。";

		return $message;
	}

	// 定期商品数量
	function sfSetTeikiQuantity($teiki_id, $teiki_sub_id, $quantity) {
		if(empty($teiki_id)){
			return "エラー：定期IDがNULLです";
		}
		if(empty($quantity)){
			return "エラー：数量が空です";
		}
		$objQuery =& SC_Query_Ex::getSingletonInstance();
		$product_id = SC_Helper_Teiki_Renew::lfGetTeikiProduct($objQuery, $teiki_id, $teiki_sub_id);
		$note = $objQuery->getOne("select note from dtb_teiki where teiki_id = ?", array($teiki_id));
		
		if(!empty($note)){
			$note .= PHP_EOL;
		}
		$comment = "商品ID：".$product_id."の数量を".$quantity."個に変更";
		//---------------UPDATE CODE @create002 START----------
		$date = new DateTime();
		$note .= $date->format('Y/m/d H:i ') .":". $comment . SC_Helper_Teiki::sfGetSupportName() . PHP_EOL;
		//---------------UPDATE CODE @create002 E N D----------
		
		if ($teiki_sub_id > 0) {
			$objQuery->update("dtb_teiki", array("note" => $note, "update_date" => "current_timestamp"), "teiki_id = ?", array($teiki_id));
			$objQuery->update("dtb_teiki_sub", array("quantity" => $quantity, "update_date" => "current_timestamp"), "teiki_id = ? and teiki_sub_id = ?", array($teiki_id, $teiki_sub_id));
		} else {
			$objQuery->update("dtb_teiki", array("note" => $note, "quantity" => $quantity, "update_date" => "current_timestamp"), "teiki_id = ?", array($teiki_id));
		}
		
		$message = "商品ID:".$product_id."のお届け数量を".$quantity."個に設定しました。";
				
		return $message;
	}
	
	function sfSetTeikiInterval($teiki_id, $teiki_sub_id, $interval, $interval_type) {
		GC_Utils_Ex::gfPrintLog("teiki_id:{$teiki_id}, teiki_sub_id：{$teiki_sub_id}, interval:{$interval}, interval_type:{$interval_type}");
		if(empty($teiki_id)){
			return "エラー：定期IDがNULLです";
		}
		if(empty($interval)){
			return "エラー：お届け間隔が空です";
		}
		if(!isset($interval_type)){
			return "エラー：お届け間隔タイプが空です";
		}
		
		$objQuery =& SC_Query_Ex::getSingletonInstance();
		$product_id = SC_Helper_Teiki_Renew::lfGetTeikiProduct($objQuery, $teiki_id, $teiki_sub_id);
		$note = $objQuery->getOne("select note from dtb_teiki where teiki_id = ?", array($teiki_id));
		
		if(!empty($note)){
			$note .= PHP_EOL;
		}
		
		switch ($interval_type){
			case 2: //日
				$str_interval_type = "日ごと";
				break;
			case 1: //週
				$str_interval_type = "週間ごと";
				break;
			case 0: //月
			default:
				$str_interval_type = "ヶ月ごと";
				break;
		}
		 
		$comment = "商品ID：".$product_id."のお届け間隔を".$interval.$str_interval_type."に変更";
		//---------------UPDATE CODE @create002 START----------
		$date = new DateTime();
		$note .= $date->format('Y/m/d H:i ') .":". $comment . SC_Helper_Teiki::sfGetSupportName() . PHP_EOL;
		//---------------UPDATE CODE @create002 E N D----------
		
		// DB定期情報を更新
		if ($teiki_sub_id > 0) {
			$objQuery->update("dtb_teiki", array("note" => $note, "update_date" => "current_timestamp"), "teiki_id = ?", array($teiki_id));
			$objQuery->update("dtb_teiki_sub", array("interval" => $interval, "interval_type" => $interval_type, "update_date" => "current_timestamp"), "teiki_id = ? AND teiki_sub_id = ?", array($teiki_id, $teiki_sub_id));
		} else {
			$objQuery->update("dtb_teiki", array("note" => $note, "interval" => $interval, "interval_type" => $interval_type, "update_date" => "current_timestamp"), "teiki_id = ?", array($teiki_id));
		}
		$message = "商品ID:".$product_id."のお届け間隔を".$interval.$str_interval_type."に設定しました。";
		return $message;
	}

	function sfUpdateTrialTeikiMapping() {
		$objQuery =& SC_Query_Ex::getSingletonInstance();
		return $objQuery->exec("select update_trial_customer_id_mapping()");
	}
	
	function sfDiscountKeshikomi($teiki_id, $teiki_sub_id) {
		$objQuery =& SC_Query_Ex::getSingletonInstance();
		if ($teiki_sub_id > 0) {
			$objQuery->update("dtb_teiki_sub", array("discount" => 0, "update_date" => "current_timestamp"), "teiki_id = ? and teiki_sub_id = ?", array($teiki_id, $teiki_sub_id));
		} else {
			$objQuery->update("dtb_teiki", array("discount" => 0, "update_date" => "current_timestamp"), "teiki_id = ?", array($teiki_id));
		}
	}
	function sfChargeKeshikomi($teiki_id) {
		$objQuery =& SC_Query_Ex::getSingletonInstance();
		$objQuery->update("dtb_teiki", array("charge" => 0, "update_date" => "current_timestamp"), "teiki_id = ?", array($teiki_id));
	}

	// カード登録を1件に制限しているため、CardSeq は 1 が取得される
	function sfGetCustomerCardIdFromDtbOrder($customer_id) {
		$objQuery =& SC_Query_Ex::getSingletonInstance();
		$paygent_card = $objQuery->getOne("select paygent_card from dtb_customer where customer_id = ?", array($customer_id));
		$cardSeq = null;
		if (!empty($paygent_card)) {
			$str_quick_memo = $objQuery->getOne("select quick_memo from dtb_order where quick_memo is not null and customer_id = ? order by order_id desc limit 1", array($customer_id));
			if (!empty($str_quick_memo)) {
				$quick_memo = unserialize($str_quick_memo);
				if(is_numeric($quick_memo['CardSeq'])) $cardSeq = $quick_memo['CardSeq'];
			}
		}
		return $cardSeq;
	}

	//---------------UPDATE CODE @create002 START----------
	/**
	 * 定期情報及び、顧客対応履歴の変更時の対応者名を取得する
	 *
	 * @return string
	 */
	function sfGetSupportName() {
		// マイページからの変更は、「:MYPAGE」で表示する
		if (preg_match("/mypage/", $_SERVER["REQUEST_URI"])) {
			return '：MYPAGE';
		}

		// セッションのログイン名 が存在する場合は、「:ログイン名」で表示する
		if (!empty($_SESSION['login_name'])) {
			return '：' . $_SESSION['login_name'];
		}

		// 上記以外の場合は、「:SYSTEM」で表示する
		return '：SYSTEM';
	}
	//---------------UPDATE CODE @create002 E N D----------

	/**
	 * 再オーソリエラー件数を取得
	 *
	 * @return integer
	 */
	function sfGetCreditAuthErrorCount($teiki_id) {
		$objQuery =& SC_Query_Ex::getSingletonInstance();
		$arrTeiki = $objQuery->getRow("auth_error_count","dtb_teiki","teiki_id = ?", array($teiki_id));
		return $arrTeiki['auth_error_count'];
	}

	/**
	 * 解約日を取得する
	 *
	 * @param  integer $teiki_id 定期ID
	 * @return string  $date     解約日
	 */
	function sfGetStopTeikiDate($teiki_id) {
		$stop_teiki_date = "";
		$objQuery =& SC_Query_Ex::getSingletonInstance();
		$stop_teiki_date = $objQuery->getOne("SELECT cancel_date FROM dtb_teiki WHERE teiki_id = ?", array($teiki_id));
		return $stop_teiki_date;
	}

	/**
	 * 解約理由をセットする
	 *
	 * @param  integer $teiki_id 定期ID
	 *         integer $stop_teiki_reason_id 解約理由ID
	 * @return string  $message  変更履歴用メッセージ
	 */
	function sfSetStopTeikiReason($teiki_id, $teiki_detail_id, $stop_teiki_reason_id) {

		$objQuery =& SC_Query_Ex::getSingletonInstance();
		// 定期商品ID
		$product_id = SC_Helper_Teiki_Renew::lfGetTeikiProduct($objQuery, $teiki_id, $teiki_sub_id);
		$note = $objQuery->getOne("SELECT note FROM dtb_teiki WHERE teiki_id = ?", array($teiki_id));
		if (!empty($note)) {
			$note .= PHP_EOL;
		}

		$stop_teiki_reason = $objQuery->getOne("SELECT name FROM mtb_stop_teiki_reason WHERE id =? ", array($stop_teiki_reason_id));
		if (is_null($stop_teiki_reason)) $stop_teiki_reason = "指定なし";
		$comment = "【商品ID：{$product_id}】　解約理由を".$stop_teiki_reason."に設定";
		$name = (!empty($_SESSION['login_id'])) ? $_SESSION['login_id'] : "SYSTEM";
		$date = new DateTime();
		$note .= $date->format('Y/m/d H:i ') .":". $comment ." ".$name. PHP_EOL;

		$objQuery->update("dtb_teiki", array("note" => $note, "cancel_teiki_reason_id" => $stop_teiki_reason_id, "update_date" => "current_timestamp"), "teiki_id = ? and teiki_detail_id = ?", array($teiki_id,$teiki_detail_id));

		$message = "商品ID:".$product_id."の解約理由を".$stop_teiki_reason."に設定しました。";
		return $message;
	}
	
	/**
	 * 自動解約をセットする
	 *
	 * @param  integer $teiki_id 定期ID
	 *         integer $auto_kaiyaku_flg 自動解約フラグ
	 * @return string  $message  変更履歴用メッセージ
	 */
	function sfSetAutoKaiyakuFlg($teiki_id, $auto_kaiyaku_flg) {

		$objQuery =& SC_Query_Ex::getSingletonInstance();
		$note = $objQuery->getOne("select note from dtb_teiki where teiki_id = ?", array($teiki_id));
		if (!empty($note)) {
			$note .= PHP_EOL;
		}

		if($auto_kaiyaku_flg == 0){
			$auto_kaiyaku = "OFF";
		}else if($auto_kaiyaku_flg == 1){
			$auto_kaiyaku = "ON";
		}else if($auto_kaiyaku_flg == 2){
			$auto_kaiyaku = "自動解約済み";
		}
		$comment = "発送後自動解約フラグを".$auto_kaiyaku."に設定";
		$name = (!empty($_SESSION['login_id'])) ? $_SESSION['login_id'] : "SYSTEM";
		$date = new DateTime();
		$note .= $date->format('Y/m/d H:i ') .":". $comment ." ".$name. PHP_EOL;

		$objQuery->update("dtb_teiki", array("note" => $note, "auto_kaiyaku_flg" => $auto_kaiyaku_flg, "update_date" => "current_timestamp"), "teiki_id = ?", array($teiki_id));
		$objQuery->update("dtb_teiki_sub", array("auto_kaiyaku_flg" => $auto_kaiyaku_flg, "update_date" => "current_timestamp"), "teiki_id = ?", array($teiki_id));
		$message = "定期ID:".$teiki_id."の発送後自動解約を".$auto_kaiyaku."に設定しました。";
		return $message;
	}
	
	//個別解約
	function sfSetSubKaiyakuFlg($teiki_id, $teiki_sub_id, $sub_kaiyaku_flg) {
		$objQuery =& SC_Query_Ex::getSingletonInstance();
		$note = $objQuery->getOne("select note from dtb_teiki where teiki_id = ?", array($teiki_id));
		if (!empty($note)) {
			$note .= PHP_EOL;
		}

		if($sub_kaiyaku_flg == 0){
			$auto_kaiyaku = "OFF";
		}else if($sub_kaiyaku_flg == 1){
			$auto_kaiyaku = "ON";
		}else if($sub_kaiyaku_flg == 2){
			$auto_kaiyaku = "自動解約済み";
		}
		$comment = "発送後自動解約フラグを".$auto_kaiyaku."に設定";
		$name = (!empty($_SESSION['login_id'])) ? $_SESSION['login_id'] : "SYSTEM";
		$date = new DateTime();
		$note .= $date->format('Y/m/d H:i ') .":". $comment ." ".$name. PHP_EOL;

		if ($teiki_sub_id > 0) {
			$objQuery->update("dtb_teiki", array("note" => $note, "update_date" => "current_timestamp"), "teiki_id = ?", array($teiki_id));
			$objQuery->update("dtb_teiki_sub", array("auto_kaiyaku_flg" => $sub_kaiyaku_flg, "update_date" => "current_timestamp"), "teiki_id = ? and teiki_sub_id = ?", array($teiki_id, $teiki_sub_id));
		} else {
			$objQuery->update("dtb_teiki", array("note" => $note, "auto_kaiyaku_flg" => $sub_kaiyaku_flg, "update_date" => "current_timestamp"), "teiki_id = ?", array($teiki_id));
		}
		$product_id = SC_Helper_Teiki_Renew::lfGetTeikiProduct($objQuery, $teiki_id, $teiki_sub_id);
		$message = "商品ID:".$product_id."の発送後自動解約を".$auto_kaiyaku."に設定しました。";
		return $message;
	}

	/**
	 * 【水なし用】入れ物・香り・テクスチャを設定
	 *
	 * @param	integer $teiki_id		定期ID
	 * @param	integer $teiki_sub_id	定期サブID
	 * @param	integer $container_flg	入れ物フラグ
	 * @param	integer $flavor			香りフラグ
	 * @param	integer $texture		テクスチャフラグ
	 * @return	string  $message  変更履歴用メッセージ
	 */
	function sfSetNwSetting($teiki_id, $teiki_sub_id, $container_flg, $flavor, $texture) {
		$objQuery =& SC_Query_Ex::getSingletonInstance();
		$note = $objQuery->getOne("SELECT note FROM dtb_teiki WHERE teiki_id = ?", array($teiki_id));
		if (!empty($note)) {
			$note .= PHP_EOL;
		}

		// 変更コメント設定
		$product_id = SC_Helper_Teiki_Renew::lfGetTeikiProduct($objQuery, $teiki_id, $teiki_sub_id);
		$container_nm = ($container_flg == 0) ? "容器" : "レフィル";
		$flavor_nm = $objQuery->getOne("SELECT name FROM mtb_flavor WHERE id = ?", array($flavor));
		$texture_nm = $objQuery->getOne("SELECT name FROM mtb_texture WHERE id = ?", array($texture));

		$name = (!empty($_SESSION['login_id'])) ? $_SESSION['login_id'] : "SYSTEM";
		$comment = date("Y/m/d H:i").": 商品ID：".$product_id."の入れ物を「".$container_nm."」に設定しました。 ".$name. PHP_EOL;
		$comment .= date("Y/m/d H:i").": 商品ID：".$product_id."の香りを「".$flavor_nm."」に設定しました。 ".$name. PHP_EOL;
		$comment .= date("Y/m/d H:i").": 商品ID：".$product_id."のテクスチャを「".$texture_nm."」に設定しました。 ".$name. PHP_EOL;
		$note .= $comment;

		// データ更新
		if ($teiki_sub_id > 0) {
			// サブデータ更新
			$objQuery->update("dtb_teiki", array("note" => $note, "update_date" => "current_timestamp"), "teiki_id = ?", array($teiki_id));
			$objQuery->update("dtb_teiki_sub", array("container_flg" => $container_flg, "flavor" => $flavor, "texture" => $texture, "update_date" => "current_timestamp"), "teiki_id = ? and teiki_sub_id = ?", array($teiki_id, $teiki_sub_id));
		} else {
			// メインデータ更新
			$objQuery->update("dtb_teiki", array("note" => $note, "container_flg" => $container_flg, "flavor" => $flavor, "texture" => $texture, "update_date" => "current_timestamp"), "teiki_id = ?", array($teiki_id));
		}

		$message = "商品ID:".$product_id."の水なし設定を「".$container_nm."/".$flavor_nm."/".$texture_nm."」に設定しました。";
		return $message;
	}

	/**
	 * 商品オプション設定
	 *
	 * @param	integer $teiki_id		定期ID
	 * @param	integer $teiki_sub_id	定期サブID
	 * @param	integer $product_id		商品ID
	 * @param	string  $shikibetsu_id	商品識別子（商品コード先頭3桁）
	 * @param	array   $arrOptions		商品オプション設定配列
	 * @return	string  $message  変更履歴用メッセージ
	 */
	function sfTeikiOptionSetting($teiki_id, $teiki_sub_id, $product_id, $shikibetsu_id, $arrOptions) {
		$objQuery =& SC_Query_Ex::getSingletonInstance();
		$note = $objQuery->getOne("SELECT note FROM dtb_teiki WHERE teiki_id = ?", array($teiki_id));
		if (!empty($note)) {
			$note .= PHP_EOL;
		}

		// 準備
		$now_date = date("Y/m/d H:i");
		$comment = "";
		$message = "";
		$name = (!empty($_SESSION['login_id'])) ? SC_Helper_Teiki::sfGetSupportName() : "SYSTEM";
		$sql = " SELECT dpo.option_nm, dpoc.option_child_id, dpoc.option_child_nm"
			 . " FROM dtb_products_option dpo"
			 . " INNER JOIN dtb_products_option_child dpoc ON dpo.option_id = dpoc.option_id"
			 . " WHERE dpo.shikibetsu_id = ? AND dpo.column_nm = ? AND dpoc.option_child_id = ?"
		;

		// コメント情報を取得
		foreach($arrOptions as $key => $option) {
			// 更新情報を取得
			$arrData = $objQuery->getAll($sql, array($shikibetsu_id, $key, $option));
			// コメント設定
			$comment .= $now_date.": 商品ID：".$product_id."の".$arrData[0]["option_nm"]."を「".$arrData[0]["option_child_nm"]."」に設定しました。 ".$name. PHP_EOL;
			// 返却メッセージ
			if (!empty($message)) {
				$message .= "/";
			}
			$message .= $arrData[0]["option_child_nm"];
		}
		// 元に連結
		$note .= $comment;
		$arrOptions["update_date"] = "current_timestamp";

		// データ更新
		if ($teiki_sub_id > 0) {
			// サブデータ更新
			$objQuery->update("dtb_teiki", array("note" => $note, "update_date" => "current_timestamp"), "teiki_id = ?", array($teiki_id));
			$objQuery->update("dtb_teiki_sub", $arrOptions, "teiki_id = ? and teiki_sub_id = ?", array($teiki_id, $teiki_sub_id));
		} else {
			// メインデータ更新
			$arrOptions["note"] = $note;
			$objQuery->update("dtb_teiki", $arrOptions, "teiki_id = ?", array($teiki_id));
		}

		$message = "商品ID：".$product_id."の設定を「".$message."」に設定しました。";
		return $message;
	}

	/*
	* 定期商品IDを取得
	*
	* @param	object  $objQuery		DB接続インスタンス
	*			integer $teiki_id		定期ID
	*			integer $teiki_sub_id	定期サブID
	* @return	integer $product_id		定期商品ID
	*/
	function lfGetTeikiProduct($objQuery, $teiki_id, $teiki_sub_id) {
		if ($teiki_sub_id > 0) {
			return $objQuery->getOne("SELECT product_id FROM dtb_teiki_sub WHERE teiki_id = ? AND teiki_sub_id = ?", array($teiki_id, $teiki_sub_id));
		} else {
			return $objQuery->getOne("SELECT product_id FROM dtb_teiki WHERE teiki_id = ?", array($teiki_id));
		}
	}
	
	/*
	* 定期商品毎の情報を取得
	*
	* @param	string  $tk_where			条件①
	*			string  $ts_where			条件②
	* @return	array   $arrTeikiProduct	定期商品情報
	*/
	function lfGetTeikiProductInfo($tk_where) {
		$objQuery =& SC_Query_Ex::getSingletonInstance();
		
		$tk_sql = "";
		
		if (!empty($tk_where)) {
			// 定期メインテーブル
			$tk_sql = " SELECT"
					. "	 tk.teiki_id"
					. "	,0 AS teiki_sub_id"
					. "	,tk.product_id"
					. "	,tk.quantity"
					. "	,tk.customer_id"
					. "	,tk.interval"
					. "	,tk.interval_type"
					. "	,tk.next_shipping_date"
					. "	,tk.deliv_id"
					. "	,tk.status"
					. "	,tk.cancel_teiki_reason_id"
					. "	,tk.del_flg"
					. "	,dc.payment_id"
					. "	,tk.cancel_date"
					. "	,0 AS onetime_flg"
					. "	,pc.category_id"
					. "	,ca.category_name"
					. "	,dc.payment_method"
					. "	,tk.deliv_time_id"
					. "	,0 AS onetime_flg"
					. "	,tk.is_deliv_manual"
					. "	,tk.discount"
					. " FROM dtb_teiki tk"
					. " INNER JOIN dtb_product_categories pc"
					. " ON tk.product_id = pc.product_id"
					. " INNER JOIN dtb_category ca"
					. " ON pc.category_id = ca.category_id"
					. " INNER JOIN dtb_customer dc"
					. " ON tk.customer_id = dc.customer_id"
					. " WHERE ".$tk_where
			;
		}
		
		// 並び順
		$order = "status, next_shipping_date, deliv_id, is_deliv_manual, teiki_id, teiki_sub_id";
		
		// SQL連結
		$sql = $tk_sql." ORDER BY ".$order;

		return $objQuery->getAll($sql);
	}
	
	// 顧客・商品単位で定期情報を取得
	function sfGetTeikiByCustomerIdToProduct($customer_id, $product_id) {
		$objQuery =& SC_Query_Ex::getSingletonInstance();
		
		// メイン定期を確認
		$arrTeiki = $objQuery->getRow("*", "dtb_teiki", "customer_id = ? AND product_id = ?", array($customer_id, $product_id));
		if (empty($arrTeiki)) {
			// サブ定期を確認
			$arrTeiki = $objQuery->getRow(	"ts.*,tk.customer_id,dc.payment_id,dc.payment_method",
											"dtb_teiki_sub ts INNER JOIN dtb_teiki tk ON ts.teiki_id = tk.teiki_id INNER JOIN dtb_customer dc ON tk.customer_id = dc.customer_id",
											"tk.customer_id = ? AND ts.product_id = ?",
											array($customer_id, $product_id))
			;
		}
		return $arrTeiki;
	}

	// 顧客IDから定期IDを取得
	function sfGetTeikiIdByCustomerId($customer_id) {
		$objQuery = SC_Query_Ex::getSingletonInstance();
		$teiki_id = $objQuery->getOne(" SELECT teiki_id FROM dtb_teiki WHERE customer_id = ? ORDER BY update_date DESC LIMIT 1", array($customer_id));
		return $teiki_id;
	}

	// 定期サブデータ登録
	function sfRegistTeikiSubData($order_id, $teiki_id) {
		$objQuery = SC_Query_Ex::getSingletonInstance();
		$objProduct = new SC_Product_Ex();

		// 定期データ
		$where_tk = "tk.teiki_id = {$teiki_id}";
		$where_ts = "ts.teiki_id = {$teiki_id}";
		$arrTeikiAll = $this->lfGetTeikiProductInfo($where_tk, $where_ts);
		$arrTeiki = $arrTeikiAll[0];

		// 受注データ
		$arrOrder = $objQuery->select('*', 'dtb_order', 'order_id = ?', array($order_id));

		// 受注明細データ
		$objQuery->setOrder("order_detail_id");
		$arrOrderDetail = $objQuery->select('*', 'dtb_order_detail', 'order_id = ?', array($order_id));
		$objQuery->setOrder("");
		// お届け日データ
		$shipping_date = $objQuery->getOne(" SELECT shipping_date FROM dtb_shipping WHERE order_id = ?", array($order_id));

		foreach($arrOrderDetail as $detail) {
			$mode = 0;
			$is_pouch = false;

			// 注文商品カテゴリ確認
			$category_id = $objProduct->getProductCategories($detail["product_id"]);

			// 次回商品確認
			$product_id = $objQuery->getOne("SELECT next_product_id FROM dtb_products WHERE product_id = ?", array($detail["product_id"]));
			if (empty($product_id)) {
				// 次回商品設定がない場合はそのまま
				$product_id = $detail["product_id"];
			} else {
				$is_pouch = true;
			}

			// サブIDを取得（既に同じ商品IDに設定がないか確認
			$arrData = $this->sfGetTeikiByCustomerIdToProduct($arrOrder["customer_id"], $product_id);
			if (empty($arrData)) {
				// 取得できない場合は新規
				$teiki_sub_id = $objQuery->getOne("SELECT COALESCE(MAX(teiki_sub_id),0)+1 FROM dtb_teiki_sub WHERE teiki_id = ?", array($teiki_id));
				$mode = 0;
			} else {
				// 取得できた場合は更新
				if (isset($arrData["teiki_sub_id"])) {
					// 定期サブ
					$teiki_sub_id = $arrData["teiki_sub_id"];
				} else {
					// 定期
					$teiki_sub_id = 0;
				}
				$mode = 1;
			}

			// 次回お届け日を設定
			if ($category_id == 21) {
				// UVスプレー
				$interval = 1;
				$next_interval = 1;
				$interval_type = 0;
				$quantity = 1;
			} else {
				// その他
				if ($is_pouch) {
					// パウチ or ハーフ
					if ($category_id == 17 || $category_id == 18 || $category_id == 25) {
						// ハーフ
						if ($category_id == 25) {
							// マスク
							$interval = 20;
							$next_interval = 70;
						} else {
							// その他
							$interval = 14;
							$next_interval = 50;
						}
						$interval_type = 2;
						$quantity = 2;
					} else {
						// パウチ
						if ($category_id == 24) {
							// マスク
							$interval = 15;
							$next_interval = 35;
						} else {
							// その他
							$interval = 8;
							$next_interval = 25;
						}
						$interval_type = 2;
						$quantity = 1;
					}
				} else {
					// 本品
					$interval = 25;
					$next_interval = 50;
					$interval_type = 2;
					$quantity = 2;
				}
			}

			// 次回お届け日
			switch($interval_type) {
				case 2:
					// 日
					$next_shipping_date = strtotime("+".$interval." day", strtotime($shipping_date));
					break;

				case 1:
					// 週
					$next_shipping_date = strtotime("+".$interval." week", strtotime($shipping_date));
					break;

				case 0:
				default:
					// 月
					$next_shipping_date = strtotime("+".$interval." month", strtotime($shipping_date));
					break;
			}
			$next_shipping_date = date("Y-m-d", $next_shipping_date);

			// 登録/更新データ
			$arrValues = array();
			$arrValues["product_id"] = $product_id;
			$arrValues["quantity"] = $quantity;
			$arrValues["interval"] = $next_interval;
			$arrValues["interval_type"] = $interval_type;
			$arrValues["next_shipping_date"] = $next_shipping_date;
			$arrValues["del_flg"] = 0;
			$arrValues["discount"] = 0;
			$arrValues["status"] = 1;
			$arrValues["container_flg"] = $detail["container_flg"];
			$arrValues["texture"] = $detail["texture"];
			$arrValues["flavor"] = $detail["flavor"];
			$arrValues["deliv_id"] = $arrOrder["deliv_id"];
			$arrValues["auto_kaiyaku_flg"] = NULL;
			$arrValues["deliv_time_id"] = NULL;
			$arrValues["update_date"] = "CURRENT_TIMESTAMP";

			if ($mode == 0) {
				// 新規（サブしかないと思う）
				$arrValues["teiki_id"] = $teiki_id;
				$arrValues["create_date"] = "CURRENT_TIMESTAMP";

				if ($teiki_sub_id > 0) {
					$arrValues["teiki_sub_id"] = $teiki_sub_id;
					$objQuery->insert("dtb_teiki_sub", $arrValues);
				}
			} else {
				// 更新
				if ($teiki_sub_id > 0) {
					// 定期サブ
					$objQuery->update("dtb_teiki_sub", $arrValues, "teiki_id = ? AND teiki_sub_id = ?", array($teiki_id, $teiki_sub_id));
				} else {
					// 定期メイン
					$objQuery->update("dtb_teiki", $arrValues, "teiki_id = ?", array($teiki_id));
				}
			}
		}

		// マッピングデータ登録
		$objQuery->insert("dtb_teiki_order_mapping", array("order_id"=>$order_id, "teiki_id"=>$teiki_id, "create_date"=>"CURRENT_TIMESTAMP"));

		return "定期コース管理テーブルに正常に登録されました。";
	}
}
