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
 * クロス品購入ページ
 *
 * @package Page
 * @author EC-CUBE CO.,LTD.
 * @version $Id$
 */
class LC_Page_Store_Complete_Crosssell_Ex extends LC_Page_Ex
{
	/**
	 * Page を初期化する.
	 *
	 * @return void
	 */
	function init()
	{
		parent::init();
		$this->tpl_title = 'お得な特典情報';
		$this->httpCacheControl('nocache');

		// ショップ共通情報
		$this->arrInfo = SC_Helper_DB_Ex::sfGetBasisData();

		// テンプレート設定
		$this->setTemplate(SITE_FRAME);
		$this->tpl_mainpage = "store/complete_cross.tpl";

		// SMSフラグ
		$this->is_mode_sms = true;

		// 当日日付
		$this->tpl_month = date('n');
		$this->tpl_date = date('j');
	}

	/**
	 * Page のプロセス.
	 *
	 * @return void
	 */
	function process()
	{
		parent::process();
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
		GC_Utils_Ex::gfPrintLog("LC_Page_Store_Complete_Crosssell::action Start");

		$objCustomer = new SC_Customer_Ex();
		$objQuery =& SC_Query_Ex::getSingletonInstance();

		// 処理モード
		$mode = $_REQUEST["mode"];
		// 購入表示
		$this->dispCrosssell = true;
		
		GC_Utils_Ex::gfPrintLog("mode：".$mode);

		switch ($mode) {
			case "cross":
				// 注文番号がない場合は表示されないようにする
				if (!isset($_SESSION["order_id"])) {
					break;
				}
				// 購入情報
				$order_id = $_POST['order_id'];
				$thanks_id = $_POST['thanks_id'];
				$cta_id = $_POST['cta_id'];
				$thanks_next_action = $_POST['thanks_next_action'];

				// 購入処理実行
				$ret = $this->lfCrossRegistart($order_id, $thanks_id, $cta_id, $thanks_next_action);
				if (!$ret) {
					// 与信NG
					$_SESSION["cross_auth_ng"] = true;
				}
				$this->tpl_title = 'ご注文完了';
				$this->dispCrosssell = false;

				// セッション情報をクリア
				unset($_SESSION["order_id"]);
				unset($_SESSION["crosssell_flg"]);
				break;
			default:
				// 引き継ぎパラメータを設定
				if (!isset($_SESSION['thanks_id'])) {
					// 初回ページ遷移
					$this->order_id = $_REQUEST["order_id"];
					$this->brand_id = $_REQUEST["brand_id"];
					$this->customer_id = $_REQUEST["customer_id"];
					$this->thanks_id = $_REQUEST["thanks_id"];
					$_SESSION['order_id'] = $this->order_id;
					$_SESSION['brand_id'] = $this->brand_id;
					$_SESSION['customer_id'] = $this->customer_id;
					$_SESSION['thanks_id'] = $this->thanks_id;
					$_SESSION['crosssell_flg'] = true;
				} else {
					// ブラウザ更新など
					$this->order_id = $_SESSION["order_id"];
					$this->brand_id = $_SESSION["brand_id"];
					$this->customer_id = $_SESSION["customer_id"];
					$this->thanks_id = $_SESSION["thanks_id"];
				}

				GC_Utils_Ex::gfPrintLog("order_id=".$this->order_id);
				GC_Utils_Ex::gfPrintLog("brand_id=".$this->brand_id);
				GC_Utils_Ex::gfPrintLog("customer_id=".$this->customer_id);
				GC_Utils_Ex::gfPrintLog("thanks_id=".$this->thanks_id);

				// 表示モデル情報取得
				$this->lfSetCrossModel($this->thanks_id, $this->order_id, $this->customer_id);
				break;
		}

		// 注文番号がない場合は表示されないようにする
		if (!isset($_SESSION["order_id"])) {
			$this->dispCrosssell = false;
			$this->tpl_title = 'ご注文完了';
		}

		// 共通項目データ
		$this->arrBrandInfo = SC_Helper_DB_Ex::sfGetBrandData($_REQUEST["brand_id"]);

		GC_Utils_Ex::gfPrintLog("LC_Page_Store_Complete_Crosssell::action End");
	}

