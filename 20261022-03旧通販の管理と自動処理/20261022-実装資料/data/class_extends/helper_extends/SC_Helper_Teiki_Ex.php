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

/**
 * 定期関連のヘルパークラス.
 *
 * @package Helper
 * @author Kentaro Ohkouchi
 * @version $Id$
 */
class SC_Helper_Teiki_Ex
{
	/*
	* メンバ変数の定義
	*/
	/** @var integer ブランドID */
	protected $brand_id;

	// インスタンス
	function __construct($brand_id = null) {
		if (empty($brand_id)) {
			$this->brand_id = $_SESSION["brand_id"];
		} else {
			$this->brand_id = $brand_id;
		}
	}

	/**
	 * 定期解約処理
	 *
	 * @param  array   $row			定期明細データ
	 * @param  integer $cancel_teiki_reason_id	解約理由ID
	 * @return string メッセージ
	 */
	function sfCancelTeiki($row, $cancel_teiki_reason_id) {
		$teiki_id = $row["teiki_id"];
		$detail_id = $row["detail_id"];
		$product_id = $row["product_id"];
		$note = $row["note"];

		$objQuery =& SC_Query_Ex::getSingletonInstance();
		$riyu = $objQuery->getOne("SELECT name FROM mtb_stop_teiki_reason WHERE id = ?", array($cancel_teiki_reason_id));

		if(!empty($note)){
			$note .= PHP_EOL;
		}
		$comment = "[解約]商品ID：".$product_id."を解約に変更。（".$riyu."）";
		$date = new DateTime();
		$note .= $date->format('Y/m/d H:i ') .":". $comment . SC_Helper_Teiki_Ex::sfGetSupportName() . PHP_EOL;

		// 更新
		$objQuery->update("dtb_teiki", array("note" => $note, "update_date" => "current_timestamp"), "teiki_id = ? AND brand_id = ?", array($teiki_id, $this->brand_id));
		$objQuery->update("dtb_teiki", array("status" => 3, "cancel_teiki_reason_id" => $cancel_teiki_reason_id, "update_date" => "current_timestamp", "cancel_date" => "current_timestamp"), "teiki_id = ? AND detail_id = ? AND brand_id = ? AND status <> 3", array($teiki_id, $detail_id, $this->brand_id));

		$message = "商品ID:".$product_id."を解約に変更しました。";
		return $message;
	}

	/**
	 * 解約理由変更処理
	 *
	 * @param  array   $row			定期明細データ
	 * @param  integer $cancel_teiki_reason_id	解約理由ID
	 * @return string メッセージ
	 */
	function sfCancelReasonTeiki($row, $cancel_teiki_reason_id) {
		$objQuery =& SC_Query_Ex::getSingletonInstance();
		$teiki_id = $row["teiki_id"];
		$detail_id = $row["detail_id"];
		$product_id = $row["product_id"];
		$note = $row["note"];
		$riyu = $objQuery->getOne("SELECT name FROM mtb_stop_teiki_reason WHERE id = ?", array($cancel_teiki_reason_id));

		if(!empty($note)){
			$note .= PHP_EOL;
		}
		$comment = "[解約]商品ID：".$product_id."の解約理由を".$riyu."に変更";
		$date = new DateTime();
		$note .= $date->format('Y/m/d H:i ') .":". $comment . SC_Helper_Teiki_Ex::sfGetSupportName() . PHP_EOL;

		// 更新
		$objQuery->update("dtb_teiki", array("note" => $note, "update_date" => "current_timestamp"), "teiki_id = ? AND brand_id = ?", array($teiki_id, $this->brand_id));
		$objQuery->update("dtb_teiki", array("cancel_teiki_reason_id" => $cancel_teiki_reason_id, "update_date" => "current_timestamp"), "teiki_id = ? AND detail_id = ? AND brand_id = ? AND status <> 3", array($teiki_id, $detail_id, $this->brand_id));

		$message = "商品ID:".$product_id."の解約理由を".$riyu."に変更しました。";
		return $message;
	}

	/**
	 * 定期休止処理
	 *
	 * @param  array   $row			定期明細データ
	 * @return string メッセージ
	 */
	function sfPeddingTeiki($row) {
		$teiki_id = $row["teiki_id"];
		$detail_id = $row["detail_id"];
		$product_id = $row["product_id"];
		$note = $row["note"];

		if(!empty($note)){
			$note .= PHP_EOL;
		}
		$comment = "[休止]商品ID：".$product_id."を休止に変更。";
		$date = new DateTime();
		$note .= $date->format('Y/m/d H:i ') .":". $comment . SC_Helper_Teiki_Ex::sfGetSupportName() . PHP_EOL;

		// 更新
		$objQuery =& SC_Query_Ex::getSingletonInstance();
		$objQuery->update("dtb_teiki", array("note" => $note, "update_date" => "current_timestamp"), "teiki_id = ? AND brand_id = ?", array($teiki_id, $this->brand_id));
		$objQuery->update("dtb_teiki", array("status" => 2, "update_date" => "current_timestamp"), "teiki_id = ? AND detail_id = ? AND brand_id = ?", array($teiki_id, $detail_id, $this->brand_id));

		$message = "商品ID:".$product_id."を休止に変更しました。";
		return $message;
	}

