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

require_once CLASS_REALDIR . 'helper/SC_Helper_Customer.php';

/**
 * CSV関連のヘルパークラス(拡張).
 *
 * LC_Helper_Customer をカスタマイズする場合はこのクラスを編集する.
 *
 * @package Helper
 * @author EC-CUBE CO.,LTD.
 * @version $Id$
 */
class SC_Helper_Customer_Ex extends SC_Helper_Customer
{
	/**
	 * 電話番号から、登録済み会員や退会済み会員をチェックする
	 *
	 * XXX SC_CheckError からしか呼び出されず, 本クラスの中で SC_CheckError を呼び出している
	 *
	 * @param string $tel  電話番号
	 * @return integer  0:登録可能     1:登録済み   2:再登録制限期間内削除ユーザー
	 */
	function sfCheckRegisterUserFromTel_Brand($tel, $brand_id) {
		$objCustomer = new SC_Customer_Ex();
		$objQuery =& SC_Query_Ex::getSingletonInstance();

		$arrRet = $objQuery->select('tel01, update_date, del_flg',
			'dtb_customer',
			'tel01 = ? AND brand_id = ? ORDER BY del_flg',
			array($tel, $brand_id));

		if (count($arrRet) > 0) {
			// 会員である場合
			if ($arrRet[0]['del_flg'] != '1') {
				// 登録済み
				return 1;
			} else {
				// 退会した会員である場合
				$leave_time = SC_Utils_Ex::sfDBDatetoTime($arrRet[0]['update_date']);
				$now_time   = time();
				$pass_time  = $now_time - $leave_time;
				// 退会から何時間-経過しているか判定する。
				$limit_time = ENTRY_LIMIT_HOUR * 3600;
				if ($pass_time < $limit_time) {
					// 再登録制限期間内削除ユーザー
					return 2;
				}
			}
		}

		// 登録可能
		return 0;
	}

	/**
	 * emailアドレスから、登録済み会員や退会済み会員をチェックする
	 *
	 * XXX SC_CheckError からしか呼び出されず, 本クラスの中で SC_CheckError を呼び出している
	 *
	 * @param  string  $email メールアドレス
	 * @return integer 0:登録可能     1:登録済み   2:再登録制限期間内削除ユーザー  3:自分のアドレス
	 */
	public static function sfCheckRegisterUserFromEmail_Brand($email, $brand_id)
	{
		$objCustomer = new SC_Customer_Ex();
		$objQuery = SC_Query_Ex::getSingletonInstance();

		// ログインしている場合、すでに登録している自分のemailの場合
		if ($objCustomer->isLoginSuccess(true)
			&& SC_Helper_Customer_Ex::sfCustomerEmailDuplicationCheck($objCustomer->getValue('customer_id'), $email)) {
			// 自分のアドレス
			return 3;
		}

		$arrRet = $objQuery->select('email, update_date, del_flg',
			'dtb_customer',
			'email = ? AND brand_id = ? ORDER BY del_flg',
			array($email, $brand_id));

		if (count($arrRet) > 0) {
			// 会員である場合
			if ($arrRet[0]['del_flg'] != '1') {
				// 登録済み
				return 1;
			} else {
				// 退会した会員である場合
				$leave_time = SC_Utils_Ex::sfDBDatetoTime($arrRet[0]['update_date']);
				$now_time   = time();
				$pass_time  = $now_time - $leave_time;
				// 退会から何時間-経過しているか判定する。
				$limit_time = ENTRY_LIMIT_HOUR * 3600;
				if ($pass_time < $limit_time) {
					// 再登録制限期間内削除ユーザー
					return 2;
				}
			}
		}

		// 登録可能
		return 0;
	}

