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

require_once CLASS_REALDIR . 'helper/SC_Helper_Course.php';

/**
 * コース管理するヘルパークラス(拡張).
 *
 * SC_Helper_Course をカスタマイズする場合はこのクラスを編集する.
 *
 * @package Helper
 * @author pineray
 * @version $Id$
 */
class SC_Helper_Course_Ex extends SC_Helper_Course
{
	/**
	 * 引き上げ用プレミアムコース取得
	 * 
	 */
	public function getPremiumCourse($brand_id, $product_id) {
		$objQuery =& SC_Query_Ex::getSingletonInstance();
		$sql = " SELECT dc.id"
			 . " FROM dtb_course dc"
			 . " INNER JOIN dtb_course_shibari dcs ON dc.id = dcs.course_id"
			 . " WHERE dc.del_flg = 0 AND dc.is_cross = 3 AND dc.brand_id = ? AND dcs.product_id = ?"
		;
		return $objQuery->getOne($sql, array($brand_id, $product_id));
	}

	/**
	 * 引き上げ用プレミアムコース取得（こちらを使用）
	 * 
	 */
	public function getUpgradeCourse($course_id) {
		$objQuery =& SC_Query_Ex::getSingletonInstance();
		$sql = " SELECT dc.upgrade_course_id"
			 . " FROM dtb_course dc"
			 . " WHERE dc.del_flg = 0 AND dc.id = ?"
		;
		return $objQuery->getOne($sql, array($course_id));
	}

	/**
	 * コース名称を取得
	 */
	public function getCourseName($id, $is_official = false) {
		$objQuery =& SC_Query_Ex::getSingletonInstance();
		if ($is_official) {
			$sql = " SELECT name_official FROM dtb_course WHERE id = ?";
		} else {
			$sql = " SELECT name FROM dtb_course WHERE id = ?";
		}
		return $objQuery->getOne($sql, array($id));
	}

	/**
	 * コース明細データ取得
	 */
	public function getCourseDetail($course_id, $course_type = null, $order_count = null) {
		$objQuery =& SC_Query_Ex::getSingletonInstance();
		$where = " WHERE dc.id = ?";
		$arrWhere = array($course_id);
		// コースタイプ
		if (!is_null($course_type)) {
			$where .= " AND dcd.course_type = ?";
			$arrWhere[] = $course_type;
		}
		// 回数
		if (!empty($order_count)) {
			$where .= " AND dcd.course_count = ?";
			$arrWhere[] = $order_count;
		}

		// コース明細データ
		$sql = " SELECT dc.id, dc.is_cross, dcd.product_id, dcd.course_type, dcd.quantity, dcd.interval, dcd.interval_type, dcd.order_count, dcs.num AS is_shibari, dp.category_id"
			 . " FROM dtb_course dc "
			 . " INNER JOIN dtb_course_detail dcd ON dc.id = dcd.course_id"
			 . " INNER JOIN dtb_products dp ON dcd.product_id = dp.product_id"
			 . " LEFT JOIN dtb_course_shibari dcs ON dcd.course_id = dcs.course_id AND dcd.product_id = dcs.product_id"
			 . $where
			 . " ORDER BY dcd.course_type, dcd.order_count"
		;
		return $objQuery->getAll($sql, $arrWhere);
	}
}