	/**
	 * 定期商品コースの追加
	 *
	 * @param  integer $teiki_id	定期ID
	 * @param  integer $course_id	コースID
	 * @param  boolean $is_onetime	次回のみフラグ
	 * @return string メッセージ
	 */
	function sfAddCourseTeiki($teiki_id, $course_id, $arrParams, $is_onetime = false) {
		$objPurchase = new SC_Helper_Purchase_Ex();
		$objQuery =& SC_Query_Ex::getSingletonInstance();
		$yesterday = date("Y-m-d 00:00:00", strtotime("+1 day"));

		if(empty($course_id)){
			return "エラー：コースIDがNULLです";
		}
		$arrTeiki = $objQuery->getRow("*","dtb_teiki", "teiki_id = ? AND brand_id = ?", array($teiki_id, $this->brand_id));
		$note = $arrTeiki["note"];
		$upd_next_shipping_date = $objQuery->getOne("SELECT next_shipping_date FROM dtb_teiki WHERE teiki_id = ? AND brand_id = ? AND status <> 3 ORDER BY next_shipping_date LIMIT 1", array($teiki_id, $this->brand_id));

		// コース名を取得
		$course_name = $objQuery->getOne("SELECT name FROM dtb_course WHERE id = ? AND brand_id = ?", array($course_id, $this->brand_id));

		// コース明細情報を取得
		$arrCourse = $objQuery->select("*", "dtb_course_detail", "course_id = ? AND order_count = 1", array($course_id));
		foreach($arrCourse AS $data) {
			// コース明細情報
			$product_id = $data["product_id"];
			$interval = $data["interval"];
			$interval_type = $data["interval_type"];
			$quantity = $data["quantity"];
			$is_exist = 0;
			$detail_id = null;
			// お届け間隔設定が内意場合は強制的に次回のみと判定する
			if (empty($interval)) {
				$is_onetime = true;
			}
			if (!$is_onetime) {
				// 商品IDから定期存在確認
				$detail_id = $objQuery->getOne("SELECT detail_id FROM dtb_teiki WHERE teiki_id = ? AND product_id = ? AND course_id = ? AND brand_id = ? AND status = 1", array($teiki_id, $product_id, $course_id, $this->brand_id));
				// 同一商品の継続中定期存在確認
				$is_exist = $objQuery->getOne("SELECT product_id FROM dtb_teiki WHERE teiki_id = ? AND product_id = ? AND brand_id = ? AND status = 1", array($teiki_id, $product_id, $this->brand_id));
			}
			if (empty($detail_id) && empty($is_exist)) {
				$arrVal = array();
				$arrVal["teiki_id"] = $teiki_id;
				$arrVal["detail_id"] = $objQuery->nextVal('dtb_teiki_detail_id_seq');
				$arrVal["customer_id"] = $arrTeiki["customer_id"];
				$arrVal["product_id"] = $product_id;
				$arrVal["quantity"] = $quantity;
				$arrVal["deliv_id"] = $arrTeiki["deliv_id"];
				$arrVal["deliv_time_id"] = $arrTeiki["deliv_time_id"];
				$arrVal["interval"] = $interval;
				$arrVal["interval_type"] = $interval_type;
				$arrVal["status"] = 1;
				$arrVal["del_flg"] = 0;
				$arrVal["brand_id"] = $this->brand_id;
				$arrVal["course_id"] = $course_id;
				$arrVal["is_onetime"] = $is_onetime ? 1:0;
				$arrVal["create_date"] = "CURRENT_TIMESTAMP";

				if (empty($upd_next_shipping_date)) {
					// 継続中の定期がない場合は最短お届け日を取得
					$shipping_date = $objPurchase->lfGetHolidayOfShippingDate($yesterday);
					$arrVal["next_shipping_date"] = $shipping_date;
				} else {
					// 継続定期の最短お届け予定日に設定
					$arrVal["next_shipping_date"] = $upd_next_shipping_date;
				}

				// 新規追加
				$objQuery->insert("dtb_teiki", $arrVal);

				// 履歴情報を追記
				if(!empty($note)){
					$note .= PHP_EOL;
				}
				$comment = "コースID：".$course_id." / 商品ID：".$product_id."を定期情報に追加。";
				$date = new DateTime();
				$note .= $date->format('Y/m/d H:i ') .":". $comment . SC_Helper_Teiki_Ex::sfGetSupportName() . PHP_EOL;

				// 商品オプション情報を取得
				$arrOption = SC_Product_Ex::getProductOptionDetail($product_id);
				if (isset($arrParams["teiki_add_option_id_" . $arrOption[0]["option_id"]])) {
					foreach($arrOption as $option) {
						if (isset($arrParams["teiki_add_option_id_" . $option["option_id"]])) {
							if ($arrParams["teiki_add_option_id_" . $option["option_id"]] == $option["option_detail_id"]) {
								$arrOptionVal = array();
								$arrOptionVal["teiki_id"] = $teiki_id;
								$arrOptionVal["teiki_detail_id"] = $arrVal["detail_id"];
								$arrOptionVal["option_id"] = $option["option_id"];
								$arrOptionVal["option_detail_id"] = $option["option_detail_id"];
								$arrOptionVal["create_date"] = "CURRENT_TIMESTAMP";
								// 新規追加
								$objQuery->insert("dtb_teiki_option", $arrOptionVal);
							}
						}
					}

					// 選択香り
					$select_flavor_id = $arrParams["teiki_add_option_id_" . $arrOption[0]["option_id"]];
					// 容器情報ありの商品カテゴリかを取得
					$category_id = $objQuery->getOne("SELECT dp.category_id FROM dtb_products dp WHERE dp.product_id = ? AND EXISTS (SELECT NULL FROM dtb_category_container dcc WHERE dp.category_id = dcc.category_id)", array($product_id));
					if (!empty($category_id)) {
						// 容器情報あり
						$objContainer = new SC_Helper_ProductContainer_Ex();
						$arrLastShipFlavor = $objContainer->fnGetContainerFlavor($arrTeiki["customer_id"], $category_id);
						if (in_array((int)$select_flavor_id, $arrLastShipFlavor, true)) {
							// 最終受注と同じ香りの場合はレフィル
							$arrTeikiContainerIns = array();
							$arrTeikiContainerIns["teiki_id"] = $teiki_id;
							$arrTeikiContainerIns["detail_id"] = $arrVal["detail_id"];
							$arrTeikiContainerIns["container_id"] = 2;	// レフィル
							$arrTeikiContainerIns["quantity"] = $quantity;
							// 新規追加
							$objQuery->insert("dtb_teiki_container_quantity", $arrTeikiContainerIns);
						}
						else {
							// 新容器に強制変換
							$arrTeikiContainerIns = array();
							$arrTeikiContainerIns["teiki_id"] = $teiki_id;
							$arrTeikiContainerIns["detail_id"] = $arrVal["detail_id"];
							$arrTeikiContainerIns["container_id"] = 1;	// 新容器
							$arrTeikiContainerIns["quantity"] = 1;
							// 新規追加
							$objQuery->insert("dtb_teiki_container_quantity", $arrTeikiContainerIns);

							// 数量あまり確認
							if (($quantity - 1) > 0) {
								// 残りはレフィル
								$arrTeikiContainerIns["container_id"] = 2;	// レフィル
								$arrTeikiContainerIns["quantity"] = ($quantity - 1);
								// 新規追加
								$objQuery->insert("dtb_teiki_container_quantity", $arrTeikiContainerIns);
							}
						}
					}
				}
			} else if (!empty($is_exist)) {
				$message = "定期継続中の同一の商品があるため、追加できません。";
				return $message;
			} else if (!empty($detail_id)) {
				$status = $objQuery->getOne("SELECT status FROM dtb_teiki WHERE detail_id = ?", array($detail_id));
				if ($status == 1) {
					// 継続中
					$message = "定期継続中の同一商品、コースがあるため、追加できません。";
					return $message;
				} else {
					// 定期再登録
					$arrVal["status"] = 1;
					// 最短お届け日を取得
					$shipping_date = $objPurchase->lfGetHolidayOfShippingDate($yesterday);
					$arrVal["next_shipping_date"] = $shipping_date;
					$arrVal["update_date"] = "current_timestamp";
					// 定期情報を更新
					$objQuery->update("dtb_teiki", $arrVal, "teiki_id = ? AND detail_id = ? AND brand_id = ?", array($teiki_id, $detail_id, $this->brand_id));

					$comment = "商品ID：".$product_id."を定期情報に追加。";
					$date = new DateTime();
					$note .= $date->format('Y/m/d H:i ') .":". $comment . SC_Helper_Teiki_Ex::sfGetSupportName() . PHP_EOL;

					// 定期履歴を更新
					$objQuery->update("dtb_teiki", array("note" => $note, "update_date" => "current_timestamp"), "teiki_id = ? AND brand_id = ?", array($teiki_id, $this->brand_id));
					$message = "「".$course_name."」の契約状況を通常に変更しました。";
					return $message;
				}
			}
		}
		
		// 定期履歴を更新
		$objQuery->update("dtb_teiki", array("note" => $note, "update_date" => "current_timestamp"), "teiki_id = ? AND brand_id = ?", array($teiki_id, $this->brand_id));
		$message = "「".$course_name."」の商品を定期に追加しました。";
		return $message;
	}