	/**
	 * ログイン購入可能チェック
	 */
	public static function sfCheckLoginRegister($arrParam, $brand_id, $category_id) {
		$objQuery =& SC_Query_Ex::getSingletonInstance();

		// 電話番号のみで顧客ヒット情報を確認
		$objQuery->setOrder("update_date");
		$arrWork = $objQuery->select("*", "dtb_customer", "del_flg = 0 AND brand_id = ? AND tel01 = ?", array($brand_id, $arrParam["order_tel01"]));
		if (empty($arrWork)) {
			// 電話番号が顧客情報にヒットしないので新規購入者と判別
			return 0;
		}
		// ヒットした顧客情報から氏名の一致を検索
		$arrHit = array();
		$arrHit_category = array();
		$is_name_hit = false;
		$is_category_hit = false;
		foreach($arrWork as $data) {
			// 初期化
			$is_name_hit = false;
			$is_category_hit = false;
			// 顧客名を整形（空白などを削除）
			GC_Utils_Ex::gfPrintLog("整形前：". $arrParam["order_name01"]);
			$name = sprintf('%s', preg_replace('/[ 　]/u', '', $arrParam["order_name01"]));
			GC_Utils_Ex::gfPrintLog("整形後：". $name);
			// 顧客ID＋顧客名で検索
			$arrHit = $objQuery->getRow("*", "dtb_customer", "customer_id = ? AND replace(replace(name01,'　',' '),' ','') = ?", array($data["customer_id"], $name));
			if (!empty($arrHit)) {
				// 氏名一致あり
				$is_name_hit = true;
				// 購入商品が初購入なのか確認
				if (in_array($_SESSION['baitai_id'], array(281, 128))) {
					// 休眠用ロジック ---
					// 購入対象商品のカテゴリチェック
					$arrBuyCategory = $objQuery->getAll("SELECT DISTINCT dp.category_id FROM dtb_course_detail dcd INNER JOIN dtb_products dp ON dcd.product_id = dp.product_id WHERE dcd.course_id = ? AND dcd.order_count = 1 AND dp.is_confirm_show = 0", array($_SESSION["course_id"]));
					if (!empty($arrBuyCategory)) {
						// 対象カテゴリの定期状況チェック
						foreach($arrBuyCategory as $value) {
							$arrHit_category = $objQuery->getRow("*", "dtb_teiki", "del_flg = 0 AND status <> 3 AND customer_id = ? AND func_get_product_category(product_id) = ?", array($data["customer_id"], $value["category_id"]));
							if (!empty($arrHit_category)) {
								$is_category_hit = true;
								break 2;
							}
						}
					} else {
						$arrHit_category = $objQuery->getRow("*", "dtb_teiki", "del_flg = 0 AND status <> 3 AND customer_id = ? AND func_get_product_category(product_id) = ?", array($data["customer_id"], $category_id));
					}
				} else {
					// 通常新規
					$arrHit_category = $objQuery->getRow("*", "dtb_order", "del_flg = 0 AND is_first = 1 AND customer_id = ? AND func_get_order_course_main_category(order_id) = ?", array($data["customer_id"], $category_id));
					if (!empty($arrHit_category)) {
						$is_category_hit = true;
						break;
					}
				}
			}
		}
		unset($arrHit);
		unset($arrHit_category);
		// ログイン購入可能チェック
		if ($is_name_hit) {
			// 氏名一致あり
			if ($is_category_hit) {
				// 既に購入実績があるので通常の電話番号被りエラーを表示
				return 9;
			} else {
				// 対象商品の初回購入なのでログイン購入OK
				return 1;
			}
		} else {
			// 氏名一致なし（氏名再入力依頼）
			return 2;
		}
	}


	/**
	 * ユニークIDから顧客IDを取得
	 */
	public function getUidForCustomerId($uid) {
		$objQuery = SC_Query_Ex::getSingletonInstance();
		return $objQuery->getOne("SELECT customer_id FROM dtb_customer_code WHERE code = ?", array($uid));
	}

	/**
	 * パスワード変更
	 */
	public function changeCustomerPassword($customer_id, $password) {
		$objQuery = SC_Query_Ex::getSingletonInstance();
		// パスワードをハッシュ化
		$hash_password = password_hash($password, PASSWORD_DEFAULT);
		// 更新
		$arrUpdate = array();
		$arrUpdate["password"] = $hash_password;
		$arrUpdate["login_date"] = "CURRENT_TIMESTAMP";
		$arrUpdate["update_date"] = "CURRENT_TIMESTAMP";
		
		// 一応存在チェック＆なかったら登録処理
		$cnt = $objQuery->count("dtb_customer_password", "customer_id = ?", array($customer_id));
		if ($cnt == 0) {
			// 登録
			$arrUpdate["customer_id"] = $customer_id;
			$arrUpdate["create_date"] = "CURRENT_TIMESTAMP";
			$objQuery->insert("dtb_customer_password", $arrUpdate);
		} else {
			// 更新
			$objQuery->update("dtb_customer_password", $arrUpdate, "customer_id = ?", array($customer_id));
		}
	}

