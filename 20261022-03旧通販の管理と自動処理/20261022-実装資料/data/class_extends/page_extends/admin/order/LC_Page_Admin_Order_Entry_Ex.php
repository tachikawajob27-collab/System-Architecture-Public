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

require_once MODULE_REALDIR . 'mdl_paygent/include.php';
require_once(dirname(__FILE__) . '/../../../../module/amazon-pay-api-sdk-php-master/vendor/autoload.php');
// require_once MODULE_REALDIR . 'mdl_nppro/define.php';
// require_once MDL_NPPRO_CLASSEX_DIR_PATH . 'helper_extends/SC_Helper_Mdl_NPPRO_Ex.php';
require_once MODULE_REALDIR . 'mdl_vamos/define.php';
require_once MDL_VAMOS_CLASSEX_DIR_PATH . 'helper_extends/SC_Helper_Mdl_VAMOS_Ex.php';


/**
 * 受注登録 のページクラス
 *
 * @package Page
 * @author EC-CUBE CO.,LTD.
 * @version $Id$
 */
class LC_Page_Admin_Order_Entry_Ex extends LC_Page_Admin
{
	/**
	 * Page を初期化する.
	 *
	 * @return void
	 */
	function init()
	{
		parent::init();
		$this->tpl_mainpage = 'order/entry.tpl';
		$this->tpl_mainno = 'order';
		$this->tpl_maintitle = '受注管理';
		$this->tpl_subtitle = '受注登録';

		$this->arrInfo = SC_Helper_DB_Ex::sfGetBasisData();
		$this->arrBrandInfo = SC_Helper_DB_Ex::sfGetBrandData($_SESSION["brand_id"]);

		$masterData = new SC_DB_MasterData_Ex();
		$this->arrPref = $masterData->getMasterData('mtb_pref');

		// お届け間隔
		$this->arrIntervalType = array( ""=>"--", 0 => "ヶ月ごと", 1 => "週間ごと", 2 => "日ごと" );
		// 定期数量
		for ($i = 1; $i <= 20; $i++) {
			$this->arrQuantity[$i] = $i;
		}
		// 定期お届け間隔
		for($i = 1; $i <= 100; $i++) {
			if ($i == 1) {
				$this->arrInterval[0] = "--";
			}
			$this->arrInterval[$i] = $i;
		}

		$objQuery =& SC_Query_Ex::getSingletonInstance();
		// コース一覧
		$this->arrCourseAll = $this->getCourseList();
		// 配送方法マスタ
		$this->arrDeliv = $objQuery->getAll("SELECT * FROM dtb_deliv WHERE del_flg = 0 ORDER BY deliv_id");
		// 配送時間帯マスタ
		$this->arrDelivTime = $objQuery->getAll("SELECT * FROM dtb_delivtime ORDER BY deliv_id, time_id");
		// 支払い方法
		$this->arrPayment = $objQuery->getAll("SELECT * FROM dtb_payment WHERE del_flg = 0 ORDER BY payment_id");

	}

	/**
	 * Page のプロセス.
	 *
	 * @return void
	 */
	function process()
	{
		$this->action();
		$this->sendResponse();
	}

