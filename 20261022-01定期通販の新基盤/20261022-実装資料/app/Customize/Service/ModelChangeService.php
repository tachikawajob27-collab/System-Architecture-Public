<?php

namespace Customize\Service;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Knp\Component\Pager\PaginatorInterface;
use Knp\Component\Pager\Pagination\PaginationInterface;

/**
 * 管理画面：コードモデル変更管理 (2系 LC_Page_Admin_Model_Change_Ex / LC_Page_Admin_Model_Change_Edit_Ex 移植).
 */
class ModelChangeService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly PaginatorInterface $paginator,
    ) {}

    private function conn(): Connection
    {
        return $this->entityManager->getConnection();
    }

    /**
     * ページング付き検索.
     * ソート: dlc.status, dlc.asp_id, dlc.media_id, dlc.baitai_id (2系 order 相当)
     */
    public function getPagination(array $searchData, int $brandId, int $page, int $pageMax): PaginationInterface
    {
        $sql = <<<SQL
            SELECT dlc.*, dl.name AS lp_name, dm.name AS model_name, dm2.name AS model_name2
            FROM dtb_lps_code_model_change dlc
            LEFT JOIN dtb_lps dl ON dlc.lp_id = dl.id
            LEFT JOIN dtb_model dm ON dm.id = dlc.model_id
            LEFT JOIN dtb_model dm2 ON dm2.id = dlc.model_id2
            WHERE dlc.del_flg = 0 AND dlc.brand_id = :brand_id
        SQL;
        $params = ['brand_id' => $brandId];

        if (!empty($searchData['search_category_id'])) {
            $sql .= ' AND dlc.category_id = :category_id';
            $params['category_id'] = (int) $searchData['search_category_id'];
        }
        if (!empty($searchData['search_asp_id'])) {
            $sql .= ' AND dlc.asp_id = :asp_id';
            $params['asp_id'] = (int) $searchData['search_asp_id'];
        }
        if (!empty($searchData['search_media_id'])) {
            $sql .= ' AND dlc.media_id = :media_id';
            $params['media_id'] = (int) $searchData['search_media_id'];
        }
        if (!empty($searchData['search_baitai_id'])) {
            $sql .= ' AND dlc.baitai_id = :baitai_id';
            $params['baitai_id'] = (int) $searchData['search_baitai_id'];
        }
        if (!empty($searchData['search_start_date'])) {
            // 2系: start_date の完全一致 (date型として比較)
            $sql .= ' AND dlc.start_date::date = :start_date';
            $params['start_date'] = $searchData['search_start_date'];
        }
        if (!empty($searchData['search_end_date'])) {
            $sql .= ' AND dlc.end_date::date = :end_date';
            $params['end_date'] = $searchData['search_end_date'];
        }
        if (isset($searchData['search_status']) && $searchData['search_status'] !== '') {
            $sql .= ' AND dlc.status = :status';
            $params['status'] = (int) $searchData['search_status'];
        }

        $sql .= ' ORDER BY dlc.status, dlc.asp_id, dlc.media_id, dlc.baitai_id';

        $rows = $this->conn()->executeQuery($sql, $params)->fetchAllAssociative();
        return $this->paginator->paginate($rows, $page, $pageMax);
    }

    /**
     * 1件取得（編集用）.
     */
    public function findById(int $id, ?int $brandId = null): ?array
    {
        $sql = <<<SQL
            SELECT dlc.*,
                (SELECT dm.name FROM dtb_model dm WHERE dlc.model_id = dm.id) AS model_name,
                (SELECT dm2.name FROM dtb_model dm2 WHERE dlc.model_id2 = dm2.id) AS model_name2
            FROM dtb_lps_code_model_change dlc
            WHERE dlc.id = :id AND dlc.del_flg = 0
        SQL;
        $params = ['id' => $id];
        if ($brandId !== null) {
            $sql .= ' AND dlc.brand_id = :brand_id';
            $params['brand_id'] = $brandId;
        }
        $row = $this->conn()->fetchAssociative($sql, $params);
        return $row ?: null;
    }

    /**
     * LPタグ配信用: モデル変更設定を取得
     * (2系 SC_Helper_Tag_Ex::lfGetModelChangeData L221-244 移植、Issue #1862)。
     *
     * ①LP単位 (asp/baitai/category/brand/lp の5条件) で引き、0件なら
     * ②lp_id 条件を**丸ごと外して**再検索する。どちらも0件なら null。
     *
     * 2系との差異ゼロを保つための注意点:
     *   - ★start_date / end_date は見ない。2系は $today / $today_e を作っているが WHERE に
     *     一切使っていないデッド変数で、有効/無効は status 列だけで決まる (0=使用中 / 1=未使用)。
     *   - ★media_id は条件に入れない。購入画面側 (2系 LC_Page_Store::getCodeModelChange →
     *     4系 StoreEntryService::getCodeModelChange) は media_id を含み brand_id を含まず、
     *     フォールバックも `lp_id IS NULL` で、同じテーブルに対する別ロジックである。混同しないこと。
     *   - ★フォールバックは `lp_id IS NULL` ではなく lp_id 条件の削除。結果として他LP専用の行を
     *     拾いうるが2系の挙動をそのまま踏襲する。
     *   - ★ORDER BY を付けない (2系は getAll()[0] で先頭行を採る)。複数行ヒット時の選択は
     *     2系も非決定的。
     *
     * 実データ (2026-08-07 実測 / 2026-08-17 再測でも同値): 2系 dtb_lps_code_model_change 57件・
     * 4系57件とも全て status=1 のため、現時点では常に null を返す(2系も同じ)。
     * 4系は管理画面の編集画面 (`ModelChangeEditType` の 使用状況 select → `save()`。2系
     * `LC_Page_Admin_Model_Change_Edit_Ex.php:167,263` と同じ位置づけ)で運用者が status=0 に
     * 戻せるため、配信側が無いと「有効化しても何も起きずエラーも出ない」サイレント欠落になる。
     * ★一覧の「使用状況切替」(`toggleStatus`)は2系に実体が無く導線をコメントアウトしたので、
     *   status=0 を作れるのは編集画面だけ (Issue #2060)。
     *
     * @return array{class_name: ?string, image_path: ?string}|null
     */
    public function findForLpTag(int $aspId, int $baitaiId, int $categoryId, int $brandId, int $lpId): ?array
    {
        $base = 'SELECT lp_target_class, lp_change_image_path FROM dtb_lps_code_model_change'
            . ' WHERE asp_id = :asp_id AND baitai_id = :baitai_id AND category_id = :category_id'
            . ' AND brand_id = :brand_id';
        $params = [
            'asp_id' => $aspId,
            'baitai_id' => $baitaiId,
            'category_id' => $categoryId,
            'brand_id' => $brandId,
        ];

        // ① LP単位
        $row = $this->conn()->fetchAssociative(
            $base . ' AND lp_id = :lp_id AND del_flg = 0 AND status = 0',
            $params + ['lp_id' => $lpId],
        );

        // ② LP単位で設定が無ければ ASP・媒体単位 (lp_id 条件ごと削除)
        if ($row === false) {
            $row = $this->conn()->fetchAssociative($base . ' AND del_flg = 0 AND status = 0', $params);
        }

        if ($row === false) {
            return null;
        }

        return [
            'class_name' => $row['lp_target_class'] !== null ? (string) $row['lp_target_class'] : null,
            'image_path' => $row['lp_change_image_path'] !== null ? (string) $row['lp_change_image_path'] : null,
        ];
    }

    /**
     * 使用状況トグル (0→1, 1→0).
     *
     * ★「2系 status_change 相当」ではない。2系
     *   `LC_Page_Admin_Model_Change_Ex.php:104-144` の switch に `case 'status_change':` は
     *   存在せず、`model_change.tpl:167` のボタンは `default: break;` に落ちて何もしない
     *   (Issue #2060)。呼び出し元 `ModelChangeController::statusChange` ごと導線を
     *   コメントアウトしてあるので、現状このメソッドは呼ばれない。
     * ★復活させる場合の注意: 2系で status を書く唯一の箇所である編集画面
     *   (`LC_Page_Admin_Model_Change_Edit_Ex.php:271-278` / 4系 `save()`) は
     *   status=1 なら end_date=NOW()、status=0 なら end_date=NULL と**必ずセットで**整合させる。
     *   このメソッドは status だけを反転するため、2系には存在し得ない組み合わせ
     *   (status=0 かつ end_date 有 など)を作れてしまう。
     */
    public function toggleStatus(int $id, int $brandId): void
    {
        $this->conn()->executeStatement(
            'UPDATE dtb_lps_code_model_change SET status = CASE WHEN status = 0 THEN 1 ELSE 0 END, update_date = :now WHERE id = :id AND brand_id = :brand_id AND del_flg = 0',
            ['now' => date('Y-m-d H:i:s'), 'id' => $id, 'brand_id' => $brandId],
        );
    }

    /**
     * 登録・更新 (2系 lfRegister 相当).
     * status=1(未使用) + end_date空 → end_date=NOW(), status=0(使用中) + end_date有 → end_date=NULL
     */
    public function save(array $data, int $brandId): int
    {
        $endDate = ($data['end_date'] ?? '') !== '' ? $data['end_date'] : null;

        if (empty($data['end_date']) && (int) ($data['status'] ?? 0) === 1) {
            $endDate = date('Y-m-d H:i:s');
        }
        if (!empty($data['end_date']) && (int) ($data['status'] ?? 0) === 0) {
            $endDate = null;
        }

        $vals = [
            'category_id'          => isset($data['category_id']) && $data['category_id'] !== '' ? (int) $data['category_id'] : null,
            'asp_id'               => isset($data['asp_id']) && $data['asp_id'] !== '' ? (int) $data['asp_id'] : null,
            'media_id'             => isset($data['media_id']) && $data['media_id'] !== '' ? (int) $data['media_id'] : null,
            'baitai_id'            => isset($data['baitai_id']) && $data['baitai_id'] !== '' ? (int) $data['baitai_id'] : null,
            'start_date'           => $data['start_date'] ?? null,
            'end_date'             => $endDate,
            'status'               => (int) ($data['status'] ?? 0),
            'model_id'             => isset($data['model_id']) && $data['model_id'] !== '' ? (int) $data['model_id'] : null,
            'model_id2'            => isset($data['model_id2']) && $data['model_id2'] !== '' ? (int) $data['model_id2'] : null,
            'lp_target_class'      => $data['lp_target_class'] ?? null,
            'lp_change_image_path' => $data['lp_change_image_path'] ?? null,
            'lp_id'                => isset($data['lp_id']) && $data['lp_id'] !== '' ? (int) $data['lp_id'] : null,
            'brand_id'             => $brandId,
            'update_date'          => date('Y-m-d H:i:s'),
        ];

        $editId = isset($data['id']) && $data['id'] !== '' ? (int) $data['id'] : null;

        if ($editId === null) {
            $vals['create_date'] = date('Y-m-d H:i:s');
            $this->conn()->insert('dtb_lps_code_model_change', $vals);
            $id = (int) $this->conn()->lastInsertId('dtb_lps_code_model_change_id_seq');
        } else {
            $this->conn()->update('dtb_lps_code_model_change', $vals, ['id' => $editId]);
            $id = $editId;
        }

        return $id;
    }

    /**
     * 論理削除 (2系 delete 相当: del_flg=1, update_date=CURRENT_TIMESTAMP).
     */
    public function softDelete(int $id, int $brandId): bool
    {
        $affected = $this->conn()->executeStatement(
            'UPDATE dtb_lps_code_model_change SET del_flg = 1, update_date = :now WHERE id = :id AND brand_id = :brand_id',
            ['now' => date('Y-m-d H:i:s'), 'id' => $id, 'brand_id' => $brandId],
        );
        return $affected > 0;
    }

    /**
     * LP 一覧 (ModelChangeEdit の select 用, 2系 lfGetLps 相当).
     *
     * 2系はコード発行済み(dtb_lps_code)のLPのみを母集団にし、func_get_lpcode_category
     * (JOIN展開、AbTestService::getLps と同等のロジック) でカテゴリ順に並べる。
     * Issue #579: dtb_lps 直引き(全アクティブLP)になっていたため母集団を修正。
     *
     * ★戻り値は2系 `lfGetLps()` と同じく **行のリスト** (lp_id / name / category_id / status)。
     * 2系 model_change_edit.tpl L152-153 は各 option に `category` 属性を出し、L230-236 の
     * `$("#lp_id option[category='...']").show()` でカテゴリ絞り込みをしている (Issue #840)。
     * 以前は `[name => lp_id]` に潰しており **category_id と status を捨てていた**ため絞り込みが不可能で、
     * かつ **LP名が重複すると候補が上書きで欠落**していた
     * (2系は option ラベルが `{lp_id} - {name}` で lp_id を含むため構造的に衝突しない)。
     *
     * ★JOIN展開が func_get_lpcode_category と等価であることは確認済み。同関数の本体
     * (`pg_get_functiondef` で実測) は adcode で dtb_lps_code の行を選ぶだけで、category_id 自体は
     * lp_id → dtb_model → dtb_course_detail(course_type=0, order_count=1) → dtb_product から引くため、
     * 同一 lp_id なら adcode によらず同じ値になる。よって本SQLのJOINと同じ結果。
     *
     * @return list<array{lp_id:int, name:string, category_id:int|null, status:int}>
     */
    public function getLpChoices(int $brandId): array
    {
        $sql = 'SELECT DISTINCT dlc.lp_id, dl.name, dp.category_id, dl.status'
            . ' FROM dtb_lps_code dlc'
            . ' INNER JOIN dtb_lps dl ON dlc.lp_id = dl.id'
            . ' LEFT JOIN dtb_model dm ON dl.model_id = dm.id'
            . ' LEFT JOIN dtb_course_detail dcd ON dm.course_id = dcd.course_id AND dcd.course_type = 0 AND dcd.order_count = 1'
            . ' LEFT JOIN dtb_product dp ON dcd.product_id = dp.id'
            . ' WHERE dlc.del_flg = 0 AND dlc.brand_id = :brand_id AND dl.del_flg = 0 AND dl.status = 0'
            . ' ORDER BY dp.category_id, dl.status, dlc.lp_id DESC';
        $rows = $this->conn()->fetchAllAssociative($sql, ['brand_id' => $brandId]);

        return array_map(static fn (array $row): array => [
            'lp_id'       => (int) $row['lp_id'],
            'name'        => (string) $row['name'],
            'category_id' => $row['category_id'] !== null ? (int) $row['category_id'] : null,
            'status'      => (int) $row['status'],
        ], $rows);
    }
}