	/**
	 * 顧客登録ブランドのマイページURL取得
	 */
	public function getCustomerBrandPage($customer_id, $mode) {
		$objQuery = SC_Query_Ex::getSingletonInstance();
		if ($mode == 0) {
			// マイページ
			return $objQuery->getOne("SELECT db.mypage_url FROM dtb_customer dc INNER JOIN dtb_brand db ON dc.brand_id = db.id WHERE dc.customer_id = ?", array($customer_id));
		} else if ($mode == 1) {
			// 解約ASS
			return $objQuery->getOne("SELECT db.kaiyaku_ass FROM dtb_customer dc INNER JOIN dtb_brand db ON dc.brand_id = db.id WHERE dc.customer_id = ?", array($customer_id));
		}
	}

	/**
	 * 顧客使用可能ポイント取得
	 */
	public function getCustomerPoint($customer_id) {
		$objQuery = SC_Query_Ex::getSingletonInstance();
		return $objQuery->getOne("SELECT func_get_customer_point(?)", array($customer_id));
	}

	/**
	 * 顧客使用可能ポイント有効期限日
	 */
	public function getCustomerPointExpirationDate($customer_id) {
		$objQuery = SC_Query_Ex::getSingletonInstance();
		return $objQuery->getOne("SELECT MAX(lost_date) FROM dtb_customer_point_history WHERE customer_id = ? AND is_fugo = 0", array($customer_id));
	}

	/**
	 * 顧客ポイント履歴取得
	 */
	public function getCustomerPointHistory($customer_id) {
		$objQuery = SC_Query_Ex::getSingletonInstance();
		$sql = " SELECT dcph.*, mpc.name AS category_name, dp.name_official AS product_name, dp.product_image"
			 . " FROM dtb_customer_point_history dcph"
			 . " INNER JOIN mtb_point_category mpc ON dcph.point_category = mpc.id"
			 . " LEFT JOIN dtb_point_exchange_list dpel ON dcph.exchange_id = dpel.id"
			 . " LEFT JOIN dtb_products dp ON dpel.product_id = dp.product_id "
			 . " WHERE dcph.customer_id = ?"
			 . " ORDER BY point_date DESC"
		;
		return $objQuery->getAll($sql, array($customer_id));
	}

	/**
	 * 顧客定期商品購入回数を取得
	 */
	public function getCustomerPurchaseCount($customer_id) {
		$objQuery = SC_Query_Ex::getSingletonInstance();
		$sql = " SELECT COUNT(DISTINCT do2.order_id) FROM dtb_order do2 WHERE do2.del_flg = 0 AND do2.status = 5 AND do2.teiki_id IS NOT NULL AND do2.customer_id = ?";
		return $objQuery->getOne($sql, array($customer_id));
	}