	/**
	 * Page のアクション.
	 *
	 * @return void
	 */
	public function action()
	{
		$objPurchase = new SC_Helper_Purchase_Ex();
		$objCourse = new SC_Helper_Course_Ex();
		$objTeiki = new SC_Helper_Teiki_Ex();
		$objCustomer = new SC_Helper_Customer_Ex();
		$objFormParam = new SC_FormParam_Ex();
		$objQuery =& SC_Query_Ex::getSingletonInstance();

		// パラメーター情報の初期化
		$this->lfInitParam($objFormParam);
		$objFormParam->setParam($_REQUEST);
		$objFormParam->convParam();
		$this->mode = $this->getMode();

		$this->arrSearchHidden = $objFormParam->getSearchArray();

		// ログインユーザー
		$login_id = $_SESSION['login_id'];

		switch ($this->mode) {
			case "edit":
				// 登録
				$this->arrErr = $this->lfCheckError($objFormParam);
				if (SC_Utils_Ex::isBlank($this->arrErr)) {
					$message = '受注登録しました。';
					// 金額再計算
					$this->lfSetPaymentTotal($objFormParam, $objPurchase);
					// 更新/登録
					$order_id = $this->doRegister($order_id, $objPurchase, $objFormParam, $message, $arrValuesBefore);
					$this->tpl_onload = "window.alert('" . $message . "「" . $order_id . "」" . "');";
					$this->tpl_onload .= " eccube.changeAction('".ADMIN_ORDER_EDIT_URLPATH."');";
					$this->tpl_onload .= " eccube.setModeAndSubmit('pre_edit', 'order_id', '".$order_id."');";
					$this->tpl_onload .= " return false;";
				}
				break;
			case "add_course":
				$course_id = $_POST["add_course_id"];
				$order_count = $_POST["add_order_count"];
				$course_type = $_POST["add_course_type"];
				$option_id = $_POST["add_option_id"];
				$option_detail_id = $_POST["add_option_detail_id"];
				$product_id = $_POST["add_product_id_".$order_count."-".$course_type];
// GC_Utils_Ex::gfPrintLog("add_product_id：".$product_id);
// GC_Utils_Ex::gfPrintLog("add_option_id：".$option_id);
// GC_Utils_Ex::gfPrintLog("add_option_detail_id：".$option_detail_id);

				// コース明細情報取得
				$arrCourseDetail = $objCourse->getDetailProducts($course_id, $product_id);
				foreach($arrCourseDetail as $data) {
					if ($order_count == $data["order_count"]) {
						$this->doRegistProduct($objFormParam, $data);
						break;
					}
				}
				$this->lfSetPaymentTotal($objFormParam, $objPurchase);
				break;
			case "products_delete":
				// 商品削除
				$delete_no = $_POST["delete_no"];
				$this->doDeleteProduct($delete_no, $objFormParam);
				$this->lfSetPaymentTotal($objFormParam, $objPurchase);
				break;
			case "recalculate":
				// 金額再計算
				$this->lfSetPaymentTotal($objFormParam, $objPurchase);
				break;

			// ==================================
			//	その他非同期項目取得処理
			// ==================================
			case "course_select":
				// コース選択戻り
				$course_id = $_POST["add_course_id"];
				// 選択したコース明細情報を取得
				$arrCourse = $objCourse->getDetailProducts($course_id);

				$arrSelect = array();
				$arrCheck = array();
				$i = 0;
				foreach($arrCourse as $data) {
					if (!isset($arrCheck[$data["product_id"]])) {
						$arrSelect[$i]["product_id"] = $data["product_id"];
						$arrSelect[$i]["product_name"] = $data["product_name"];
						$arrSelect[$i]["quantity"] = $data["quantity"];
						$arrSelect[$i]["price"] = $data["price"];
						$arrSelect[$i]["order_count"] = $data["order_count"];
						$arrSelect[$i]["course_type"] = $data["course_type"];

						// 商品オプション明細を取得
						$arrOptionDetail = SC_Product_Ex::getProductOptionDetail($data["product_id"]);
						$arrSelect[$i]["option_select"] = $this->lfSetProductOptionSelect($arrOptionDetail);
						++$i;
					}
					$arrCheck[$data["product_id"]] = true;
				}
				echo SC_Utils_Ex::jsonEncode($arrSelect);
				exit;
				break;

			case "customer_search":
				$customer_id = $_POST["customer_id"];
				$arrSelect = array();

				// 選択した顧客の明細情報を取得
				$arrCustomer = $objCustomer->sfGetCustomerData($customer_id);

				// 送信データ作成
				$arrSelect["name"] = $arrCustomer["name01"];
				$arrSelect["email"] = $arrCustomer["email"];
				$arrSelect["tel"] = $arrCustomer["tel01"];
				$arrSelect["zip"] = $arrCustomer["zip01"];
				$arrSelect["pref"] = $arrCustomer["pref"];
				$arrSelect["addr01"] = $arrCustomer["addr01"];
				$arrSelect["addr02"] = $arrCustomer["addr02"];
				$arrSelect["payment_id"] = $arrCustomer["payment_id"];
				$arrSelect["customer_card_id"] = $arrCustomer["customer_card_id"];
				$arrSelect["amazon_billing_agreement_id"] = $arrCustomer["amazon_billing_agreement_id"];
				if ($arrCustomer["payment_id"] == 5) {
					$arrData = array('customer_id'=>$customer_id);
					$ret = sfGetPaygentCreditStock($arrData);
					$arrSelect["card_number"] = $ret[1]["card_number"];
				}
				echo SC_Utils_Ex::jsonEncode($arrSelect);
				exit;
				break;
		}

		$this->arrFormList = $objFormParam->getFormParamList();
		$this->arrForm = $objFormParam->getHashArray();
		$this->tpl_onload .= $this->getAnchorKey($objFormParam);
	}

