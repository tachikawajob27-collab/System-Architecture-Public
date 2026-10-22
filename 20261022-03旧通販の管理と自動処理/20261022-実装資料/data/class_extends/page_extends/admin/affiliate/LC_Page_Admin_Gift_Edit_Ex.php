
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

require_once CLASS_EX_REALDIR . 'page_extends/admin/affiliate/LC_Page_Admin_Gift_Ex.php';

class LC_Page_Admin_Gift_Edit_Ex extends LC_Page_Admin_Gift_Ex
{
	/**
	 * 初期化
	 */
	public function init()
	{
		parent::init();

		$this->tpl_mainpage   = 'affiliate/gift_edit.tpl';
		$this->tpl_mainno     = 'affiliate';
		$this->tpl_subno      = 'gift';
		$this->tpl_maintitle  = '広告管理';
		$this->tpl_subtitle   = 'おまけ登録';

		// 使用状況（フォームには表示しないが検索で使う可能性あり）
		$this->arrApply = array(
			0 => "使用中",
			1 => "未使用"
		);

		// プレゼント商品一覧を取得（カテゴリID = 14）
		$this->arrProduct = $this->getExchangeProductList($_SESSION["brand_id"]);

		// 商品カテゴリ一覧（セレクトボックス用）
		$objBrand = new SC_Helper_Brand_Ex();
		$this->arrCategory = $objBrand->getBrandCategoryList($_SESSION["brand_id"]);

		// ASP一覧
		$objAsp = new SC_Helper_Asp_Ex();
		$arrAsp = $objAsp->getList();
		// 媒体一覧
		$objBaitai = new SC_Helper_Baitai_Ex();
		$arrBaitai = $objBaitai->getList();
		// HTML表示用に整形
		list($this->arrAsp,$this->arrBaitai) = $this->lfSetSelectHtml($arrAsp,$arrBaitai);
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
	 * メイン処理
	 */
	public function action()
	{
		// 初期化
		$this->arrResults = [];
		$this->arrGiftList = [];
		if (!isset($_SESSION['transactionid'])) {
		$_SESSION['transactionid'] = md5(uniqid(mt_rand(), true));
		}
		$this->transactionid = $_SESSION['transactionid'];
		$objFormParam = new SC_FormParam_Ex();

		// 初期化（フォーム定義）
		$this->lfInitFormParam($objFormParam, $_POST);

		$mode = $this->getMode();

		switch ($mode) {
			// 編集前（既存データをフォームにセット）
			case 'pre_edit':
			case "copy":
				$id = $_POST['id'] ?? null;
				if (empty($id)) trigger_error("IDが指定されていません。", E_USER_ERROR);

				$arrData = $this->lfGetGiftData($id);
				if (!$arrData) trigger_error("データが存在しません。", E_USER_ERROR);
				if (empty($this->arrForm['start_date'])) {
					$this->arrForm['start_date'] = date('Y-m-d'); 
				}

				// DBの値を FormParam にセット
				$objFormParam->setParam($arrData);
				$this->arrForm = $this->dbRowToForm($arrData);
				$this->arrForm['id'] = $arrData['id'];
				// 除外ASPと媒体IDと除外URLを取得
				list($aspIds,$baitaiIds) = $this->lfGetAspBaitaiData($id);
				$this->arrForm["asp_ids"] = $aspIds;
				$this->arrForm["baitai_ids"] = $baitaiIds;
				break;

			// 登録（新規 or 更新）
			case 'register':
				// POSTをFormParamへセットして正規化
				// $objFormParam->setParam($_POST);
				// $objFormParam->convParam();
				$arrForm = $objFormParam->getHashArray();
// GC_Utils_Ex::gfPrintLog("arrForm=".serialize($arrForm));

				$editId = !empty($_POST['id']) ? intval($_POST['id']) : null;

				// バリデーション / 重複チェック
				$this->arrErr = $this->lfCheckError($objFormParam, $arrForm, $editId);

				if (empty($this->arrErr)) {
					// DB登録（新規/更新）
					$newId = $this->lfRegister($arrForm);
					// 登録完了後はリダイレクト
					$this->tpl_mainpage = 'affiliate/gift_complete.tpl';
				}

				// エラー時は入力値を保持してフォーム再表示
				$this->arrForm = $arrForm;
				break;

			default:
				// 新規表示用の空デフォルト
				$this->arrForm = array(
					'offer_name'      => '',
					'parameter_key'   => '',
					'parameter_value' => '',
					'product_id'      => '',
					'product_count'   => '',
					'lp_change_class' => '',
					'lp_change_url'   => '',
					'discount_price'  => '',
					'start_date'      => date('Y-m-d'),
					'end_date'        => '',
					'apply_type'      => 0,
					'category_id'     => 0
				);
				break;
		}

		// 複製の場合、画像ファイルコピー
		if ($mode == 'copy') {
			$this->arrForm['copy_id'] = $this->arrForm['id'];
			$this->arrForm['id'] = '';
		}
	}

	/**
	 * FormParam 設定
	 */
	public function lfInitFormParam(&$objFormParam, $arrPost)
	{
		$objFormParam->addParam("ID", "id", INT_LEN, 'n', array("NUM_CHECK"));

		// offer_name (おまけ名)
		$objFormParam->addParam("オファーネーム", "offer_name", STEXT_LEN, 'KVa', array("EXIST_CHECK", "MAX_LENGTH_CHECK"));

		// カテゴリID
		$objFormParam->addParam('商品カテゴリ', 'category_id', INT_LEN, 'n', array('EXIST_CHECK', 'NUM_CHECK', 'MAX_LENGTH_CHECK'));

		// parameter_key (おまけ名／種類)
		// $objFormParam->addParam("パラメータキー", "parameter_key", STEXT_LEN, 'KVa', array("EXIST_CHECK", "MAX_LENGTH_CHECK"));
		$objFormParam->addParam("パラメータキー", "parameter_key", STEXT_LEN, 'KVa', array("MAX_LENGTH_CHECK"));

		// parameter_value (値)
		// $objFormParam->addParam("パラメータ値", "parameter_value", STEXT_LEN, 'KVa', array("EXIST_CHECK", "MAX_LENGTH_CHECK"));
		$objFormParam->addParam("パラメータ値", "parameter_value", STEXT_LEN, 'KVa', array("MAX_LENGTH_CHECK"));

		// プレゼント商品（カテゴリID=14の商品の product_id を選択）
		$objFormParam->addParam("プレゼント商品", "product_id", INT_LEN, 'n', array("NUM_CHECK"));

		// 個数
		$objFormParam->addParam("個数", "product_count", INT_LEN, 'n', array("NUM_CHECK"));

		// LPクラス / LP URL
		$objFormParam->addParam("LPクラス", "lp_change_class", STEXT_LEN, 'KVa', array("MAX_LENGTH_CHECK"));
		$objFormParam->addParam("LP URL", "lp_change_url", LTEXT_LEN, 'KVa', array("MAX_LENGTH_CHECK"));

		// 値引額
		$objFormParam->addParam("値引額", "discount_price", INT_LEN, 'n', array("NUM_CHECK"));

		// 日付
		$objFormParam->addParam("開始日", "start_date", STEXT_LEN, 'KVa', array("EXIST_CHECK", "MAX_LENGTH_CHECK"));
		$objFormParam->addParam("終了日", "end_date", STEXT_LEN, 'KVa', array("MAX_LENGTH_CHECK"));

		// 状態（フォームでは非表示にしているが保持）
		$objFormParam->addParam("使用状況", "apply_type", INT_LEN, 'n', array("NUM_CHECK"));

		// 両方に値がない場合は消す
		if (!empty($arrPost['asp_ids']) && !empty($arrPost['baitai_ids'])) {
			$arrPost['asp_ids'] = json_decode($arrPost['asp_ids'],true);
			$arrPost['baitai_ids'] = json_decode($arrPost['baitai_ids'],true);

			// 多い方を基準にする
			$count = max(count($arrPost['asp_ids']),count($arrPost['baitai_ids']));
			for ($i = 0; $i < $count; $i++) {
				// 両方空なら消す
				if (empty($arrPost['asp_ids'][$i]) && empty($arrPost['baitai_ids'][$i])) {
					array_splice($arrPost['asp_ids'],$i,1);
					array_splice($arrPost['baitai_ids'],$i,1);
				}
			}
		}

		if (!empty($arrPost['asp_ids'])) {
			$objFormParam->addParam('ASP', "asp_ids", INT_LEN, 'n', array('NUM_CHECK', 'MAX_LENGTH_CHECK'));
		}

		if (!empty($arrPost['baitai_ids'])) {
			$objFormParam->addParam('媒体', "baitai_ids", INT_LEN, 'n', array('NUM_CHECK', 'MAX_LENGTH_CHECK'));
		}

		$objFormParam->setParam($arrPost);
		$objFormParam->convParam();
	}

	/**
	 * プレゼント商品（productsテーブルのカテゴリID=14）おまけの取得
	 * 
	 */
	public function getExchangeProductList($brand_id) {
		$objQuery = SC_Query_Ex::getSingletonInstance();
		$arrResult = array();
		$arrData = $objQuery->getAll("SELECT * FROM dtb_products dp WHERE dp.brand_id = ? AND dp.category_id = 14 ORDER BY dp.product_id DESC", array($brand_id));
		foreach($arrData as $data) {
			$arrResult[$data["product_id"]] = $data["product_id"] . " - " . $data["name"];
		}
		return $arrResult;
	}

	/**
	 * DB から1行取得（編集用）
	 */
	public function lfGetGiftData($id)
	{
		$objQuery = SC_Query_Ex::getSingletonInstance();
		return $objQuery->getRow("*", "dtb_chat_scenario_gift", "id = ? AND dlt_flg = 0", array($id));
	}

	/**
	 * DB行 -> フォーム配列 に変換（編集時のマッピング）
	 */
	protected function dbRowToForm($row)
	{
		// DBカラム名は既存設計に合わせる：gift_id, quantity, discount, lp_change_class, lp_change_url
		return array(
			'id'              => isset($row['id']) ? $row['id'] : '',
			'offer_name'      => isset($row['offer_name']) ? $row['offer_name'] : '',
			'parameter_key'   => isset($row['parameter_key']) ? $row['parameter_key'] : '',
			'parameter_value' => isset($row['parameter_value']) ? $row['parameter_value'] : '',
			// present product mapping: DB uses gift_id
			'product_id'      => isset($row['gift_id']) ? $row['gift_id'] : '',
			'product_count'   => isset($row['quantity']) ? $row['quantity'] : '',
			'lp_change_class' => isset($row['lp_change_class']) ? $row['lp_change_class'] : '',
			'lp_change_url'   => isset($row['lp_change_url']) ? $row['lp_change_url'] : '',
			'discount_price'  => isset($row['discount']) ? $row['discount'] : '',
			'start_date'      => isset($row['start_date']) ? $row['start_date'] : '',
			'end_date'        => isset($row['end_date']) ? $row['end_date'] : '',
			'dlt_flg'      => isset($row['dlt_flg']) ? $row['dlt_flg'] : 0,
			'apply_type' => isset($row['apply_type']) ? $row['apply_type'] : 0,
			'category_id' => isset($row['category_id']) ? $row['category_id'] : 0,
		);
	}

	/**
	 * バリデーション / 重複チェック
	 */
	public function lfCheckError(&$objFormParam, $arrForm)
	{
		$arrErr = $objFormParam->checkError();
		$objQuery = SC_Query_Ex::getSingletonInstance();


		// parameter_key の重複チェック（新規登録時のみチェック）
		// parameter_key と parameter_value の組み合わせチェック（組み合わせ重複時エラー）
		// if (!empty($arrForm['parameter_key']) && !empty($arrForm['parameter_value'])) {

		// 	$where = "parameter_key = ? AND parameter_value = ? AND dlt_flg = 0";
		// 	$params = [
		// 		$arrForm['parameter_key'],
		// 		$arrForm['parameter_value']
		// 	];

			// 編集時は自分自身を除外
			if (!empty($arrForm['id'])) {
				$where .= " AND id <> ?";
				$params[] = $arrForm['id'];
			}

		// 	if ($objQuery->count('dtb_chat_scenario_gift', $where, $params) > 0) {
		// 		$arrErr['parameter_value'] = "この「パラメータ値 × パラメータキー」の組み合わせは既に登録されています。";
		// 	}
		// }


		// product_count チェック
		if ($arrForm['product_count'] === null || $arrForm['product_count'] === '') {
			$arrErr['product_count'] = '※ 個数を入力してください';
		} elseif (!is_numeric($arrForm['product_count']) || intval($arrForm['product_count']) < 1) {
			$arrErr['product_count'] = "個数は1以上の整数で入力してください。";
		}

		// discount_price チェック
		if ($arrForm['discount_price'] !== '' && (!is_numeric($arrForm['discount_price']) || intval($arrForm['discount_price']) < 0)) {
			$arrErr['discount_price'] = "値引額は0以上の整数で入力してください。";
		}

		// 日付チェック（開始 <= 終了）
		if (!empty($arrForm['start_date']) && !empty($arrForm['end_date'])) {
			$start = strtotime($arrForm['start_date']);
			$end   = strtotime($arrForm['end_date']);
			if ($start === false || $end === false) {
				$arrErr['start_date'] = "日付の形式が正しくありません。";
			} elseif ($start > $end) {
				$arrErr['end_date'] = "終了日は開始日より後の日付を指定してください。";
			}
		}

		// ASP,媒体チェック
		if (!empty($arrForm['asp_ids']) && !empty($arrForm['baitai_ids'])) {
			$count = max(count($arrForm['asp_ids']),count($arrForm['baitai_ids']));
			for ($i = 0; $i < $count; $i++) {
				// どちらかが空ならエラーにする
				if (empty($arrForm['asp_ids'][$i])) {
					$arrErr['asp_ids'][$i] = 'ASPを選択してください。';
				}
				if ($arrForm['baitai_ids'][$i] == "") {
					$arrErr['baitai_ids'][$i] = '媒体を選択してください。';
				}
			}
		}

		return $arrErr;
	}

	/**
	 * DB登録（新規/更新）
	 */
	public function lfRegister($arrForm)
	{
		$objQuery = SC_Query_Ex::getSingletonInstance();

		// フォーム名 -> DBカラム名 のマッピング
		$sqlVal = array(
			'offer_name'      => $arrForm['offer_name'],
			'parameter_key'   => $arrForm['parameter_key'],
			'parameter_value' => $arrForm['parameter_value'],
			'category_id'     => $arrForm['category_id'],

			// DB 側カラム名に合わせる
			'gift_id'         => $arrForm['product_id'],        // product_id -> gift_id
			'quantity'        => $arrForm['product_count'],     // product_count -> quantity
			'lp_change_class' => $arrForm['lp_change_class'],   // lp_class -> lp_change_class
			'lp_change_url'   => $arrForm['lp_change_url'],     // lp_url -> lp_change_url
			'discount'        => $arrForm['discount_price'],    // discount_price -> discount

			'start_date'      => $arrForm['start_date'],
			'end_date'        => $arrForm['end_date'],

			'brand_id'        => $_SESSION['brand_id'],
			'apply_type'      => isset($arrForm['apply_type']) ? $arrForm['apply_type'] : 0,
			'dlt_flg'         => 0,
			'updated_at'      => 'NOW()',
		);

		if (empty($arrForm['id'])) {
			// 新規
			$id = $objQuery->nextVal('dtb_chat_scenario_gift_id');
			$sqlVal['id'] = $id;
			$sqlVal['created_at'] = 'NOW()';
			$objQuery->insert('dtb_chat_scenario_gift', $sqlVal);
		} else {
			// 更新
			$objQuery->update('dtb_chat_scenario_gift', $sqlVal, 'id = ?', array($arrForm['id']));
			$id = $arrForm['id'];
		}

		// ASP・媒体紐付け設定
		$where = "";
		$arrWhere = array();
		// 1回消す
		if (!empty($arrForm["id"])) {
			$where = 'offer_id = ?';
			$arrWhere = array($arrForm["id"]);
			$objQuery->delete('dtb_chat_scenario_gift_mapping',$where,$arrWhere);
		}

		if (!empty($arrForm['asp_ids']) && !empty($arrForm['baitai_ids'])) {
			$count = max(count($arrForm['asp_ids']),count($arrForm['baitai_ids']));
			for ($i = 0; $i < $count; $i++) {
				$sqlRejectVal = array();
				$sqlRejectVal['offer_id'] = $id;
				$sqlRejectVal['asp_id'] = $arrForm['asp_ids'][$i];
				$sqlRejectVal['baitai_id'] = $arrForm['baitai_ids'][$i];
				$sqlRejectVal['create_date'] = "NOW()";
				$objQuery->insert('dtb_chat_scenario_gift_mapping', $sqlRejectVal);
			}
		}
		return $id;
	}

	/**
	 * DBからASPID・媒体IDを取得する
	 *
	 * @param  integer $id リマケID
	 * @return array   ASPID,媒体ID
	 */
	public function lfGetAspBaitaiData($id)
	{
		$objQuery = SC_Query_Ex::getSingletonInstance();

		$col = 'asp_id,baitai_id';
		$table = "dtb_chat_scenario_gift_mapping";
		$where = 'offer_id = ?';
		$idsAspBaitai = $objQuery->select($col, $table, $where, array($id));

		$aspIds = array();
		$baitaiIds = array();
		if (!empty($idsAspBaitai)) {
			$aspIds = array_column($idsAspBaitai,'asp_id');
			$baitaiIds = array_column($idsAspBaitai,'baitai_id');
		}
		return array($aspIds,$baitaiIds);
	}

	/**
	 * それぞれの配列からセレクトボックス表示用配列を作成
	 * @param array $arrAsp		ASP一覧
	 * @param array $arrBaitai	媒体一覧
	 */
	public function lfSetSelectHtml($arrAsp,$arrBaitai) {
		// ASP
		$arrAspSelect = array();
		foreach($arrAsp as $data) {
			$arrAspSelect[$data["id"]] = $data["name"];
		}
		$arrAspSelect[0] = "ALL";
		// 媒体
		$arrBaitaiSelect = array();
		foreach($arrBaitai as $data) {
			$arrBaitaiSelect[$data["id"]] = $data["name"];
		}
		$arrBaitaiSelect[0] = "ALL";
		return array($arrAspSelect,$arrBaitaiSelect,$arrModelSelect);
	}
}
