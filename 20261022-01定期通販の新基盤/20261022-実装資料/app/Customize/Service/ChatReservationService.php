<?php

declare(strict_types=1);

namespace Customize\Service;

use Doctrine\DBAL\Connection;
use Knp\Component\Pager\Pagination\PaginationInterface;
use Knp\Component\Pager\PaginatorInterface;

/**
 * チャット シナリオ予約切替 (2系 LC_Page_Admin_Chat_Reservation + Edit_Ex 移植).
 *
 * dtb_chat_scenario_reservation: 予約本体 (即時/予約 + 切替元/切替先シナリオ)
 * dtb_chat_scenario_reservation_model: 個別選択モデル
 * dtb_chat_scenario_info: シナリオマスタ
 * dtb_model: モデル (即時実行時に scenario_no を一括更新)
 */
class ChatReservationService
{
    public function __construct(
        private readonly Connection $conn,
        private readonly PaginatorInterface $paginator,
    ) {}

    /**
     * 2系 doSearch (L218-225 の getAll JOIN 文を踏襲).
     *
     * update_datetime は2系同様 `<br>` をフォーマット文字列に直接埋め込んで2行表示させる
     * (2系 `LC_Page_Admin_Chat_Reservation_Ex.php` L218。Issue #1607)。この列は `dcsr.update_date`
     * (DBが生成した固定書式のタイムスタンプ文字列) のみが元でユーザー入力を含まないため、
     * 出力側で `|raw` を使っても XSS リスクは無い。
     *
     * @param array<string, mixed> $searchData
     */
    public function getPagination(array $searchData, int $brandId, int $page, int $pageMax): PaginationInterface
    {
        $sql = "SELECT dcsr.*,"
            . " dcsi.scenario_name AS before_name,"
            . " dcsi2.scenario_name AS after_name,"
            . " TO_CHAR(dcsr.update_date, 'YYYY-MM-DD<br>HH24時MI分') AS update_datetime,"
            . " ARRAY_TO_STRING("
            . "   ARRAY("
            . "     SELECT dm.name FROM dtb_model dm"
            . "     INNER JOIN dtb_chat_scenario_reservation_model dcsrm ON dm.id = dcsrm.model_id"
            . "     WHERE dcsrm.reservation_id = dcsr.id ORDER BY dm.id"
            . "   ),"
            . "   E'\n'"
            . " ) AS target_name"
            . " FROM dtb_chat_scenario_reservation dcsr"
            . " INNER JOIN dtb_chat_scenario_info dcsi  ON dcsr.scenario_no_before = dcsi.scenario_no AND dcsi.brand_id = :brand_id"
            . " INNER JOIN dtb_chat_scenario_info dcsi2 ON dcsr.scenario_no_after  = dcsi2.scenario_no AND dcsi2.brand_id = :brand_id"
            . " WHERE dcsr.del_flg = 0 AND dcsr.brand_id = :brand_id";

        $params = ['brand_id' => $brandId];

        // search_status (空文字以外なら絞り込み。0 も有効値)
        if (isset($searchData['search_status']) && $searchData['search_status'] !== '') {
            $sql .= ' AND dcsr.status = :status';
            $params['status'] = (int) $searchData['search_status'];
        }

        $sql .= ' ORDER BY dcsr.status, dcsr.reservation_date, dcsr.reservation_time';

        $rows = $this->conn->fetchAllAssociative($sql, $params);

        // ページ番号が範囲外(0以下 / 最終ページ超過)なら1ページ目に戻す。
        // 2系 SC_PageNavi::__construct (data/class/SC_PageNavi.php L59-73) と同じクランプ。
        $maxPage = $pageMax > 0 ? (int) ceil(count($rows) / $pageMax) : 0;
        if ($page < 1 || ($maxPage > 0 && $page > $maxPage)) {
            $page = 1;
        }

        return $this->paginator->paginate($rows, $page, $pageMax);
    }

    /**
     * 2系 getReservation. 編集用に予約 + シナリオ category_id を1件取得.
     *
     * @return array<string, mixed>|null
     */
    public function findById(int $id, int $brandId): ?array
    {
        $row = $this->conn->fetchAssociative(
            'SELECT dcsr.*, dcsi.category_id'
            . ' FROM dtb_chat_scenario_reservation dcsr'
            . ' INNER JOIN dtb_chat_scenario_info dcsi ON dcsr.scenario_no_before = dcsi.scenario_no AND dcsi.brand_id = :brand_id'
            . ' WHERE dcsr.id = :id AND dcsr.brand_id = :brand_id AND dcsr.del_flg = 0',
            ['id' => $id, 'brand_id' => $brandId],
        );
        return $row === false ? null : $row;
    }

