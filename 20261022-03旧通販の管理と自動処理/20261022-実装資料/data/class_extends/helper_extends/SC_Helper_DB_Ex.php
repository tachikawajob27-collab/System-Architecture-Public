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

require_once CLASS_REALDIR . 'helper/SC_Helper_DB.php';

/**
 * DB関連のヘルパークラス(拡張).
 *
 * LC_Helper_DB をカスタマイズする場合はこのクラスを編集する.
 *
 * @package Helper
 * @author EC-CUBE CO.,LTD.
 * @version $Id$
 */
class SC_Helper_DB_Ex extends SC_Helper_DB
{
	/**
	 * カテゴリツリーの取得を行う.（複数ブランド対応）
	 *
	 * 親カテゴリの Value=0 を対象とする
	 *
	 * @param  bool  $parent_zero 親カテゴリの Value=0 の場合 true
	 * @return array カテゴリツリーの配列
	 */
	public static function sfGetLevelCatList($is_realtime = false, $parent_zero = true)
	{
		$objQuery = SC_Query_Ex::getSingletonInstance();

		// カテゴリ名リストを取得
		$col = 'category_id, parent_category_id, category_name';
		if ($is_realtime) {
			$where = 'del_flg = 0 AND is_realtime = 1 AND brand_id = ?';
		} else {
			$where = 'del_flg = 0 AND brand_id = ?';
		}
		$objQuery->setOption('ORDER BY level');
		$arrRet = $objQuery->select($col, 'dtb_category', $where, array($_SESSION["brand_id"]));
		$arrCatName = array();
		foreach ($arrRet as $arrTmp) {
			$arrCatName[$arrTmp['category_id']] =
				(($arrTmp['parent_category_id'] > 0)?
					$arrCatName[$arrTmp['parent_category_id']] : '')
				. CATEGORY_HEAD . $arrTmp['category_name'];
		}

		$col = 'category_id, parent_category_id, category_name, level';
		if ($is_realtime) {
			$where = 'del_flg = 0 AND is_realtime = 1 AND brand_id = ?';
		} else {
			$where = 'del_flg = 0 AND brand_id = ?';
		}
		$objQuery->setOption('ORDER BY rank DESC');
		$arrRet = $objQuery->select($col, 'dtb_category', $where, array($_SESSION["brand_id"]));
		$max = count($arrRet);

		$arrValue = array();
		$arrOutput = array();
		for ($cnt = 0; $cnt < $max; $cnt++) {
			if ($parent_zero) {
				if ($arrRet[$cnt]['level'] == LEVEL_MAX) {
					$arrValue[$cnt] = $arrRet[$cnt]['category_id'];
				} else {
					$arrValue[$cnt] = '';
				}
			} else {
				$arrValue[$cnt] = $arrRet[$cnt]['category_id'];
			}

			$arrOutput[$cnt] = $arrCatName[$arrRet[$cnt]['category_id']];
		}

		return array($arrValue, $arrOutput);
	}

	/**
	 * ブランド情報を取得する.
	 *
	 * @param  int $brand_id ブランドID
	 * @return array   ブランド情報の配列
	 */
	public static function sfGetBrandData($brand_id)
	{
		static $arrData = null;
		$objQuery = SC_Query_Ex::getSingletonInstance();
		$arrData = $objQuery->getRow('*', 'dtb_brand', "id = ?", array($brand_id));
		return $arrData;
	}
}
