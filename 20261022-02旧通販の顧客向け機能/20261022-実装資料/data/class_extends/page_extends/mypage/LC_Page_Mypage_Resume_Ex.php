<?php
require_once CLASS_REALDIR . 'pages/mypage/LC_Page_Mypage_Resume.php';
require_once CLASS_REALDIR . 'helper/SC_Helper_Teiki_Renew.php';
require_once CLASS_EX_REALDIR . 'helper_extends/SC_Helper_Purchase_Ex.php';
require_once CLASS_EX_REALDIR . 'SC_Date_Ex.php';

/**
 * 定期再開検討ページクラス(拡張).
 */
class LC_Page_Mypage_Resume_Ex extends LC_Page_Mypage_Resume
{
    /** @var string */
    public $teiki_next_shipping_year;
    /** @var string */
    public $teiki_next_shipping_month;
    /** @var string */
    public $teiki_next_shipping_day;
    /** @var array */
    public $arrTeikiNextShippingYear;
    /** @var array */
    public $arrMonthShippingDate;
    /** @var array */
    public $arrDayShippingDate;
    /** @var string */
    public $feature_shipping_date;
    /** @var int */
    public $tpl_model_first_charge;
    /** @var int */
    public $tpl_total_subtotal;
    /** @var int */
    public $tpl_total_amount;
    /** @var string */
    public $tpl_payment_method;
    /** @var string */
    public $tpl_min_date;

    /**
     * Page を初期化する.
     *
     * @return void
     */
    public function init()
    {
        parent::init();
    }

    /**
     * POST アクセスの妥当性を検証する.
     *
     * @param boolean $is_admin
     * @return void
     */
    public function doValidToken($is_admin = false)
    {
        // 読取専用のAJAX POSTではトークンチェックをスキップする（トークン破棄を防ぐため）
        $mode = $this->getMode();
        if ($mode === 'change_feature_shipping_date' || $mode === 'get_details') {
            return;
        }
        parent::doValidToken($is_admin);
    }

    /**
     * Page のプロセス.
     *
     * @return void
     */
    public function process()
    {
        parent::process();
    }