	/**
	 * 定期商品コースの変更
	 *
	 * @param  integer $teiki_id	定期ID
	 * @param  integer $detail_id	明細ID
	 * @param  integer $course_id	コースID
	 * @param  integer $product_id	商品ID
	 * @return string メッセージ
	 */
	function sfChangeCourseTeiki($teiki_id, $detail_id, $course_id, $product_id, $arrParams) {
		$objQuery =& SC_Query_Ex::getSingletonInstance();
		if(empty($course_id)){
			return "エラー：コースIDがNULLです";
		}
		if(empty($product_id)){
			return "エラー：商品IDがNULLです";
		}
		$arrTeiki = $objQuery->getRow("*","dtb_teiki", "teiki_id = ? AND detail_id = ? AND brand_id = ?", array($teiki_id, $detail_id, $this->brand_id));
		$note = $arrTeiki["note"];
		$date = new DateTime();

		// 変更があるか確認
		$is_course_change = true;
		if ($arrTeiki["course_id"] == $course_id && $arrTeiki["product_id"] == $product_id) {
			// return "エラー：変更内容がありません。";
			$is_course_change = false;
		}

		$msg = "";
		if ($is_course_change) {
			if(!empty($note)){
				$note .= PHP_EOL;
			}
			$comment = "コースID：".$arrTeiki["course_id"]."から".$course_id."に変更";
			$note .= $date->format('Y/m/d H:i ') .":". $comment . SC_Helper_Teiki_Ex::sfGetSupportName() . PHP_EOL;
			$comment = "商品ID：".$arrTeiki["product_id"]."から".$product_id."に変更";
			$note .= $date->format('Y/m/d H:i ') .":". $comment . SC_Helper_Teiki_Ex::sfGetSupportName() . PHP_EOL;

			// 定期更新
			$objQuery->update("dtb_teiki", array("course_id" => $course_id, "product_id" => $product_id, "update_date" => "current_timestamp"), "teiki_id = ? AND detail_id = ? AND brand_id = ? AND status <> 3", array($teiki_id, $detail_id, $this->brand_id));
			$objQuery->update("dtb_teiki", array("note" => $note, "update_date" => "current_timestamp"), "teiki_id = ? AND brand_id = ?", array($teiki_id, $this->brand_id));
		}

		// 一旦オプション情報を削除
		$objQuery->delete("dtb_teiki_option", "teiki_id = ? AND teiki_detail_id = ?", array($teiki_id, $detail_id));

		// 商品オプション情報を取得
		$is_option_change = false;
		$option_name = "";
		$arrProductOption = SC_Product_Ex::getProductOption($product_id);
		foreach($arrProductOption as $option) {
			$arrVal = array();
			$arrVal["teiki_id"] = $teiki_id;
			$arrVal["teiki_detail_id"] = $detail_id;
			$arrVal["option_id"] = $option["option_id"];
			$arrVal["option_detail_id"] = $arrParams["teiki_option_id_" . $product_id.$teiki_id.$detail_id . "_" . $option["option_id"]];
			$arrVal["create_date"] = "CURRENT_TIMESTAMP";
			// オプション情報を登録
			$objQuery->insert("dtb_teiki_option", $arrVal);

			// 容器情報確認
			$cnt = $objQuery->count("dtb_teiki_container_quantity", "teiki_id = ? AND detail_id = ? AND container_id > 0", array($teiki_id, $detail_id));
			if ($cnt > 0) {
				// 商品カテゴリ取得
				$category_id = $objQuery->getOne("SELECT category_id FROM dtb_products WHERE product_id = ?", array($arrTeiki["product_id"]));
				// ボトル最終出荷の情報を取得
				$objContainer = new SC_Helper_ProductContainer_Ex();
				$arrLastPurchaseList = $objContainer->fnGetContainerFlavor($arrTeiki["customer_id"], $category_id);
				if (in_array($arrParams["teiki_option_id_" . $product_id.$teiki_id.$detail_id . "_" . $option["option_id"]], $arrLastPurchaseList)) {
					// 同じ香りの場合はALLレフィル
					$objQuery->delete("dtb_teiki_container_quantity", "teiki_id = ? AND detail_id = ?", array($teiki_id, $detail_id));
					$arrContainer = [];
					$arrContainer["teiki_id"] = $teiki_id;
					$arrContainer["detail_id"] = $detail_id;
					$arrContainer["container_id"] = 2;
					$arrContainer["quantity"] = $arrTeiki["quantity"];
					$objQuery->insert("dtb_teiki_container_quantity", $arrContainer);
				} else {
					// 別の香りの場合は組み合わせ
					$objQuery->delete("dtb_teiki_container_quantity", "teiki_id = ? AND detail_id = ?", array($teiki_id, $detail_id));
					if ($arrTeiki["quantity"] > 1) {
						// 複数
						$arrContainer = [];
						$arrContainer["teiki_id"] = $teiki_id;
						$arrContainer["detail_id"] = $detail_id;
						$arrContainer["container_id"] = 1;
						$arrContainer["quantity"] = 1;
						$objQuery->insert("dtb_teiki_container_quantity", $arrContainer);

						// 残り数量をレフィル
						$arrContainer["container_id"] = 2;
						$arrContainer["quantity"] = $arrTeiki["quantity"] - 1;
						$objQuery->insert("dtb_teiki_container_quantity", $arrContainer);
					} else {
						// 単品
						$arrContainer = [];
						$arrContainer["teiki_id"] = $teiki_id;
						$arrContainer["detail_id"] = $detail_id;
						$arrContainer["container_id"] = 1;
						$arrContainer["quantity"] = $arrTeiki["quantity"];
						$objQuery->insert("dtb_teiki_container_quantity", $arrContainer);
					}
				}

			}

			// 履歴情報を残す
			$arrOptionDetail = SC_Product_Ex::getProductOptionRow($arrVal["option_id"], $arrVal["option_detail_id"]);
			$arrTeiki = $objQuery->getRow("*","dtb_teiki", "teiki_id = ? AND detail_id = ? AND brand_id = ?", array($teiki_id, $detail_id, $this->brand_id));
			$note = $arrTeiki["note"];
			if(!empty($note)){
				$note .= PHP_EOL;
			}
			$comment = "商品ID：".$arrTeiki["product_id"]."の".$option["option_name"]."を「".$arrOptionDetail["option_detail_name"]."」に変更";
			$note .= $date->format('Y/m/d H:i ') .":". $comment . SC_Helper_Teiki_Ex::sfGetSupportName() . PHP_EOL;
			$objQuery->update("dtb_teiki", array("note" => $note, "update_date" => "current_timestamp"), "teiki_id = ? AND brand_id = ?", array($teiki_id, $this->brand_id));

			if (strlen($option_name) > 0) {
				$option_name .= "/";
			}
			$option_name .= $arrOptionDetail["option_detail_name"];
			$is_option_change = true;
		}

		if ($is_course_change || $is_option_change) {
			// コース名を取得
			$course_name = $objQuery->getOne("SELECT name FROM dtb_course WHERE id = ? AND brand_id = ?", array($course_id, $this->brand_id));
			// 商品名を取得
			$product_name = $objQuery->getOne("SELECT name FROM dtb_products WHERE product_id = ? AND brand_id = ?", array($product_id, $this->brand_id));

			$msg = '定期商品情報を変更しました。\\n\\n';
			$msg .= 'コース：'.$course_name.'\\n';
			if ($is_option_change) {
				$msg .= '商品名：'.$product_name."（".$option_name."）";
			} else {
				$msg .= '商品名：'.$product_name;
			}
		} else {
			$msg = "エラー：変更内容がありません。";
		}

		return $msg;
	}

