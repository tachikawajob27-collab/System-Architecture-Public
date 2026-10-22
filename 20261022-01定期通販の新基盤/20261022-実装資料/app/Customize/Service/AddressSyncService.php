<?php

declare(strict_types=1);

namespace Customize\Service;

use Doctrine\DBAL\Connection;
use Eccube\Entity\Customer;

/**
 * 顧客住所変更時に、保留中受注の配送先情報を同期する.
 *
 * 2系: `LC_Page_Mypage_Change::lfUpdateOrderData` (L759-772) と
 * `lfUpdateShippingTableData` (L780-793) を移植.
 *
 * ## 2系フロー
 * マイページ「お客様情報変更」完了時 (L167-168) に以下を実行:
 *   1. `lfGetOrderIdToChangeOrderTableData` (L801-820): 対象顧客の保留中受注 ID 一覧取得
 *   2. `lfUpdateOrderData`: `dtb_order` の顧客情報カラム UPDATE
 *   3. `lfUpdateShippingTableData`: `dtb_shipping` の配送先カラム UPDATE
 *
 * ※ 2系には `lfGetOrderIdToChangeShippingTableData` (L828-860) というより厳しい条件
 * (dtb_teiki JOIN + shipping_date filter) の関数もあるが、実際には `lfUpdateShippingTableData`
 * からは `lfGetOrderIdToChangeOrderTableData` の方が呼ばれており、dead code.
 * 4系も 2系稼働仕様に合わせて order 側と同じ条件で shipping を更新する.
 *
 * ## 対象 status (2系 L810)
 *   1 (新規受付), 7 (決済処理中), 9000 (後払い決済完了), 9001 (後払い審査OK),
 *   216 (保留), 217 (保留・予約), 219 (2系稼働実績 0件のため 4系では除外)
 *
 * ## 4系差分
 * - `dtb_order.status` → `order_status_id`
 * - `dtb_customer.tel01/02/03` → `phone_number` (単一カラム化)
 * - `dtb_customer.zip01/02`     → `postal_code`
 * - `dtb_customer.pref/sex/job` → `pref_id/sex_id/job_id` (FK化)
 * - `dtb_order.del_flg` 廃止
 * - `dtb_customer.email` 直接コピー (4系 mypage_change は email 変更にトークン検証を挟む)
 * - `fax01/02/03` は 4系 core で廃止 (dtb_customer/order/shipping どれにもなし)
 */
class AddressSyncService
{
    /**
     * 2系 L810 の status 値そのまま.
     * 219 は 2系本番でも 0件のため除外.
     *
     * @var list<int>
     */
    private const ELIGIBLE_STATUSES = [1, 7, 9000, 9001, 216, 217];

    public function __construct(
        private readonly Connection $connection,
    ) {}

    /**
     * @return int 更新した受注件数 (対象なしなら 0)
     */
    public function syncPendingOrders(Customer $customer): int
    {
        $customerId = (int) $customer->getId();
        if ($customerId <= 0) {
            return 0;
        }

        $orderIds = $this->fetchEligibleOrderIds($customerId);
        if (empty($orderIds)) {
            return 0;
        }

        $this->updateOrders($orderIds, $customer);
        $this->updateShippings($orderIds, $customer);

        return count($orderIds);
    }

    /**
     * @return list<int>
     */
    private function fetchEligibleOrderIds(int $customerId): array
    {
        /** @var list<array{id:int}> $rows */
        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT id
                  FROM dtb_order
                 WHERE customer_id     = :cid
                   AND order_status_id IN (:statuses)
            SQL,
            ['cid' => $customerId, 'statuses' => self::ELIGIBLE_STATUSES],
            ['statuses' => \Doctrine\DBAL\ArrayParameterType::INTEGER],
        );

        return array_map(static fn(array $r): int => (int) $r['id'], $rows);
    }

    /**
     * dtb_order の顧客情報カラム UPDATE (2系 lfConvertCustomerSqlValToOrderTableSqlVal).
     *
     * @param list<int> $orderIds
     */
    private function updateOrders(array $orderIds, Customer $customer): void
    {
        $this->connection->executeStatement(
            <<<'SQL'
                UPDATE dtb_order
                   SET name01       = :name01,
                       name02       = :name02,
                       kana01       = :kana01,
                       kana02       = :kana02,
                       email        = :email,
                       phone_number = :phone_number,
                       postal_code  = :postal_code,
                       pref_id      = :pref_id,
                       addr01       = :addr01,
                       addr02       = :addr02,
                       sex_id       = :sex_id,
                       job_id       = :job_id
                 WHERE id IN (:ids)
            SQL,
            [
                'name01'       => $customer->getName01(),
                'name02'       => $customer->getName02(),
                'kana01'       => $customer->getKana01(),
                'kana02'       => $customer->getKana02(),
                'email'        => $customer->getEmail(),
                'phone_number' => $customer->getPhoneNumber(),
                'postal_code'  => $customer->getPostalCode(),
                'pref_id'      => $customer->getPref()?->getId(),
                'addr01'       => $customer->getAddr01(),
                'addr02'       => $customer->getAddr02(),
                'sex_id'       => $customer->getSex()?->getId(),
                'job_id'       => $customer->getJob()?->getId(),
                'ids'          => $orderIds,
            ],
            ['ids' => \Doctrine\DBAL\ArrayParameterType::INTEGER],
        );
    }

    /**
     * dtb_shipping の配送先カラム UPDATE (2系 lfConvertCustomerSqlValToShippingTableSqlVal).
     * 2系 sqlVal に email / sex / job は含まれないため 4系も除外.
     *
     * @param list<int> $orderIds
     */
    private function updateShippings(array $orderIds, Customer $customer): void
    {
        $this->connection->executeStatement(
            <<<'SQL'
                UPDATE dtb_shipping
                   SET name01       = :name01,
                       name02       = :name02,
                       kana01       = :kana01,
                       kana02       = :kana02,
                       phone_number = :phone_number,
                       postal_code  = :postal_code,
                       pref_id      = :pref_id,
                       addr01       = :addr01,
                       addr02       = :addr02
                 WHERE order_id IN (:ids)
            SQL,
            [
                'name01'       => $customer->getName01(),
                'name02'       => $customer->getName02(),
                'kana01'       => $customer->getKana01(),
                'kana02'       => $customer->getKana02(),
                'phone_number' => $customer->getPhoneNumber(),
                'postal_code'  => $customer->getPostalCode(),
                'pref_id'      => $customer->getPref()?->getId(),
                'addr01'       => $customer->getAddr01(),
                'addr02'       => $customer->getAddr02(),
                'ids'          => $orderIds,
            ],
            ['ids' => \Doctrine\DBAL\ArrayParameterType::INTEGER],
        );
    }
}
