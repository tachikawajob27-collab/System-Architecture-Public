<?php

declare(strict_types=1);

namespace Customize\Service\Shipping;

use Doctrine\DBAL\Connection;

/**
 * 出荷準備管理 Service.
 *
 * 2系: SC_Helper_Shipping_Ex (data/class_extends/helper_extends/SC_Helper_Shipping_Ex.php) を移植.
 *   - getShippingCommitHistory  L36-51  過去7日間の出荷完了履歴
 *   - getShippingYtiData        L56-72  本日から15日間の出荷予定 (出荷準備中は含めない)
 *   - getShippingReady          L77-88  出荷準備中一覧
 *   - cancelShippingReady       L101-123 出荷準備解除 (dtb_order.status→1/9001, dtb_shipping_ready DELETE)
 *
 * 4系 rename:
 *   - dtb_order.order_id  → id
 *   - dtb_order.status    → order_status_id
 *   - dtb_order.del_flg   → (廃止)
 *   - dtb_deliv           → dtb_delivery (4系 core: dtb_delivery.id, dtb_delivery.name)
 *
 * 依存する PG 関数:
 *   - func_get_syuka_date (Version20260703000001 で移植)
 *
 * 決済 ID: 13 = VAMOS 後払い (2系 MDL_VAMOS_PAYMENT_ID).
 */
class ShippingReadyService
{
    /** 2系 status→4系 order_status_id: 出荷準備中. */
    private const STATUS_SHIPPING_READY = 8;

    /** 2系 status→4系 order_status_id: 新規受付. */
    private const STATUS_NEW = 1;

    /** 2系 status→4系 order_status_id: NP後払い決済処理中相当 (2系独自ステータス 9001 を継続). */
    private const STATUS_NP_PENDING = 9001;

    /** 2系 MDL_VAMOS_PAYMENT_ID. */
    private const VAMOS_PAYMENT_ID = 13;

    public function __construct(
        private readonly Connection $connection,
    ) {}

    /**
     * 過去 7 日間の出荷完了履歴 (2系 getShippingCommitHistory L36-51).
     *
     * @return list<array{commit_date: string, deliv_name: string, count: int}>
     */
    public function getShippingCommitHistory(int $brandId): array
    {
        $sql = <<<'SQL'
            SELECT ds.shipping_commit_date::text AS commit_date,
                   (SELECT name FROM dtb_delivery WHERE id = ds.delivery_id) AS deliv_name,
                   COUNT(*)::int AS count
              FROM dtb_shipping ds
             INNER JOIN dtb_order do2 ON ds.order_id = do2.id
             WHERE ds.shipping_commit_date >= current_date - interval '7 days'
               AND ds.shipping_commit_date IS NOT NULL
               AND do2.brand_id = :brand_id
             GROUP BY ds.shipping_commit_date, deliv_name
             ORDER BY ds.shipping_commit_date DESC, deliv_name
        SQL;

        /** @var list<array{commit_date: string, deliv_name: string, count: int}> */
        return $this->connection->fetchAllAssociative($sql, ['brand_id' => $brandId]);
    }

    /**
     * 本日から 15 日間の出荷予定 (出荷準備中は含めない).
     *
     * 2系 getShippingYtiData L56-72. func_get_syuka_date でお届け日→出荷日を算出。
     *
     * @return list<array{deliv_date: string, count: int}>
     */
    public function getShippingYtiData(int $brandId): array
    {
        $sql = <<<'SQL'
            SELECT al.deliv_date, COUNT(DISTINCT al.order_id)::int AS count
              FROM (
                SELECT TO_CHAR(func_get_syuka_date(s.delivery_id, s.shipping_date::timestamp), 'YYYY/MM/DD') AS deliv_date,
                       o.id AS order_id
                  FROM dtb_order o
                 INNER JOIN dtb_shipping s ON o.id = s.order_id
                 WHERE o.order_status_id IN (1, 9001)
                   AND o.brand_id = :brand_id
                   AND TO_CHAR(COALESCE(s.shipping_date, CURRENT_DATE), 'YYYYMMDD')
                       <= TO_CHAR(CURRENT_DATE + INTERVAL '15 day', 'YYYYMMDD')
              ) al
             GROUP BY al.deliv_date
             ORDER BY al.deliv_date
        SQL;

        /** @var list<array{deliv_date: string, count: int}> */
        return $this->connection->fetchAllAssociative($sql, ['brand_id' => $brandId]);
    }

