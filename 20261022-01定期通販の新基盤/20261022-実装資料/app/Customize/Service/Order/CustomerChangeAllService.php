<?php

namespace Customize\Service\Order;

use Doctrine\ORM\EntityManagerInterface;
use Eccube\Entity\Customer;
use Eccube\Repository\Master\PrefRepository;

/**
 * 「お客様情報」一括変更 (2系 `LC_Page_Admin_Order_Edit_Ex::action()` `mode=customer_change_all`、
 * `Customize\EventSubscriber\Admin\Order\SC_Helper_Customer_Ex::sfEditCustomerData()` /
 * `sfEditCustomerOrderData()` / `sfEditShipping()` の移植、Issue #3080)。
 *
 * 2系は以下3テーブルをそれぞれ別トランザクションで更新する:
 *   - dtb_customer (顧客マスタ本体、customer_id一致)
 *   - dtb_order    (その顧客の**全**受注、customer_id一致のみ。ブランド条件なし)
 *   - dtb_shipping (それら受注に紐づく**全**配送先、email列は無いので対象外)
 *
 * ★4系は1つの操作にまとめる(このメソッド自体はDBALの明示的トランザクションを張らない)。
 *   `Eccube\EventListener\TransactionListener` がリクエスト全体を1トランザクションで
 *   包んでおり(`kernel.request`でbeginTransaction、`kernel.terminate`でcommit)、本メソッド内で
 *   例外を握り潰さず素通しする限り、3テーブルいずれかの更新が失敗すれば
 *   `kernel.exception`が例外発生**前**の状態へロールバックする(2系の「客だけ変わって
 *   注文は変わらない」ような部分更新を防げる、技術的な改善であり業務ロジックの変更ではない)。
 *
 * ★2系 `sfEditCustomerData()`/`sfEditCustomerOrderData()`/`sfEditShipping()` はサーバ側で
 *   `kana01 = name01`(氏名をそのままカナ欄へ複製、3テーブルとも)を行う。
 *   2026-10-19 に画面を2系どおり「お名前」1欄にしたので、複製も2系どおり Controller 側で行う
 *   (`CustomerChangeAllController` が kana01 = name01 / name02 = '' / kana02 = NULL を渡す)。
 *   本 Service は渡された値を3テーブルにそのまま書く。
 */
class CustomerChangeAllService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PrefRepository $prefRepository,
    ) {}

    /**
     * @param array{name01: string, name02: string, kana01: ?string, kana02: ?string,
     *              email: string, phone_number: ?string, postal_code: ?string,
     *              pref_id: ?int, addr01: ?string, addr02: ?string} $data
     */
    public function changeAll(Customer $Customer, array $data): void
    {
        $Pref = $data['pref_id'] !== null ? $this->prefRepository->find($data['pref_id']) : null;

        // --- dtb_customer (顧客マスタ本体) ---
        $Customer->setName01($data['name01']);
        $Customer->setName02($data['name02']);
        $Customer->setKana01($data['kana01']);
        $Customer->setKana02($data['kana02']);
        $Customer->setEmail($data['email']);
        $Customer->setPhoneNumber($data['phone_number']);
        $Customer->setPostalCode($data['postal_code']);
        $Customer->setPref($Pref);
        $Customer->setAddr01($data['addr01']);
        $Customer->setAddr02($data['addr02']);
        $this->em->flush();

        $conn = $this->em->getConnection();
        $params = [
            'name01'       => $data['name01'],
            'name02'       => $data['name02'],
            'kana01'       => $data['kana01'],
            'kana02'       => $data['kana02'],
            'email'        => $data['email'],
            'phone_number' => $data['phone_number'],
            'postal_code'  => $data['postal_code'],
            'pref_id'      => $data['pref_id'],
            'addr01'       => $data['addr01'],
            'addr02'       => $data['addr02'],
            'customer_id'  => $Customer->getId(),
        ];

        // --- dtb_order (その顧客の全受注、2系 sfEditCustomerOrderData() 相当) ---
        $conn->executeStatement(
            <<<'SQL'
            UPDATE dtb_order
            SET name01 = :name01, name02 = :name02, kana01 = :kana01, kana02 = :kana02,
                email = :email, phone_number = :phone_number, postal_code = :postal_code,
                pref_id = :pref_id, addr01 = :addr01, addr02 = :addr02,
                update_date = CURRENT_TIMESTAMP
            WHERE customer_id = :customer_id
            SQL,
            $params,
        );

        // --- dtb_shipping (その顧客の全受注に紐づく全配送先、2系 sfEditShipping() 相当。
        //     email列は無いため対象外) ---
        $conn->executeStatement(
            <<<'SQL'
            UPDATE dtb_shipping
            SET name01 = :name01, name02 = :name02, kana01 = :kana01, kana02 = :kana02,
                phone_number = :phone_number, postal_code = :postal_code,
                pref_id = :pref_id, addr01 = :addr01, addr02 = :addr02,
                update_date = CURRENT_TIMESTAMP
            WHERE order_id IN (SELECT id FROM dtb_order WHERE customer_id = :customer_id)
            SQL,
            [
                'name01'       => $data['name01'],
                'name02'       => $data['name02'],
                'kana01'       => $data['kana01'],
                'kana02'       => $data['kana02'],
                'phone_number' => $data['phone_number'],
                'postal_code'  => $data['postal_code'],
                'pref_id'      => $data['pref_id'],
                'addr01'       => $data['addr01'],
                'addr02'       => $data['addr02'],
                'customer_id'  => $Customer->getId(),
            ],
        );
    }
}
