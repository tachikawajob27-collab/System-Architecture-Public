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
require_once CLASS_EX_REALDIR . 'page_extends/mypage/LC_Page_AbstractMypage_Ex.php';

/**
 * ポイント交換 のページクラス
 *
 * @package Page
 * @author EC-CUBE CO.,LTD.
 * @version $Id$
 */
class LC_Page_Point_Exchange_Ex extends LC_Page_AbstractMypage_Ex
{
	/**
	 * Page を初期化する.
	 *
	 * @return void
	 */
	function init()
	{
		parent::init();
		$this->tpl_subtitle = 'ポイント交換';
		$this->tpl_mainpage = 'point/exchange.tpl';
		$this->tpl_pager = 'pager.tpl';
		// ベースフレームをMYPAGE専用にする
		$this->setTemplate('mypage_frame.tpl');

		// 顧客情報
		$objCustomer = new SC_Customer_Ex();
		$this->customer_id = $objCustomer->getValue('customer_id');
		$this->customer_name = $objCustomer->getValue('name01');

		// ポイント情報
		$objCustomerHelper = new SC_Helper_Customer_Ex();
		$this->customer_point = $objCustomerHelper->getCustomerPoint($this->customer_id);
		$this->customer_point_expiration_date = $objCustomerHelper->getCustomerPointExpirationDate($this->customer_id);
	}

	/**
	 * Page のプロセス.
	 *
	 * @return void
	 */
	function process() {
		// $this->action();
		// $this->sendResponse();
		parent::process();
	}

	/**
	 * 実処理
	 */
	public function action() {
		$this->arrExchangeList = $this->getPointExchangeList($_SESSION["arrBrandData"]["id"]);
	}

	/**
	 * 交換商品一覧
	 */
	public function getPointExchangeList($brand_id) {
		$objQuery = SC_Query_Ex::getSingletonInstance();
		$sql = " SELECT dpel.*, dp.product_image, dp.name_official as product_name"
			 . " FROM dtb_point_exchange_list dpel"
			 . " INNER JOIN dtb_products dp ON dpel.product_id = dp.product_id"
			 . " WHERE dpel.del_flg = 0 AND dpel.brand_id = ?"
			 . " ORDER BY dpel.id"
		;
		// ベースデータ取得
		$arrData = $objQuery->getAll($sql, array($brand_id));
		if (!empty($arrData)) {
			$this->tpl_linemax = count($arrData);
			// ページ送りの処理(5行1ページ)
			$page_max = SC_Utils_Ex::sfGetSearchPageMax(5);
			// ページ送りの取得
			$objNavi = new SC_PageNavi_Ex($_REQUEST['search_pageno'],
										$this->tpl_linemax, $page_max,
										'eccube.moveNaviPage', NAVI_PMAX);
			$this->arrPagenavi = $objNavi->arrPagenavi;
		}
		return $arrData;
	}
}
