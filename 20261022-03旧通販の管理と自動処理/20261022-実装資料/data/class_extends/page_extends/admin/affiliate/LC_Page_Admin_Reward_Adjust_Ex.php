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
 * 単価調整確認 のページクラス
 *
 * LC_Page_Admin_Ex をカスタマイズする場合はこのクラスを編集する.
 *
 * @package Page
 * @author EC-CUBE CO.,LTD.
 * @version $Id$
 */
class LC_Page_Admin_Reward_Adjust_Ex extends LC_Page_Admin_Ex
{
	/**
	 * Page を初期化する.
	 *
	 * @return void
	 */
	function init()
	{
		parent::init();
		$this->tpl_mainpage = 'affiliate/reward_adjust.tpl';
		$this->tpl_mainno = 'affiliate';
		$this->tpl_subno = 'reward_adjust';
		$this->tpl_pager = 'pager.tpl';
		$this->tpl_maintitle = '広告管理';
		$this->tpl_subtitle = '単価調整管理';

		$masterData = new SC_DB_MasterData_Ex();
		$this->arrPageMax = $masterData->getMasterData('mtb_page_max');
		$this->arrDISP = $masterData->getMasterData('mtb_disp');

		// ブランドデータ取得
		$objDb = new SC_Helper_DB_Ex();
		$this->arrBrand = $objDb->sfGetBrandData($_SESSION["brand_id"]);

		// ASP一覧
		$objAsp = new SC_Helper_Asp_Ex();
		$arrAsp = $objAsp->getList();

		// メディア一覧
		$objMedia = new SC_Helper_Media_Ex();
		$arrMedia = $objMedia->getList();

		// 媒体一覧
		$objBaitai = new SC_Helper_Baitai_Ex();
		$arrBaitai = $objBaitai->getList();

		// セレクトボックス用に整形
		list($this->arrAsp, $this->arrMedia, $this->arrBaitai) = $this->lfSetSelectHtml($arrAsp, $arrMedia, $arrBaitai);
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

		// パラメーター情報の初期化
		$this->lfInitParam($objFormParam);
		$objFormParam->setParam($_POST);
		$this->arrHidden = $objFormParam->getSearchArray();

		switch ($this->getMode()) {
			case 'search':
				$this->arrErr = $this->lfCheckError($objFormParam);
// GC_Utils_Ex::gfPrintLog("エラー：". serialize($this->arrErr));
				if (empty($this->arrErr)) {
					// 日付
					list($sdate, $edate) = $this->lfSetStartEndDate($objFormParam);
					// 単価情報を検索
					$this->arrResults = $this->lfGetRewardAdjust($sdate, $edate, $objFormParam);
					if (!empty($this->arrResults)) {
						$this->tpl_linemax = count($this->arrResults);
					}
				}
				break;

			case 'csv':
				// 日付
				list($sdate, $edate) = $this->lfSetStartEndDate($objFormParam);
				// CSVデータ出力
				$this->lfGetOutputRewardAdjust($sdate, $edate, $objFormParam);
				SC_Response_Ex::actionExit();
				break;
		}

		// 画面宣しても日付が保存される
		$_SESSION = $this->lfSaveDateSession($_SESSION, $this->arrHidden);
		$objFormParam->setParam($_SESSION['reward']);
		$this->arrForm = $objFormParam->getFormParamList();
	}