	/**
	 * 商品数量の変更
	 *
	 * @param  array   $row			定期明細情報
	 * @param  integer $quantity	数量
	 * @return string メッセージ
	 */
	function sfQuantityTeiki($row, $quantity) {
		if(empty($quantity)){
			return "エラー：数量がNULLです";
		}
		$teiki_id = $row["teiki_id"];
		$detail_id = $row["detail_id"];
		$product_id = $row["product_id"];
		$note = $row["note"];

		if(!empty($note)){
			$note .= PHP_EOL;
		}
		$comment = "商品ID：".$product_id."の数量を".$quantity."に設定";
		$date = new DateTime();
		$note .= $date->format('Y/m/d H:i ') .":". $comment . SC_Helper_Teiki_Ex::sfGetSupportName() . PHP_EOL;

		// 更新
		$objQuery =& SC_Query_Ex::getSingletonInstance();
		$objQuery->update("dtb_teiki", array("note" => $note, "update_date" => "current_timestamp"), "teiki_id = ? AND brand_id = ?", array($teiki_id, $this->brand_id));
		$objQuery->update("dtb_teiki", array("quantity" => $quantity, "update_date" => "current_timestamp"), "teiki_id = ? AND detail_id = ? AND brand_id = ? AND status <> 3", array($teiki_id, $detail_id, $this->brand_id));

		$message = "商品ID:".$product_id."の数量を".$quantity."に設定しました。";
		return $message;
	}