    /**
     * Page のAction.
     *
     * @return void
     */
    public function action()
    {
        $objCustomer = new SC_Customer_Ex();
        $this->customer_id = $objCustomer->getValue('customer_id');

        $objHelperTeiki = new SC_Helper_Teiki_Renew();
        $objDate = new SC_Date_Ex(RELEASE_YEAR);

        // 1. AJAX詳細取得モードの場合は最優先で処理して終了する
        $mode = $this->getMode();
        if ($mode === 'get_details' || $_REQUEST['mode'] === 'get_details') {
            $arrTeiki = $this->lfGetTargetTeiki($this->customer_id, $_REQUEST['course_id']);
            
            if (empty($arrTeiki)) {
                echo SC_Utils_Ex::jsonEncode(array('error' => 'target_not_found'));
                exit;
            }

            // 最短お届け日の計算 (SC_Helper_Purchase_Ex を使用)
            $objPurchase = new SC_Helper_Purchase_Ex();
            $tpl_min_date = $objPurchase->lfGetHolidayOfShippingDate(array("create_date" => date("Y/m/d H:i:s")));
            
            if (empty($tpl_min_date)) {
                $tpl_min_date = date('Y/m/d', strtotime('+3 days'));
            }

            // 手数料 (model_first_charge) の取得
            $objQuery = SC_Query_Ex::getSingletonInstance();
            $model_first_charge = (int)$objQuery->getOne("SELECT model_first_charge FROM dtb_customer WHERE customer_id = ?", array($this->customer_id));

            $subtotal_all = 0;
            $items = array();
            
            // リクエストから数量の配列(product_id => quantity)を取得
            $req_quantities = $_REQUEST['quantities'];

            foreach ($arrTeiki as $t) {
                $product_id = $t['product_id'];
                $quantity = $t['quantity'];
                
                // リクエストに数量があれば上書き
                if (isset($req_quantities[$product_id])) {
                    $quantity = (int)$req_quantities[$product_id];
                    // 小計の再計算 (単価 * 数量)
                    $subtotal_tax_included = (int)$t['price_tax_included'] * $quantity;
                } else {
                    $subtotal_tax_included = (int)$t['subtotal_tax_included'];
                }

                $subtotal_all += $subtotal_tax_included;
                $items[] = array(
                    'product_id' => $product_id,
                    'name' => $t['product_name'] ? $t['product_name'] : $t['name_official'],
                    'price' => $t['price_tax_included'],
                    'quantity' => $quantity,
                    'subtotal' => $subtotal_tax_included,
                    'model_first_charge' => $model_first_charge,
                    'item_total' => $subtotal_tax_included, // モーダル内の各商品行には手数料を乗せない（合計で加算するため）
                    'arrProductOption' => $this->lfGetProductOptionList($product_id)
                );
            }
            
            // 手数料は最後に1回だけ合計に足す
            $total = $subtotal_all + $model_first_charge;
            
            // 年と月の選択肢生成
            $current_year = date('Y');
            $arrYear = array();
            for ($i = 0; $i < 2; $i++) {
                $arrYear[$current_year + $i] = ($current_year + $i);
            }
            $arrMonth = $objDate->getMonth();
            // 年の選択肢（今月〜6ヶ月後まで）
            $max_date = strtotime("+6 month");
            $y1 = date("Y");
            $y2 = date("Y", $max_date);
            $arrYear = array();
            $arrYear[$y1] = $y1;
            if ($y1 != $y2) $arrYear[$y2] = $y2;

            // 月の選択肢（6ヶ月分）
            $arrMonth = array();
            $first_day_of_month = strtotime(date("Y-m-01"));
            for ($i = 0; $i <= 6; $i++) {
                $m = date('n', strtotime("+$i month", $first_day_of_month));
                $arrMonth[$m] = $m;
            }

            // 支払い方法の取得 (顧客データから)
            $payment_method = $objQuery->getOne("SELECT payment_method FROM dtb_customer WHERE customer_id = ?", array($this->customer_id));

            $data = array(
                'course_name' => $arrTeiki[0]['course_name'] ? $arrTeiki[0]['course_name'] : '定期コース',
                'subtotal' => $subtotal_all,
                'commission' => $model_first_charge,
                'total' => $total,
                'items' => $items,
                'tpl_min_date' => $tpl_min_date,
                'next_shipping_date' => date('Y年m月d日', strtotime($tpl_min_date)),
                'arrTeikiNextShippingYear' => $arrYear,
                'arrMonthShippingDate' => $arrMonth,
                'payment_method' => $payment_method,
                'cancel_policy' => '定期コースの解約・変更は次回発送予定日の10日前までにお願いいたします。' . "\n" . '※解約の申し出があるまで、継続してお届けいたします。'
            );

            echo SC_Utils_Ex::jsonEncode($data);
            exit;
        }

        $this->course_id = $_REQUEST['course_id'];
        if (empty($this->course_id)) {
            SC_Response_Ex::sendRedirect($_SESSION["arrBrandData"]["mypage_url"]);
            return;
        }

        $objHelperTeiki = new SC_Helper_Teiki_Renew();

        switch ($this->getMode()) {
            case 'resume':
                $this->doResume();
                break;


            default:
                // 再開対象の定期データを取得
                $this->arrTeiki = $this->lfGetTargetTeiki($this->customer_id, $this->course_id);
                if (empty($this->arrTeiki)) {
                    SC_Response_Ex::sendRedirect($_SESSION["arrBrandData"]["mypage_url"] . "?resume_error=no_items");
                    return;
                }

                // 手数料 (model_first_charge) の取得
                $objQuery = SC_Query_Ex::getSingletonInstance();
                $this->tpl_model_first_charge = $objQuery->getOne("SELECT model_first_charge FROM dtb_customer WHERE customer_id = ?", array($this->customer_id));

                foreach ($this->arrTeiki as $key => &$item) {
                    // 各商品の手数料と合計をセット
                    // リスト表示（個別再開ボタン）用なので、個別商品ごとに手数料を乗せた値をセットする
                    $item['model_first_charge'] = $this->tpl_model_first_charge;
                    $item['item_total'] = $item['subtotal_tax_included'] + $this->tpl_model_first_charge;

                    // 商品のオプション（香り）リストを取得
                    $item['arrProductOption'] = $this->lfGetProductOptionList($item['product_id']);
                }
                unset($item);

                if (empty($this->arrTeiki)) {
                    // 全ての商品が継続中の場合はマイページトップへ
                    SC_Response_Ex::sendRedirect($_SESSION["arrBrandData"]["mypage_url"] . "?resume_error=no_items");
                    return;
                }

                // --- テンプレート用の日付選択データ準備 (祝日・曜日を考慮した動的な最短日) ---
                $objPurchase = new SC_Helper_Purchase_Ex();
                $shipping_date = $objPurchase->lfGetHolidayOfShippingDate(array("create_date" => date("Y/m/d H:i:s")));
                $min_date = strtotime($shipping_date);
                $this->tpl_min_date = $shipping_date;

                $max_date = strtotime("+6 month");

                $this->teiki_next_shipping_year = date("Y", $min_date);
                $this->teiki_next_shipping_month = date("n", $min_date);
                $this->teiki_next_shipping_day = date("j", $min_date);

                // 年の選択肢（今月〜6ヶ月後まで）
                $y1 = date("Y");
                $y2 = date("Y", $max_date);
                $this->arrTeikiNextShippingYear[$y1] = $y1;
                if ($y1 != $y2) $this->arrTeikiNextShippingYear[$y2] = $y2;

                // 月の選択肢（6ヶ月分）
                $first_day_of_month = strtotime(date("Y-m-01"));
                for ($i = 0; $i <= 6; $i++) {
                    $m = date('n', strtotime("+$i month", $first_day_of_month));
                    $this->arrMonthShippingDate[$m] = $m;
                }

                // 日の選択肢 (1-31)
                $objDate = new SC_Date_Ex(RELEASE_YEAR);
                $this->arrDayShippingDate = $objDate->getDay(true);


                // 支払い方法の名称取得（顧客データから）
                $this->tpl_payment_method = $objQuery->getOne("SELECT payment_method FROM dtb_customer WHERE customer_id = ?", array($this->customer_id));
                break;
        }
    }

