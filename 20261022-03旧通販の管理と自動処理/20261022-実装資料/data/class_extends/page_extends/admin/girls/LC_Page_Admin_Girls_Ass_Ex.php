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
// require_once CLASS_REALDIR . 'pages/admin/girls/LC_Page_Admin_Girls.php';

/**
 * ガールズ管理 のページクラス(拡張).
 *
 * LC_Page_Admin_Girls_Ass をカスタマイズする場合はこのクラスを編集する.
 *
 * @package Page
 * @author EC-CUBE CO.,LTD.
 * @version $Id$
 */
class LC_Page_Admin_Girls_Ass_Ex extends LC_Page_Admin_Ex
{
    /**
     * Page を初期化する.
     *
     * @return void
     */
    function init()
    {
        parent::init();
		$this->tpl_mainpage = 'girls/girls_ass.tpl';
		$this->tpl_mainno = 'girls';
		$this->tpl_subno = 'girls_ass';
		$this->tpl_pager = 'pager.tpl';
		$this->tpl_maintitle = 'ガールズ管理';
		$this->tpl_subtitle = 'ASS動画管理';

		$masterData = new SC_DB_MasterData_Ex();
		$this->arrPageMax = $masterData->getMasterData('mtb_page_max');
		$this->arrDISP = $masterData->getMasterData('mtb_disp');
		$this->arrSTATUS = $masterData->getMasterData('mtb_status');
		$this->arrPRODUCTSTATUS_COLOR = $masterData->getMasterData('mtb_product_status_color');

        $objQuery =& SC_Query_Ex::getSingletonInstance();
        $arrData = $objQuery->getAll("SELECT * FROM mtb_ass_faicial ORDER BY rank");
        foreach ($arrData as $key => $data) {
            $this->arrFAICIAL[$data['id']] = $data['name'];
        }

		$objDate = new SC_Date_Ex();
		// 登録・更新検索開始年
		$objDate->setStartYear(RELEASE_YEAR);
		$objDate->setEndYear(DATE('Y'));
		$this->arrStartYear = $objDate->getYear();
		$this->arrStartMonth = $objDate->getMonth();
		$this->arrStartDay = $objDate->getDay();
		// 登録・更新検索終了年
		$objDate->setStartYear(RELEASE_YEAR);
		$objDate->setEndYear(DATE('Y'));
		$this->arrEndYear = $objDate->getYear();
		$this->arrEndMonth = $objDate->getMonth();
		$this->arrEndDay = $objDate->getDay();
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
            $objFormParam->addParam('ガールID', 'girl_id', INT_LEN, 'n', array('NUM_CHECK', 'MAX_LENGTH_CHECK'));
            $objFormParam->addParam('区分ID', 'faicial_id', INT_LEN, 'n', array('NUM_CHECK', 'MAX_LENGTH_CHECK'));
            $objFormParam->setParam($_POST);
            $objFormParam->convParam();

            $this->arrErr = $objFormParam->checkError();
            GC_Utils_Ex::gfPrintLog_r("arrErr:",$this->arrErr);
            if (!empty($this->arrErr)) {
                trigger_error('', E_USER_ERROR);
                return;
            }
            $post = $objFormParam->getHashArray();
        }

        switch ($this->getMode()) {
            case 'delete':
                // 削除
                $girl_id = $post['girl_id'];
                $faicial_id = $post['faicial_id'];
                $this->delete($girl_id,$faicial_id);
                
                // 再表示
                $this->objDisplay->reload();
                $this->tpl_onload = "window.alert('削除しました。');";
                break;
        }
        $this->arrGirlsAssList = $this->getList();
	}

    /**
     * ガール動画削除.
     * 
     * @param integer $girl_id ガールID
     * @param integer $faicial_id 動画区分
     * @return void
     */
    public function delete($girl_id,$faicial_id) {
        $objQuery =& SC_Query_Ex::getSingletonInstance();
        $sql_val['del_flg'] = 1;
        $sql_val['update_date'] = 'CURRENT_TIMESTAMP';
        $where = 'girl_id = ? AND faicial_id = ? AND brand_id = ?';
        $objQuery->update('dtb_girl_talk', $sql_val, $where, array($girl_id,$faicial_id,$_SESSION['brand_id']));
    }

    /**
     * ガール一覧の取得.
     *
     * @return array
     */
    public function getList() {
        $objQuery =& SC_Query_Ex::getSingletonInstance();
        $sql = "SELECT dgt.girl_id, dg.name, dgt.faicial_id, dgt.video, dgt.brand_id FROM dtb_girls dg"
            . " INNER JOIN dtb_girl_talk dgt ON dg.id = dgt.girl_id"
            . " WHERE dgt.del_flg = 0 AND dgt.brand_id = ?"
            . " ORDER BY dgt.girl_id, dgt.faicial_id";
        $arrRet = $objQuery->getAll($sql, array($_SESSION['brand_id']));
        return $arrRet;
    }
}