	/**
	 * パラメーター情報の初期化を行う.
	 *
	 * @param SC_FormParam $objFormParam SC_FormParam インスタンス
	 * @return void
	 */
	function lfInitParam(&$objFormParam) {
		// 最短お届け日取得
		$objPurchase = new SC_Helper_Purchase_Ex();
		$shipping_date = $objPurchase->lfGetHolidayOfShippingDate(date("Y/m/d H:i:s"));

		// コース・商品情報
		$objFormParam->addParam('コースID', 'course_id', INT_LEN, 'n', array('EXIST_CHECK', 'MAX_LENGTH_CHECK', 'NUM_CHECK'), '0');
		$objFormParam->addParam('コース名', 'course_name');
		$objFormParam->addParam('商品ID', 'product_id', INT_LEN, 'n', array('EXIST_CHECK', 'MAX_LENGTH_CHECK', 'NUM_CHECK'), '0');
		$objFormParam->addParam('単価', 'price', INT_LEN, 'n', array('EXIST_CHECK', 'MAX_LENGTH_CHECK', 'NUM_CHECK'), '0');
		$objFormParam->addParam('数量', 'quantity', INT_LEN, 'n', array('EXIST_CHECK', 'MAX_LENGTH_CHECK', 'NUM_CHECK'), '0');
		$objFormParam->addParam('商品コード', 'product_code');
		$objFormParam->addParam('商品名', 'product_name');
		$objFormParam->addParam('商品名(表示用)', 'product_name_disp');
		$objFormParam->addParam('税率', 'tax_status', INT_LEN, 'n', array('NUM_CHECK'));
		$objFormParam->addParam('お届け間隔', 'interval');
		$objFormParam->addParam('お届け間隔(単位)', 'interval_type');
		$objFormParam->addParam('削除用項番', 'delete_no', INT_LEN, 'n', array('MAX_LENGTH_CHECK', 'NUM_CHECK'));
		$objFormParam->addParam('tax_rate', 'tax_rate');
		$objFormParam->addParam('tax_rule', 'tax_rule');
		$objFormParam->addParam('order_count', 'order_count');
		$objFormParam->addParam('option_id', 'option_id');
		$objFormParam->addParam('option_detail_id', 'option_detail_id');

		// 金額
		$objFormParam->addParam('合計', 'total');
		$objFormParam->addParam('合計', 'payment_total');
		$objFormParam->addParam('小計', 'subtotal');
		$objFormParam->addParam('消費税合計', 'tax');
		$objFormParam->addParam('手数料', 'charge', INT_LEN, 'n', array('EXIST_CHECK', 'MAX_LENGTH_CHECK', 'NUM_CHECK'), '0');
		$objFormParam->addParam('値引き', 'discount', INT_LEN, 'n', array('EXIST_CHECK', 'MAX_LENGTH_CHECK', 'NUM_CHECK'), '0');
		$objFormParam->addParam('送料', 'deliv_fee', INT_LEN, 'n', array('EXIST_CHECK', 'MAX_LENGTH_CHECK', 'NUM_CHECK'), '0');

		// お客様情報
		$objFormParam->addParam('【配送先情報】お名前', 'order_name01', STEXT_LEN, 'KVa', array('EXIST_CHECK', 'SPTAB_CHECK', 'MAX_LENGTH_CHECK', 'NO_SPTAB'));
		$objFormParam->addParam('【配送先情報】メールアドレス', 'order_email', null, 'KVCa', array('NO_SPTAB', 'EMAIL_CHECK', 'EMAIL_CHAR_CHECK'));
		$objFormParam->addParam('【配送先情報】郵便番号', 'order_zip01', (ZIP01_LEN+ZIP02_LEN), 'n', array('NUM_CHECK', 'NUM_COUNT_CHECK'));
		$objFormParam->addParam('【配送先情報】都道府県', 'order_pref', INT_LEN, 'n', array('MAX_LENGTH_CHECK', 'NUM_CHECK'));
		$objFormParam->addParam('【配送先情報】住所1', 'order_addr01', MTEXT_LEN, 'KVa', array('SPTAB_CHECK', 'MAX_LENGTH_CHECK'));
		$objFormParam->addParam('【配送先情報】住所2', 'order_addr02', MTEXT_LEN, 'KVa', array('SPTAB_CHECK', 'MAX_LENGTH_CHECK'));
		$objFormParam->addParam('【配送先情報】電話番号', 'order_tel01', 12, 'n', array('MAX_LENGTH_CHECK', 'NUM_CHECK'));
		$objFormParam->addParam('配送方法', 'deliv_id', INT_LEN, 'n', array('EXIST_CHECK', 'MAX_LENGTH_CHECK', 'NUM_CHECK'));
		$objFormParam->addParam('お支払い方法', 'payment_id', INT_LEN, 'n', array('EXIST_CHECK', 'MAX_LENGTH_CHECK', 'NUM_CHECK'));
		$objFormParam->addParam('お届け時間ID', 'time_id', INT_LEN, 'n', array('MAX_LENGTH_CHECK', 'NUM_CHECK'));
		$objFormParam->addParam('お届け予定日', 'shipping_date', STEXT_LEN, 'KVa', array('SPTAB_CHECK', 'MAX_LENGTH_CHECK'), $shipping_date);

		// その他
		$objFormParam->addParam('アンカーキー', 'anchor_key', STEXT_LEN, 'KVa', array('SPTAB_CHECK', 'MAX_LENGTH_CHECK'));
		$objFormParam->addParam('add_course_id', 'add_course_id');
		$objFormParam->addParam('select_course_id', 'select_course_id');
		$objFormParam->addParam('select_product_id', 'select_product_id');
		$objFormParam->addParam('select_price', 'select_price');
		$objFormParam->addParam('edit_customer_id', 'edit_customer_id');
		$objFormParam->addParam('add_option_id', 'add_option_id');
		$objFormParam->addParam('add_option_detail_id', 'add_option_detail_id');
	}

