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

require_once CLASS_REALDIR . 'helper/SC_Helper_Category.php';

/**
 * カテゴリーを管理するヘルパークラス(拡張).
 *
 * LC_Helper_Category をカスタマイズする場合はこのクラスを編集する.
 *
 * @package Helper
 * @author pineray
 * @version $Id$
 */
class SC_Helper_Category_Ex extends SC_Helper_Category
{
	/**
	 * カテゴリグループ一覧取得
	 */
	public function getCategoryGroupList($brand_id) {
		$objQuery = SC_Query_Ex::getSingletonInstance();
		return $objQuery->getAll("SELECT * FROM dtb_category_group WHERE del_flg = 0 AND brand_id = ? ORDER BY category_group_id", array($brand_id));
	}

	/**
	 * カテゴリグループ情報取得
	 */
	public function getCategoryGroup($category_group_id) {
		$objQuery = SC_Query_Ex::getSingletonInstance();
		return $objQuery->getRow("*", "dtb_category_group", "category_group_id = ?", array($category_group_id));
	}

	/**
	 * カテゴリグループ削除チェック
	 */
	public function checkCategoryGroup($category_group_id) {
		$objQuery = SC_Query_Ex::getSingletonInstance();
		// 既に紐付けされているカテゴリが存在するか
		return $objQuery->select("*", "dtb_category", "del_flg = 0 AND category_group_id = ?", array($category_group_id));
	}

	/**
	 * カテゴリグループ登録
	 */
	public function saveGroup($arrParams, $brand_id) {
		$objQuery = SC_Query_Ex::getSingletonInstance();
		$objQuery->begin();
		$arrSave = array();
		$arrSave["category_group_name"] = $arrParams["category_group_name"];
		$arrSave["lp_path"] = $arrParams["lp_path"];
		$arrSave["logo_path"] = $arrParams["logo_path"];
		$arrSave["display_flg"] = $arrParams["display_flg"];
		$arrSave["update_date"] = "CURRENT_TIMESTAMP";
		if (empty($arrParams["category_group_id"])) {
			// 新規登録
			$arrSave["category_group_id"] = $objQuery->nextVal('dtb_category_group_category_group_id');
			$arrSave["create_date"] = "CURRENT_TIMESTAMP";
			$arrSave["brand_id"] = $brand_id;
			$objQuery->insert("dtb_category_group", $arrSave);
		} else {
			// 更新
			$objQuery->update("dtb_category_group", $arrSave, "category_group_id = ?", array($arrParams["category_group_id"]));
		}
		$objQuery->commit();
		unset($arrSave);
		return;
	}

	/**
	 * カテゴリグループ削除
	 */
	public function deleteGroup($category_group_id) {
		$objQuery = SC_Query_Ex::getSingletonInstance();
		$objQuery->delete("dtb_category_group", "category_group_id = ?", array($category_group_id));
		return;
	}
}