	/**
	 * パラメーター情報の初期化を行う.
	 *
	 * @param  SC_FormParam $objFormParam SC_FormParam インスタンス
	 * @return void
	 */
	public function lfInitParam(&$objFormParam)
	{
		// デフォルト値の取得
		$arrList = $this->lfGetDateDefault();

		// POSTされる値
		$objFormParam->addParam('ページ送り番号', 'search_pageno', INT_LEN, 'n', array('MAX_LENGTH_CHECK', 'NUM_CHECK'));
		$objFormParam->addParam('表示件数', 'search_page_max', INT_LEN, 'n', array('MAX_LENGTH_CHECK', 'NUM_CHECK'));

		// 検索条件
		$objFormParam->addParam('単価開始日From', 'search_startdate', STEXT_LEN, 'n', array('EXIST_CHECK','MAX_LENGTH_CHECK'), $arrList['start_date']);
		$objFormParam->addParam('単価開始日To', 'search_enddate', STEXT_LEN, 'n', array('EXIST_CHECK','MAX_LENGTH_CHECK'), $arrList['end_date']);
		$objFormParam->addParam('ASP', 'search_asp', INT_LEN, 'n', array('NUM_CHECK', 'MAX_LENGTH_CHECK'));
		$objFormParam->addParam('メディア', 'search_media', INT_LEN, 'n', array('NUM_CHECK', 'MAX_LENGTH_CHECK'));
		$objFormParam->addParam('媒体', 'search_baitai', INT_LEN, 'n', array('NUM_CHECK', 'MAX_LENGTH_CHECK'));
		$objFormParam->addParam('前後確認期間', 'search_target_span', INT_LEN, 'n', array('EXIST_CHECK','NUM_CHECK', 'MAX_LENGTH_CHECK'));
	}
	/* デフォルト値の取得 */
	function lfGetDateDefault() {
		$year = date('Y', strtotime("-1 day"));
		$month = date('m', strtotime("-1 day"));
		$day = date('d', strtotime("-1 day"));

		$list = isset($_SESSION['reward']) ? $_SESSION['reward'] : '';
		
		// セッション情報に開始月度が保存されていない。
		if (empty($_SESSION['reward']['startyear_m'])) {
			$list = array();
			$list['startyear_m'] = $year;
			$list['startmonth_m'] = $month;
		}

		// セッション情報に開始日付、終了日付が保存されていない。
		if (empty($_SESSION['reward']['startyear']) && empty($_SESSION['reward']['endyear'])) {
			$list['start_date'] = $year."-".$month."-".$day;
			$list['end_date'] = $year."-".$month."-".$day;
		}
		return $list;
	}


	/**
	 * 検索条件必須入力チェック
	 */
	function lfCheckError(&$objFormParam) {
		$objFormParam->convParam();
		$objErr = new SC_CheckError_Ex($objFormParam->getHashArray());
		$objErr->arrErr = $objFormParam->checkError();
		// 期間集計範囲確認
		if (!isset($objErr->arrErr["search_startdate"]) && !isset($objErr->arrErr["search_enddate"])) {
			$from = date("Y-m-d H:i:00", strtotime($objFormParam->getValue('search_startdate')));
			$to = date("Y-m-d H:i:59", strtotime($objFormParam->getValue('search_enddate')));
			if ($from > $to) {
				$objErr->arrErr["search_startdate"] = "※ 単価開始日の範囲が正しくありません。<br>";
			}
		}
		return $objErr->arrErr;
	}
	/**
	 * 単価開始日セッション保持処理
	 */
	function lfSaveDateSession($session, $arrForm) {
		// session の初期化をする
		if (!isset($session['reward'])) {
			$session['reward'] = $this->lfGetDateInit();
		}
		if (!empty($arrForm)) {
			$session['reward'] = array_merge($session['reward'], $arrForm);
		}
		return $session;
	}
	/**
	 * 単価開始日初期値設定
	 */
	function lfGetDateInit() {
		$search_startyear_m     = $search_startyear  = $search_endyear  = date('Y');
		$search_startmonth_m    = $search_startmonth = $search_endmonth = date('m');
		$search_startday        = $search_endday     = date('d');
		return compact($this->arrSearchForm1, $this->arrSearchForm2);
	}

	/**
	 * 単価開始日を検索用に整形
	 */
	function lfSetStartEndDate(&$objFormParam) {
		$arrRet = $objFormParam->getHashArray();
		// 期間集計
		$sdate = date('Y-m-d', strtotime($arrRet['search_startdate']));
		$edate = date('Y-m-d', strtotime($arrRet['search_enddate']));
		return array($sdate, $edate);
	}