	/**
	 * クロスモデル情報設定
	 */
	public function lfSetCrossModel($thanks_id, $order_id, $customer_id) {
		$objQuery = SC_Query_Ex::getSingletonInstance();
		$arrThanks = $objQuery->getRow("*", "dtb_model_thanks", "id = ?", array($thanks_id));
		$this->template_name = $objQuery->getOne("SELECT path FROM dtb_model_template WHERE id = ?", array($arrThanks["model_template_id"]));

		// 元受注情報を取得
		$arrOrder = $objQuery->getRow("*","dtb_order","order_id = ?",array($order_id));
		$layout_id = $objQuery->getOne("SELECT layout_thanks_id_sp FROM dtb_confirm_setting dcs WHERE dcs.asp_id = ? AND dcs.baitai_id = ? AND dcs.brand_id = ?", array($arrOrder["asp_id"], $arrOrder["baitai_id"], $arrOrder["brand_id"]));
		if (empty($layout_id)) {
			$layout_id = $objQuery->getOne("SELECT layout_thanks_id_sp FROM dtb_confirm_default WHERE brand_id = ?", array($arrOrder["brand_id"]));
		}

		// お客様氏名
		$this->customer_name = $arrOrder["order_name01"];

		// クロス明細情報取得
		$this->arrCtaButton = array();
		$arrCrossDetail = $objQuery->getAll("SELECT * FROM dtb_model_thanks_detail WHERE thanks_id = ?",array($thanks_id));
		foreach ($arrCrossDetail as $data) {
			// ボタン情報
			$this->arrCtaButton[$data['cta_id']] = $data['thanks_next_action'];
			// 確認ポップ情報作成
			if ($data['thanks_next_action'] == 0 || $data['thanks_next_action'] == 2) {
				// 確認ポップ表示情報を取得
				$arrConfirmInfo = $this->fnSetConfirm($data, $arrOrder);
				// レイアウト情報を取得
				$arrLayout = $objQuery->getAll(" SELECT * FROM dtb_confirm_layout_detail WHERE layout_id = ? ORDER BY rank", array($layout_id));
				$layout_tag = "";
				foreach($arrLayout as $row) {
					$layout_tag .= $row["customize_code"];
				}
				// 購入商品・お客様・支払・注釈情報を置き換え
				$objQuery->setOrder("type, id");
				$arrReplace = $objQuery->select("*", "dtb_confirm_block_define", "del_flg = 0");
				foreach($arrReplace as $rows) {
					if ($rows["replace_code"] == "___EMAIL___") {
						// メアドのドメインを確認
						if (explode("@",$arrConfirmInfo[$rows["column_name"]])[1] == $this->arrInfo["mail_domain"]) {
							// 店舗ドメインと同じメアドの場合は確認ポップから削除する
							$layout_tag = str_replace("メールアドレス:___EMAIL___", "", $layout_tag);
						} else {
							$layout_tag = str_replace($rows["replace_code"], $arrConfirmInfo[$rows["column_name"]], $layout_tag);
						}
					} else {
						$layout_tag = str_replace($rows["replace_code"], $arrConfirmInfo[$rows["column_name"]], $layout_tag);
					}
				}
				// GC_Utils_Ex::gfPrintLog("layout_tag=".$layout_tag);
				$this->arrConfirmLayout[$data['cta_id']] = $layout_tag;
			}
		}
	}