    /**
     * 出荷準備中一覧 (2系 getShippingReady L77-88).
     *
     * @return list<array{shipping_ready_id: int, output_date: string, shipping_date: string, output_type: int, shipping_cnt: int, shipping_total: int}>
     */
    public function getShippingReady(int $brandId): array
    {
        $sql = <<<'SQL'
            SELECT dsr.shipping_ready_id,
                   dsr.output_date,
                   dsr.shipping_date,
                   dsr.output_type,
                   (SELECT COUNT(DISTINCT do3.id)
                      FROM dtb_order do3
                     WHERE do3.shipping_ready_id = dsr.shipping_ready_id
                       AND do3.order_status_id = :status_ready)::int AS shipping_cnt,
                   COALESCE((SELECT SUM(do3.payment_total)
                               FROM dtb_order do3
                              WHERE do3.shipping_ready_id = dsr.shipping_ready_id
                                AND do3.order_status_id = :status_ready), 0)::int AS shipping_total
              FROM dtb_shipping_ready dsr
             WHERE dsr.del_flg = 0
               AND dsr.brand_id = :brand_id
               AND EXISTS (
                    SELECT NULL
                      FROM dtb_order do2
                     WHERE do2.shipping_ready_id = dsr.shipping_ready_id
                       AND do2.order_status_id = :status_ready)
             ORDER BY dsr.shipping_date, dsr.output_type
        SQL;

        /** @var list<array{shipping_ready_id: int, output_date: string, shipping_date: string, output_type: int, shipping_cnt: int, shipping_total: int}> */
        return $this->connection->fetchAllAssociative($sql, [
            'brand_id'     => $brandId,
            'status_ready' => self::STATUS_SHIPPING_READY,
        ]);
    }

    /**
     * 出荷準備中件数・金額 — ブランド全体の単純集計 (2系 brand 1/2 用 `shipment.php`
     * = `LC_Page_Admin_Shipping_Logi_Ex::getShipPreparCount()` / `getShipPreparTotalAmount()`
     * L2771-2781 / L2894-2903 移植)。
     *
     * ★brand 3 は別ページ `shipment_renew.php`（直近 `shipping_date` バッチのみ合計）を使うため
     * このメソッドの対象外（`LogiRenewController` 側でブランド分岐して呼び分ける。Phase5 SHIP-65）。
     * `del_flg = 0` は4系コアに列が無いため対象外（SHIP-63/SHIP-63系と同じ構造差）。
     *
     * @return array{count: int, total: int}
     */
    public function getShipPreparSummary(int $brandId): array
    {
        $sql = <<<'SQL'
            SELECT COUNT(DISTINCT id)::int AS count,
                   COALESCE(SUM(payment_total), 0)::int AS total
              FROM dtb_order
             WHERE order_status_id = :status_ready
               AND brand_id = :brand_id
        SQL;

        /** @var array{count: int, total: int} */
        return $this->connection->fetchAssociative($sql, [
            'status_ready' => self::STATUS_SHIPPING_READY,
            'brand_id'     => $brandId,
        ]);
    }

    /**
     * 出荷準備中解除 (2系 cancelShippingReady L101-123).
     *
     * - 対象 shipping_ready_id の 出荷準備中 (order_status_id=8) 受注を取得
     * - 支払方法により戻しステータスを分岐 (VAMOS=9001, 他=1) + shipping_ready_id=NULL
     * - dtb_shipping_ready を DELETE
     */
    public function cancelShippingReady(int $brandId, int $shippingReadyId): void
    {
        $this->connection->beginTransaction();
        try {
            /** @var list<array{id: int, payment_id: int}> $targets */
            $targets = $this->connection->fetchAllAssociative(
                <<<'SQL'
                    SELECT id, payment_id
                      FROM dtb_order
                     WHERE order_status_id = :status_ready
                       AND brand_id = :brand_id
                       AND shipping_ready_id = :shipping_ready_id
                SQL,
                [
                    'status_ready'      => self::STATUS_SHIPPING_READY,
                    'brand_id'          => $brandId,
                    'shipping_ready_id' => $shippingReadyId,
                ]
            );

            foreach ($targets as $row) {
                $revertStatus = ((int) $row['payment_id'] === self::VAMOS_PAYMENT_ID)
                    ? self::STATUS_NP_PENDING
                    : self::STATUS_NEW;

                $this->connection->executeStatement(
                    <<<'SQL'
                        UPDATE dtb_order
                           SET order_status_id  = :status,
                               shipping_ready_id = NULL,
                               update_date       = CURRENT_TIMESTAMP
                         WHERE id = :id
                    SQL,
                    ['status' => $revertStatus, 'id' => (int) $row['id']]
                );
            }

            $this->connection->executeStatement(
                'DELETE FROM dtb_shipping_ready WHERE shipping_ready_id = :id',
                ['id' => $shippingReadyId]
            );

            $this->connection->commit();
        } catch (\Throwable $e) {
            $this->connection->rollBack();
            throw $e;
        }
    }
}
