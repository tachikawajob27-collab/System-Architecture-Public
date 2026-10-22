<?php

namespace Customize\Service;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;

/**
 * 管理画面：月間目標管理 (2系 LC_Page_Admin_MonthlyTarget 移植).
 * dtb_monthly_target は (category_id, brand_id, target_year, month) のセットで月次目標件数を保持.
 */
class MonthlyTargetService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {}

    private function conn(): Connection
    {
        return $this->entityManager->getConnection();
    }

    /**
     * 商品カテゴリ一覧 (2系 init 内の SQL 完全踏襲: is_realtime=1, ORDER BY rank).
     * @return array<int, string>  [category_id => category_name]
     */
    public function getCategories(int $brandId): array
    {
        // 2系 dtb_category.category_id → 4系 id (PKリネーム), rank → sort_no (カラムリネーム)
        $rows = $this->conn()->fetchAllAssociative(
            'SELECT id, category_name AS name FROM dtb_category'
            . ' WHERE is_realtime = 1 AND brand_id = :brand_id'
            . ' ORDER BY sort_no',
            ['brand_id' => $brandId],
        );
        $list = [];
        foreach ($rows as $row) {
            $list[(int) $row['id']] = (string) $row['name'];
        }
        return $list;
    }

    /**
     * 指定カテゴリ・年の月次目標 12ヶ月分を取得 (2系 lfGetTarget).
     * @return array<int, int|string>  [1=>count, 2=>count, ... 12=>count]。データなしの月は空文字
     */
    public function getTarget(int $categoryId, int $brandId, int $year): array
    {
        $rows = $this->conn()->fetchAllAssociative(
            'SELECT month, target_count FROM dtb_monthly_target'
            . ' WHERE category_id = :category_id AND brand_id = :brand_id AND target_year = :year'
            . ' ORDER BY month',
            ['category_id' => $categoryId, 'brand_id' => $brandId, 'year' => $year],
        );
        $arr = array_fill(1, 12, '');
        foreach ($rows as $row) {
            $arr[(int) $row['month']] = (int) $row['target_count'];
        }
        return $arr;
    }

    /**
     * 月次目標 12ヶ月分を upsert (2系 lfSaveTarget).
     * 空セル ('' or null) はスキップ (登録しない / 既存も触らない).
     *
     * @param array<int, int|string|null> $targetCounts  [month => count]
     */
    public function saveTarget(int $categoryId, int $brandId, int $year, array $targetCounts): void
    {
        $conn = $this->conn();
        foreach ($targetCounts as $month => $count) {
            if ($count === '' || $count === null) {
                continue;
            }
            $month = (int) $month;
            $count = (int) $count;
            if ($month < 1 || $month > 12) {
                continue; // CHECK constraint 防御
            }

            $existsId = $conn->fetchOne(
                'SELECT id FROM dtb_monthly_target'
                . ' WHERE category_id = :category_id AND brand_id = :brand_id'
                . '   AND target_year = :year AND month = :month',
                ['category_id' => $categoryId, 'brand_id' => $brandId, 'year' => $year, 'month' => $month],
            );
            if ($existsId !== false) {
                $conn->update('dtb_monthly_target', [
                    'target_count' => $count,
                    'update_date'  => date('Y-m-d H:i:s'),
                ], ['id' => (int) $existsId]);
            } else {
                $conn->insert('dtb_monthly_target', [
                    'category_id'  => $categoryId,
                    'brand_id'     => $brandId,
                    'target_year'  => $year,
                    'month'        => $month,
                    'target_count' => $count,
                ]);
            }
        }
    }
}
