<?php

namespace Customize\Service;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;

/**
 * LP管理のビジネスロジック (2系 LC_Page_Admin_Lp + LC_Page_Admin_Lp_Edit 移植).
 *
 * 主要メソッド:
 *   - findById / save / softDelete / existsByCode
 *   - findModelData (dtb_model + dtb_course + dtb_products JOIN, モデル選択時に course_id 等を補完)
 *   - getCourseDetailList (lfEditCourseInfo のコース内訳取得。確認画面 + 編集画面プレビュー(#disp_model, Issue #1428)双方で使用)
 *   - getChatIconChoices / getChatHeaderChoices
 */
class LpService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {}

    /**
     * @return array<string, mixed>|null
     */
    public function findById(int $id, int $brandId): ?array
    {
        $row = $this->conn()->fetchAssociative(
            'SELECT * FROM dtb_lps WHERE id = :id AND brand_id = :brand_id',
            ['id' => $id, 'brand_id' => $brandId],
        );
        if ($row === false) {
            return null;
        }

        // モデル情報を JOIN で補完 (2系 lfGetModelData 相当)
        //
        // ★offer_name (初回オファー品) は2系 lfGetModelData で dp.name AS offer_name を JOIN 取得しては
        // いるが、$arrForm への代入行 (pre_edit の L116, model_select の L151/L159) が2系コード上で
        // 全てコメントアウトされているため、2系では offer_name は常に空文字列のままであり、
        // lp_confirm.tpl L95-97 の `{if $arrForm.offer_name != ""}` は成立しない。
        // つまり「2系で初回オファー品が確認画面に表示される」という parity 記載 (lp_edit.md AFF-31) は
        // 2系のコメントアウト部分を精読不足のまま事実として扱った誤りであり、4系での復元は不要
        // (Issue #836 対応、PR #1429 レビューで是正)。
        if (!empty($row['model_id'])) {
            $model = $this->findModelData((int) $row['model_id'], $brandId);
            if ($model !== null) {
                $row['model_name'] = $model['model_name'];
                $row['course_name'] = $model['course_name'];
                $row['course_id'] = $model['course_id'];
                $row['is_chat'] = $model['is_chat'] ?? null;
            }
        }
        if (!empty($row['model_id_2'])) {
            $model2 = $this->findModelData((int) $row['model_id_2'], $brandId);
            if ($model2 !== null) {
                $row['model_name_2'] = $model2['model_name'];
                $row['course_name_2'] = $model2['course_name'];
                $row['course_id_2'] = $model2['course_id'];
            }
        }

        return $row;
    }

    /**
     * 2系 lfGetModelData 相当。
     *
     * TODO: 2系の dtb_model.offer_id は dtb_products.product_id を指していた。
     * 4系で dtb_products → dtb_product の移行時に offer_id を新しい dtb_product.id に
     * 変換するマッピング処理が必要。現状は dp.id でJOINしているが、移行データ次第で
     * NULLになる可能性あり (実データ移行後に要動作確認)。
     *
     * @return array<string, mixed>|null
     */
    public function findModelData(int $modelId, int $brandId): ?array
    {
        $row = $this->conn()->fetchAssociative(
            'SELECT dm.*, dm.name AS model_name, dc.name AS course_name, dc.main_setting, dc.sub_setting, dp.name AS offer_name'
            . ' FROM dtb_model dm'
            . ' INNER JOIN dtb_course dc ON dm.course_id = dc.id'
            . ' LEFT JOIN dtb_product dp ON dm.offer_id = dp.id'
            . ' WHERE dm.id = :id AND dm.brand_id = :brand_id LIMIT 1',
            ['id' => $modelId, 'brand_id' => $brandId],
        );
        return $row === false ? null : $row;
    }

    /**
     * 2系 lfRegister 相当。INSERT/UPDATE 切り替え。
     */
    public function save(array $data, int $brandId, ?int $memberId, ?int $lpId = null): int
    {
        $conn = $this->conn();
        $conn->beginTransaction();
        try {
            $now = (new \DateTime())->format('Y-m-d H:i:s');
            $values = [
                'name' => (string) ($data['name'] ?? ''),
                'code' => (string) ($data['code'] ?? ''),
                'lp_url_pc' => (string) ($data['lp_url_pc'] ?? ''),
                'lp_url_sp' => (string) ($data['lp_url_sp'] ?? ''),
                'category_group_id' => !empty($data['category_group_id']) ? (int) $data['category_group_id'] : null,
                'model_id' => (int) ($data['model_id'] ?? 0),
                'model_id_2' => !empty($data['model_id_2']) ? (int) $data['model_id_2'] : null,
                'status' => (int) ($data['status'] ?? 0),
                'header_id' => !empty($data['header_id']) ? (int) $data['header_id'] : null,
                'chat_icon' => !empty($data['chat_icon']) ? (int) $data['chat_icon'] : null,
                'update_date' => $now,
                'updator_id' => $memberId,
            ];

            if ($lpId === null || $lpId <= 0) {
                $values['brand_id'] = $brandId;
                $values['creator_id'] = $memberId;
                $values['create_date'] = $now;
                $values['del_flg'] = 0;
                $conn->insert('dtb_lps', $values);
                $newId = (int) $conn->lastInsertId('dtb_lps_id_seq');
                $conn->commit();
                return $newId;
            }

            $conn->update('dtb_lps', $values, ['id' => $lpId, 'brand_id' => $brandId]);
            $conn->commit();
            return $lpId;
        } catch (\Throwable $e) {
            $conn->rollBack();
            throw $e;
        }
    }

    /**
     * 2系 doDelete 相当。論理削除 + updator_id 記録。
     */
    public function softDelete(int $id, int $brandId, ?int $memberId): bool
    {
        $affected = $this->conn()->executeStatement(
            'UPDATE dtb_lps SET del_flg = 1, updator_id = :uid, update_date = CURRENT_TIMESTAMP'
            . ' WHERE id = :id AND brand_id = :brand_id',
            ['id' => $id, 'brand_id' => $brandId, 'uid' => $memberId],
        );
        return $affected > 0;
    }

    /**
     * 2系 lfCheckDuplicateCode 相当 (新規時のみ重複チェック)。
     * brand_id + code + category_group_id の組み合わせで重複判定。
     */
    public function existsByCode(string $code, int $brandId, ?int $categoryGroupId = null): bool
    {
        $cnt = (int) $this->conn()->fetchOne(
            'SELECT COUNT(*) FROM dtb_lps WHERE brand_id = :brand_id AND code = :code AND category_group_id = :category_group_id',
            ['brand_id' => $brandId, 'code' => $code, 'category_group_id' => $categoryGroupId],
        );
        return $cnt > 0;
    }

    /**
     * カテゴリグループ選択肢 (2系 SC_Helper_Category_Ex::getCategoryGroupList 相当).
     *
     * @return array<int, string>
     */
    public function getCategoryGroupChoices(int $brandId): array
    {
        $rows = $this->conn()->fetchAllAssociative(
            'SELECT category_group_id, category_group_name FROM dtb_category_group'
            . ' WHERE del_flg = 0 AND brand_id = :brand_id ORDER BY category_group_id',
            ['brand_id' => $brandId],
        );
        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r['category_group_id']] = (string) $r['category_group_name'];
        }
        return $out;
    }

    /**
     * ブランドの lp_path (2系 lp_edit.tpl `$arrBrand.lp_path` 相当)。
     * URL自動生成JS(fnChangeCode)のベースパスとして使用する。
     */
    public function getBrandLpPath(int $brandId): string
    {
        $path = $this->conn()->fetchOne(
            'SELECT lp_path FROM dtb_brand WHERE id = :id',
            ['id' => $brandId],
        );
        return $path !== false && $path !== null ? (string) $path : '';
    }

    /**
     * カテゴリグループ id => lp_path マップ (2系 `<option data-lppath=...>` 相当)。
     * URL自動生成JSでカテゴリグループ選択に応じたパス断片を取得するために使用する。
     *
     * @return array<int, string>
     */
    public function getCategoryGroupLpPathMap(int $brandId): array
    {
        $rows = $this->conn()->fetchAllAssociative(
            'SELECT category_group_id, lp_path FROM dtb_category_group'
            . ' WHERE del_flg = 0 AND brand_id = :brand_id',
            ['brand_id' => $brandId],
        );
        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r['category_group_id']] = (string) ($r['lp_path'] ?? '');
        }
        return $out;
    }

    /**
     * ブランド内の model_id → モデル名 マップ (LP一覧の生ID→名称解決用、N+1回避)。
     *
     * 2系 LC_Page_Admin_Lp::doSearch() は
     *   `INNER JOIN dtb_model dm ON dl.model_id = dm.id`
     *   `LEFT JOIN dtb_model dm2 ON dl.model_id_2 = dm2.id`
     * でモデル名を解決する。4系一覧は brand スコープなので、当該ブランドの全モデルを
     * 一括取得して添字引きする。
     *
     * @return array<int,string> id => name
     */
    public function getModelNameMap(int $brandId): array
    {
        $rows = $this->conn()->fetchAllAssociative(
            'SELECT id, name FROM dtb_model WHERE brand_id = :brand_id',
            ['brand_id' => $brandId],
        );
        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r['id']] = (string) $r['name'];
        }
        return $out;
    }

    /**
     * 確認画面用にコース内訳を取得 (2系 lfEditCourseInfo の主要部分)。
     * dtb_course_detail を course_type で分割し、商品名と間隔表示を整形。
     *
     * $brandId で dtb_course.brand_id を突合し、他ブランドの course_id を渡された場合は
     * 空配列を返す(Issue #1428 レビュー指摘④: admin_affiliate_lp_course_detail Ajaxルートに
     * brand_id スコープが無く、他ブランドのcourse_idをそのまま返し得た)。
     *
     * @return array<int, array<int, array<string, mixed>>> [main, sub, sub2]
     */
    public function getCourseDetailList(?int $courseId, int $brandId): array
    {
        if (empty($courseId)) {
            return [[], [], []];
        }
        $intervalLabels = [0 => 'ヶ月毎', 1 => '週毎', 2 => '日毎'];

        $details = $this->conn()->fetchAllAssociative(
            'SELECT cd.course_type, cd.order_count, cd.product_id, cd.quantity, cd.price, cd.interval, cd.interval_type, p.name AS product_name'
            . ' FROM dtb_course_detail cd'
            . ' INNER JOIN dtb_course c ON cd.course_id = c.id AND c.brand_id = :brand_id'
            . ' LEFT JOIN dtb_product p ON cd.product_id = p.id'
            . ' WHERE cd.course_id = :id'
            . ' ORDER BY cd.course_type, cd.order_count',
            ['id' => $courseId, 'brand_id' => $brandId],
        );

        $byType = [0 => [], 1 => [], 2 => []];
        foreach ($details as $d) {
            $type = (int) $d['course_type'];
            if (!isset($byType[$type])) {
                continue;
            }
            $byType[$type][] = [
                'count' => (int) $d['order_count'],
                'product_name' => $d['product_name'] ?? '',
                'quantity' => $d['quantity'],
                'price' => $d['price'],
                'interval' => ((string) ($d['interval'] ?? '')) . ($intervalLabels[(int) ($d['interval_type'] ?? 0)] ?? ''),
            ];
        }
        return [$byType[0], $byType[1], $byType[2]];
    }

    /** @return array<int, string> */
    public function getChatIconChoices(int $brandId): array
    {
        return $this->fetchSelectMap('dtb_chat_icon', $brandId);
    }

    /** @return array<int, string> */
    public function getChatHeaderChoices(int $brandId): array
    {
        return $this->fetchSelectMap('dtb_chat_header', $brandId);
    }

    /**
     * 2系 lfEditSelectBox 相当。
     *
     * @return array<int, string>
     */
    private function fetchSelectMap(string $table, int $brandId): array
    {
        $rows = $this->conn()->fetchAllAssociative(
            "SELECT id, name FROM {$table} WHERE del_flg = 0 AND brand_id = :brand_id ORDER BY id",
            ['brand_id' => $brandId],
        );
        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r['id']] = (string) $r['name'];
        }
        return $out;
    }

    /** @return array<int, string> */
    public function getStatusChoices(): array
    {
        return [0 => '配信中', 1 => '停止中'];
    }

    private function conn(): Connection
    {
        return $this->entityManager->getConnection();
    }
}