    /**
     * 定期を再開する
     *
     * @return void
     */
    public function doResume()
    {
        $objQuery = SC_Query_Ex::getSingletonInstance();
        $this->course_id = $_POST['course_id'];
        $target_product_id = $_POST['product_id'];

        // 1. 二重送信防止（同一商品の連続リクエストを弾く）
        $duplicated = $objQuery->getOne(
            "SELECT COUNT(*) FROM dtb_teiki WHERE customer_id = ? AND course_id = ? AND product_id = ? AND create_date > CURRENT_TIMESTAMP - INTERVAL '3 seconds'",
            array($this->customer_id, $this->course_id, $target_product_id)
        );

        if ($duplicated > 0) {
            GC_Utils_Ex::gfPrintLog("Duplicate resume request blocked. customer_id={$this->customer_id}, course_id={$this->course_id}");
            SC_Response_Ex::sendRedirect('resume_complete.php?course_id=' . $this->course_id);
            SC_Response_Ex::actionExit();
        }

        // 2. 再開対象のデータを取得
        $arrTarget = $this->lfGetTargetTeiki($this->customer_id, $this->course_id);
        if (empty($arrTarget)) {
            $this->arrErr['resume'] = '再開可能な商品が見つかりませんでした。';
            return;
        }

        // 3. 次回お届け日の決定
        // ユーザーが選択した日付があればそれを使用、なければデフォルト（最短日）
        $objPurchase = new SC_Helper_Purchase_Ex();
        $shipping_date = $objPurchase->lfGetHolidayOfShippingDate(array("create_date" => date("Y/m/d H:i:s")));
        $min_date = strtotime($shipping_date);
        if (!empty($_POST['next_shipping_date'])) {
            $selected_time = strtotime($_POST['next_shipping_date']);
            // 8日後より前の日付が送られてきた場合は、強制的に最短（8日後）にする（バリデーション補完）
            if ($selected_time < $min_date) {
                $next_shipping_date = date('Y-m-d 00:00:00', $min_date);
            } else {
                $next_shipping_date = date('Y-m-d 00:00:00', $selected_time);
            }
        } else {
            $next_shipping_date = date('Y-m-d 00:00:00', $min_date);
        }

        $objQuery->begin();
        foreach ($arrTarget as $sqlval) {
            // 個別再開の場合のフィルタ
            if ($target_product_id && $sqlval['product_id'] != $target_product_id) {
                continue;
            }

            $teiki_id = $sqlval['teiki_id'];
            $teiki_detail_id = $sqlval['detail_id'];
            $product_id = $sqlval['product_id'];
            $product_name = $sqlval['product_name'] ? $sqlval['product_name'] : $sqlval['name_official'];

            // POSTされた数量があれば上書き
            if (isset($_POST['quantities'][$product_id])) {
                $sqlval['quantity'] = (int)$_POST['quantities'][$product_id];
            }

            // DBにないカラムを削除（unset）
            $remove_keys = array(
                'price', 'tax_rate', 'tax_rule',
                'price_tax_excluded', 'subtotal_tax_excluded',
                'subtotal_tax_included', 'price_tax_included',
                'product_name', 'course_name', 'teiki_id',
                'teiki_count', 'shipping_count', 'model_first_charge'
            );
            foreach ($remove_keys as $key) {
                unset($sqlval[$key]);
            }

            // ステータスを「有効」にし、お届け日を設定
            $sqlval['status'] = 1;
            $sqlval['next_shipping_date'] = $next_shipping_date;
            $sqlval['update_date'] = date('Y-m-d H:i:s');

            // 解約時の情報をリセットし、配送手配を「未（1）」にする
            $sqlval['cancel_date'] = null;
            $sqlval['cancel_teiki_reason_id'] = null;
            $sqlval['note'] = null;
            $sqlval['shipping_arrangement_id'] = 1;

            // detail_id は常に新しく採番し直す
            // ★購入 (SC_Helper_Purchase / SC_Helper_Teiki_Renew) と同じ MDB2 の nextVal を使う。MDB2 は名前に _seq を
            //   足すので実体は dtb_teiki_detail_id_seq_seq。生SQLの nextval('dtb_teiki_detail_id_seq') は誰も進めない
            //   古いシーケンスで、既存の他人の明細IDを掴んでいた (2026-09-29 判明)
            $new_detail_id = $objQuery->nextVal('dtb_teiki_detail_id_seq');
            $sqlval['detail_id'] = $new_detail_id;

            if ($teiki_id) {
                // 既存の teiki_id を維持しつつ、新しいレコードとして挿入（履歴保持）
                $sqlval['teiki_id'] = $teiki_id;
            } else {
                // ID が取れなかった場合は新規作成
                // ★購入と同じ MDB2 の nextVal (実体 dtb_teiki_teiki_id_seq_seq)。上と同じ理由 (2026-09-29)
                $teiki_id = $objQuery->nextVal('dtb_teiki_teiki_id_seq');
                $sqlval['teiki_id'] = $teiki_id;
            }
            $sqlval['create_date'] = date('Y-m-d H:i:s');
            $objQuery->insert("dtb_teiki", $sqlval);

            // 4. 香り（商品オプション）の保存
            $arrProductOption = $this->lfGetProductOptionList($sqlval['product_id']);
            if (!empty($arrProductOption)) {
                foreach ($arrProductOption as $option_id => $option_data) {
                    $key = 'option_' . $option_id . '_' . $sqlval['product_id'];
                    $option_detail_id = $_POST[$key];
                    if ($option_detail_id) {
                        $arrInsertOption = array();
                        $arrInsertOption["teiki_id"] = $teiki_id;
                        $arrInsertOption["teiki_detail_id"] = $new_detail_id;
                        $arrInsertOption["option_id"] = $option_id;
                        $arrInsertOption["option_detail_id"] = $option_detail_id;
                        $arrInsertOption["create_date"] = date('Y-m-d H:i:s');
                        $objQuery->insert("dtb_teiki_option", $arrInsertOption);
                    }
                }
            }

            // 5. 容器構成（ボトル・レフィル）の保存
            // 以前の情報を消さずに、新しい detail_id で新規登録することで履歴を保持する

            // ウォッシュ商品の場合は、1個目＝ボトル(1)、2個目以降＝レフィル(2)とする
            // それ以外の商品は、定期再開時は一律「1:ボトル」とする（ユーザー要望：再開時は期間が空きボトルの劣化も考えられるため）
            $quantity = $sqlval["quantity"];
            if ($product_id == 13 && $quantity >= 1) {
                // 1. ボトル分 (1個)
                $arrNewContainer = array();
                $arrNewContainer["teiki_id"] = $teiki_id;
                $arrNewContainer["detail_id"] = $new_detail_id;
                $arrNewContainer["container_id"] = 1; // ボトル
                $arrNewContainer["quantity"] = 1;
                $objQuery->insert("dtb_teiki_container_quantity", $arrNewContainer);

                // 2. レフィル分 (残りがある場合)
                if ($quantity > 1) {
                    $arrNewContainer["container_id"] = 2; // レフィル
                    $arrNewContainer["quantity"] = $quantity - 1;
                    $objQuery->insert("dtb_teiki_container_quantity", $arrNewContainer);
                }
            } else {
                // 通常商品：全数ボトル
                $arrNewContainer = array();
                $arrNewContainer["teiki_id"] = $teiki_id;
                $arrNewContainer["detail_id"] = $new_detail_id;
                $arrNewContainer["container_id"] = 1; // ボトル
                $arrNewContainer["quantity"] = $quantity;
                $objQuery->insert("dtb_teiki_container_quantity", $arrNewContainer);
            }
        }
        $objQuery->commit();

        SC_Response_Ex::sendRedirect('resume_complete.php?course_id=' . $this->course_id);
        SC_Response_Ex::actionExit();
    }