	/**
	 * 単価調整データ取得
	 */
	function lfGetRewardAdjust($sdate, $edate, &$objFormParam) {
		$objQuery = SC_Query_Ex::getSingletonInstance();
		$arrRet = $objFormParam->getHashArray();

		// 配列
		$arrResults = array();
		$arrWorks = array();
		$idx = 0;
		$span = (int)$arrRet["search_target_span"];
		GC_Utils_Ex::gfPrintLog("データ抽出期間：". $span . "週間前後");

		// 検索条件
		$where = "";
		$arrWhere = array($sdate, $edate, $sdate, $edate, $_SESSION["brand_id"]);
		if (isset($arrRet["search_asp"]) && !empty($arrRet["search_asp"])) {
			// ASP
			$where .= " AND dr.asp_id = ?";
			$arrWhere[] = $arrRet["search_asp"];
		}
		if (isset($arrRet["search_media"]) && !empty($arrRet["search_media"])) {
			// メディア
			$where .= " AND dr.media_id = ?";
			$arrWhere[] = $arrRet["search_media"];
		}
		if (isset($arrRet["search_baitai"]) && !empty($arrRet["search_baitai"])) {
			// 媒体
			$where .= " AND dr.baitai_id = ?";
			$arrWhere[] = $arrRet["search_baitai"];
		}

		// 検索SQL
		$sql = " SELECT dr.asp_id, dr.media_id, dr.baitai_id, dr.brand_id, drd.start_date, drd.close_date, drd.reward, da.name AS asp_name, dm.name AS media_name, db.name AS baitai_name"
			 . " FROM dtb_reward_detail drd "
			 . " INNER JOIN dtb_reward dr ON drd.reward_id = dr.id "
			 . " INNER JOIN dtb_asp da ON dr.asp_id = da.id "
			 . " INNER JOIN dtb_media dm ON dr.media_id = dm.id "
			 . " INNER JOIN dtb_baitai db ON dr.baitai_id = db.id "
			 . " WHERE ((drd.start_date >= ? AND drd.start_date <= ?) OR (drd.close_date >= ? AND drd.close_date <= ?))"
			 . " AND dr.brand_id = ?"
			 . $where
			 . " ORDER BY dr.asp_id, dr.media_id, dr.baitai_id, drd.start_date"
		;
		$arrWorks = $objQuery->getAll($sql, $arrWhere);
		foreach($arrWorks as $data) {
			// まず結果はそのまま格納する
			$arrResults[$idx] = $data;

			// 変更前単価を取得
			$arrResults[$idx]["before_reward"] = $this->lfGetBeforeReward($objQuery, $data);

			// 変更前情報を取得
			$arrBeforeData = $this->lfGetRewordCvData($objQuery, 1, $span, $data);
			$arrResults[$idx]["before_pv"] = $arrBeforeData["pv"];
			$arrResults[$idx]["before_cv"] = $arrBeforeData["cv"];
			$arrResults[$idx]["before_cvr"] = $arrBeforeData["cvr"];
			$arrResults[$idx]["before_ave_pv"] = $arrBeforeData["daily_pv"];
			$arrResults[$idx]["before_ave_cv"] = $arrBeforeData["daily_cv"];

			// 変更後情報を取得
			$arrAfterData = $this->lfGetRewordCvData($objQuery, 2, $span, $data);
			$arrResults[$idx]["after_pv"] = $arrAfterData["pv"];
			$arrResults[$idx]["after_cv"] = $arrAfterData["cv"];
			$arrResults[$idx]["after_cvr"] = $arrAfterData["cvr"];
			$arrResults[$idx]["after_ave_pv"] = $arrAfterData["daily_pv"];
			$arrResults[$idx]["after_ave_cv"] = $arrAfterData["daily_cv"];

			// 差分
			$arrResults[$idx]["diff_cvr"] = number_format(($arrBeforeData["cvr"] - $arrAfterData["cvr"]), 2, ".", ",");
			$arrResults[$idx]["diff_ave_pv"] = $arrBeforeData["daily_pv"] - $arrAfterData["daily_pv"];
			$arrResults[$idx]["diff_ave_cv"] = $arrBeforeData["daily_cv"] - $arrAfterData["daily_cv"];
			$idx++;
		}
		return $arrResults;
	}
	/**
	 * 指定期間前後のPV・CV・CVRデータ取得
	 */
	public function lfGetRewordCvData(&$objQuery, $mode, $span, $row) {
		$arrResult = array();
		if ($mode == 1) {
			// 変更前情報
			$interval = $span * 7;
			$date_from = date("Y-m-d", strtotime("-".$interval." day", strtotime($row["start_date"])));
			$date_to = date("Y-m-d", strtotime("-1 day", strtotime($row["start_date"])));
			GC_Utils_Ex::gfPrintLog("変更前期間：". $date_from . "〜" . $date_to);
		} else {
			// 変更後情報
			$interval = ($span * 7)-1;
			$date_from = date("Y-m-d", strtotime($row["start_date"]));
			$date_to = date("Y-m-d", strtotime($interval." day", strtotime($row["start_date"])));
			GC_Utils_Ex::gfPrintLog("変更後期間：". $date_from . "〜" . $date_to);
		}

		// 対象期間日数差分を取得
		// $time1 = new DateTime($date_to);
		// $time2 = new DateTime($date_from);
		// $diff = $time1->diff($time2)->format("%a")+1;
		$diff = $span * 7;
		GC_Utils_Ex::gfPrintLog("期間差分日数：". $diff);

		// PV
		$pv = $objQuery->getOne("SELECT SUM(dra.total) FROM dtb_realtime_access dra WHERE dra.access_date >= ? AND dra.access_date <= ? AND dra.asp_id = ? AND dra.media_id = ? AND dra.baitai_id = ?", array($date_from, $date_to, $row["asp_id"], $row["media_id"], $row["baitai_id"]));
		// CV
		$cv = $objQuery->getOne("SELECT SUM(drs.total) FROM dtb_realtime_sales drs WHERE drs.sales_date >= ? AND drs.sales_date <= ? AND drs.asp_id = ? AND drs.media_id = ? AND drs.baitai_id = ?", array($date_from, $date_to, $row["asp_id"], $row["media_id"], $row["baitai_id"]));
		// CVR
		if ($pv > 0 && $cv > 0) {
			$cvr = number_format(($cv / $pv * 100), 2, ".", ",");
		} else {
			$cvr = 0;
		}
		$arrResult["pv"] = $pv;
		$arrResult["cv"] = $cv;
		$arrResult["cvr"] = $cvr;
		$arrResult["daily_pv"] = floor($pv / $diff);
		$arrResult["daily_cv"] = floor($cv / $diff);
		return $arrResult;
	}

