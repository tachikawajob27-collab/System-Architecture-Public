<?php

namespace Customize\Service;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;

/**
 * 管理画面：定休日管理 (2系 SC_Helper_Holiday 移植).
 * dtb_holiday は単一PK (holiday_id) + del_flg (一覧/取得は del_flg=0 で除外) + rank。
 * delete() のみ 2系 sfDeleteRankRecord($delete=true) 踏襲で物理削除 (Issue #557)。
 */
class HolidayService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {}

    private function conn(): Connection
    {
        return $this->entityManager->getConnection();
    }

    /**
     * 一覧取得 (2系 getList) — month, day ソート.
     * @return array<int, array{holiday_id:int, title:string, month:int, day:int, rank:int}>
     */
    public function getList(): array
    {
        $rows = $this->conn()->fetchAllAssociative(
            'SELECT holiday_id, title, month, day, rank FROM dtb_holiday'
            . ' WHERE del_flg = 0 ORDER BY month, day',
        );
        return array_map(static fn(array $r): array => [
            'holiday_id' => (int) $r['holiday_id'],
            'title'      => (string) $r['title'],
            'month'      => (int) $r['month'],
            'day'        => (int) $r['day'],
            'rank'       => (int) $r['rank'],
        ], $rows);
    }

    /**
     * 1件取得 (2系 get).
     */
    public function get(int $holidayId): ?array
    {
        $row = $this->conn()->fetchAssociative(
            'SELECT * FROM dtb_holiday WHERE holiday_id = :id AND del_flg = 0',
            ['id' => $holidayId],
        );
        return $row === false ? null : $row;
    }

    /**
     * 同日付の祝日が存在するか (2系 isDateExist).
     * 自レコードは除外可.
     */
    public function isDateExist(int $month, int $day, ?int $excludeHolidayId = null): bool
    {
        $sql = 'SELECT COUNT(*) FROM dtb_holiday WHERE del_flg = 0 AND month = :month AND day = :day';
        $params = ['month' => $month, 'day' => $day];
        if ($excludeHolidayId !== null && $excludeHolidayId > 0) {
            $sql .= ' AND holiday_id <> :id';
            $params['id'] = $excludeHolidayId;
        }
        return ((int) $this->conn()->fetchOne($sql, $params)) > 0;
    }

    /**
     * 登録 / 更新 (2系 save).
     * @return int 登録/更新した holiday_id
     */
    public function save(array $data, int $memberId): int
    {
        $conn = $this->conn();
        $holidayId = (int) ($data['holiday_id'] ?? 0);
        $now = date('Y-m-d H:i:s');

        if ($holidayId > 0) {
            // 更新 (creator_id / create_date は触らない)
            $conn->update('dtb_holiday', [
                'title'       => (string) $data['title'],
                'month'       => (int) $data['month'],
                'day'         => (int) $data['day'],
                'update_date' => $now,
            ], ['holiday_id' => $holidayId]);
            return $holidayId;
        }

        // 新規 — rank は max+1
        $maxRank = (int) $conn->fetchOne('SELECT COALESCE(MAX(rank), 0) FROM dtb_holiday');
        $conn->insert('dtb_holiday', [
            'title'       => (string) $data['title'],
            'month'       => (int) $data['month'],
            'day'         => (int) $data['day'],
            'rank'        => $maxRank + 1,
            'creator_id'  => $memberId,
            'create_date' => $now,
            'update_date' => $now,
            'del_flg'     => 0,
        ]);
        return (int) $conn->lastInsertId('dtb_holiday_holiday_id_seq');
    }

    /**
     * 物理削除 (2系 delete → sfDeleteRankRecord($delete=true)).
     * 2系 SC_Helper_Holiday::delete() は sfDeleteRankRecord の第5引数 $delete=true で
     * 呼ばれるため del_flg 更新ではなく DELETE 文が実行される (SC_Helper_DB::sfDeleteRankRecord
     * L1299-1301)。削除後、上位レコードの rank を -1 して詰める点は2系と同一。
     */
    public function delete(int $holidayId): bool
    {
        $conn = $this->conn();
        $conn->beginTransaction();
        try {
            $target = $conn->fetchAssociative(
                'SELECT rank FROM dtb_holiday WHERE holiday_id = :id AND del_flg = 0',
                ['id' => $holidayId],
            );
            if ($target === false) {
                $conn->rollBack();
                return false;
            }
            $targetRank = (int) $target['rank'];

            // 物理削除 (2系 sfDeleteRankRecord $delete=true 相当)
            $conn->executeStatement(
                'DELETE FROM dtb_holiday WHERE holiday_id = :id',
                ['id' => $holidayId],
            );
            // 上位レコードの rank を 1 下げる
            $conn->executeStatement(
                'UPDATE dtb_holiday SET rank = rank - 1, update_date = CURRENT_TIMESTAMP'
                . ' WHERE rank > :rank AND del_flg = 0',
                ['rank' => $targetRank],
            );
            $conn->commit();
            return true;
        } catch (\Throwable $e) {
            $conn->rollBack();
            throw $e;
        }
    }

    /**
     * rank を 1 上げる (2系 sfRankUp).
     * 自レコードと「自レコード+1 ランクのレコード」を入れ替え.
     */
    public function rankUp(int $holidayId): void
    {
        $this->swapRank($holidayId, +1);
    }

    /**
     * rank を 1 下げる (2系 sfRankDown).
     */
    public function rankDown(int $holidayId): void
    {
        $this->swapRank($holidayId, -1);
    }

    /**
     * @param int $direction +1 = rank up (swap with rank+1 record), -1 = rank down
     */
    private function swapRank(int $holidayId, int $direction): void
    {
        $conn = $this->conn();
        $conn->beginTransaction();
        try {
            $target = $conn->fetchAssociative(
                'SELECT rank FROM dtb_holiday WHERE holiday_id = :id AND del_flg = 0',
                ['id' => $holidayId],
            );
            if ($target === false) {
                $conn->rollBack();
                return;
            }
            $targetRank   = (int) $target['rank'];
            $neighborRank = $targetRank + $direction;

            $neighbor = $conn->fetchAssociative(
                'SELECT holiday_id FROM dtb_holiday WHERE rank = :rank AND del_flg = 0',
                ['rank' => $neighborRank],
            );
            if ($neighbor === false) {
                $conn->rollBack();
                return; // 端のレコード
            }
            $neighborId = (int) $neighbor['holiday_id'];

            $conn->executeStatement(
                'UPDATE dtb_holiday SET rank = :rank, update_date = CURRENT_TIMESTAMP WHERE holiday_id = :id',
                ['rank' => $neighborRank, 'id' => $holidayId],
            );
            $conn->executeStatement(
                'UPDATE dtb_holiday SET rank = :rank, update_date = CURRENT_TIMESTAMP WHERE holiday_id = :id',
                ['rank' => $targetRank, 'id' => $neighborId],
            );
            $conn->commit();
        } catch (\Throwable $e) {
            $conn->rollBack();
            throw $e;
        }
    }
}
