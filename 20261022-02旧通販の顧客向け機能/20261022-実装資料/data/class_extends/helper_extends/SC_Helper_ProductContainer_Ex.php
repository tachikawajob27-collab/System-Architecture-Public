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
 * 商品容器関連の判定ヘルパークラス
 *
 * @package Helper
 */
class SC_Helper_ProductContainer_Ex
{

	/**
	 * マイページクロスセルで追加した定期明細に容器（ボトル／レフィル）を登録する.
	 *
	 * 同梱（LC_Page_Mypage_Teiki の add_onetime / add_teiki）と
	 * 別便（LC_Page_Mypage_Teiki_Ex::doAddTeikiStandalone）の両方から呼ぶ。
	 * どちらも「新しい定期明細を1件作る」という点で同じなので、判定はここ1箇所に置く。
	 *
	 * 判定は香り変更側（LC_Page_Mypage_TeikiDetail / LC_Page_Mypage_TeikiFragrance）
	 * の考え方を踏襲する。
	 *
	 *   1. 商品カテゴリが dtb_category_container に無ければ容器管理対象外＝何もしない
	 *   2. 香りが「新容器しかない香り」(NEWCONTAINER_FLAVOR_LIST) ならボトル強制
	 *   3. 直近の出荷／受注でボトルを買った香りと同じならレフィル
	 *      （既にその香りのボトルを持っているのでボトルは不要）
	 *   4. それ以外はボトル1本 ＋ 残数ぶんレフィル
	 *
	 * @param int $customer_id
	 * @param int $teiki_id
	 * @param int $detail_id 追加した明細のID
	 * @param int $product_id
	 * @param int|null $option_detail_id 香り
	 * @param int $quantity
	 * @return void
	 */
	public function fnRegisterCrossSellContainer($customer_id, $teiki_id, $detail_id, $product_id, $option_detail_id, $quantity = 1) {
		$objQuery =& SC_Query_Ex::getSingletonInstance();

		$category_id = $objQuery->getOne("SELECT category_id FROM dtb_products WHERE product_id = ?", array($product_id));
		if (empty($category_id)) {
			return;
		}
		// 容器管理対象のカテゴリでなければ容器行は作らない
		$container_cnt = $objQuery->count("dtb_category_container", "category_id = ?", array($category_id));
		if ($container_cnt <= 0) {
			return;
		}

		$quantity = max(1, (int)$quantity);
		$option_detail_id = (int)$option_detail_id;

		// 新容器しかない香り。定数が無い環境では判定をスキップする（既存実装と同じ扱い）
		$arrNewContainerFlavor = (defined('NEWCONTAINER_FLAVOR_LIST') && NEWCONTAINER_FLAVOR_LIST !== '')
			? array_map('intval', explode(',', NEWCONTAINER_FLAVOR_LIST))
			: array();

		$arrLastPurchaseFlavor = $this->fnGetContainerFlavor($customer_id, $category_id);

		if (in_array($option_detail_id, $arrNewContainerFlavor, true)) {
			$need_bottle = true;  // 新容器専用の香りはボトル強制
		} elseif (in_array($option_detail_id, $arrLastPurchaseFlavor, true)) {
			$need_bottle = false; // 同じ香りのボトルを既に持っているのでレフィル
		} else {
			$need_bottle = true;
		}

		GC_Utils_Ex::gfPrintLog(
			"crosssell_container: customer_id={$customer_id} teiki_id={$teiki_id} detail_id={$detail_id} "
			. "product_id={$product_id} option_detail_id={$option_detail_id} "
			. "need_bottle=" . ($need_bottle ? '1' : '0')
			. " last_flavor=" . implode(',', $arrLastPurchaseFlavor)
		);

		$arrContainer = array(
			"teiki_id"  => $teiki_id,
			"detail_id" => $detail_id,
		);

		if (!$need_bottle) {
			$arrContainer["container_id"] = 2; // レフィル
			$arrContainer["quantity"]     = $quantity;
			$objQuery->insert("dtb_teiki_container_quantity", $arrContainer);
			return;
		}

		$arrContainer["container_id"] = 1; // ボトル（新容器）
		$arrContainer["quantity"]     = 1;
		$objQuery->insert("dtb_teiki_container_quantity", $arrContainer);

		if (($quantity - 1) > 0) {
			$arrContainer["container_id"] = 2; // 残数はレフィル
			$arrContainer["quantity"]     = $quantity - 1;
			$objQuery->insert("dtb_teiki_container_quantity", $arrContainer);
		}
	}