	/**
	 * 入力内容のチェックを行う.
	 *
	 * @param  SC_FormParam $objFormParam SC_FormParam インスタンス
	 * @return array        エラーメッセージの配列
	 */
	public function lfCheckError(&$objFormParam) {
		$arrValues = $objFormParam->getHashArray();
		$arrErr = $objFormParam->checkError();
		return $arrErr;
	}

	/**
	 * 注文金額計算
	 * 
	 * @param $objFormParam 画面項目インスタンス
	 * @param $arrOrder 注文配列
	 * @param $arrOrderDetail 注文明細配列
	 * @return void
	 */
	function lfSetPaymentTotal(&$objFormParam, $objPurchase) {
		$objQuery =& SC_Query_Ex::getSingletonInstance();

//GC_Utils_Ex::gfPrintLog_r("objFormParam：", $objFormParam);
		$total = 0;
		$subtotal = 0;
		$tax = 0;
		$taxtotal = 0;
		$tax_free_total = 0;
		$deliv_fee = $objFormParam->getValue("deliv_fee");
		$is_delivfee = false;
		$check = false;

		if (!empty($objFormParam->getValue('edit_customer_id'))) {
			// 2023/07/11 顧客アイコン確認（NP切り替え案内）
			$check = $objPurchase->lfCheckChangeNotiesNp($objFormParam->getValue('edit_customer_id'));
		}
		if ($check) {
			$charge = 0;
		} else {
			$charge = $_POST["charge"];
		}
		$discount = $_POST["discount"];
		$deliv_id = $_POST["deliv_id"];

		// 注文明細から合計金額を算出
		$max = count($objFormParam->getValue('quantity'));
		for($i = 0; $i < $max; $i++) {
			$price = $objFormParam->getValue('price')[$i];
			$quantity = $objFormParam->getValue('quantity')[$i];
			$tax_rate = $objFormParam->getValue('tax_rate')[$i];
			$tax_rule = $objFormParam->getValue('tax_rule')[$i];

			// 税別単価合計
			$tax_free_total += $price * $quantity;
			$tax = SC_Utils_Ex::sfTax($price, $tax_rate, $tax_rule) * $quantity;
			// 消費税合計
			$taxtotal += $tax;
			// 税込単価合計
			$subtotal += SC_Utils_Ex::sfCalcIncTax($price, $tax_rate, $tax_rule) * $quantity;
		}
//GC_Utils_Ex::gfPrintLog("tax_free_total：". $tax_free_total);

		// 総合計金額
		if ($subtotal > 0) {
			// 金額あり
			$total = $subtotal + $charge + $deliv_fee - $discount;
			if ($total < 0) {
				// マイナスだったら「0」にする
				$total = 0;
			}
		} else {
			// 金額なし
			$subtotal = 0;
			if ($deliv_fee == 0) {
				// 送料がない場合は手数料も0にする
				$charge = 0;
			}
			$total = $subtotal + $charge + $deliv_fee - $discount;
			if ($total < 0) {
				// マイナスだったら「0」にする
				$total = 0;
			}
		}
		// 項目設定
		$objFormParam->setValue("deliv_fee", $deliv_fee);
		$objFormParam->setValue("charge", $charge);
		$objFormParam->setValue("subtotal", $subtotal);
		$objFormParam->setValue("tax", $taxtotal);
		$objFormParam->setValue("discount", $discount);
		$objFormParam->setValue("total", $total);
		$objFormParam->setValue("payment_total", $total);

	}

	/**
	 * 受注商品を削除する.
	 *
	 * @param integer $delete_no 削除する受注商品の項番
	 * @param SC_FormParam $objFormParam SC_FormParam インスタンス
	 * @return void
	 */
	function doDeleteProduct($delete_no, &$objFormParam) {
		$arrDeleteKeys = array(
			'product_id', 'product_name', 'product_code', 'course_id',
			'course_name', 'price', 'quantity', 'tax_status', 'tax_rule', 'tax_rate', 'order_count'
		);
		foreach ($arrDeleteKeys as $key) {
			$arrNewValues = array();
			$arrValues = $objFormParam->getValue($key);
			foreach ($arrValues as $index => $val) {
				if ($index != $delete_no) {
					$arrNewValues[] = $val;
				}
			}
			$objFormParam->setValue($key, $arrNewValues);
		}
	}