    /**
     * 商品オプション一覧を取得
     */
    public function lfGetProductOptionList($product_id)
    {
        $objQuery = SC_Query_Ex::getSingletonInstance();
        // 商品オプション情報取得
        $sql = " SELECT dpo.option_id, dpo.option_name, dpo.option_detail_id, dpo.option_detail_name, dpod.product_id"
            . " FROM dtb_products_option dpo"
            . " INNER JOIN dtb_products_option_mapping dpom ON dpo.option_id = dpom.option_id"
            . " INNER JOIN dtb_products_option_disp dpod ON dpom.product_id = dpod.product_id AND dpom.option_id = dpod.option_id AND dpo.option_detail_id = dpod.option_detail_id"
            . " WHERE dpom.product_id = ? AND dpo.is_show_mypage = 1"
            . " ORDER BY dpo.option_id, dpo.option_detail_id";
        $arrList = $objQuery->getAll($sql, array($product_id));

        // 画面表示用に加工
        $arrOption = array();
        foreach ($arrList as $value) {
            $arrOption[$value["option_id"]]["name"] = $value["option_name"];
            $arrOption[$value["option_id"]]["product_id"] = $value["product_id"];
            $arrOption[$value["option_id"]]["detail"][$value["option_detail_id"]]["name"] = $value["option_detail_name"];
        }
        return $arrOption;
    }
}