    /**
     * 2系 getChatScenario.
     *   mode=0: 切替元 (dtb_model に紐付くシナリオ。is_chat=0)
     *   mode=1: 切替先 (dtb_chat_scenario_info から全シナリオ)
     *
     * @return array<int, string>  scenario_no => "scenario_no - scenario_name"
     */
    public function getScenarios(int $mode, int $brandId, int $categoryId): array
    {
        if ($categoryId <= 0) {
            return [];
        }
        if ($mode === 0) {
            $sql = 'SELECT DISTINCT dm.scenario_no, dcsi.scenario_name'
                . ' FROM dtb_model dm'
                . ' INNER JOIN dtb_chat_scenario_info dcsi ON dm.scenario_no = dcsi.scenario_no AND dcsi.brand_id = :brand_id'
                . ' WHERE dm.del_flg = 0 AND dm.is_chat = 0 AND dm.brand_id = :brand_id AND dcsi.category_id = :category_id'
                . ' ORDER BY dm.scenario_no DESC';
        } else {
            $sql = 'SELECT dcsi.scenario_no, dcsi.scenario_name'
                . ' FROM dtb_chat_scenario_info dcsi'
                . ' WHERE dcsi.del_flg = 0 AND dcsi.brand_id = :brand_id AND dcsi.category_id = :category_id'
                . ' ORDER BY dcsi.scenario_no DESC';
        }

        $rows = $this->conn->fetchAllAssociative($sql, [
            'brand_id'    => $brandId,
            'category_id' => $categoryId,
        ]);

        $list = [];
        foreach ($rows as $row) {
            $no = (int) $row['scenario_no'];
            $list[$no] = $no . ' - ' . (string) $row['scenario_name'];
        }
        return $list;
    }

    /**
     * 2系 getReservationModel. 切替元シナリオに紐づく新規モデル一覧.
     *
     * @return list<array{id:int, name:string, category_id:?int, reservation_id:?int}>
     */
    public function getReservationModels(?int $reservationId, int $brandId, int $scenarioNoBefore): array
    {
        if ($scenarioNoBefore <= 0) {
            return [];
        }

        if ($reservationId === null || $reservationId <= 0) {
            // 新規登録
            $sql = 'SELECT dm.id, dm.name, dcsi.category_id, NULL::integer AS reservation_id'
                . ' FROM dtb_model dm'
                . ' INNER JOIN dtb_chat_scenario_info dcsi ON dm.scenario_no = dcsi.scenario_no AND dcsi.brand_id = :brand_id'
                . ' WHERE dm.del_flg = 0 AND dm.is_chat = 0 AND dm.brand_id = :brand_id AND dm.scenario_no = :scenario_no'
                . ' ORDER BY dm.id DESC';
            $params = ['brand_id' => $brandId, 'scenario_no' => $scenarioNoBefore];
        } else {
            // 編集 (現予約と LEFT JOIN して既選択判定)
            $sql = 'SELECT dm.id, dm.name, dcsi.category_id, dcsrm.reservation_id'
                . ' FROM dtb_model dm'
                . ' INNER JOIN dtb_chat_scenario_info dcsi ON dm.scenario_no = dcsi.scenario_no AND dcsi.brand_id = :brand_id'
                . ' LEFT JOIN dtb_chat_scenario_reservation_model dcsrm ON dm.id = dcsrm.model_id AND dcsrm.reservation_id = :reservation_id'
                . ' WHERE dm.del_flg = 0 AND dm.is_chat = 0 AND dm.brand_id = :brand_id AND dm.scenario_no = :scenario_no'
                . ' ORDER BY dm.id DESC';
            $params = ['brand_id' => $brandId, 'scenario_no' => $scenarioNoBefore, 'reservation_id' => $reservationId];
        }

        $rows = $this->conn->fetchAllAssociative($sql, $params);
        return array_map(static fn (array $r) => [
            'id'             => (int) $r['id'],
            'name'           => (string) $r['name'],
            'category_id'    => $r['category_id'] !== null ? (int) $r['category_id'] : null,
            'reservation_id' => $r['reservation_id'] !== null ? (int) $r['reservation_id'] : null,
        ], $rows);
    }

