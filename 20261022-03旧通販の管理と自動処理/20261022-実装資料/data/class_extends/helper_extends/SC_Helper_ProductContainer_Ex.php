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

	/**
	 * 商品カテゴリ容器情報取得
	 */
	public function fnGetcategoryContainer($brand_id, $is_html = false) {
		$objQuery =& SC_Query_Ex::getSingletonInstance();
		$sql = "SELECT dc.category_id, dcc.container_id, dcc.container_name
				FROM dtb_category dc INNER JOIN dtb_category_container dcc ON dc.category_id = dcc.category_id 
				WHERE dc.brand_id = ?
				ORDER BY dc.category_id, dcc.container_id"
		;
		$arrContainer = $objQuery->getAll($sql, array($brand_id));
		if ($is_html) {
			// HTML表示用に配列整形
			$arrResults = [];
			foreach($arrContainer as $data) {
				$arrResults[$data["category_id"]][$data["container_id"]] = $data["container_name"];
			}
			return $arrResults;
		} else {
			// そのまま返却
			return $arrContainer;
		}
	}

	/**
	 * 定期容器情報の取得
	 */
	public function fnGetTeikiContainer($teiki_id, $detail_id) {
		$objQuery =& SC_Query_Ex::getSingletonInstance();
		$arrContainer = $objQuery->getAll("SELECT * FROM dtb_teiki_container_quantity WHERE teiki_id = ? AND detail_id = ? ORDER BY container_id", array($teiki_id, $detail_id));
		$arrResults = [];
		foreach($arrContainer as $data) {
			$arrResults[$data["container_id"]] = $data["quantity"];
		}
		return $arrResults;
	}
}