	/**
	 * 容器別数量の変更
	 *
	 * @param  integer $teiki_id
	 * @param  integer $detail_id
	 * @param  array   $arrContainerQuantity
	 * @return string メッセージ
	 */
	public function sfContainerQuantityTeiki($teiki_id, $detail_id, $arrContainerQuantity) {
		$objQuery =& SC_Query_Ex::getSingletonInstance();
		$objQuery->begin();

		// 容器＆数量テーブルを削除
		$objQuery->delete("dtb_teiki_container_quantity", "teiki_id = ? AND detail_id = ?", array($teiki_id, $detail_id));
		// 容器対象分ループ
		$quantity_total = 0;
		foreach($arrContainerQuantity as $container_id => $quantity) {
			if (!empty($container_id) && $quantity > 0) {
				$arrContainer = [];
				$arrContainer["teiki_id"] = $teiki_id;
				$arrContainer["detail_id"] = $detail_id;
				$arrContainer["container_id"] = $container_id;
				$arrContainer["quantity"] = $quantity;
				$objQuery->insert("dtb_teiki_container_quantity", $arrContainer);
			}
			$quantity_total += $quantity;
		}
		// 全体数量を更新
		$arrTeiki = $this->getDetail($teiki_id, $detail_id, true);
		$message = $this->sfQuantityTeiki($arrTeiki, $quantity_total);
		if (strpos($message, "NULL") !== false) {
			$objQuery->rollback();
		} else {
			$objQuery->commit();
		}
		
		return $message;
	}

	/**
	 * お支払い方法の変更
	 *
	 * @param  integer $customer_id	顧客ID
	 * @param  integer $payment_id	支払方法ID
	 * @return string メッセージ
	 */
	function sfPaymentTeiki($customer_id, $payment_id) {
		if(empty($customer_id)){
			return "エラー：顧客IDがNULLです";
		}
		if(empty($payment_id)){
			return "エラー：支払方法IDが空です";
		}
		$objQuery =& SC_Query_Ex::getSingletonInstance();
		$arrTeiki = $objQuery->getRow("*", "dtb_teiki", "customer_id = ? AND brand_id = ?", array($customer_id, $this->brand_id));
		$payment_method = $objQuery->getOne("select payment_method from dtb_payment where payment_id = ?", array($payment_id));
		
		$teiki_id = $arrTeiki["teiki_id"];
		$note = $arrTeiki["note"];
		if(!empty($note)){
			$note .= PHP_EOL;
		}
		$comment = "【定期】支払方法を「".$payment_method."」に設定";
		$date = new DateTime();
		$note .= date("Y/m/d H:i ") .":". $comment . SC_Helper_Teiki_Ex::sfGetSupportName() . PHP_EOL;

		// 定期
		$sqlVal['note'] = $note;
		$sqlVal['update_date'] = "current_timestamp";
		if ($payment_id == 4) {
			$sqlVal['deliv_id'] = 2;
		}
		$objQuery->update("dtb_teiki", $sqlVal, "teiki_id = ? AND brand_id = ?", array($teiki_id, $this->brand_id));
		// 顧客
		$objQuery->update("dtb_customer", array("payment_id" => $payment_id, "payment_method" => $payment_method, "update_date" => "current_timestamp"), "customer_id = ? AND brand_id = ?", array($customer_id, $this->brand_id));

		$message = "【定期】支払方法を「".$payment_method."」に設定しました。";
		if ($payment_id == 4) {
			$message .= "\\n【定期】配送方法を「ゆうパック」に設定しました。";
		}
		return $message;
	}

	/**
	 * 次回お届け日の変更
	 *
	 * @param  array   $row			定期明細情報
	 * @param  string  $next_shipping_date	次回お届け日
	 * @return string メッセージ
	 */
	function sfRestartTeiki($row, $next_shipping_date) {
		if(empty($next_shipping_date)){
			return "エラー：次回お届け日がNULLです";
		}
		$teiki_id = $row["teiki_id"];
		$detail_id = $row["detail_id"];
		$product_id = $row["product_id"];
		$note = $row["note"];

		if(!empty($note)){
			$note .= PHP_EOL;
		}
		$comment = "商品ID：".$product_id."の次回お届け日を".$next_shipping_date."に設定";
		$date = new DateTime();
		$note .= $date->format('Y/m/d H:i ') .":". $comment . SC_Helper_Teiki_Ex::sfGetSupportName() . PHP_EOL;

		// 更新
		$objQuery =& SC_Query_Ex::getSingletonInstance();
		$objQuery->update("dtb_teiki", array("note" => $note, "update_date" => "current_timestamp"), "teiki_id = ? AND brand_id = ?", array($teiki_id, $this->brand_id));
		$objQuery->update("dtb_teiki", array("status" => 1, "next_shipping_date" => $next_shipping_date, "update_date" => "current_timestamp"), "teiki_id = ? AND detail_id = ? AND brand_id = ? AND status <> 3", array($teiki_id, $detail_id, $this->brand_id));

		$message = "商品ID:".$product_id."の次回お届け日を".$next_shipping_date."に設定しました。";
		return $message;
	}

