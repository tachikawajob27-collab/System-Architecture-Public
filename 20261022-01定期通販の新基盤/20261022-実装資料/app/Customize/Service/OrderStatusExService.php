<?php

namespace Customize\Service;

use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * 注文アイコン (dtb_order_status) 操作サービス.
 *
 * 2系の `_and_status` 系 case (customer_note_and_status L1561-1568 /
 * customer_inqury_and_status L1565-1568) で使用される
 * `INSERT INTO dtb_order_status (order_id, status)` を移植したもの。
 *
 * **顧客アイコン (dtb_customer_status) との違い:**
 *  - 顧客アイコン: dtb_customer_status (顧客単位) ← CustomerStatusExService
 *  - 注文アイコン: dtb_order_status     (注文単位) ← 本 Service
 *
 * 共に PR #135 で master (mtb_order_status_ex / mtb_customer_status_ex) を投入済。
 */
class OrderStatusExService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * 注文アイコンを追加する (2系 L1561-1568 / L1588-1596 / L1866-1879 相当).
     *
     * @return array{added: bool, message: string} 既に設定済の場合 added=false
     */
    public function add(int $orderId, int $status): array
    {
        $conn = $this->em->getConnection();
        $count = (int) $conn->fetchOne(
            'SELECT COUNT(*) FROM dtb_order_status WHERE order_id = :oid AND status = :status',
            ['oid' => $orderId, 'status' => $status],
        );
        if ($count > 0) {
            return ['added' => false, 'message' => '注文アイコンが既に設定済みです。'];
        }
        $conn->insert('dtb_order_status', [
            'order_id'    => $orderId,
            'status'      => $status,
            'create_date' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);
        $this->logger->info('[OrderStatusEx] add', ['order_id' => $orderId, 'status' => $status]);
        return ['added' => true, 'message' => '注文アイコンを追加しました。'];
    }

    /**
     * 注文アイコンを削除する (2系 L1880-1891 相当).
     *
     * @return array{deleted: bool, message: string} 設定されていない場合 deleted=false
     */
    public function delete(int $orderId, int $status): array
    {
        $conn = $this->em->getConnection();
        $count = (int) $conn->fetchOne(
            'SELECT COUNT(*) FROM dtb_order_status WHERE order_id = :oid AND status = :status',
            ['oid' => $orderId, 'status' => $status],
        );
        if ($count === 0) {
            return ['deleted' => false, 'message' => '注文アイコンがありません。'];
        }
        $conn->executeStatement(
            'DELETE FROM dtb_order_status WHERE order_id = :oid AND status = :status',
            ['oid' => $orderId, 'status' => $status],
        );
        $this->logger->info('[OrderStatusEx] delete', ['order_id' => $orderId, 'status' => $status]);
        return ['deleted' => true, 'message' => '注文アイコンを削除しました。'];
    }

    /**
     * 受注に紐付く注文アイコン一覧を取得する (view 表示用).
     *
     * @return list<array{status:int, name:string}>
     */
    public function listForOrder(int $orderId): array
    {
        return $this->em->getConnection()->fetchAllAssociative(
            'SELECT os.status AS status, m.name AS name
             FROM dtb_order_status os
             INNER JOIN mtb_order_status_ex m ON os.status = m.id
             WHERE os.order_id = :oid
             ORDER BY os.create_date ASC',
            ['oid' => $orderId],
        );
    }
}