	/**
	 * 受注商品を追加する.
	 *
	 * @param SC_FormParam $objFormParam SC_FormParam インスタンス
	 * @return void
	 */
	function doRegistProduct(&$objFormParam, $arrCourse) {
		$arrDeleteKeys = array(
			'product_id', 'product_name', 'product_code', 'course_id',
			'course_name', 'price', 'tax_status', 'tax_rate', 'tax_rule', 'quantity', 'order_count',
			'option_id', 'option_detail_id', 'product_name_disp'
		);

		// 変更項目
		$product_id = $arrCourse["product_id"];
		$course_id = $arrCourse["course_id"];
		$price = $arrCourse["price"];
		$quantity = $arrCourse["quantity"];
		$course_name = $arrCourse["course_name"];
		$order_count = $arrCourse["order_count"];
		$option_id = $objFormParam->getValue('add_option_id');
		$option_detail_id = $objFormParam->getValue('add_option_detail_id');
// GC_Utils_Ex::gfPrintLog("add_option_id：".$option_id);
// GC_Utils_Ex::gfPrintLog("add_option_detail_id：".$option_detail_id);

		// 追加商品が既にあるかチェック
		$is_duplicate = false;
		$idx = 0;
		if (!empty($objFormParam->getValue('quantity'))) {
			$max = count($objFormParam->getValue('quantity'));
			for($i = 0; $i <= $max; $i++) {
				$chk_product_id = $objFormParam->getValue('product_id')[$i];
				$chk_course_id = $objFormParam->getValue('course_id')[$i];
				$chk_price= $objFormParam->getValue('price')[$i];
				$chk_option_id = $objFormParam->getValue('option_id')[$i];
				$chk_option_detail_id = $objFormParam->getValue('option_detail_id')[$i];
				if ($product_id == $chk_product_id && $course_id == $chk_course_id && $price == $chk_price && $option_id == $chk_option_id && $option_detail_id == $chk_option_detail_id) {
					// 同一コース/商品/金額/オプション情報で存在する場合
					$is_duplicate = true;
					$idx = $i;
				}
			}
		}

		if ($is_duplicate) {
			// 存在あり（個数を加算させる）
			$arrNewValues = array();
			foreach ($objFormParam->getValue('quantity') as $index => $val) {
				if ($index == $idx) {
					$arrNewValues[] = $val+1;
				} else {
					$arrNewValues[] = $val;
				}
			}
			$objFormParam->setValue("quantity", $arrNewValues);
		} else {
			// 存在なし
			// マスタ情報取得
			$objProduct = new SC_Product_Ex();
			$arrProduct = $objProduct->getProductRow($product_id);
			$arrOption = $objProduct->getProductOptionRow($option_id, $option_detail_id);

			foreach ($arrDeleteKeys as $key) {
				$arrNewValues = array();
				$arrValues = $objFormParam->getValue($key);
				foreach ($arrValues as $index => $val) {
					$arrNewValues[] = $val;
				}
				switch($key) {
					case "product_id":
						$arrNewValues[] = $product_id;
						break;
					case "course_id":
						$arrNewValues[] = $course_id;
						break;
					case "price":
						$arrNewValues[] = $price;
						break;
					case "product_name":
						$arrNewValues[] = $arrProduct["name"];
						break;
					case "product_name_disp":
						if (empty($arrOption)) {
							$arrNewValues[] = $arrProduct["name"];
						} else {
							$arrNewValues[] = $arrProduct["name"] . "(" . $arrOption["option_detail_name"]. ")";
						}
						break;
					case "course_name":
						$arrNewValues[] = $course_name;
						break;
					case "tax_rate":
						if ($arrProduct["tax_status"] == 1) {
							$arrNewValues[] = 8;
						} else {
							$arrNewValues[] = 10;
						}
						break;
					case "product_code":
					case "tax_status":
						$arrNewValues[] = $arrProduct[$key];
						break;
					case "quantity":
						$arrNewValues[] = $quantity;
						break;
					case "tax_rule":
						$arrNewValues[] = $this->arrInfo["tax_rule"];
						break;
					case "order_count":
						$arrNewValues[] = $order_count;
						break;
					case "option_id":
						$arrNewValues[] = $option_id;
						break;
					case "option_detail_id":
						$arrNewValues[] = $option_detail_id;
						break;
				}
				$objFormParam->setValue($key, $arrNewValues);
			}
		}
	}

	/**
	 * コース選択リストを取得
	 *
	 * @return array 
	 */
	function getCourseList() {
		$objQuery =& SC_Query_Ex::getSingletonInstance();

		$arrData = array();
		$arrCourse = array();
		$arrCourseAll = array();

		$sql = " SELECT id, name, is_cross"
			 . " FROM dtb_course dc"
			 . " WHERE dc.del_flg = 0 AND dc.brand_id = ?"
			 . " ORDER BY id desc"
		;
		$arrData = $objQuery->getAll($sql, array($_SESSION["brand_id"]));
		foreach($arrData as $row) {
			$arrCourseAll[$row["id"]] = $row["id"]."：".$row["name"];
		}
		return $arrCourseAll;
	}