    /**
     * 2系 lfRegister.
     *
     * 1. dtb_chat_scenario_reservation INSERT/UPDATE
     * 2. UPDATE 時は dtb_chat_scenario_reservation_model DELETE
     * 3. change_kbn=1 (個別) のとき model_check の各 model_id を dtb_chat_scenario_reservation_model に INSERT
     * 4. reservation_mode=0 (即時) のとき dtb_model.scenario_no を一括/個別に切替 + 予約 status=1
     *
     * @param array<string, mixed> $data
     * @return int 登録/更新 ID
     */
    public function save(array $data, int $brandId, int $memberId): int
    {
        $id      = isset($data['id']) ? (int) $data['id'] : 0;
        $isNew   = $id <= 0;
        $now     = date('Y-m-d H:i:s');

        $changeKbn       = (int) ($data['change_kbn']       ?? 0);
        $reservationMode = (int) ($data['reservation_mode'] ?? 0);
        $scenarioBefore  = (int) ($data['scenario_no_before'] ?? 0);
        $scenarioAfter   = (int) ($data['scenario_no_after']  ?? 0);
        $modelCheck      = array_map('intval', (array) ($data['model_check'] ?? []));

        $vals = [
            'scenario_no_before' => $scenarioBefore,
            'change_kbn'         => $changeKbn,
            'scenario_no_after'  => $scenarioAfter,
            'reservation_mode'   => $reservationMode,
            'status'             => 0,
            'brand_id'           => $brandId,
            'update_date'        => $now,
            'updater_id'         => $memberId,
            'reservation_date'   => $reservationMode === 1 ? (string) ($data['reservation_date'] ?? '') : null,
            'reservation_time'   => $reservationMode === 1 ? (string) ($data['reservation_time'] ?? '') : null,
        ];

        $this->conn->beginTransaction();
        try {
            if ($isNew) {
                $vals['create_date'] = $now;
                $vals['creater_id']  = $memberId;
                $this->conn->insert('dtb_chat_scenario_reservation', $vals);
                $id = (int) $this->conn->lastInsertId('dtb_chat_scenario_reservation_id_seq');
            } else {
                $this->conn->update('dtb_chat_scenario_reservation', $vals, ['id' => $id]);
                $this->conn->delete('dtb_chat_scenario_reservation_model', ['reservation_id' => $id]);
            }

            // 個別選択モデルを登録
            if ($changeKbn === 1) {
                foreach ($modelCheck as $modelId) {
                    if ($modelId <= 0) {
                        continue;
                    }
                    $this->conn->insert('dtb_chat_scenario_reservation_model', [
                        'reservation_id' => $id,
                        'model_id'       => $modelId,
                        'create_date'    => $now,
                    ]);
                }
            }

            // 即時の場合: dtb_model.scenario_no を切替 + 予約 status=1
            if ($reservationMode === 0) {
                $update = ['scenario_no' => $scenarioAfter, 'update_date' => $now];
                if ($changeKbn === 0) {
                    // 全て
                    $this->conn->executeStatement(
                        'UPDATE dtb_model SET scenario_no = :sn, update_date = :ud'
                        . ' WHERE del_flg = 0 AND is_chat = 0 AND brand_id = :brand_id AND scenario_no = :sn_before',
                        [
                            'sn'        => $scenarioAfter,
                            'ud'        => $now,
                            'brand_id'  => $brandId,
                            'sn_before' => $scenarioBefore,
                        ],
                    );
                } else {
                    // 個別
                    foreach ($modelCheck as $modelId) {
                        if ($modelId <= 0) {
                            continue;
                        }
                        $this->conn->executeStatement(
                            'UPDATE dtb_model SET scenario_no = :sn, update_date = :ud'
                            . ' WHERE del_flg = 0 AND is_chat = 0 AND brand_id = :brand_id AND scenario_no = :sn_before AND id = :id',
                            [
                                'sn'        => $scenarioAfter,
                                'ud'        => $now,
                                'brand_id'  => $brandId,
                                'sn_before' => $scenarioBefore,
                                'id'        => $modelId,
                            ],
                        );
                    }
                }
                // 予約完了に更新
                $this->conn->update('dtb_chat_scenario_reservation', [
                    'status'      => 1,
                    'update_date' => $now,
                ], ['id' => $id]);
            }

            $this->conn->commit();
            return $id;
        } catch (\Throwable $e) {
            $this->conn->rollBack();
            throw $e;
        }
    }

    /**
     * 2系 delete. brand_id スコープ + 論理削除 + updater_id 記録.
     */
    public function softDelete(int $id, int $brandId, int $memberId): bool
    {
        $affected = $this->conn->executeStatement(
            'UPDATE dtb_chat_scenario_reservation SET del_flg = 1, update_date = :now, updater_id = :member_id'
            . ' WHERE id = :id AND brand_id = :brand_id',
            [
                'now'       => date('Y-m-d H:i:s'),
                'member_id' => $memberId,
                'id'        => $id,
                'brand_id'  => $brandId,
            ],
        );
        return $affected > 0;
    }
}
