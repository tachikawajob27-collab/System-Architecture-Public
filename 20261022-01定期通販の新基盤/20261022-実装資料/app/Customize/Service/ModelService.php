<?php

namespace Customize\Service;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Knp\Component\Pager\PaginatorInterface;
use Knp\Component\Pager\Pagination\PaginationInterface;

/**
 * 管理画面：モデル管理 (2系 LC_Page_Admin_Model / LC_Page_Admin_Model_Edit 移植).
 */
class ModelService
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
     */
    public function getPagination(array $searchData, int $brandId, int $page, int $pageMax): PaginationInterface
    {
        $sql = <<<SQL
            SELECT dm.*, dc.name AS course_name, dp.name AS offer_name, dmt.name AS thanks_name
            FROM dtb_model dm
            INNER JOIN dtb_course dc ON dm.course_id = dc.id
            LEFT JOIN dtb_product dp ON dm.offer_id = dp.id
            LEFT JOIN dtb_model_thanks_mapping dmtm ON dm.id = dmtm.model_id
            LEFT JOIN dtb_model_thanks dmt ON dmtm.thanks_id = dmt.id
            WHERE dm.del_flg = 0 AND dm.brand_id = :brand_id
        SQL;
        $params = ['brand_id' => $brandId];

        if (!empty($searchData['search_model_id'])) {
            $sql .= ' AND dm.id = :model_id';
            $params['model_id'] = (int) $searchData['search_model_id'];
        }
        if (!empty($searchData['search_model_name'])) {
            $sql .= ' AND dm.name LIKE :model_name';
            $params['model_name'] = '%' . $searchData['search_model_name'] . '%';
        }
        if (!empty($searchData['search_course_name'])) {
            $sql .= ' AND dm.course_id IN (SELECT id FROM dtb_course WHERE del_flg = 0 AND is_cross = 0 AND brand_id = :course_brand AND name LIKE :course_name)';
            $params['course_brand'] = $brandId;
            $params['course_name']  = '%' . $searchData['search_course_name'] . '%';
        }
        if (isset($searchData['search_form']) && $searchData['search_form'] !== '') {
            if ((int) $searchData['search_form'] === 2) {
                $sql .= ' AND dm.is_chat = 1';
            } else {
                $sql .= ' AND dm.is_chat = 0 AND dm.is_direct = :is_direct';
                $params['is_direct'] = (int) $searchData['search_form'];
            }
        }
        if (!empty($searchData['search_category'])) {
            $sql .= ' AND dm.course_id IN (
                SELECT dcd.course_id FROM dtb_course_detail dcd
                INNER JOIN dtb_product dp2 ON dcd.product_id = dp2.id
                WHERE dcd.course_type = 0 AND dcd.order_count = 1 AND dp2.category_id = :category_id
            )';
            $params['category_id'] = (int) $searchData['search_category'];
        }

        $sql .= ' ORDER BY dm.update_date DESC';

        $rows = $this->conn()->executeQuery($sql, $params)->fetchAllAssociative();
        return $this->paginator->paginate($rows, $page, $pageMax);
    }

    /**
     * 1件取得 (2系 lfGetModelData 相当).
     */
    public function findById(int $id, int $brandId): ?array
    {
        $row = $this->conn()->fetchAssociative(
            'SELECT dm.*, dc.name AS course_name, dp.name AS offer_name FROM dtb_model dm'
            . ' INNER JOIN dtb_course dc ON dm.course_id = dc.id'
            . ' LEFT JOIN dtb_product dp ON dm.offer_id = dp.id'
            . ' WHERE dm.id = :id AND dm.brand_id = :brand_id AND dm.del_flg = 0',
            ['id' => $id, 'brand_id' => $brandId],
        );
        return $row ?: null;
    }

    /**
     * サンクスモデルID取得 (2系 lfGetThanksModelData 相当).
     */
    public function getThanksModelId(int $modelId): ?int
    {
        $val = $this->conn()->fetchOne(
            'SELECT thanks_id FROM dtb_model_thanks_mapping WHERE model_id = :model_id LIMIT 1',
            ['model_id' => $modelId],
        );
        return $val !== false ? (int) $val : null;
    }

    /**
     * コース情報取得 (2系 lfGetCourseData 相当).
     * func_get_course_main_category 代替: course_detail type=0, order_count=1 → products.category_id
     */
    public function getCourseInfo(int $courseId, int $brandId): ?array
    {
        $row = $this->conn()->fetchAssociative(
            'SELECT dc.id, dc.name,'
            . ' (SELECT dp2.category_id FROM dtb_course_detail dcd'
            . '  INNER JOIN dtb_product dp2 ON dcd.product_id = dp2.id'
            . '  WHERE dcd.course_id = dc.id AND dcd.course_type = 0 AND dcd.order_count = 1 LIMIT 1) AS category_id'
            . ' FROM dtb_course dc'
            . ' WHERE dc.id = :id AND dc.brand_id = :brand_id AND dc.del_flg = 0',
            ['id' => $courseId, 'brand_id' => $brandId],
        );
        return $row ?: null;
    }

    /**
     * コース内容一覧 (2系 lfEditCourseInfo 相当).
     * @return array{0: array[], 1: array[], 2: array[]} [main, sub, sub2]
     */
    public function getCourseDetails(int $courseId): array
    {
        $rows = $this->conn()->fetchAllAssociative(
            'SELECT dcd.*, dp.name AS product_name FROM dtb_course_detail dcd'
            . ' LEFT JOIN dtb_product dp ON dcd.product_id = dp.id'
            . ' WHERE dcd.course_id = :course_id ORDER BY dcd.course_type, dcd.order_count',
            ['course_id' => $courseId],
        );
        $main = $sub = $sub2 = [];
        foreach ($rows as $row) {
            switch ((int) $row['course_type']) {
                case 0: $main[] = $row; break;
                case 1: $sub[]  = $row; break;
                case 2: $sub2[] = $row; break;
            }
        }
        return [$main, $sub, $sub2];
    }

    /**
     * シナリオ選択肢 (2系 lfGetChatScenario 相当).
     */
    public function getScenarioChoices(int $brandId, int $categoryId): array
    {
        $rows = $this->conn()->fetchAllAssociative(
            'SELECT scenario_no, scenario_name FROM dtb_chat_scenario_info'
            . ' WHERE del_flg = 0 AND brand_id = :brand_id AND category_id = :category_id ORDER BY scenario_no DESC',
            ['brand_id' => $brandId, 'category_id' => $categoryId],
        );
        $result = [];
        foreach ($rows as $row) {
            $result[$row['scenario_no']] = $row['scenario_no'] . ' - ' . $row['scenario_name'];
        }
        return $result;
    }

    /**
     * チャットアイコン選択肢 (2系 lfEditSelectBox("dtb_chat_icon") 相当).
     */
    public function getChatIconChoices(int $brandId): array
    {
        $rows = $this->conn()->fetchAllAssociative(
            'SELECT id, name FROM dtb_chat_icon WHERE del_flg = 0 AND brand_id = :brand_id ORDER BY id',
            ['brand_id' => $brandId],
        );
        $result = [];
        foreach ($rows as $row) {
            $result[$row['id']] = $row['name'];
        }
        return $result;
    }

    /**
     * チャットヘッダー選択肢 (2系 lfEditSelectBox("dtb_chat_header") 相当).
     */
    public function getChatHeaderChoices(int $brandId): array
    {
        $rows = $this->conn()->fetchAllAssociative(
            'SELECT id, name FROM dtb_chat_header WHERE del_flg = 0 AND brand_id = :brand_id ORDER BY id',
            ['brand_id' => $brandId],
        );
        $result = [];
        foreach ($rows as $row) {
            $result[$row['id']] = $row['name'];
        }
        return $result;
    }

    /**
     * コース選択肢.
     */
    public function getCourseChoices(int $brandId): array
    {
        $rows = $this->conn()->fetchAllAssociative(
            'SELECT id, name FROM dtb_course WHERE del_flg = 0 AND brand_id = :brand_id ORDER BY id',
            ['brand_id' => $brandId],
        );
        $result = [];
        foreach ($rows as $row) {
            $result[$row['id']] = $row['name'];
        }
        return $result;
    }

    /**
     * サンクスモデル選択肢.
     */
    public function getThanksModelChoices(int $brandId): array
    {
        $rows = $this->conn()->fetchAllAssociative(
            'SELECT id, name FROM dtb_model_thanks WHERE del_flg = 0 AND brand_id = :brand_id ORDER BY id',
            ['brand_id' => $brandId],
        );
        $result = [];
        foreach ($rows as $row) {
            $result[$row['id']] = $row['name'];
        }
        return $result;
    }

    /**
     * 初回後払い手数料デフォルト値 (2系 getDeferredFirstCharge 相当).
     */
    public function getDefaultFirstCharge(int $brandId): int
    {
        $val = $this->conn()->fetchOne(
            'SELECT first_charge FROM dtb_payment_charge WHERE payment_id = 13 AND brand_id = :brand_id LIMIT 1',
            ['brand_id' => $brandId],
        );
        return $val !== false ? (int) $val : 0;
    }

    /**
     * INSERT / UPDATE (2系 lfRegister 相当).
     * dtb_model + dtb_model_thanks_mapping + (新規チャット時) dtb_remake_model_mapping 設定.
     */
    public function save(array $data, int $brandId, int $memberId): int
    {
        $now    = date('Y-m-d H:i:s');
        $isChat = (int) ($data['is_chat'] ?? 0);

        $sqlVal = [
            'name'            => $data['name'],
            'course_id'       => (int) $data['course_id'],
            'offer_id'        => !empty($data['offer_id']) ? (int) $data['offer_id'] : null,
            'is_chat'         => $isChat,
            'is_direct'       => $isChat === 0 && trim($data['is_direct'] ?? '') !== '' ? (int) $data['is_direct'] : null,
            'scenario_no'     => $isChat === 0 && !empty($data['scenario_no']) ? (int) $data['scenario_no'] : null,
            'chat_icon'       => $isChat === 0 && !empty($data['chat_icon']) ? (int) $data['chat_icon'] : null,
            'header_id'       => $isChat === 0 && !empty($data['header_id']) ? (int) $data['header_id'] : null,
            'chat_image_1'    => $isChat === 0 ? ($data['save_chat_image_1'] ?: null) : null,
            'chat_image_2'    => $isChat === 0 ? ($data['save_chat_image_2'] ?: null) : null,
            'chat_image_3'    => $isChat === 0 ? ($data['save_chat_image_3'] ?: null) : null,
            'chat_image_4'    => $isChat === 0 ? ($data['save_chat_image_4'] ?: null) : null,
            'chat_image_5'    => $isChat === 0 ? ($data['save_chat_image_5'] ?: null) : null,
            'confirm_image_1' => $data['save_confirm_image_1'] ?: null,
            'confirm_image_2' => $data['save_confirm_image_2'] ?: null,
            'confirm_image_3' => $data['save_confirm_image_3'] ?: null,
            'confirm_txt'     => $data['confirm_txt'] ?? '',
            'confirm_txt_2'   => $data['confirm_txt_2'] ?? '',
            'online_discount' => trim($data['online_discount'] ?? '') !== '' ? (int) $data['online_discount'] : 0,
            'first_charge'    => trim($data['first_charge'] ?? '') !== '' ? (int) $data['first_charge'] : 0,
            'update_date'     => $now,
            'updator_id'      => $memberId,
        ];

        $id = !empty($data['id']) ? (int) $data['id'] : 0;

        if ($id === 0) {
            $sqlVal['brand_id']    = $brandId;
            $sqlVal['del_flg']     = 0;
            $sqlVal['creator_id']  = $memberId;
            $sqlVal['create_date'] = $now;
            $this->conn()->insert('dtb_model', $sqlVal);
            $id = (int) $this->conn()->lastInsertId('dtb_model_id_seq');

            // 新規チャット設定時: リマケのデフォルト設定を登録 (2系 lfRegister 相当)
            if ($isChat === 0) {
                $categoryId = $this->conn()->fetchOne(
                    'SELECT dp.category_id FROM dtb_course_detail dcd'
                    . ' INNER JOIN dtb_product dp ON dcd.product_id = dp.id'
                    . ' WHERE dcd.course_id = :course_id AND dcd.course_type = 0 AND dcd.order_count = 1 LIMIT 1',
                    ['course_id' => $sqlVal['course_id']],
                );
                if ($categoryId !== false) {
                    $this->conn()->executeStatement(
                        'INSERT INTO dtb_remake_model_mapping (remake_id, model_id)'
                        . ' SELECT id AS remake_id, :model_id AS model_id'
                        . ' FROM dtb_remake_setting'
                        . ' WHERE is_default = 1 AND category_id = :category_id AND brand_id = :brand_id',
                        ['model_id' => $id, 'category_id' => (int) $categoryId, 'brand_id' => $brandId],
                    );
                }
            }
        } else {
            $this->conn()->update('dtb_model', $sqlVal, ['id' => $id, 'brand_id' => $brandId]);
        }

        // サンクスモデル: 全削除 → 再登録
        $this->conn()->executeStatement(
            'DELETE FROM dtb_model_thanks_mapping WHERE model_id = :model_id',
            ['model_id' => $id],
        );
        if (!empty($data['thanks_model_id'])) {
            $this->conn()->insert('dtb_model_thanks_mapping', [
                'model_id'    => $id,
                'thanks_id'   => (int) $data['thanks_model_id'],
                'create_date' => $now,
                'update_date' => $now,
            ]);
        }

        return $id;
    }

    /**
     * 論理削除 (2系 doDelete 相当: del_flg=1, updator_id, update_date).
     */
    public function softDelete(int $id, int $brandId, ?int $memberId): bool
    {
        $affected = $this->conn()->executeStatement(
            'UPDATE dtb_model SET del_flg = 1, updator_id = :member_id, update_date = :now WHERE id = :id AND brand_id = :brand_id',
            ['member_id' => $memberId, 'now' => date('Y-m-d H:i:s'), 'id' => $id, 'brand_id' => $brandId],
        );
        return $affected > 0;
    }

    /**
     * モデル一覧（select用）— ModelChangeEdit で使用.
     */
    public function getModelChoices(int $brandId): array
    {
        $rows = $this->conn()->fetchAllAssociative(
            'SELECT id, name FROM dtb_model WHERE del_flg = 0 AND brand_id = :brand_id ORDER BY update_date DESC',
            ['brand_id' => $brandId],
        );
        $result = [];
        foreach ($rows as $row) {
            $result[$row['name']] = $row['id'];
        }
        return $result;
    }
}
