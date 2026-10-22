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
 * ガールズポイント管理（動画）
 *
 * @package Page
 * @author EC-CUBE CO.,LTD.
 * @version $Id$
 */
class LC_Page_Admin_Girls_Point_Video_Ex extends LC_Page_Admin_Ex
{
	/**
	 * Page を初期化する.
	 *
	 * @return void
	 */
	function init()
	{
		parent::init();
		parent::init();
		$this->tpl_mainpage = 'girls/point_video.tpl';
		$this->tpl_mainno = 'girls';
		$this->tpl_subno = 'point';
		$this->tpl_subno_point = 'video';
		$this->tpl_pager = 'pager.tpl';
		$this->tpl_maintitle = 'ガールズ管理';
		$this->tpl_subtitle = 'ポイント管理（動画）';

		$masterData = new SC_DB_MasterData_Ex();
		$this->arrPageMax = $masterData->getMasterData('mtb_page_max');
		$this->arrDISP = $masterData->getMasterData('mtb_disp');

		// ガール一覧
		$this->getGirlsList();
	}

	/**
	 * Page のプロセス.
	 *
	 * @return void
	 */
	public function process()
	{
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
		$objFormParam = new SC_FormParam_Ex();

		// パラメーター情報の初期化
		$this->lfInitParam($objFormParam);
		$objFormParam->setParam($_POST);
		$this->arrHidden = $objFormParam->getSearchArray();
		$this->arrForm = $objFormParam->getFormParamList();

		switch ($this->getMode()) {
			case 'delete':
				// データ削除（論理削除）
				$this->doDelete('id = ? AND brand_id = ?', array($objFormParam->getValue('id'), $_SESSION["brand_id"]));
				break;
			case 'search':
				// 検索
				$objFormParam->convParam();
				$objFormParam->trimParam();
				$this->arrErr = $this->lfCheckError($objFormParam);
				$arrParam = $objFormParam->getHashArray();

				if (count($this->arrErr) == 0) {
					$where = 'dgpv.del_flg = 0';
					$arrWhereVal = array();
					foreach ($arrParam as $key => $val) {
						if ($val == '') {
							continue;
						}
						$this->buildQuery($key, $where, $arrWhereVal, $objFormParam, $objDb);
					}
					$order = 'dgpv.id DESC';

					// 検索処理
					$this->arrResults = $this->doSearch($where, $arrWhereVal, $order,  $objFormParam);
				}
				break;
			case 'back':
				break;
			default:
				break;
		}
	}

	/**
	 * パラメーター情報の初期化を行う.
	 *
	 * @param  integer $mode
	 * @param  SC_FormParam $objFormParam SC_FormParam インスタンス
	 * @return void
	 */
	public function lfInitParam(&$objFormParam)
	{
		// 検索条件
		$objFormParam->addParam('ガール', 'search_girls_id', STEXT_LEN, 'KVa', array('SPTAB_CHECK', 'MAX_LENGTH_CHECK'));
		$objFormParam->addParam('動画名', 'search_video_name', STEXT_LEN, 'KVa', array('SPTAB_CHECK', 'MAX_LENGTH_CHECK'));
		$objFormParam->addParam('ページ送り番号', 'search_pageno', INT_LEN, 'n', array('MAX_LENGTH_CHECK', 'NUM_CHECK'));
		$objFormParam->addParam('表示件数', 'search_page_max', INT_LEN, 'n', array('MAX_LENGTH_CHECK', 'NUM_CHECK'));
	}

	/**
	 * 入力内容のチェックを行う.
	 *
	 * @param  SC_FormParam $objFormParam SC_FormParam インスタンス
	 * @return array
	 */
	public function lfCheckError(&$objFormParam)
	{
		$objErr = new SC_CheckError_Ex($objFormParam->getHashArray());
		$objErr->arrErr = $objFormParam->checkError();
		return $objErr->arrErr;
	}

