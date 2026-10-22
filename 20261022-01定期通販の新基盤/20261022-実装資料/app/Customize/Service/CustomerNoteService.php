<?php

namespace Customize\Service;

use Doctrine\ORM\EntityManagerInterface;

/**
 * 受注/顧客メモ操作サービス.
 *
 * 2系 LC_Page_Admin_Order_Edit_Ex の以下 case を移植:
 *  - case 'change_note'                  (L1602-1612) 受注メモ変更
 *  - case 'customer_note'                (L1575-1581) 顧客対応履歴 全置換
 *  - case 'customer_inqury'              (L1538-1573) 顧客対応履歴 追記モード
 *
 * `_and_status` 系 (customer_note_and_status / customer_inqury_and_status) は
 * Controller 側で本Service + CustomerStatusExService を組み合わせる。
 */
class CustomerNoteService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {}

    /**
     * 受注メモ変更 (2系 change_note L1602-1612).
     *
     * 2系: UPDATE dtb_order SET note=?, update_date=CURRENT_TIMESTAMP
     *       WHERE order_id=? AND brand_id=?
     */
    public function updateOrderNote(int $orderId, int $brandId, string $note): void
    {
        $this->em->getConnection()->executeStatement(
            'UPDATE dtb_order SET note = :note, update_date = CURRENT_TIMESTAMP
             WHERE id = :id AND brand_id = :brand_id',
            [
                'note'     => $note,
                'id'       => $orderId,
                'brand_id' => $brandId,
            ]
        );
    }

    /**
     * 顧客対応履歴 全置換 (2系 customer_note L1575-1581).
     *
     * 2系: $objCustomer->sfEditCustomerData(['note' => $_POST['customer_note']], $customer_id)
     *      → UPDATE dtb_customer SET note=?, update_date=CURRENT_TIMESTAMP WHERE customer_id=?
     */
    public function updateCustomerNote(int $customerId, string $note): void
    {
        $this->em->getConnection()->executeStatement(
            'UPDATE dtb_customer SET note = :note, update_date = CURRENT_TIMESTAMP
             WHERE id = :id',
            [
                'note' => $note,
                'id'   => $customerId,
            ]
        );
    }

    /**
     * 顧客対応履歴 追記モード (2系 customer_inqury L1538-1554).
     *
     * 2系:
     *  $border = empty($data['note']) ? '' : PHP_EOL . '________________________' . PHP_EOL . PHP_EOL;
     *  $addData = $border . $inqury_date . ' ' . $inqury_time . ' ' . $objTeiki->sfGetSupportName() . PHP_EOL . $_POST['customer_note_new'];
     *  $array['note'] = $data['note'] . $addData;
     *  → 既存 note の末尾に区切り線 + 日時 + 担当者名 + 本文 を追記。
     *
     * @param string $supportName 4系: ":<管理者ログイン名>" (2系 sfGetSupportName 互換)
     */
    public function appendCustomerInqury(int $customerId, string $body, string $supportName): string
    {
        $conn = $this->em->getConnection();

        $existing = (string) ($conn->fetchOne(
            'SELECT note FROM dtb_customer WHERE id = :id',
            ['id' => $customerId]
        ) ?: '');

        // 2系 L1546-1548 互換: 日付と時刻は同一インスタンスから取得 (深夜0時跨ぎ防止)
        $now          = new \DateTimeImmutable();
        $border       = $existing === '' ? '' : PHP_EOL . '________________________' . PHP_EOL . PHP_EOL;
        $appended     = $border . $now->format('Y/m/d') . ' ' . $now->format('H:i') . ' ' . $supportName . PHP_EOL . $body;
        $newNote      = $existing . $appended;

        $conn->executeStatement(
            'UPDATE dtb_customer SET note = :note, update_date = CURRENT_TIMESTAMP
             WHERE id = :id',
            [
                'note' => $newNote,
                'id'   => $customerId,
            ]
        );

        return $newNote;
    }
}
