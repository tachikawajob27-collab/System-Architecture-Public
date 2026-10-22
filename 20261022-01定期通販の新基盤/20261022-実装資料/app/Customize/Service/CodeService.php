<?php

namespace Customize\Service;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;

/**
 * 2系 LC_Page_Admin_Code の init で集める select choices / 共通取得を1ヶ所に集約.
 *
 * - 検索画面と発行画面の両方で使う ASP / Media / Baitai / Category select を提供
 * - 発行画面用に LPデザイン一覧 (model→course 経由でカテゴリIDを併記)
 * - PostgreSQL関数 func_get_course_main_category の PHP 代替
 */
class CodeService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {}

    /**
     * ★並び順は 2系が画面ごとに二分されている (Issue #1685)。既定は id DESC。
     *
     * - id DESC: `SC_Helper_Asp_Ex::getList()` L48 / `SC_Helper_Media_Ex::getList()` L48 /
     *   `SC_Helper_Baitai_Ex::getList()` L48 がいずれも `$objQuery->setOrder('id DESC')` を
     *   発行する。コード管理/コード発行/モデルチェンジ/リアルタイム信頼度/報酬/リマケ/ギフト等、
     *   このヘルパー経由の画面は全て降順。
     * - id ASC : 集計系 `LC_Page_Admin_Total_Chat_Ex::getAspList/getMediaList/getBaitaiList`
     *   (L695-727) と `LC_Page_Admin_Total_Remake_Ex::getAspList/getBaitaiList` (L510-531) は
     *   ヘルパーを使わず `getAll("... ORDER BY id")` を直接書いており昇順。
     *   → 呼出側で `ascending: true` を渡す。
     *
     * @return array<int, string>
     */
    public function getAspChoices(int $brandId, bool $ascending = false): array
    {
        return $this->fetchSelectMap('dtb_asp', $brandId, $ascending);
    }

    /**
     * 並び順の根拠は `getAspChoices()` の docblock を参照 (既定 = id DESC)。
     *
     * @return array<int, string>
     */
    public function getMediaChoices(int $brandId, bool $ascending = false): array
    {
        return $this->fetchSelectMap('dtb_media', $brandId, $ascending);
    }

    /**
     * 並び順の根拠は `getAspChoices()` の docblock を参照 (既定 = id DESC)。
     *
     * @return array<int, string>
     */
    public function getBaitaiChoices(int $brandId, bool $ascending = false): array
    {
        return $this->fetchSelectMap('dtb_baitai', $brandId, $ascending);
    }

    /**
     * 2系 SC_Helper_Brand::getBrandCategoryList($brandId, true) 相当。
     * brand_id 一致 + is_realtime DESC, id ASC で全カテゴリ。
     *
     * @return array<int, string>
     */
    public function getCategoryChoices(int $brandId): array
    {
        $rows = $this->conn()->fetchAllAssociative(
            'SELECT id, category_name FROM dtb_category WHERE brand_id = :brand_id'
            . ' ORDER BY is_realtime DESC, id',
            ['brand_id' => $brandId],
        );
        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r['id']] = (string) $r['category_name'];
        }
        return $out;
    }

    /**
     * 2系 LC_Page_Admin_Total_Remake_Ex::getCategoryList() (L536-545) 相当。
     * リマケ集計専用のカテゴリ候補: is_realtime=1 のみ(2系は del_flg=0 も条件に持つが、
     * 4系 dtb_category に del_flg 列自体が存在しないため対象外)。
     *
     * getCategoryChoices() は Affiliate/Code 系など他画面と共有の「全カテゴリ」候補のため、
     * リマケ集計だけ絞り込みたい場合はこちらを使う (Issue #864)。
     *
     * @return array<int, string>
     */
    public function getRealtimeCategoryChoices(int $brandId): array
    {
        $rows = $this->conn()->fetchAllAssociative(
            'SELECT id, category_name FROM dtb_category WHERE brand_id = :brand_id AND is_realtime = 1'
            . ' ORDER BY id',
            ['brand_id' => $brandId],
        );
        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r['id']] = (string) $r['category_name'];
        }
        return $out;
    }

    /** @return array<int, string> [0=>'成果', 1=>'予算'] */
    public function getBudgetChoices(): array
    {
        return [0 => '成果', 1 => '予算'];
    }

    /**
     * 一覧画面の検索用 LPデザイン一覧 (lp_id => name)。
     * 呼出元は `CodeSearchType::buildForm` (コード管理 検索フォーム) のみ。
     *
     * 2系 `LC_Page_Admin_Code::init()` L73-74 → `SC_Helper_Lps_Ex`(空override) →
     * `SC_Helper_Lps::getList()` L56-67。引数省略 = `$is_show_close = false` なので
     * L62-64 の `AND status = 0` が必ず付く (Issue #1685)。
     *
     * 並び順が id DESC なのは 2系のシングルトン状態の持ち越しによる。
     * `SC_Query_Ex::getSingletonInstance()` は同一インスタンスを返し、`SC_Query::$order` は
     * `setOrder()` でしか書き換わらず `select()`/`getSql()` は実行後もリセットしない
     * (`SC_Query.php` L39/L368/L488-493)。`LC_Page_Admin_Code::init()` は
     * ASP(L62) → メディア(L66) → 媒体(L70) の順に `setOrder('id DESC')` を出すヘルパーを
     * 呼んでから LP(L74) を引くため、LP の SELECT にも `ORDER BY id DESC` が残る。
     * (同 init L81 のカテゴリは `getAll()` + 明示 ORDER BY なので影響を受けない。)
     *
     * @return array<int, string>
     */
    public function getLpChoices(int $brandId): array
    {
        $rows = $this->conn()->fetchAllAssociative(
            'SELECT id, name FROM dtb_lps WHERE del_flg = 0 AND status = 0 AND brand_id = :brand_id'
            . ' ORDER BY id DESC',
            ['brand_id' => $brandId],
        );
        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r['id']] = (string) $r['name'];
        }
        return $out;
    }

    /**
     * 発行画面用の LP 一覧 (category_id 併記、カテゴリ別フィルタJS用)。
     * 2系 LC_Page_Admin_Code_Issue::lfGetLps 相当。
     *
     * @return array<int, array{id:int, name:string, category_id:?int}>
     */
    public function getLpListWithCategory(int $brandId): array
    {
        $rows = $this->conn()->fetchAllAssociative(
            'SELECT DISTINCT dl.id, dl.name,'
            . ' (SELECT dp.category_id FROM dtb_course_detail dcd'
            . '  INNER JOIN dtb_product dp ON dcd.product_id = dp.id'
            . '  WHERE dcd.course_id = dm.course_id AND dcd.course_type = 0 AND dcd.order_count = 1'
            . '  LIMIT 1) AS category_id'
            . ' FROM dtb_lps dl'
            . ' INNER JOIN dtb_model dm ON dl.model_id = dm.id'
            . ' WHERE dl.del_flg = 0 AND dl.brand_id = :brand_id AND dl.status = 0'
            . ' ORDER BY category_id, dl.id DESC',
            ['brand_id' => $brandId],
        );
        return array_map(static fn($r) => [
            'id' => (int) $r['id'],
            'name' => (string) $r['name'],
            'category_id' => $r['category_id'] !== null ? (int) $r['category_id'] : null,
        ], $rows);
    }

    /**
     * @param bool $ascending true で `ORDER BY id` 昇順 (集計系画面用)。既定は降順。
     *
     * @return array<int, string>
     */
    private function fetchSelectMap(string $table, int $brandId, bool $ascending = false): array
    {
        $order = $ascending ? 'ORDER BY id' : 'ORDER BY id DESC';
        $rows = $this->conn()->fetchAllAssociative(
            "SELECT id, name FROM {$table} WHERE del_flg = 0 AND brand_id = :brand_id {$order}",
            ['brand_id' => $brandId],
        );
        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r['id']] = (string) $r['name'];
        }
        return $out;
    }

    /**
     * 一覧表示専用の名称解決 map (2系 LC_Page_Admin_Code::doSearch の
     * INNER JOIN dtb_lps/dtb_asp/dtb_media/dtb_baitai 相当、del_flgフィルタ無し)。
     *
     * 検索セレクトボックス用の fetchSelectMap() (del_flg=0 必須) とは用途が異なる:
     * こちらは論理削除後も過去に発行済みのコード行の名称を表示し続けるための解決先。
     * brand_id は 2系の `dlc.brand_id = da.brand_id` 等の結合条件と同じくスコープする。
     *
     * @return array<int, string>
     */
    public function getAspNameMap(int $brandId): array
    {
        return $this->fetchNameMap('dtb_asp', $brandId);
    }

    /** @return array<int, string> */
    public function getBaitaiNameMap(int $brandId): array
    {
        return $this->fetchNameMap('dtb_baitai', $brandId);
    }

    /** @return array<int, string> */
    public function getLpNameMap(int $brandId): array
    {
        return $this->fetchNameMap('dtb_lps', $brandId);
    }

    /**
     * メディア名 + 分類名 (2系 code.tpl L206 `{$media_name}({$media_class})` 相当)。
     * mtb_media_class は LEFT JOIN (2系同様、class 未設定なら空の括弧になる)。
     *
     * @return array<int, string>
     */
    public function getMediaNameMap(int $brandId): array
    {
        $rows = $this->conn()->fetchAllAssociative(
            'SELECT dm.id, dm.name, mmc.name AS class_name'
            . ' FROM dtb_media dm'
            . ' LEFT JOIN mtb_media_class mmc ON dm.class = mmc.id'
            . ' WHERE dm.brand_id = :brand_id ORDER BY dm.id',
            ['brand_id' => $brandId],
        );
        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r['id']] = (string) $r['name'] . '(' . (string) ($r['class_name'] ?? '') . ')';
        }
        return $out;
    }

    /**
     * @return array<int, string>
     */
    private function fetchNameMap(string $table, int $brandId): array
    {
        $rows = $this->conn()->fetchAllAssociative(
            "SELECT id, name FROM {$table} WHERE brand_id = :brand_id ORDER BY id",
            ['brand_id' => $brandId],
        );
        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r['id']] = (string) $r['name'];
        }
        return $out;
    }

    private function conn(): Connection
    {
        return $this->entityManager->getConnection();
    }
}