	/**
	 * ポイント動画を削除（論理削除）
	 *
	 * @param  string $where    削除対象の WHERE 句
	 * @param  array  $arrParam 削除対象の値
	 * @return void
	 */
	public function doDelete($where, $arrParam = array())
	{
		$objQuery = SC_Query_Ex::getSingletonInstance();
		$sqlval['del_flg'] = 1;
		$sqlval['update_date'] = 'CURRENT_TIMESTAMP';
		$objQuery->begin();
		$objQuery->update('dtb_girls_point_video', $sqlval, $where, $arrParam);
		$objQuery->commit();
	}

	/**
	 * 検索
	 *
	 * @param  string $where	対象の WHERE 句
	 * @param  array  $arrWhere	対象の値
	 * @return void
	 */
	public function doSearch($where, $arrValues, $order, $objFormParam)
	{
		$objQuery = SC_Query_Ex::getSingletonInstance();

		// 行数の取得
		$this->tpl_linemax = $this->getNumberOfLines($where, $arrValues);
		// ページ送りの処理
		$page_max = SC_Utils_Ex::sfGetSearchPageMax($objFormParam->getValue('search_page_max'));
		// ページ送りの取得
		$objNavi = new SC_PageNavi_Ex($this->arrHidden['search_pageno'],
									  $this->tpl_linemax, $page_max,
									  'eccube.moveNaviPage', NAVI_PMAX);
		$this->arrPagenavi = $objNavi->arrPagenavi;

		// 読み込む列とテーブルの指定
		$col = 'dgpv.*, dg.name AS girls_name';
		$from = "dtb_girls_point_video dgpv INNER JOIN dtb_girls dg ON dgpv.girls_id = dg.id";
		$objQuery->setLimitOffset($page_max, $objNavi->start_row);
		$objQuery->setOrder($order);

		return $objQuery->select($col, $from, $where, $arrValues);
	}

	/**
	 * クエリを構築する.
	 *
	 * 検索条件のキーに応じた WHERE 句と, クエリパラメーターを構築する.
	 * クエリパラメーターは, SC_FormParam の入力値から取得する.
	 *
	 * 構築内容は, 引数の $where 及び $arrValues にそれぞれ追加される.
	 *
	 * @param  string       $key          検索条件のキー
	 * @param  string       $where        構築する WHERE 句
	 * @param  array        $arrValues    構築するクエリパラメーター
	 * @param  SC_FormParam $objFormParam SC_FormParam インスタンス
	 * @param  SC_FormParam $objDb        SC_Helper_DB_Ex インスタンス
	 * @return void
	 */
	public function buildQuery($key, &$where, &$arrValues, &$objFormParam, &$objDb)
	{
		$dbFactory = SC_DB_DBFactory_Ex::getInstance();
		switch ($key) {
			// ガールID
			case 'search_girls_id':
				$where .= ' AND dgpv.girls_id = ?';
				$arrValues[] = $objFormParam->getValue($key);
				break;
			// 動画名称
			case 'search_video_name':
				$where .= ' AND dgpv.video_name LIKE ?';
				$arrValues[] = sprintf('%%%s%%', $objFormParam->getValue($key));
				break;
			default:
				break;
		}
	}

	/**
	 * 検索結果の行数を取得する.
	 *
	 * @param  string  $where     検索条件の WHERE 句
	 * @param  array   $arrValues 検索条件のパラメーター
	 * @return integer 検索結果の行数
	 */
	public function getNumberOfLines($where, $arrValues)
	{
		$objQuery = SC_Query_Ex::getSingletonInstance();
		return $objQuery->count('dtb_girls_point_video dgpv', $where, $arrValues);
	}

	/**
	 * ガールズ一覧取得
	 */
	public function getGirlsList() {
		$objQuery = SC_Query_Ex::getSingletonInstance();
		$objQuery->setOrder("id");
		$arrRet = $objQuery->select("id, name", "dtb_girls", "del_flg = 0 AND brand_id = ?", array($_SESSION["brand_id"]));
		$this->arrGirls = array();
		foreach($arrRet as $data) {
			$this->arrGirls[$data["id"]] = $data["name"];
		}
	}
}
