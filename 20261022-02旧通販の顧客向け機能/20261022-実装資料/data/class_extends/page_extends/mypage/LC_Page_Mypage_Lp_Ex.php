<?php
/*
 * マイページ内 商品LP の共通ページクラス
 *
 * LC_Page_Mypage_Teiki_Ex を継承している。理由は表示だけの都合ではなく、
 * 親定期の特定ロジック（10日前カットオフ・代替定期へのフォールバック）と
 * add_onetime / add_teiki / add_teiki_standalone の処理をそのまま使うため。
 * ここで再実装すると teiki.php 側と二重管理になり、必ず片方が腐る。
 *
 * 商品ごとの違い（商品ID・画像・表示名・LPのファイル名）はサブクラスが
 * プロパティで持つだけ。テンプレートは mypage/lp_product.tpl の共通1枚。
 */
require_once CLASS_EX_REALDIR . 'page_extends/mypage/LC_Page_Mypage_Teiki_Ex.php';

class LC_Page_Mypage_Lp_Ex extends LC_Page_Mypage_Teiki_Ex
{
    /** @var int LP対象の商品ID。サブクラスで指定する */
    protected $lp_product_id = null;

    /** @var string 画像ディレクトリ名（user_data/packages/img/mypage/lp/{ここ}/） */
    protected $lp_image_dir = '';

    /** @var string 画面表示・確認ダイアログで使う商品名 */
    protected $lp_product_label = '';

    /** @var string このLPのPHPファイル名（フォームのpost先） */
    protected $lp_page = '';

    /** @var array LP画像。array(array('file' => ..., 'alt' => ...), ...) を上から順に積む */
    protected $lp_images = array();

    /** @var int */
    public $tpl_lp_product_id;
    /** @var string */
    public $tpl_lp_product_label;
    /** @var string */
    public $tpl_lp_image_dir;
    /** @var array */
    public $tpl_lp_images;
    /** @var string */
    public $tpl_lp_page;
    /** @var string GA・note に残す流入元 */
    public $tpl_lp_from_source;

    /**
     * @var string 追加完了後の戻り先。'teiki' なら定期コース管理、それ以外はホーム画面。
     *             LPは定期コース管理（teiki.tpl）とホーム画面（index.tpl）の両方から
     *             リンクされているので、入ってきた画面に戻せるよう ?back= で受け取る。
     */
    public $tpl_lp_back_to;

    /**
     * @var bool マイページ共通のフッター固定バナーを抑止する。
     *           LPは自前の追従CTAを画面下部に固定するので、出すと2段に重なる。
     *           バナーの遷移先（teiki.php?scroll=recommend）もLP閲覧中は役割が重複する。
     */
    public $tpl_hide_footer_banner = true;

    public function init()
    {
        parent::init();
        $this->tpl_mainpage = 'mypage/lp_product.tpl';
        $this->tpl_subtitle = $this->lp_product_label;
        $this->httpCacheControl('nocache');
    }

    public function process()
    {
        parent::process();
    }

    public function action()
    {
        // add_onetime / add_teiki / add_teiki_standalone と
        // 親定期・レコメンドの各種アサインはすべて親に任せる
        parent::action();

        $this->tpl_lp_product_id    = $this->lp_product_id;
        $this->tpl_lp_product_label = $this->lp_product_label;
        $this->tpl_lp_image_dir     = $this->lp_image_dir;
        $this->tpl_lp_images        = $this->lp_images;
        $this->tpl_lp_page          = $this->lp_page;
        $this->tpl_lp_from_source   = 'lp_' . $this->lp_image_dir;

        // 戻り先。想定外の値はホーム画面（既定）に倒す
        $back = isset($_GET['back']) ? $_GET['back'] : '';
        $this->tpl_lp_back_to = ($back === 'teiki') ? 'teiki' : 'index';
    }

    public function destroy()
    {
        parent::destroy();
    }
}