	/**
	 * お届け間隔の変更
	 *
	 * @param  array   $row			定期明細情報
	 * @param  integer $interval	お届け間隔（値）
	 * @param  integer $interval_type	お届け間隔（単位）
	 * @return string メッセージ
	 */
	function sfIntervalTeiki($row, $interval, $interval_type) {
		if(empty($interval)){
			return "エラー：お届け間隔がNULLです";
		}
		if($interval_type == null){
			return "エラー：お届け間隔がNULLです";
		}
		$teiki_id = $row["teiki_id"];
		$detail_id = $row["detail_id"];
		$product_id = $row["product_id"];
		$note = $row["note"];

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

		if(!empty($note)){
			$note .= PHP_EOL;
		}
		$comment = "商品ID：".$product_id."のお届け間隔を".$interval.$str_interval_type."に設定";
		$date = new DateTime();
		$note .= $date->format('Y/m/d H:i ') .":". $comment . SC_Helper_Teiki_Ex::sfGetSupportName() . PHP_EOL;

		// 更新
		$objQuery =& SC_Query_Ex::getSingletonInstance();
		$objQuery->update("dtb_teiki", array("note" => $note, "update_date" => "current_timestamp"), "teiki_id = ? AND brand_id = ?", array($teiki_id, $this->brand_id));
		$objQuery->update("dtb_teiki", array("interval" => $interval, "interval_type" => $interval_type, "update_date" => "current_timestamp"), "teiki_id = ? AND detail_id = ? AND brand_id = ? AND status <> 3", array($teiki_id, $detail_id, $this->brand_id));

		$message = "商品ID:".$product_id."のお届け間隔を".$interval.$str_interval_type."に設定しました。";
		return $message;
	}

	/**
	 * 配送方法の変更
	 *
	 * @param  array   $row			定期明細情報
	 * @param  integer $deliv_id	配送方法ID
	 * @return string メッセージ
	 */
	function sfDelivTeiki($row, $deliv_id, $is_manual = 0) {
		if(empty($deliv_id)){
			return "エラー：配送方法がNULLです";
		}
		$objQuery =& SC_Query_Ex::getSingletonInstance();
		$teiki_id = $row["teiki_id"];
		$detail_id = $row["detail_id"];
		$product_id = $row["product_id"];
		$note = $row["note"];
		$deliv_name = $objQuery->getOne("SELECT service_name from dtb_deliv where deliv_id = ?", array($deliv_id));

		if(!empty($note)){
			$note .= PHP_EOL;
		}
		$comment = "商品ID：".$product_id."の配送方法を".$deliv_name."に設定";
		$date = new DateTime();
		$note .= $date->format('Y/m/d H:i ') .":". $comment . SC_Helper_Teiki_Ex::sfGetSupportName() . PHP_EOL;

		// 更新
		$objQuery->update("dtb_teiki", array("note" => $note, "update_date" => "current_timestamp"), "teiki_id = ? AND brand_id = ?", array($teiki_id, $this->brand_id));
		$objQuery->update("dtb_teiki", array("deliv_id" => $deliv_id, "is_deliv_manual" => $is_manual, "update_date" => "current_timestamp"), "teiki_id = ? AND detail_id = ? AND brand_id = ? AND status <> 3", array($teiki_id, $detail_id, $this->brand_id));

		$message = "商品ID:".$product_id."の配送方法を".$deliv_name."に設定しました。";
		return $message;
	}

	/**
	 * お届け時間帯の変更
	 *
	 * @param  array   $row			定期明細情報
	 * @param  integer $time_id		時間帯ID
	 * @return string メッセージ
	 */
	function sfDelivTimeTeiki($row, $deliv_id, $time_id) {
		$objQuery =& SC_Query_Ex::getSingletonInstance();
		$teiki_id = $row["teiki_id"];
		$detail_id = $row["detail_id"];
		$product_id = $row["product_id"];
		$note = $row["note"];
		$deliv_time = $objQuery->getOne("SELECT deliv_time from dtb_delivtime where deliv_id = ? AND time_id = ?", array($deliv_id, $time_id));
		if (empty($deliv_time)) {
			$deliv_time = "指定無し";
		}

		if(!empty($note)){
			$note .= PHP_EOL;
		}
		$comment = "商品ID：".$product_id."のお届け時間帯を".$deliv_time."に設定";
		$date = new DateTime();
		$note .= $date->format('Y/m/d H:i ') .":". $comment . SC_Helper_Teiki_Ex::sfGetSupportName() . PHP_EOL;

		// 更新
		$objQuery->update("dtb_teiki", array("note" => $note, "update_date" => "current_timestamp"), "teiki_id = ? AND brand_id = ?", array($teiki_id, $this->brand_id));
		$objQuery->update("dtb_teiki", array("deliv_time_id" => $time_id, "update_date" => "current_timestamp"), "teiki_id = ? AND detail_id = ? AND brand_id = ? AND status <> 3", array($teiki_id, $detail_id, $this->brand_id));

		$message = "商品ID:".$product_id."のお届け時間帯を".$deliv_time."に設定しました。";
		return $message;
	}

