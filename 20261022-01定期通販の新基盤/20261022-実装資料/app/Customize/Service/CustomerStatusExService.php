<?php

namespace Customize\Service;

use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * 顧客アイコン (mtb_customer_status_ex) の add/delete 操作 Service.
 *
 * 2系 LC_Page_Admin_Order_Edit_Ex L1452-1478 (customer_status_add / customer_status_delete) を移植。
 *
 * dtb_customer_status テーブルに customer_id × status の組合せで INSERT/DELETE する。
 * master ステータス値は PR #135 で投入済 (mtb_customer_status_ex, 18件)。
 *
 * **2系ロジック完全踏襲:**
 * - add: count() で既存確認 → 0件のみ INSERT
 * - delete: count() で存在確認 → 1件以上のみ DELETE
 * - 2系の count() は 0件でもエラーにならず、メッセージのみ変える
 *
 * **brand_id スコープなし (2系踏襲):**
 * dtb_customer_status は (customer_id, status) の複合PKで brand_id を持たない。
 * 1顧客が複数ブランドに所属している場合、設定したアイコンは全ブランドで共有される。
 * これは 2系仕様そのままで、4系移行時の変更は無し。
 */
class CustomerStatusExService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * 顧客に対し特定のアイコン (status) を追加する (2系 customer_status_add L1452-1465).
     *
     * @return array{added: bool, message: string} 既に設定済の場合 added=false で警告メッセージ
     */
    public function add(int $customerId, int $status): array
    {
        $conn = $this->em->getConnection();
        $count = (int) $conn->fetchOne(
            'SELECT COUNT(*) FROM dtb_customer_status WHERE customer_id = :cid AND status = :status',
            ['cid' => $customerId, 'status' => $status],
        );
        if ($count > 0) {
            return ['added' => false, 'message' => '顧客アイコンが既に設定済みです。'];
        }
        $conn->insert('dtb_customer_status', [
            'customer_id' => $customerId,
            'status'      => $status,
            'create_date' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);
        $this->logger->info('[CustomerStatusEx] add', ['customer_id' => $customerId, 'status' => $status]);
        return ['added' => true, 'message' => '顧客アイコンを追加しました。'];
    }

    /**
     * 顧客から特定のアイコン (status) を削除する (2系 customer_status_delete L1466-1478).
     *
     * @return array{deleted: bool, message: string} 該当なしの場合 deleted=false
     */
    public function delete(int $customerId, int $status): array
    {
        $conn = $this->em->getConnection();
        $count = (int) $conn->fetchOne(
            'SELECT COUNT(*) FROM dtb_customer_status WHERE customer_id = :cid AND status = :status',
            ['cid' => $customerId, 'status' => $status],
        );
        if ($count === 0) {
            return ['deleted' => false, 'message' => '顧客アイコンがありません。'];
        }
        $conn->delete('dtb_customer_status', [
            'customer_id' => $customerId,
            'status'      => $status,
        ]);
        $this->logger->info('[CustomerStatusEx] delete', ['customer_id' => $customerId, 'status' => $status]);
        return ['deleted' => true, 'message' => '顧客アイコンを削除しました。'];
    }

    /**
     * 顧客に設定されているアイコン一覧を取得 (受注詳細画面の右ペイン表示用).
     *
     * @return list<array{status:int, name:string}>
     */
    public function listForCustomer(int $customerId): array
    {
        $rows = $this->em->getConnection()->fetchAllAssociative(
            <<<SQL
            SELECT dcs.status, mcsx.name
            FROM dtb_customer_status dcs
            LEFT JOIN mtb_customer_status_ex mcsx ON dcs.status = mcsx.id
            WHERE dcs.customer_id = :cid
            ORDER BY mcsx.sort_no ASC, dcs.status ASC
            SQL,
            ['cid' => $customerId],
        );
        $list = [];
        foreach ($rows as $row) {
            $list[] = [
                'status' => (int) $row['status'],
                'name'   => (string) ($row['name'] ?? ('未定義(id=' . $row['status'] . ')')),
            ];
        }
        return $list;
    }
}
