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
 * 出荷管理関連のヘルパークラス
 *
 * @package Helper
 * @author pineray
 * @version $Id$
 */
class SC_Helper_Shipping_Ex
{
	/**
	 * 出荷完了履歴データ取得
	 */
	public function getShippingCommitHistory($brand_id) {
		$objQuery = SC_Query_Ex::getSingletonInstance();
		$sql = " SELECT ds.shipping_commit_date AS commit_date"
			 . "		,(SELECT name FROM dtb_deliv WHERE deliv_id = ds.deliv_id) AS deliv_name"
			 . "		,COUNT(*)"
			 . " FROM dtb_shipping ds"
			 . " INNER JOIN dtb_order do2 ON ds.order_id = do2.order_id"
			 . " WHERE ds.shipping_commit_date >= current_date - interval '7 days'"
			 . " AND ds.shipping_commit_date IS NOT NULL"
			 . " AND do2.del_flg = 0"
			 . " AND do2.brand_id = ?"
			 . " GROUP BY shipping_commit_date, deliv_name"
			 . " ORDER BY shipping_commit_date DESC, deliv_name"
		;
		return $objQuery->getAll($sql, array($brand_id));
	}

	/**
	 * 出荷予定データ取得
	 */
	public function getShippingYtiData($brand_id) {
		$objQuery = SC_Query_Ex::getSingletonInstance();
		$sql = "SELECT al.deliv_date, COUNT(DISTINCT al.order_id) AS count
				FROM
				(
					SELECT
						TO_CHAR(func_get_syuka_date(o.deliv_id , s.shipping_date),'YYYY/MM/DD') AS deliv_date, o.order_id
					FROM dtb_order o INNER JOIN dtb_shipping s ON o.order_id = s.order_id
					WHERE o.del_flg = 0
					AND o.status IN (1,9001)
					AND o.brand_id = ?
					AND TO_CHAR(COALESCE(s.shipping_date, CURRENT_DATE), 'YYYYMMDD') <= TO_CHAR(CURRENT_DATE + INTERVAL '15 day', 'YYYYMMDD')
				) al
				GROUP BY al.deliv_date ORDER BY al.deliv_date"
		;
		return $objQuery->getAll($sql, array($brand_id));
	}

	/**
	 * 出荷準備中一覧データ取得
	 */
	public function getShippingReady($brand_id) {
		$objQuery = SC_Query_Ex::getSingletonInstance();
		$sql = "SELECT dsr.shipping_ready_id, dsr.output_date, dsr.shipping_date, dsr.output_type,
					(SELECT COUNT(DISTINCT do3.order_id) FROM dtb_order do3 WHERE do3.shipping_ready_id = dsr.shipping_ready_id AND do3.status = 8) AS shipping_cnt,
					(SELECT SUM(do3.payment_total) FROM dtb_order do3 WHERE do3.shipping_ready_id = dsr.shipping_ready_id AND do3.status = 8) AS shipping_total
				FROM dtb_shipping_ready dsr
				WHERE dsr.del_flg = 0 AND dsr.brand_id = ?
				AND EXISTS (SELECT NULL FROM dtb_order do2 WHERE do2.shipping_ready_id = dsr.shipping_ready_id AND do2.status = 8)
				ORDER BY dsr.shipping_date, dsr.output_type"
		;
		return $objQuery->getAll($sql, array($brand_id));
	}

	/**
	 * 特定出荷準備中データ取得
	 */
	public function getShippingReadyRow($shipping_ready_id) {
		$objQuery = SC_Query_Ex::getSingletonInstance();
		return $objQuery->getRow("*", "dtb_shipping_ready", "shipping_ready_id = ?", array($shipping_ready_id));
	}

	/**
	 * 出荷準備中解除処理
	 */
	public function cancelShippingReady($brand_id, $shipping_ready_id) {
		$objQuery = SC_Query_Ex::getSingletonInstance();
		$arrUpdate = [];

		//　対象IDの出荷準備中受注を取得
		$sql = " SELECT order_id, payment_id FROM dtb_order WHERE del_flg = 0 AND status = 8 AND brand_id = ? AND shipping_ready_id = ?";
		$arrCancel = $objQuery->getAll($sql, array($brand_id, $shipping_ready_id));
		foreach($arrCancel as $data) {
			// 支払方法により戻しステータス分け
			if ($data["payment_id"] == 13) {
				// コンビニ後払い
				$arrUpdate["status"] = 9001;
			} else {
				// その他
				$arrUpdate["status"] = 1;
			}
			$arrUpdate["shipping_ready_id"] = null;
			$arrUpdate["update_date"] = "CURRENT_TIMESTAMP"; 
			$objQuery->update("dtb_order", $arrUpdate, "order_id = ?", array($data["order_id"]));
		}
		// 準備テーブルを削除
		$objQuery->delete("dtb_shipping_ready", "shipping_ready_id = ?", array($shipping_ready_id));
	}
}