	/**
	 * DB更新処理
	 *
	 * @param integer $order_id 受注ID
	 * @param SC_Helper_Purchase $objPurchase SC_Helper_Purchase インスタンス
	 * @param SC_FormParam $objFormParam SC_FormParam インスタンス
	 * @param string $message 通知メッセージ
	 * @return integer $order_id 受注ID
	 * @throws Exception
	 *
	 * エラー発生時は負数を返す。
	 */
	function doRegister($order_id, &$objPurchase, &$objFormParam, &$message, &$arrValuesBefore) {
		$objQuery =& SC_Query_Ex::getSingletonInstance();
		$arrValues = $objFormParam->getDbArray();
		$where = 'order_id = ?';

		$payment_method = $objQuery->getOne("SELECT payment_method FROM dtb_payment WHERE payment_id = ?", array($arrValues['payment_id']));

		// --------------------
		// 顧客管理テーブルの登録
		// --------------------
		$arrCustomer['name01'] = $arrValues['order_name01'];
		$arrCustomer['kana01'] = $arrValues['order_name01'];
		$arrCustomer['email'] = $arrValues['order_email'];
		$arrCustomer['tel01'] = $arrValues['order_tel01'];
		$arrCustomer['zip01'] = $arrValues['order_zip01'];
		$arrCustomer['pref'] = $arrValues['order_pref'];
		$arrCustomer['addr01'] = $arrValues['order_addr01'];
		$arrCustomer['addr02'] = $arrValues['order_addr02'];
		$arrCustomer['payment_id'] = $arrValues['payment_id'];
		$arrCustomer["payment_method"] = $payment_method;
		if (empty($arrValues["edit_customer_id"])) {
			// 新規
			$arrCustomer['order_payment_id'] = $arrValues['payment_id'];
			$arrCustomer["brand_id"] = $_SESSION["brand_id"];
			$arrCustomer["secret_key"] = SC_Helper_Customer_Ex::sfGetUniqSecretKey();
			$customer_id = SC_Helper_Customer_Ex::sfEditCustomerData($arrCustomer);
		} else {
			// 既存
			$customer_id = SC_Helper_Customer_Ex::sfEditCustomerData($arrCustomer, $arrValues["edit_customer_id"]);
		}

		$objQuery->begin();

		// ---------------------
		// 受注テーブルの登録
		// ---------------------
		$arrOrderDb = array();
		$arrOrderValues = array();
		$arrOrderValues["brand_id"] = $_SESSION["brand_id"];
		$arrOrderValues["customer_id"] = $customer_id;
		$arrOrderValues["order_name01"] = $arrValues['order_name01'];
		$arrOrderValues["order_kana01"] = $arrValues['order_name01'];
		$arrOrderValues["order_email"] = $arrValues['order_email'];
		$arrOrderValues["order_tel01"] = $arrValues['order_tel01'];
		$arrOrderValues["order_zip01"] = $arrValues['order_zip01'];
		$arrOrderValues["order_pref"] = $arrValues['order_pref'];
		$arrOrderValues["order_addr01"] = $arrValues['order_addr01'];
		$arrOrderValues["order_addr02"] = $arrValues['order_addr02'];
		$arrOrderValues["payment_id"] = $arrValues['payment_id'];
		$arrOrderValues["payment_method"] = $payment_method;
		$arrOrderValues["deliv_id"] = $arrValues['deliv_id'];
		$arrOrderValues["charge"] = $arrValues['charge'];
		$arrOrderValues["deliv_fee"] = $arrValues['deliv_fee'];
		$arrOrderValues["discount"] = $arrValues['discount'];
		$arrOrderValues["subtotal"] = $arrValues['subtotal'];
		$arrOrderValues["tax"] = $arrValues['tax'];
		$arrOrderValues["total"] = $arrValues['total'];
		$arrOrderValues["payment_total"] = $arrValues['payment_total'];
		// if ($arrValues['payment_id'] == 6 || $arrValues['payment_id'] == MDL_VAMOS_PAYMENT_ID) {
		if ($arrValues['payment_id'] == MDL_VAMOS_PAYMENT_ID) {
			// コンビニ後払い
			$arrOrderValues["status"] = 9000;
		} else {
			// その他
			$arrOrderValues["status"] = 1;
		}
		// 受注登録
		$order_id = $objPurchase->registerOrder(null, $arrOrderValues);

		// ---------------------
		// 受注明細テーブルの登録
		// ---------------------
		$arrDetail = $objFormParam->getSwapArray(array(
				'order_detail_id',
				'product_id',
				'product_code',
				'product_name',
				'price',
				'quantity',
				'tax_rate',
				'tax_rule',
				'course_id',
				'option_id',
				'option_detail_id'
		));
		$max = count($arrDetail);
		for ($i = 0; $i < $max; $i++) {
			$arrValues2 = $objQuery->extractOnlyColsOf("dtb_order_detail", $arrDetail[$i]);
			$order_detail_id = $objQuery->nextVal('dtb_order_detail_order_detail_id');
			$arrValues2['order_detail_id'] = $order_detail_id;
			$arrValues2['order_id'] = $order_id;
			$objQuery->insert("dtb_order_detail", $arrValues2);

			if (!empty($arrDetail[$i]["option_id"])) {
				// 商品オプション追加
				$arrValues3 = array();
				$arrValues3["order_id"] = $order_id;
				$arrValues3["order_detail_id"] = $order_detail_id;
				$arrValues3["option_id"] = $arrDetail[$i]["option_id"];
				$arrValues3["option_detail_id"] = $arrDetail[$i]["option_detail_id"];
				$arrValues3["create_date"] = "CURRENT_TIMESTAMP";
				$objQuery->insert("dtb_order_detail_option", $arrValues3);
			}
		}

		// ---------------------
		// 配送テーブルの登録
		// ---------------------
		$arrShippingValues = array();
		$arrShippingValues[0]["shipping_name01"] = $arrValues["order_name01"];
		$arrShippingValues[0]["shipping_kana01"] = $arrValues["order_name01"];
		$arrShippingValues[0]["shipping_tel01"] = $arrValues["order_tel01"];
		$arrShippingValues[0]["shipping_pref"] = $arrValues["order_pref"];
		$arrShippingValues[0]["shipping_zip01"] = $arrValues["order_zip01"];
		$arrShippingValues[0]["shipping_addr01"] = $arrValues["order_addr01"];
		$arrShippingValues[0]["shipping_addr02"] = $arrValues["order_addr02"];
		$arrShippingValues[0]["time_id"] = $arrValues["time_id"];
		$arrShippingValues[0]["deliv_id"] = $arrValues["deliv_id"];
		$arrShippingValues[0]["del_flg"] = 0;
		if (!empty($arrValues["shipping_date"])) {
			$arrShippingValues[0]["shipping_date"] = $arrValues["shipping_date"];
		} else {
			$arrShippingValues[0]["shipping_date"] = $objPurchase->lfGetHolidayOfShippingDate(date("Y/m/d H:i:s"));
		}
		$objPurchase->registerShipping($order_id, $arrShippingValues, false);

		// ---------------------
		// 定期テーブルの登録
		// ---------------------

		// 登録された注文データを取得
		$arrOrder = $objQuery->getRow("*", "dtb_order", "order_id = ?", array($order_id));
		$arrOrderDetail = $objQuery->getAll("SELECT * FROM dtb_order_detail WHERE order_id = ? ORDER BY order_detail_id", array($order_id));
		$arrShipping = $objQuery->getRow("*", "dtb_shipping", "order_id = ?", array($order_id));
		$shipping_date = $arrShippingValues[0]["shipping_date"];

		// 定期ID取得
		if (empty($arrValues["edit_customer_id"])) {
			// 新規
			$teiki_id = $objQuery->nextVal('dtb_teiki_teiki_id_seq');
		} else {
			// 既存
			$objTeiki = new SC_Helper_Teiki_Ex();
			$teiki_id = $objTeiki->getTeikiIdFromCustomer($customer_id);
			if (empty($teiki_id)) {
				$teiki_id = $objQuery->nextVal('dtb_teiki_teiki_id_seq');
			}
		}

		$is_teiki_register = false;
		// 明細単位で定期データ作成
		foreach($arrOrderDetail as $data) {
			$arrTeikiValues = array();

			// 今回追加のコース情報を取得
			$arrCourse = $objQuery->getRow("*", "dtb_course", "id = ?", array($data["course_id"]));
			// 今回追加のコース明細情報
			$arrCourseDetail = $objQuery->getAll("SELECT * FROM dtb_course_detail WHERE course_id = ? AND product_id = ? ORDER BY order_count", array($data["course_id"], $data["product_id"]));

			// 定期 or 都度判断
			if ($arrCourse["course_type"] == 4 || $arrCourseDetail[0]["interval"] == 0) {
				// 都度なので定期情報を登録しない
				continue;
			}

			if (!empty($arrCourseDetail[1])) {
				$next_interval = $arrCourseDetail[1]["interval"];
				$next_interval_type = $arrCourseDetail[1]["interval_type"];
				$next_product_id = $arrCourseDetail[1]["product_id"];
				$next_quantity = $arrCourseDetail[1]["quantity"];
			} else {
				$order_count = $arrCourseDetail[0]["order_count"] + 1;
				$arrCourseDetailNext = $objQuery->getAll("SELECT * FROM dtb_course_detail WHERE course_id = ? AND order_count <= ? ORDER BY order_count DESC", array($data["course_id"], $order_count));
				$next_interval = $arrCourseDetailNext[0]["interval"];
				$next_interval_type = $arrCourseDetailNext[0]["interval_type"];
				$next_product_id = $arrCourseDetailNext[0]["product_id"];
				$next_quantity = $arrCourseDetailNext[0]["quantity"];
			}

			// 定期情報を登録
			$arrTeikiValues["teiki_id"] = $teiki_id;
			$arrTeikiValues["customer_id"] = $customer_id;
			$arrTeikiValues["product_id"] = $next_product_id;
			$arrTeikiValues["quantity"] = $next_quantity;
			$arrTeikiValues["discount"] = 0;
			$arrTeikiValues["deliv_id"] = $arrShipping["deliv_id"];
			$arrTeikiValues["deliv_time_id"] = $arrShipping["time_id"];
			$arrTeikiValues["interval"] = $next_interval;
			$arrTeikiValues["interval_type"] = $next_interval_type;
			$arrTeikiValues["first_order_id"] = $order_id;
			$arrTeikiValues["first_order_date"] = $arrOrder["create_date"];
			$arrTeikiValues["course_id"] = $data["course_id"];
			$arrTeikiValues["update_date"] = "CURRENT_TIMESTAMP";
			$arrTeikiValues["del_flg"] = 0;

			// $is_teiki = $objQuery->getOne("SELECT is_teiki FROM dtb_products WHERE product_id = ?",array($arrCourseDetail[0]["product_id"]));
			// if ($is_teiki) {
			// 	$arrTeikiValues["status"] = 1;
			// } else {
			// 	$arrTeikiValues["status"] = 3;
			// }

			// 次回お届け日計算
			$interval = $arrCourseDetail[0]["interval"];
			$interval_type = $arrCourseDetail[0]["interval_type"];
			switch($interval_type) {
				case 2:
					$next_shipping_date = strtotime("+" . $interval . " day", strtotime($shipping_date));
					break;
				case 1:
					$next_shipping_date = strtotime("+" . $interval . " week", strtotime($shipping_date));
					break;
				case 0:
				default:
					$next_shipping_date = strtotime("+" . $interval . " month", strtotime($shipping_date));
					break;
			}
			$arrTeikiValues["next_shipping_date"] = date("Y/m/d", $next_shipping_date);

			// 既に登録あるか確認
			// $detail_id = $objQuery->getOne("SELECT detail_id FROM dtb_teiki WHERE teiki_id = ? AND course_id = ? AND product_id = ?", array($teiki_id, $data["course_id"], $next_product_id));
			// if (empty($detail_id)) {
			// 定期情報を登録
			$detail_id = $objQuery->nextVal('dtb_teiki_detail_id_seq');
			$arrTeikiValues["detail_id"] = $detail_id;
			$arrTeikiValues["brand_id"] = $_SESSION["brand_id"];
			$arrTeikiValues["create_date"] = "CURRENT_TIMESTAMP";
			$objQuery->insert("dtb_teiki", $arrTeikiValues);
			// }

			// 受注明細更新（定期明細ID更新）
			$objQuery->update("dtb_order_detail", array('teiki_detail_id' => $detail_id), 'order_detail_id = ?', array($data["order_detail_id"]));

			// 商品オプション情報を定期と紐付ける
			$arrOptionDetail = $objQuery->getAll("SELECT option_id, option_detail_id FROM dtb_order_detail_option WHERE order_id = ? AND order_detail_id = ? ORDER BY option_id, option_detail_id", array($order_id, $data["order_detail_id"]));
			foreach($arrOptionDetail as $detail) {
				$arrOptionValues = array();
				$arrOptionValues["teiki_id"] = $teiki_id;
				$arrOptionValues["teiki_detail_id"] = $detail_id;
				$arrOptionValues["option_id"] = $detail["option_id"];
				$arrOptionValues["option_detail_id"] = $detail["option_detail_id"];
				$objQuery->insert("dtb_teiki_option", $arrOptionValues);
			}
			$is_teiki_register = true;
		}

		// 定期作成あり
		if ($is_teiki_register) {
			// 受注更新（定期ID更新）
			$objQuery->update("dtb_order", array('teiki_id' => $teiki_id), 'order_id = ?', array($order_id));
		}

		// ---------------------------------------
		// オーソリ実行
		$objPurchase->orderAuthoriEntry($order_id, $arrValues["payment_id"]);

		$objQuery->commit();

		return $order_id;
	}

	/**
	 * アンカーキーを取得する.
	 *
	 * @param  SC_FormParam                   $objFormParam SC_FormParam インスタンス
	 * @return string アンカーキーの文字列
	 */
	public function getAnchorKey(&$objFormParam)
	{
		$ancor_key = $objFormParam->getValue('anchor_key');
		if (!SC_Utils_Ex::isBlank($ancor_key)) {
			return "location.hash='#" . htmlentities(urlencode($ancor_key), ENT_QUOTES) . "'";
		}

		return '';
	}

	/**
	 * 
	 */
	public function lfSetProductOptionSelect($arrOptionDetail) {
		$arrRet = array();
		$arrCheck = array();
		$i = 0;
		foreach($arrOptionDetail as $detail) {
			if (!isset($arrCheck[$detail["option_id"]])) {
				$i = 0;
			}
			$arrRet[$i]["option_id"] = $detail["option_id"];
			$arrRet[$i]["option_detail_id"] = $detail["option_detail_id"];
			$arrRet[$i]["option_detail_name"] = $detail["option_detail_name"];
			$arrCheck[$detail["option_id"]] = true;
			$i++;
		}
		return $arrRet;
	}
}