	/**
	 * 確認ポップ表示情報の設定
	 */
	function fnSetConfirm($data, $arrOrder) {
		$objQuery = SC_Query_Ex::getSingletonInstance();
		$masterData = new SC_DB_MasterData();
		$objProduct = new SC_Product_Ex();
		$objDb = new SC_Helper_DB_Ex();

		$arrRet = array();
		$arrBaseInfo = $objDb->sfGetBasisData(true);
		$arrPref = $masterData->getMasterData('mtb_pref');
		$arrTax = $masterData->getMasterData('mtb_taxrule');
		$arrPaymentList = $objQuery->getAll("SELECT payment_id, payment_method, charge FROM dtb_payment");
		foreach ($arrPaymentList as $row) {
			$arrPayment[$row["payment_id"]]["payment_method"] = $row["payment_method"];
			$arrPayment[$row["payment_id"]]["charge"] = $row["charge"];
		}

		$orderProducts = '';
		$orderQuantity = '';
		$orderPrice = "";
		$total = 0;
		$arrProductOffer = array();
		$arrProductSub = array();

		// コース情報
		$arrCourse = $objQuery->getRow("*","dtb_course","id = ?",array($data['course_id']));
		// オファー品
		if (!empty($arrCourse["cross_offer_id"])) {
			$arrProductOffer = $objProduct->getProductsClassByQuery($objQuery, $arrCourse["cross_offer_id"])[0];
		}

		if ($arrCourse["is_cross"] == 2) {
			// アップセル

			// メインコース情報
			$arrCourseMain = $objQuery->getRow("*","dtb_course_detail","course_id = ? AND course_type = 0 AND order_count = 1",array($data['course_id']));
			$arrProductMain = $objProduct->getProductsClassByQuery($objQuery, $arrCourseMain["product_id"])[0];
			// メイン商品オプション
			$option_name_main = $objQuery->getOne("SELECT func_get_order_product_option(dod.order_id, dod.order_detail_id) FROM dtb_order_detail dod WHERE dod.order_id = ? AND dod.product_id = ?", array($arrOrder["order_id"], $arrCourseMain["product_id"]));
			// サブコース情報
			$arrCourseSub = $objQuery->getRow("*","dtb_course_detail","course_id = ? AND course_type = 1 AND order_count = 1",array($data['course_id']));
			if (!empty($arrCourseSub)) {
				$arrProductSub = $objProduct->getProductsClassByQuery($objQuery, $arrCourseSub["product_id"])[0];
				// サブ商品オプション
				$option_name_sub = $objQuery->getOne("SELECT func_get_order_product_option(dod.order_id, dod.order_detail_id) FROM dtb_order_detail dod WHERE dod.order_id = ? AND dod.product_id = ?", array($arrOrder["order_id"], $arrCourseSub["product_id"]));
			}
		} else {
			// クロスセル

			// メインコース情報
			$arrCourseMain = $objQuery->getRow("*","dtb_course_detail","course_id = ? AND course_type = 0 AND order_count = 1",array($data['course_id']));
			$arrProductMain = $objProduct->getProducts($arrCourseMain["product_id"], 0);
			// オプション確認
			if (!empty($arrProductMain["option"])) {
				foreach($arrProductMain["option"] as $option_id => $rowDetail) {
					// 注文のオプション情報を取得
					$option_detail_id = $objQuery->getOne("SELECT option_detail_id FROM dtb_order_detail_option WHERE order_id = ? AND option_id = ?", array($arrOrder["order_id"], $option_id));
					if (!empty($option_detail_id)) {
						// オプション明細設定
						$option_name_main = "(" . $rowDetail[$option_detail_id] . ")";
					}
				}
			}

			// サブコース情報
			$arrCourseSub = $objQuery->getRow("*","dtb_course_detail","course_id = ? AND course_type = 1 AND order_count = 1",array($data['course_id']));
			if (!empty($arrCourseSub)) {
				$arrProductSub = $objProduct->getProducts($arrCourseSub["product_id"], 0);
				// オプション確認
				if (!empty($arrProductSub["option"])) {
					foreach($arrProductSub["option"] as $option_id => $rowDetail) {
						// 注文のオプション情報を取得
						$option_detail_id = $objQuery->getOne("SELECT option_detail_id FROM dtb_order_detail_option WHERE order_id = ? AND option_id = ?", array($arrOrder["order_id"], $option_id));
						if (!empty($option_detail_id)) {
							// オプション明細設定
							$option_name_sub = "(" . $rowDetail[$option_detail_id] . ")";
						}
					}
				}
			}
		}
// 		// メインコース情報
// 		$arrCourseMain = $objQuery->getRow("*","dtb_course_detail","course_id = ? AND course_type = 0 AND order_count = 1",array($data['course_id']));
// 		$arrProductMain = $objProduct->getProductsClassByQuery($objQuery, $arrCourseMain["product_id"])[0];
// // GC_Utils_Ex::gfPrintLog("arrProductMain:". serialize($arrProductMain));
// 		// サブコース情報
// 		$arrCourseSub = $objQuery->getRow("*","dtb_course_detail","course_id = ? AND course_type = 1 AND order_count = 1",array($data['course_id']));
// 		if (!empty($arrCourseSub)) {
// 			$arrProductSub = $objProduct->getProductsClassByQuery($objQuery, $arrCourseSub["product_id"])[0];
// 		}
		// 支払い方法情報
		$payment_id = $objQuery->getOne("SELECT payment_id FROM dtb_customer WHERE customer_id = ?",array($arrOrder['customer_id']));
		
		// -------------------------------------------------------
		// 購入商品情報
		$arrRet["conf_course"] = $arrCourse["name_official"];				// コース名
		// 商品名整形
		$orderProducts = $arrProductMain["name_official"].$option_name_main;
		if (!empty($arrProductSub) && $arrProductSub["is_confirm_show"] == 0) {$orderProducts .= "/".$arrProductSub["name_official"].$option_name_sub;}
		$arrRet["conf_products"] = $orderProducts;							// 商品名
		// 数量整形
		$orderQuantity = $arrCourseMain["quantity"];
		$arrRet["conf_quantity"] = "各".$orderQuantity."点";				// 個数
		// 単価整形
		$price_m = SC_Utils_Ex::sfCalcIncTax($arrCourseMain["price"], $arrTax[$arrProductMain["tax_status"]], $arrBaseInfo["tax_rule"]) * $arrCourseMain["quantity"];
		$price_s = 0;
		if (!empty($arrCourseSub)) {$price_s = SC_Utils_Ex::sfCalcIncTax($arrCourseSub["price"], $arrTax[$arrProductSub["tax_status"]], $arrBaseInfo["tax_rule"]) * $arrCourseSub["quantity"];}
		$price_m += $price_s;
		// 手数料
		if ($price_m > 0) {
			$charge = $arrPayment[$payment_id]["charge"];
		} else {
			$charge = 0;
		}
		$arrRet["conf_total"] = "¥".number_format($price_m);					// 商品金額合計
		$arrRet["conf_deliv_fee"] = "¥0";										// 配送料
		$arrRet["conf_charge"] = "¥".number_format($charge);					// 手数料
		$arrRet["conf_payment_total"] = "¥".number_format($price_m + $charge);	// 合計金額

		// -------------------------------------------------------
		// お客様情報
		$arrRet["conf_order_name"] = $arrOrder["order_name01"];
		$arrRet["conf_order_zip"] = substr($arrOrder["order_zip01"],0,3)."-".substr($arrOrder["order_zip01"],3,4);
		$arrRet["conf_order_addr"] = $arrPref[$arrOrder["order_pref"]].$arrOrder["order_addr01"].$arrOrder["order_addr02"];
		$arrMobile = array('020','070','080','090');
		$is_mobile = substr($arrOrder["order_tel01"],0,3);
		if (in_array($is_mobile, $arrMobile)) {
			$arrRet["conf_order_tel"] = $is_mobile."-".substr($arrOrder["order_tel01"],3,4)."-".substr($arrOrder["order_tel01"],7,4);
		} else {
			$arrRet["conf_order_tel"] = SC_Utils_Ex::sfTelFormat($arrOrder["order_tel01"]);
		}
		$arrRet["conf_order_mail"] = $arrOrder["order_email"];

		// -------------------------------------------------------
		// 支払方法情報
		if ($price_m > 0) {
			$payment_method = $objQuery->getOne("SELECT payment_method FROM dtb_payment WHERE payment_id = ?",array($payment_id));
			if (strpos($payment_method,'（') !== false) {$payment_method = mb_strstr($payment_method, '（', true);}
			$arrRet["conf_payment_method"] = $payment_method;
			$arrRet["conf_payment_id"] = $payment_id;
		} else {
			$arrRet["conf_payment_method"] = "無料";
			$arrRet["conf_payment_id"] = 7;
		}

		// -------------------------------------------------------
		// その他（規約注釈内情報）
		$arrRet['confirm_image_1'] = THANKS_IMAGE_PATH . $data['confirm_image'];
		$arrRet['confirm_txt'] = $data['confirm_txt'];
		$arrRet['conf_course_biko'] = $arrCourse["biko"];
		$arrRet['conf_first_time'] = $arrCourseMain["interval"];
		// 2回目以降コース情報
		$arrCourseMain_2 = $objQuery->getRow("*","dtb_course_detail","course_id = ? AND course_type = 0 AND order_count = 2",array($data['course_id']));
		$arrRet["conf_second_time"] = $arrCourseMain_2["interval"];
		// ブランド情報
		$arrBrand = $objDb->sfGetBrandData($_SESSION["brand_id"]);
		$arrRet["conf_brand_tel"] = $arrBrand["tel"];
		$arrRet["conf_kaiyaku_span"] = $arrBrand["cancel_ng_date"];
		$arrRet["conf_kaiyaku_ass"] = $arrBrand["kaiyaku_ass"];

		return $arrRet;
	}