	/**
	 * 変更前単価を取得
	 */
	public function lfGetBeforeReward(&$objQuery, $row) {
		// 開始日-1日で終了日を取得
		$close_date = date("Y-m-d", strtotime("-1 day", strtotime($row["start_date"])));
		// 変更前単価を取得
		$sql = " SELECT drd.reward FROM dtb_reward_detail drd"
			 . " INNER JOIN dtb_reward dr ON drd.reward_id = dr.id "
			 . " WHERE drd.close_date = ?"
			 . " AND dr.asp_id = ? AND dr.media_id = ? AND dr.baitai_id = ?"
		;
		$bf_reward = $objQuery->getOne($sql, array($close_date, $row["asp_id"], $row["media_id"], $row["baitai_id"]));
		if (empty($bf_reward)) {
			return 0;
		} else {
			return $bf_reward;
		}
	}

	/**
	 * 検索結果をCSVダウンロード
	 */
	public function lfGetOutputRewardAdjust($sdate, $edate, &$objFormParam) {
		$objQuery = SC_Query_Ex::getSingletonInstance();
		$objCSV = new SC_Helper_CSV_Ex();
		$arrRet = $objFormParam->getHashArray();

		// 出力データ
		$arrResults = $this->lfGetRewardAdjust($sdate, $edate, $objFormParam);

		// 検索条件ヘッダ
		$arrCsv = array();
		if (isset($arrRet["search_asp"]) && !empty($arrRet["search_asp"])) {
			// ASP
			$arrCsv[] = array("ASP", $this->arrAsp[$arrRet["search_asp"]]);
		}
		if (isset($arrRet["search_media"]) && !empty($arrRet["search_media"])) {
			// メディア
			$arrCsv[] = array("メディア", $this->arrMedia[$arrRet["search_media"]]);
		}
		if (isset($arrRet["search_baitai"]) && !empty($arrRet["search_baitai"])) {
			// 媒体
			$arrCsv[] = array("媒体", $this->arrBaitai[$arrRet["search_baitai"]]);
		}
		$before_title = "【変更前".$arrRet["search_target_span"]."週間】\n";
		$after_title = "【変更後".$arrRet["search_target_span"]."週間】\n";

		$arrCsv[] = array("単価開始日", date("Y年m月d日", strtotime($sdate)). " 〜 ".date("Y年m月d日", strtotime($edate)));
		$arrCsv[] = array();
		$arrCsv[] = array("ASP","メディア","媒体","開始日","終了日","変更前単価","変更後単価",$before_title."PV",$before_title."CV",$before_title."CVR",$before_title."1日平均PV",$before_title."1日平均CV",$after_title."PV",$after_title."CV",$after_title."CVR",$after_title."1日平均PV",$after_title."1日平均CV","【誤差】\nCVR","【誤差】\n1日平均PV","【誤差】\n1日平均CV");
		// 実績データ
		foreach($arrResults as $key => $data) {
			$start_date = date("m月d日", strtotime($data["start_date"]));
			$close_date = empty($data["close_date"]) ? "" : date("m月d日", strtotime($data["close_date"]));
			$before_reward = empty($data["before_reward"]) ? "" : number_format($data["before_reward"])."円";
			$arrCsv[] = array(
				$data["asp_name"],
				$data["media_name"],
				$data["baitai_name"],
				$start_date,
				$close_date,
				$before_reward,
				number_format($data["reward"])."円",
				number_format($data["before_pv"]),
				number_format($data["before_cv"]),
				$data["before_cvr"]."%",
				number_format($data["before_ave_pv"]),
				number_format($data["before_ave_cv"]),
				number_format($data["after_pv"]),
				number_format($data["after_cv"]),
				$data["after_cvr"]."%",
				number_format($data["after_ave_pv"]),
				number_format($data["after_ave_cv"]),
				$data["diff_cvr"]."%",
				number_format($data["diff_ave_pv"]),
				number_format($data["diff_ave_cv"])
			);
		}
		// 出力
		$objCSV->lfDownloadCsv($arrCsv, "単価調整確認データ_");
		return true;
	}

	/**
	 * それぞれの配列からセレクトボックス表示用配列を作成
	 * @param array $arrAsp		ASP一覧
	 * @param array $arrMedia	メディア一覧
	 * @param array $arrBaitai	媒体一覧
	 */
	public function lfSetSelectHtml($arrAsp, $arrMedia, $arrBaitai) {
		// ASP
		$arrAspSelect = array();
		foreach($arrAsp as $data) {
			$arrAspSelect[$data["id"]] = $data["name"];
		}
		// メディア
		$arrMediaSelect = array();
		foreach($arrMedia as $data) {
			$arrMediaSelect[$data["id"]] = $data["name"];
		}
		// 媒体
		$arrBaitaiSelect = array();
		foreach($arrBaitai as $data) {
			$arrBaitaiSelect[$data["id"]] = $data["name"];
		}
		return array($arrAspSelect, $arrMediaSelect, $arrBaitaiSelect);
	}

	/**
	 * デストラクタ.
	 *
	 * @return void
	 */
	function destroy() {
		parent::destroy();
	}
}
