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

require_once CLASS_EX_REALDIR . 'page_extends/admin/LC_Page_Admin_Ex.php';

/**
 * チャット管理 のページクラス(拡張).
 *
 *
 * @package Page
 * @author EC-CUBE CO.,LTD.
 * @version $Id$
 */
class LC_Page_Admin_Chat_Icon_Ex extends LC_Page_Admin_Ex
{
	/**
	 * Page を初期化する.
	 *
	 * @return void
	 */
	function init()
	{
		parent::init();
		$this->tpl_mainpage = 'chat/icon.tpl';
		$this->tpl_mainno = 'chat';
		$this->tpl_subno = 'chat_icon';
		$this->tpl_pager = 'pager.tpl';
		$this->tpl_maintitle = 'チャット管理';
		$this->tpl_subtitle = 'アイコン管理';

		$masterData = new SC_DB_MasterData_Ex();
		$this->arrPageMax = $masterData->getMasterData('mtb_page_max');
		$this->arrDISP = $masterData->getMasterData('mtb_disp');
	}

	/**
	 * Page のプロセス.
	 *
	 * @return void
	 */
	function process()
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
		$objDb = new SC_Helper_DB_Ex();
		$objFormParam = new SC_FormParam_Ex();
		$objQuery = SC_Query_Ex::getSingletonInstance();

		if (!empty($_POST)) {
			$objFormParam = new SC_FormParam_Ex();
			$objFormParam->addParam('アイコンID', 'id', INT_LEN, 'n', array('NUM_CHECK', 'MAX_LENGTH_CHECK'));
			$objFormParam->setParam($_POST);
			$objFormParam->convParam();

			$this->arrErr = $objFormParam->checkError();
			if (!empty($this->arrErr['id'])) {
				trigger_error('', E_USER_ERROR);
				return;
			}
			$post = $objFormParam->getHashArray();
		}

		switch ($this->getMode()) {
			case 'delete':
				// 削除
				$this->delete($post['id']);

				// 再表示
				$this->objDisplay->reload();
				break;
		}
		$this->arrResults = $this->getList();
	}

	/**
	 * アイコン削除（論理削除）
	 * 
	 * @param integer $id	アイコン管理ID
	 * @return void
	 */
	public function delete($id) {
		$objQuery =& SC_Query_Ex::getSingletonInstance();
		$sql_val['del_flg'] = 1;
		$sql_val['update_date'] = 'CURRENT_TIMESTAMP';
		$where = 'id = ? AND brand_id = ?';
		$objQuery->update('dtb_chat_icon', $sql_val, $where, array($id,$_SESSION['brand_id']));
	}

	/**
	 * チャットアイコン一覧の取得.
	 *
	 * @return array
	 */
	public function getList() {
		$objQuery =& SC_Query_Ex::getSingletonInstance();
		$sql = "SELECT * FROM dtb_chat_icon WHERE del_flg = 0 AND brand_id = ? ORDER BY id";
		$arrRet = $objQuery->getAll($sql, array($_SESSION['brand_id']));
		return $arrRet;
	}
}