	/**
	 * 対象顧客の最短次回お届け予定日を取得
	 */
	public function getShortestNextDate($customer_id) {
		$objQuery = SC_Query_Ex::getSingletonInstance();
		$arrResults = [];
		// 現時点の最短お届け予定日を取得
		$objPurchase = new SC_Helper_Purchase_Ex();
		$tomorrow = date("Y-m-d 00:00:00", strtotime("+1 day"));	// 翌日起点
		$shipping_date = $objPurchase->lfGetHolidayOfShippingDate(array("create_date" => $tomorrow));
		$today = date("Y-m-d 00:00:00");

		// 白データ存在確認
		$sql = "SELECT do2.order_id FROM dtb_order do2 INNER JOIN dtb_shipping ds ON do2.order_id = ds.order_id WHERE do2.customer_id = ? AND do2.status IN (1,9000,9001,9002) AND do2.teiki_id IS NOT NULL AND ds.shipping_date > ? ORDER BY ds.shipping_date LIMIT 1";
		$order_id = $objQuery->getOne($sql, array($customer_id, $shipping_date));
		if (empty($order_id)) {
			// 白データの最短がない場合は定期の最短を取得
			$sql = "SELECT dt.next_shipping_date FROM dtb_teiki dt WHERE dt.customer_id = ? AND dt.del_flg = 0 AND dt.status <> 3 AND dt.is_onetime = 0 AND dt.next_shipping_date >= ? ORDER BY dt.next_shipping_date";
			$next_shipping_date = $objQuery->getOne($sql, array($customer_id, $today));

			// 定期情報取得
			$sql = " SELECT dt.teiki_id, dt.next_shipping_date, (dp.name_official || func_get_teiki_product_option(dt.teiki_id, dt.detail_id)) AS product_name,"
				 . " func_get_course_detail(dt.course_id, dt.product_id, func_get_teiki_course_count(dt.teiki_id, dt.detail_id, dt.brand_id, dt.course_id), 2) AS price, dt.quantity, mt.name AS tax_rate, dpc.charge"
				 . " FROM dtb_teiki dt"
				 . " INNER JOIN dtb_products dp ON dt.product_id = dp.product_id INNER JOIN mtb_taxrule mt ON dp.tax_status = mt.id"
				 . " INNER JOIN dtb_customer dc ON dt.customer_id = dc.customer_id LEFT JOIN dtb_payment_charge dpc ON dt.brand_id = dpc.brand_id AND dc.payment_id = dpc.payment_id"
				 . " WHERE dt.customer_id = ? AND dt.next_shipping_date = ? AND dt.del_flg = 0 AND dt.status <> 3"
				 . " ORDER BY dt.detail_id"
			;
			$arrResults = $objQuery->getAll($sql, array($customer_id, $next_shipping_date));
		} else {
			// 白データあり
			$sql = " SELECT do2.order_id, do2.subtotal AS total, ds.shipping_date, (dp.name_official || func_get_order_product_option(dod.order_id, dod.order_detail_id)) AS product_name, dod.price, dod.quantity, do2.payment_total, do2.charge, do2.discount, do2.payment_id"
				 . " FROM dtb_order do2"
				 . " INNER JOIN dtb_shipping ds ON do2.order_id = ds.order_id INNER JOIN dtb_order_detail dod ON do2.order_id = dod.order_id INNER JOIN dtb_products dp ON dod.product_id = dp.product_id "
				 . " WHERE do2.order_id = ?"
				 . " ORDER BY dod.order_detail_id"
			;
			$arrResults = $objQuery->getAll($sql, array($order_id));
		}
		return $arrResults;
	}

	/**
	 * 定期用済みポイント値引き取得
	 */
	public function getDiscountPoint($customer_id) {
		$objQuery = SC_Query_Ex::getSingletonInstance();
		return $objQuery->getOne("SELECT point FROM dtb_customer_point_history WHERE customer_id = ? AND point_category = 4 AND is_purchase = 0", array($customer_id));
	}
	/**
	 * オンライン決済切り替えポイント付与確認
	 */
	public function chkOnlinePaymentChangePoint($customer_id) {
		$objQuery = SC_Query_Ex::getSingletonInstance();
		return $objQuery->getOne("SELECT count(*) FROM dtb_customer_point_history WHERE customer_id = ? AND point_category = 5", array($customer_id));
	}

	/**
	 * ポイント登録
	 *
	 * @param  array   $arr	ポイント登録情報
	 * @return void
	 */
	function insertPoint($arr) {
		$objQuery = SC_Query_Ex::getSingletonInstance();
		$arr['id'] = $objQuery->nextVal('dtb_customer_point_history_id');
		$arr['point_date'] = date("Y-m-d 00:00:00");
		$objQuery->insert("dtb_customer_point_history", $arr);
		return $arr['id'];
	}
	/**
	 * ポイント失効日更新
	 * 
	 * @param integer   $customer_id
	 * @return void
	 */
	function updateLostDate($customer_id) {
		$objQuery = SC_Query_Ex::getSingletonInstance();
		$arr = array();
		$arr2 = array();
		$lost_date = date("Y-m-d 00:00:00", strtotime("+6 month")); // 半年後
		$arr["point_lost_date"] = $lost_date;
		$arr["update_date"] = "CURRENT_TIMESTAMP";
		$objQuery->update("dtb_customer", $arr, "customer_id = ?", array($customer_id));

		// 履歴の方も失効日更新しておく
		$arr2["lost_date"] = $lost_date;
		$objQuery->update("dtb_customer_point_history", $arr2, "customer_id = ? AND is_fugo = 0", array($customer_id));
	}
}