	/**
	 * クロス品購入処理
	 */
	public function lfCrossRegistart($order_id, $thanks_id, $cta_id, $action_id) {
		$objPurchase = new SC_Helper_Purchase_Ex();
		$objHelperTeiki = new SC_Helper_Teiki_Renew();
		$objDb = new SC_Helper_DB_Ex();
		$objQuery = SC_Query_Ex::getSingletonInstance();
		// 元注文データ/配送データ/顧客データ取得
		$arrOrder = $objQuery->getRow("*", "dtb_order", "order_id = ?", array($order_id));
		$arrShipping = $objQuery->getRow("*", "dtb_shipping", "order_id = ?", array($order_id));
		$arrCustomer = $objQuery->getRow("*", "dtb_customer", "customer_id = ?", array($arrOrder["customer_id"]));
		$arrBaseInfo = $objDb->sfGetBasisData(true);
		
		// 購入コース情報を取得
		$course_id = $objQuery->getOne("SELECT course_id FROM dtb_model_thanks_detail dmtd WHERE thanks_id = ? AND cta_id = ? AND thanks_next_action = ?", array($thanks_id, $cta_id, $action_id));
		// 注文コース商品情報
		$sql = " SELECT dcd.product_id, dcd.quantity, dcd.price, dp.product_code, dp.name AS product_name, mt.name AS tax_rate, dcd.interval, dcd.interval_type, dc.is_cross"
			 . " FROM dtb_course_detail dcd "
			 . " INNER JOIN dtb_products dp ON dcd.product_id = dp.product_id "
			 . " INNER JOIN dtb_course dc ON dcd.course_id = dc.id "
			 . " INNER JOIN mtb_taxrule mt ON dp.tax_status = mt.id "
			 . " WHERE dcd.course_id = ? AND dcd.order_count = 1"
			 . " ORDER BY dcd.course_type"
		;
		$arrDetail = $objQuery->getAll($sql, array($course_id));

		// コース区分を確認
		if ($arrDetail[0]["is_cross"] == 2) {
			// アップセルの場合は元注文、定期をキャンセル
			$objPurchase->orderAuthoriCancel($order_id);
			$objQuery->update("dtb_order", array("status"=>3, "is_first"=>null, "is_upsell_cancel"=>1, "update_date"=>"CURRENT_TIMESTAMP"), "order_id = ?", array($order_id));

			// 定期解約
			$arrOrderDetail = $objQuery->getAll("SELECT * FROM dtb_order_detail WHERE order_id = ? ORDER BY order_detail_id", array($order_id));
			foreach($arrOrderDetail as $detail) {
				if (!empty($arrOrder["teiki_id"]) && !empty($detail["teiki_detail_id"])) {
					// 解約処理
					$objHelperTeiki->sfStopTeiki($arrOrder["teiki_id"], $detail["teiki_detail_id"], "クロス引き上げの為", 98);
					// オプション情報取得
					$arrOption = $objQuery->getAll("SELECT * FROM dtb_teiki_option WHERE teiki_id = ? AND teiki_detail_id = ? ORDER BY option_id, option_detail_id", array($arrOrder["teiki_id"], $detail["teiki_detail_id"]));
				}
			}
		}
		// 支払方法情報
		$arrPayment = $objQuery->getAll("SELECT dp.* FROM dtb_payment dp INNER JOIN dtb_customer dc ON dp.payment_id = dc.payment_id WHERE dc.customer_id = ?", array($arrOrder["customer_id"]));
		// 登録データ作成
		$arrRegOrder = array();
		$arrRegDetail = array();
		$arrRegShipping = array();

		// 新注文番号を発行
		$new_order_id = $objPurchase->getNextOrderID();

		// 受注登録情報
		$arrRegOrder["order_id"] = $new_order_id;
		$arrRegOrder["customer_id"] = $arrCustomer["customer_id"];
		$arrRegOrder["order_name01"] = $arrCustomer["name01"];
		$arrRegOrder["order_kana01"] = $arrCustomer["kana01"];
		$arrRegOrder["order_email"] = $arrCustomer["email"];
		$arrRegOrder["order_tel01"] = $arrCustomer["tel01"];
		$arrRegOrder["order_zip01"] = $arrCustomer["zip01"];
		$arrRegOrder["order_pref"] = $arrCustomer["pref"];
		$arrRegOrder["order_addr01"] = $arrCustomer["addr01"];
		$arrRegOrder["order_addr02"] = $arrCustomer["addr02"];
		$arrRegOrder["discount"] = 0;
		$arrRegOrder["deliv_fee"] = 0;
		$arrRegOrder["create_date"] = "CURRENT_TIMESTAMP";
		$arrRegOrder["update_date"] = "CURRENT_TIMESTAMP";
		$arrRegOrder["device_type_id"] = "80";
		$arrRegOrder["del_flg"] = "0";
		$arrRegOrder["brand_id"] = $_SESSION["brand_id"];
		$arrRegOrder["status"]  = 7;
		$arrRegOrder["is_sms_cross"]  = 1;
		$arrRegOrder["asp_id"]  = $arrOrder["asp_id"];
		$arrRegOrder["media_id"]  = $arrOrder["media_id"];
		$arrRegOrder["baitai_id"]  = $arrOrder["baitai_id"];
		$arrRegOrder["adcode"]  = $arrOrder["adcode"];
		// $arrRegOrder["referer"]  = $_SERVER["HTTP_REFERER"];
		$arrRegOrder["ip"]  = $_SERVER["REMOTE_ADDR"];
		$arrRegOrder["user_agent"]  = $_SERVER['HTTP_USER_AGENT'];
		if ($arrDetail[0]["is_cross"] == 2) {
			// クロスの場合は追加で引き継ぎ項目を設定
			$arrRegOrder["teiki_id"]  = $arrOrder["teiki_id"];
			$arrRegOrder["is_first"]  = 1;
		}

		// 金額計算/明細登録データ作成
		$i = 0;
		$subtotal = 0;
		$tax = 0;
		foreach($arrDetail as $detail) {
			// 明細登録情報
			$arrRegDetail[$i]["product_id"] = $detail["product_id"];
			$arrRegDetail[$i]["product_name"] = $detail["product_name"];
			$arrRegDetail[$i]["product_code"] = $detail["product_code"];
			$arrRegDetail[$i]["price"] = $detail["price"];
			$arrRegDetail[$i]["quantity"] = $detail["quantity"];
			$arrRegDetail[$i]["tax_rate"] = $detail["tax_rate"];
			$arrRegDetail[$i]["tax_rule"] = $this->arrInfo["tax_rule"];
			$arrRegDetail[$i]["course_id"] = $course_id;
			++$i;

			// 明細合計金額計算
			$price = $detail["price"];
			$quantity = $detail["quantity"];
			$tax_rate = $detail["tax_rate"];
			$tax_rule = $this->arrInfo["tax_rule"];

			// 消費税合計
			$tax += SC_Utils_Ex::sfTax($price, $tax_rate, $tax_rule) * $quantity;
			// 商品金額合計（税込）
			$subtotal += SC_Utils_Ex::sfCalcIncTax($price, $tax_rate, $tax_rule) * $quantity;
		}

		// 注文合計金額
		$arrRegOrder["deliv_fee"] = 0;
		$arrRegOrder["discount"] = 0;
		$arrRegOrder["subtotal"] = $subtotal;
		$arrRegOrder["tax"] = $tax;
		if ($subtotal > 0) {
			$arrRegOrder["payment_id"] = $arrPayment[0]["payment_id"];
			$arrRegOrder["payment_method"] = $arrPayment[0]["payment_method"];
			$arrRegOrder["charge"] = (int)$arrPayment[0]["charge"];
			$arrRegOrder["total"] = $subtotal + (int)$arrPayment[0]["charge"];
			$arrRegOrder["payment_total"] = $subtotal + (int)$arrPayment[0]["charge"];
		} else {
			$arrRegOrder["payment_id"] = 7;
			$arrRegOrder["payment_method"] = "無料";
			$arrRegOrder["charge"] = 0;
			$arrRegOrder["total"] = 0;
			$arrRegOrder["payment_total"] = 0;
		}

		// 配送登録データ作成
		$arrRegShipping[0]["shipping_name01"] = $arrCustomer["name01"];
		$arrRegShipping[0]["shipping_kana01"] = $arrCustomer["kana01"];
		$arrRegShipping[0]["shipping_tel01"] = $arrCustomer["tel01"];
		$arrRegShipping[0]["shipping_pref"] = $arrCustomer["pref"];
		$arrRegShipping[0]["shipping_zip01"] = $arrCustomer["zip01"];
		$arrRegShipping[0]["shipping_addr01"] = $arrCustomer["addr01"];
		$arrRegShipping[0]["shipping_addr02"] = $arrCustomer["addr02"];
		$arrRegShipping[0]["time_id"] = $arrShipping[0]["time_id"];
		$arrRegShipping[0]["deliv_id"] = 6;
		$arrRegShipping[0]["del_flg"] = 0;
		$arrRegShipping[0]["shipping_date"] = $objPurchase->lfGetHolidayOfShippingDate(array("create_date"=>date("Y/m/d H:i:s")));

		// ---------------------------------------
		// 受注登録（個別でINSERTする）
		$objQuery->insert("dtb_order", $arrRegOrder);
		// $objPurchase->registerOrder($new_order_id, $arrRegOrder);
		// 受注明細登録
		$objQuery->delete("dtb_order_detail", "order_id = ?", array($new_order_id));
		foreach ($arrRegDetail as $arrOdrDetail) {
			$arrValues = $objQuery->extractOnlyColsOf("dtb_order_detail", $arrOdrDetail);
			$arrValues['order_detail_id'] = $objQuery->nextVal('dtb_order_detail_order_detail_id');
			$arrValues['order_id'] = $new_order_id;
			if ($arrDetail[0]["is_cross"] == 2) {
				$arrValues['teiki_detail_id'] = $objQuery->nextVal('dtb_teiki_detail_id_seq');
			}
			$objQuery->insert("dtb_order_detail", $arrValues);

			// オプション登録
			$sql = "SELECT dodo.option_id, dodo.option_detail_id FROM dtb_order_detail dod INNER JOIN dtb_order_detail_option dodo ON dod.order_id = dodo.order_id AND dod.order_detail_id = dodo.order_detail_id WHERE dod.order_id = ? AND dod.product_id = ?";
			$arrOption = $objQuery->getAll($sql, array($order_id, $arrValues["product_id"]));
			foreach($arrOption as $option) {
				$arrValues2 = array();
				$arrValues2["order_id"] = $arrValues['order_id'];
				$arrValues2["order_detail_id"] = $arrValues['order_detail_id'];
				$arrValues2["option_id"] = $option['option_id'];
				$arrValues2["option_detail_id"] = $option['option_detail_id'];
				$arrValues2["create_date"] = "CURRENT_TIMESTAMP";
				$objQuery->insert("dtb_order_detail_option", $arrValues2);
			}
		}
		// 配送登録
		$objPurchase->registerShipping($new_order_id, $arrRegShipping, false);

		// 配送方法指定
		$objPurchase->lfChangeOrderShippingMethod($new_order_id);

		// ---------------------------------------
		// オーソリ実行
		$ret = $objPurchase->orderAuthoriEntry($new_order_id, $arrRegOrder["payment_id"]);
		if ($ret) {
			// コンビニ以外の場合は「新規受付」に更新
			if ($arrRegOrder["payment_id"] <> MDL_VAMOS_PAYMENT_ID) {
				$objQuery->update("dtb_order", array("status"=>1), "order_id = ?", array($new_order_id));
			}
		} else {
			// コンビニ以外で与信失敗の場合はコンビニに変更する
			if ($arrRegOrder["payment_id"] <> MDL_VAMOS_PAYMENT_ID) {
				// 後払い支払方法情報を取得
				$arrDeferred = $objQuery->getRow("*", "dtb_payment", "payment_id = ?", array(MDL_VAMOS_PAYMENT_ID));
				// 更新データ
				$arrUpdate = array();
				$arrUpdate["payment_id"] = $arrDeferred["payment_id"];
				$arrUpdate["payment_method"] = $arrDeferred["payment_method"];
				$arrUpdate["charge"] = $arrDeferred["charge"];
				$arrUpdate["total"] = $arrRegOrder["subtotal"] + $arrDeferred["charge"];
				$arrUpdate["payment_total"] = $arrRegOrder["subtotal"] + $arrDeferred["charge"];
				$objQuery->update("dtb_order", $arrUpdate, "order_id = ?", array($new_order_id));

				// もう一度与信登録
				$ret2 = $objPurchase->orderAuthoriEntry($new_order_id, MDL_VAMOS_PAYMENT_ID);
			}
		}

		// 定期登録
		if ($arrDetail[0]["is_cross"] == 2) {
			// アップセルした注文明細データを取得
			$arrOrderDetailNew = $objQuery->getAll("SELECT * FROM dtb_order_detail WHERE order_id = ? ORDER BY order_detail_id", array($new_order_id));
			$arrOrderNew = $objQuery->getRow("*", "dtb_order", "order_id = ?", array($new_order_id));
			$arrShippingNew = $objQuery->getRow("*", "dtb_shipping", "order_id = ?", array($new_order_id));
			foreach ($arrOrderDetailNew as $data) {
				// 今回注文の商品明細コースタイプを取得
				$course_type = $objQuery->getOne("SELECT course_type FROM dtb_course_detail WHERE course_id = ? AND product_id = ? AND order_count = 1", array($data["course_id"], $data["product_id"]));
				// 次回コース情報を取得
				$arrCourse = $objQuery->getAll("SELECT * FROM dtb_course_detail WHERE course_id = ? AND course_type = ? AND order_count <= 2 ORDER BY order_count DESC", array($data["course_id"], $course_type));
				if (!empty($arrCourse[0]["interval"])) {
					// 定期情報を登録
					$arrTeikiValue = array();
					$arrTeikiValue["teiki_id"] = $arrOrderNew["teiki_id"];
					$arrTeikiValue["detail_id"] = $data["teiki_detail_id"];
					$arrTeikiValue["customer_id"] = $arrOrderNew["customer_id"];
					$arrTeikiValue["teiki_model_id"] = $arrOrderNew["model_id"];
					$arrTeikiValue["product_id"] = $arrCourse[0]["product_id"];
					$arrTeikiValue["quantity"] = $arrCourse[0]["quantity"];
					$arrTeikiValue["deliv_time_id"] = $arrShippingNew["time_id"];
					$arrTeikiValue["interval"] = $arrCourse[0]["interval"];
					$arrTeikiValue["interval_type"] = $arrCourse[0]["interval_type"];
					$arrTeikiValue["first_order_id"] = $new_order_id;
					$arrTeikiValue["first_order_date"] = $arrOrderNew["create_date"];
					$arrTeikiValue["course_id"] = $data["course_id"];
					$arrTeikiValue["brand_id"] = $arrOrderNew["brand_id"];
					$arrTeikiValue["del_flg"] = 0;
					$arrTeikiValue["discount"] = 0;
					$arrTeikiValue["status"] = 1;
					$arrTeikiValue["create_date"] = "CURRENT_TIMESTAMP";
					$arrTeikiValue["update_date"] = "CURRENT_TIMESTAMP";

					// 商品毎の配送方法確認
					$product_weight = $objQuery->getOne("SELECT weight FROM dtb_products WHERE product_id = ?", array($arrCourse[0]["product_id"]));
					$check_weight = $product_weight * $arrCourse[0]["quantity"];
					if ($arrBaseInfo["border_weight_sagawa"] <= $check_weight) {
						// 佐川基準値を上回った場合は「佐川急便」
						$deliv_id = 1;
					} else if ($arrBaseInfo["border_weight"] < $check_weight) {
						// 郵政基準値以上であれば「ゆうパック」
						$deliv_id = 2;
					} else {
						// 郵政基準値以内であれば「ゆうパケット」
						$deliv_id = 6;
					}
					$arrTeikiValue["deliv_id"] = $deliv_id;

					// ノート
					$arrTeikiValue["note"] = $objQuery->getOne("SELECT note FROM dtb_teiki WHERE teiki_id = ? AND product_id = ? ORDER BY detail_id DESC LIMIT 1", array($arrOrderNew["teiki_id"], $data["product_id"]));
					// 次回お届け予定日
					switch($arrDetail[0]["interval_type"]) {
						case 2:
							$str_next_shipping_date = date("Y/m/d", strtotime("+".$arrDetail[0]["interval"]." day", strtotime($arrShippingNew["shipping_date"])));
							break;
						case 1:
							$str_next_shipping_date = date("Y/m/d", strtotime("+".$arrDetail[0]["interval"]." week", strtotime($arrShippingNew["shipping_date"])));
							break;
						default:
							$str_next_shipping_date = date("Y/m/d", strtotime("+".$arrDetail[0]["interval"]." month", strtotime($arrShippingNew["shipping_date"])));
							break;
					}
					$arrTeikiValue["next_shipping_date"] = $str_next_shipping_date;
					// 定期登録
					$objQuery->insert("dtb_teiki", $arrTeikiValue);

					// オプション登録
					$arrOption = $objQuery->getAll("SELECT * FROM dtb_order_detail_option WHERE order_id = ? AND order_detail_id = ? ORDER BY option_id", array($new_order_id, $data["order_detail_id"]));
					foreach($arrOption as $option) {
						$arrTeikiOption = array();
						$arrTeikiOption["teiki_id"] = $arrOrderNew["teiki_id"];
						$arrTeikiOption["teiki_detail_id"] = $data["teiki_detail_id"];
						$arrTeikiOption["option_id"] = $option["option_id"];
						$arrTeikiOption["option_detail_id"] = $option["option_detail_id"];
						$arrTeikiOption["create_date"] = "CURRENT_TIMESTAMP";
						$objQuery->insert("dtb_teiki_option", $arrTeikiOption);
					}
				}
			}
		}

		return $ret;
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