	/**
	 * 新規受注作成時定期容器割り振り処理
	 */
	public function fnSetTeikiQuantityContainer($teiki_id, $detail_id, $order_container_id = null) {
		// 受注に容器設定がない場合はスルー
		if (empty($order_container_id)) {
			return;
		}
		$objQuery =& SC_Query_Ex::getSingletonInstance();
		// 定期情報を取得
		$arrTeiki = $objQuery->getRow("dt.*, dp.category_id", "dtb_teiki dt INNER JOIN dtb_products dp ON dt.product_id = dp.product_id", "dt.teiki_id = ? AND dt.detail_id = ?", array($teiki_id, $detail_id));
		// 定期設定の容器情報を取得
		$teiki_container_id = $objQuery->getOne("SELECT container_id FROM dtb_category_container WHERE category_id = ? AND container_id > ? ORDER BY container_id LIMIT 1", array($arrTeiki["category_id"], $order_container_id));
		if (empty($teiki_container_id)) {
			// 取得できない場合は受注と同じにする
			$teiki_container_id = $order_container_id;
		}
		// 定期容器情報の登録を一度削除
		$objQuery->delete("dtb_teiki_container_quantity", "teiki_id = ? AND detail_id = ?", array($teiki_id, $detail_id));
		// 新規登録
		$arrTeikiContainer = array();
		$arrTeikiContainer["teiki_id"] = $teiki_id;
		$arrTeikiContainer["detail_id"] = $detail_id;
		$arrTeikiContainer["container_id"] = $teiki_container_id;
		$arrTeikiContainer["quantity"] = $arrTeiki["quantity"];
		$objQuery->insert("dtb_teiki_container_quantity", $arrTeikiContainer);
		return;
	}

	/**
	 * 最新受注の容器×香り確認
	 */
	public function fnGetContainerFlavor($customer_id, $category_id) {
		$objQuery =& SC_Query_Ex::getSingletonInstance();

		$arrOrderIds = [];
		// 最新発送受注情報を取得
		$sql = " SELECT do2.order_id FROM dtb_order do2
				 INNER JOIN dtb_shipping ds ON do2.order_id = ds.order_id 
				 INNER JOIN dtb_order_detail dod ON do2.order_id = dod.order_id
				 INNER JOIN dtb_products dp ON dod.product_id = dp.product_id 
				 WHERE do2.del_flg = 0 AND do2.status = 5 AND do2.customer_id = ?
				 AND dod.container_id = 1 AND dp.category_id = ?
				 ORDER BY ds.shipping_commit_date DESC LIMIT 1"
		;
		$last_order_id = $objQuery->getOne($sql, array($customer_id, $category_id));
		if (empty($last_order_id)) {
			// 発送済みがなければ最新白データを確認
			$sql = " SELECT do2.order_id FROM dtb_order do2
					 INNER JOIN dtb_shipping ds ON do2.order_id = ds.order_id 
					 INNER JOIN dtb_order_detail dod ON do2.order_id = dod.order_id
					 INNER JOIN dtb_products dp ON dod.product_id = dp.product_id 
					 WHERE do2.del_flg = 0 AND do2.status IN (1,8,9000,9001,9002,301) AND do2.customer_id = ?
					 AND dod.container_id = 1 AND dp.category_id = ?
					 ORDER BY ds.shipping_date DESC"
			;
			$arrOrder = $objQuery->getAll($sql, array($customer_id, $category_id));
			foreach($arrOrder as $data) {
				$arrOrderIds[] = $data["order_id"];
			}
		} else {
			// 最新注文番号を配列に格納
			$arrOrderIds[] = $last_order_id;
		}
		$arrResults = [];
		// 対象ありの場合
		if (!empty($arrOrderIds)) {
			// 対象注文番号の香り情報を取得
			$sql = " SELECT DISTINCT dodo.option_detail_id FROM dtb_order_detail dod
					 INNER JOIN dtb_products dp ON dod.product_id = dp.product_id
					 INNER JOIN dtb_order_detail_option dodo ON dod.order_id = dodo.order_id AND dod.order_detail_id = dodo.order_detail_id 
					 WHERE dod.order_id IN (" . implode(",", $arrOrderIds) . ") AND dp.category_id = ?
					 ORDER BY dodo.option_detail_id"
			;
			$arrOption = $objQuery->getAll($sql, array($category_id));
			foreach($arrOption as $row) {
				$arrResults[] = (int)$row["option_detail_id"];
			}
		}
		return $arrResults;
	}
}