	/**
	 * 置き配設定の変更
	 *
	 * @param  array   $row			定期明細情報
	 * @param  integer $time_id		時間帯ID
	 * @return string メッセージ
	 */
	function sfShippingArrangementTeiki($row, $deliv_id, $shipping_arrangement_id) {
		$objQuery =& SC_Query_Ex::getSingletonInstance();
		$teiki_id = $row["teiki_id"];
		$detail_id = $row["detail_id"];
		$product_id = $row["product_id"];
		$note = $row["note"];
		if (!empty($shipping_arrangement_id)) {
			$arrangement = $objQuery->getOne("SELECT name from mtb_shipping_arrangement where id = ?", array($shipping_arrangement_id));
		} else {
			$arrangement = "指定無し";
		}

		if(!empty($note)){
			$note .= PHP_EOL;
		}
		$comment = "商品ID：".$product_id."の置き配設定を「".$arrangement."」に設定";
		$date = new DateTime();
		$note .= $date->format('Y/m/d H:i ') .":". $comment . SC_Helper_Teiki_Ex::sfGetSupportName() . PHP_EOL;

		// 更新
		$objQuery->update("dtb_teiki", array("note" => $note, "update_date" => "current_timestamp"), "teiki_id = ? AND brand_id = ?", array($teiki_id, $this->brand_id));
		$objQuery->update("dtb_teiki", array("shipping_arrangement_id" => $shipping_arrangement_id, "update_date" => "current_timestamp"), "teiki_id = ? AND detail_id = ? AND brand_id = ? AND status <> 3", array($teiki_id, $detail_id, $this->brand_id));

		$message = "商品ID：".$product_id."の置き配設定を「".$arrangement."」に設定しました。";
		return $message;
	}

	/**
	 * 自動解約フラグの変更
	 *
	 * @param  array   $row			定期明細情報
	 * @param  integer $is_auto_cancel	自動解約フラグ
	 * @return string メッセージ
	 */
	function sfAutoCancelTeiki($row, $is_auto_cancel) {
		$teiki_id = $row["teiki_id"];
		$detail_id = $row["detail_id"];
		$product_id = $row["product_id"];
		$note = $row["note"];

		if(!empty($note)){
			$note .= PHP_EOL;
		}
		if($is_auto_cancel == 0){
			$auto_kaiyaku = "OFF";
		}else if($is_auto_cancel == 1){
			$auto_kaiyaku = "ON";
		}else if($is_auto_cancel == 2){
			$auto_kaiyaku = "自動解約済み";
		}
		$comment = "商品ID：".$product_id."の発送後自動解約フラグを".$auto_kaiyaku."に設定";
		$date = new DateTime();
		$note .= $date->format('Y/m/d H:i ') .":". $comment . SC_Helper_Teiki_Ex::sfGetSupportName() . PHP_EOL;

		// 更新
		$objQuery =& SC_Query_Ex::getSingletonInstance();
		$objQuery->update("dtb_teiki", array("note" => $note, "update_date" => "current_timestamp"), "teiki_id = ? AND brand_id = ?", array($teiki_id, $this->brand_id));
		$objQuery->update("dtb_teiki", array("is_auto_cancel" => $is_auto_cancel, "update_date" => "current_timestamp"), "teiki_id = ? AND detail_id = ? AND brand_id = ? AND status <> 3", array($teiki_id, $detail_id, $this->brand_id));

		$message = "商品ID：".$product_id."の発送後自動解約フラグを".$auto_kaiyaku."に設定しました。";
		return $message;
	}

	/**
	 * 次回値引きの変更
	 *
	 * @param  array   $row			定期明細情報
	 * @param  integer $discount	値引き額
	 * @return string メッセージ
	 */
	function sfDiscountTeiki($row, $discount) {
		// if(empty($discount)){
		// 	return "エラー：値引き額がNULLです";
		// }
		$objQuery =& SC_Query_Ex::getSingletonInstance();
		$teiki_id = $row["teiki_id"];
		$detail_id = $row["detail_id"];
		$product_id = $row["product_id"];
		$note = $row["note"];
		$deliv_name = $objQuery->getOne("SELECT service_name from dtb_deliv where deliv_id = ?", array($deliv_id));

		if(!empty($note)){
			$note .= PHP_EOL;
		}
		$comment = "商品ID：".$product_id."の次回値引きを".$discount."円に設定";
		$date = new DateTime();
		$note .= $date->format('Y/m/d H:i ') .":". $comment . SC_Helper_Teiki_Ex::sfGetSupportName() . PHP_EOL;

		// 更新
		$objQuery->update("dtb_teiki", array("note" => $note, "update_date" => "current_timestamp"), "teiki_id = ? AND brand_id = ?", array($teiki_id, $this->brand_id));
		$objQuery->update("dtb_teiki", array("discount" => $discount, "update_date" => "current_timestamp"), "teiki_id = ? AND detail_id = ? AND brand_id = ? AND status <> 3", array($teiki_id, $detail_id, $this->brand_id));

		$message = "商品ID:".$product_id."の次回値引きを".$discount."に設定しました。";
		return $message;
	}

	/**
	 * 【精の命】プレミアムコース変更
	 * 
	 * @param  array   $row			定期明細情報
	 * @return string メッセージ
	 */
	function sfChangePremium($row) {
		$objCourse = new SC_Helper_Course_Ex();
		$objQuery =& SC_Query_Ex::getSingletonInstance();
		$teiki_id = $row["teiki_id"];
		$detail_id = $row["detail_id"];
		$course_id = $row["course_id"];
		$product_id = $row["product_id"];

		// 引き上げ用コース取得
		// $premium_course_id = $objCourse->getPremiumCourse($this->brand_id, $product_id);
		$premium_course_id = $objCourse->getUpgradeCourse($course_id);

		// 引上げコースに該当商品が存在しているかチェック
		$count = $objQuery->getOne("SELECT COUNT(*) FROM dtb_course_detail WHERE course_id = ? AND product_id = ? AND order_count = 1", array($premium_course_id, $product_id));
		if ($count == 0) {
			// 引上げ対象商品でない場合は処理を抜ける
			return null;
		}

		$note = $objQuery->getOne("SELECT note FROM dtb_teiki WHERE teiki_id = ? AND detail_id = ?", array($teiki_id, $detail_id));
		if(!empty($note)){
			$note .= PHP_EOL;
		}
		$comment = "商品ID:".$product_id."を次回以降縛りコース切替（コースID：".$course_id." → ".$premium_course_id."）";
		$date = new DateTime();
		$note .= $date->format('Y/m/d H:i ') .":". $comment . SC_Helper_Teiki_Ex::sfGetSupportName() . PHP_EOL;

		// 更新
		$objQuery->update("dtb_teiki", array("note" => $note, "update_date" => "current_timestamp"), "teiki_id = ? AND brand_id = ?", array($teiki_id, $this->brand_id));
		$objQuery->update("dtb_teiki", array("course_id" => $premium_course_id, "update_date" => "current_timestamp"), "teiki_id = ? AND detail_id = ? AND brand_id = ? AND status <> 3", array($teiki_id, $detail_id, $this->brand_id));

		$message = "【次回以降】縛りコース切替（コースID：".$course_id." → ".$premium_course_id."）に設定しました。";
		return $message;
	}
	

	/**
	 * 定期一覧
	 *
	 * @param  integer $teiki_id	定期ID
	 * @return array   定期明細の配列
	 */
	public function getTeiki($teiki_id, $is_not_stop = false)
	{
		$objQuery = SC_Query_Ex::getSingletonInstance();
		$col = "*";
		$table = "dtb_teiki";
		$where = "del_flg = 0 AND teiki_id = ? AND brand_id = ?";
		if ($is_not_stop) {
			$where .= " AND status <> 3";
		}
		$objQuery->setOrder("detail_id");
		return $objQuery->select($col, $table, $where, array($teiki_id, $this->brand_id));
	}

	/**
	 * 定期明細一覧
	 *
	 * @param  integer $teiki_id	定期ID
	 * @param  integer $detail_id	明細ID
	 * @return array   定期明細の配列
	 */
	public function getDetail($teiki_id, $detail_id, $is_not_stop = false)
	{
		$objQuery = SC_Query_Ex::getSingletonInstance();
		$col = "*";
		$table = "dtb_teiki";
		$where = "del_flg = 0 AND teiki_id = ? AND detail_id = ? AND brand_id = ?";
		if ($is_not_stop) {
			$where .= " AND status <> 3";
		}
		return $objQuery->getRow($col, $table, $where, array($teiki_id, $detail_id, $this->brand_id));
	}

	/**
	 * 定期明細データ取得
	 *
	 * @param  integer $teiki_id	定期ID
	 * @return array   定期明細の配列
	 */
	public function getTeikiDetail($teiki_id)
	{
		$objQuery = SC_Query_Ex::getSingletonInstance();
		$sql = " SELECT al.*, func_get_course_detail(al.course_id, al.product_id, al.course_count, 2) AS price"
			 . " FROM"
			 . " (	SELECT "
			 . "		 dt.* "
			 . "		,dp.name || func_get_teiki_product_option(dt.teiki_id, dt.detail_id) AS product_name"
			 . "		,dp.name_official || func_get_teiki_product_option(dt.teiki_id, dt.detail_id) AS name_official"
			 . "		,dp.category_id"
			 . "		,dc.category_name"
			 . "		,func_get_teiki_course_commit_num(dt.teiki_id, dt.detail_id, dt.course_id, dt.brand_id) AS commit_num"
			 . "		,COALESCE(dcs.num,0) AS shibari_num"
			 . "		,func_get_teiki_detail_count(dt.teiki_id, dt.detail_id, dt.brand_id) AS order_count"
			 . "		,func_get_teiki_course_count(dt.teiki_id, dt.detail_id, dt.brand_id, dt.course_id) AS course_count"
			 . "		,func_get_category_commit_num(dt.customer_id, dp.category_id, dt.brand_id) AS category_commit_num"
			 . "		,dp.tax_status"
			 . "		,dc2.name AS course_name"
			 . "		,dc2.name_official AS course_name_official"
			 . "		,(SELECT COUNT(*) FROM dtb_order do2 INNER JOIN dtb_order_detail dod ON do2.order_id = dod.order_id WHERE dt.teiki_id = do2.teiki_id AND dt.brand_id = do2.brand_id AND dt.detail_id = dod.teiki_detail_id AND do2.status IN (5,8)) AS commit_count"
			 . "	FROM dtb_teiki dt "
			 . "	INNER JOIN dtb_products dp ON dt.product_id = dp.product_id "
			 . "	INNER JOIN dtb_category dc ON dp.category_id = dc.category_id "
			 . "	INNER JOIN dtb_course dc2 ON dt.course_id = dc2.id"
			 . "	LEFT JOIN dtb_course_shibari dcs ON dt.course_id = dcs.course_id AND dt.product_id = dcs.product_id"
			 . "	WHERE dt.del_flg = 0 AND dt.teiki_id = ? AND dt.brand_id = ?"
			 . " ) al"
			 . " ORDER BY status, next_shipping_date, detail_id"
		;

		return $objQuery->getAll($sql, array($teiki_id, $this->brand_id));
	}

	/**
	 * 定期明細オプションデータ取得
	 *
	 * @param  integer $teiki_id	定期ID
	 * @param  integer $detail_id	明細ID
	 * @return array   定期明細オプションの配列
	 */
	public function getTeikiDetailOption($teiki_id, $detail_id)
	{
		$objQuery = SC_Query_Ex::getSingletonInstance();
		$objQuery->setOrder("option_id");
		return $objQuery->select("*", "dtb_teiki_option", "teiki_id = ? AND teiki_detail_id = ?", array($teiki_id, $detail_id));
	}

	/**
	 * 定期IDを取得
	 *
	 * @param  integer $customer_id
	 * @return integer $teiki_id
	 */
	public function getTeikiIdFromCustomer($customer_id)
	{
		$objQuery = SC_Query_Ex::getSingletonInstance();
		return $objQuery->getOne("SELECT teiki_id FROM dtb_teiki WHERE customer_id = ? ORDER BY teiki_id LIMIT 1", array($customer_id));
	}

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
}
