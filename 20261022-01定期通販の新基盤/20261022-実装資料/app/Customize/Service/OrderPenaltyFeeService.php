<?php

namespace Customize\Service;

use Customize\Service\Vamos\VamosService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * 違約金請求管理 Service (2系 LC_Page_Admin_Order_Penalty_Fee_Ex + Edit_Ex 移植).
 *
 * dtb_order_penalty_fee テーブルに対する検索・登録・更新・キャンセル。
 *
 * **2系→4系 主要変換:**
 * - dtb_order_penalty_fee.id は 4系も同じ
 * - dtb_order_penalty_fee.del_flg → 4系にもカラム残存 (Entity定義済)
 * - 2系 SC_Helper_Mdl_VAMOS_Ex::requestPenaltyCredit / cancelPenaltyCredit →
 *   `Customize\Service\Vamos\VamosService::requestPenaltyCredit / cancelPenaltyCredit` (Issue #2854)。
 *   ★以前は「VAMOS 新プロバイダ確定待ち」として stub (logger::warning のみ) だったが、
 *   その保留理由は誤り (確定待ちだったのは Eltex=SMS の方で、VAMOS は確定プロバイダ)。
 *   実電文を送らないと切替後に違約金を登録しても請求が発生しない
 * - 2系 $_SESSION["brand_id"] → Symfony Session
 *
 * **検索条件 (2系 buildQuery L195-258 完全踏襲):**
 * - customer_id (=) / 顧客名 LIKE / 電話 = / 住所 (mtb_pref連結) LIKE
 * - 請求金額 From/To / 登録日 From/To / 請求書発行予定日 From/To
 * - status (=)
 * - brand_id スコープ + del_flg=0
 *
 * **ステータス (2系 L56):**
 * 0=請求書発行待ち / 1=請求書発行済み / 2=入金済み / 3=キャンセル / 9=エラー
 */
class OrderPenaltyFeeService
{
    public const STATUS_LABELS = [
        0 => '請求書発行待ち',
        1 => '請求書発行済み',
        2 => '入金済み',
        3 => 'キャンセル',
        9 => 'エラー',
    ];

    /** 2系 ORDER BY: id DESC, customer_id */
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
        private readonly VamosService $vamosService,
    ) {}

    /**
     * 違約金請求一覧 (2系 lfGetSearch + doSearch L267-290 相当).
     *
     * ★型は全て string|null。呼び出し元 OrderPenaltyFeeController::extractFilters() が
     *   `trim((string) $request->request->get($k, ''))` の結果を「空文字なら null」に畳んで渡すため、
     *   数値項目も文字列で到着し、空文字は決して届かない (Issue #2429 で int|null 表記の誤りを訂正)。
     *
     * @param array{
     *   search_customer_id?:string|null, search_customer_name?:string|null,
     *   search_tel?:string|null, search_addr?:string|null,
     *   search_total_from?:string|null, search_total_to?:string|null,
     *   search_create_from?:string|null, search_create_to?:string|null,
     *   search_invoice_from?:string|null, search_invoice_to?:string|null,
     *   search_status?:string|null,
     * } $filters
     *
     * @return array{rows: list<array<string,mixed>>, linemax: int}
     */
    public function search(int $brandId, array $filters, int $pageNo, int $pageMax): array
    {
        $conn = $this->em->getConnection();
        $where = 'dop.del_flg = 0 AND dop.brand_id = :brand_id';
        $params = ['brand_id' => $brandId];

        // 検索条件構築 (2系 buildQuery 完全踏襲)
        if (!empty($filters['search_customer_id'])) {
            $where .= ' AND dop.customer_id = :customer_id';
            $params['customer_id'] = (int) $filters['search_customer_id'];
        }
        if (!empty($filters['search_customer_name'])) {
            // 2系 L206 は dop.name01 を参照していたが、実DB (2系/4系とも) は name カラム。
            // 2系では常に空ヒットしていた既知バグの修正として、4系では正しい dop.name で検索する。
            $where .= " AND replace(replace(dop.name, '　', ' '), ' ', '') LIKE :customer_name";
            $params['customer_name'] = '%' . preg_replace('/[ 　]/u', '', $filters['search_customer_name']) . '%';
        }
        if (!empty($filters['search_tel'])) {
            $where .= ' AND dop.tel = :tel';
            $params['tel'] = str_replace('-', '', $filters['search_tel']);
        }
        if (!empty($filters['search_addr'])) {
            $where .= " AND replace(replace(concat(mp.name, dop.addr01, dop.addr02), '　', ' '), ' ', '') LIKE :addr";
            $params['addr'] = '%' . preg_replace('/[ 　]/u', '', $filters['search_addr']) . '%';
        }
        if (!empty($filters['search_total_from'])) {
            $where .= ' AND dop.payment_total >= :total_from';
            $params['total_from'] = (int) $filters['search_total_from'];
        }
        if (!empty($filters['search_total_to'])) {
            $where .= ' AND dop.payment_total <= :total_to';
            $params['total_to'] = (int) $filters['search_total_to'];
        }
        if (!empty($filters['search_create_from'])) {
            $where .= ' AND dop.create_date >= :create_from';
            $params['create_from'] = date('Y-m-d 00:00:00', strtotime((string) $filters['search_create_from']));
        }
        if (!empty($filters['search_create_to'])) {
            $where .= ' AND dop.create_date <= :create_to';
            $params['create_to'] = date('Y-m-d 23:59:59', strtotime((string) $filters['search_create_to']));
        }
        if (!empty($filters['search_invoice_from'])) {
            $where .= ' AND dop.invoice_date >= :invoice_from';
            $params['invoice_from'] = date('Y-m-d 00:00:00', strtotime((string) $filters['search_invoice_from']));
        }
        if (!empty($filters['search_invoice_to'])) {
            $where .= ' AND dop.invoice_date <= :invoice_to';
            $params['invoice_to'] = date('Y-m-d 23:59:59', strtotime((string) $filters['search_invoice_to']));
        }
        // ★ここだけ他フィルタの !empty() ではなく isset() を使うのは意図的。
        //   status = 0 は「請求書発行待ち」という**有効な検索値**なので !empty() では落ちる。
        if (isset($filters['search_status']) && $filters['search_status'] !== '') {
            $where .= ' AND dop.status = :status';
            $params['status'] = (int) $filters['search_status'];
        }

        $fromJoin = 'dtb_order_penalty_fee dop INNER JOIN mtb_pref mp ON dop.pref = mp.id';

        $linemax = (int) $conn->fetchOne(
            "SELECT COUNT(*) FROM {$fromJoin} WHERE {$where}",
            $params,
        );

        if ($linemax === 0) {
            return ['rows' => [], 'linemax' => 0];
        }

        // 2系 LC_Page_Admin_Order_Penalty_Fee_Ex::doSearch (L281): LIMIT/OFFSET 無しで全件取得
        // (setLimitOffset は L287 でコメントアウト済み、pager表示はあるがSQL上は全件返す挙動)
        $rows = $conn->fetchAllAssociative(
            "SELECT dop.*, mp.name AS pref_name FROM {$fromJoin} "
            . "WHERE {$where} ORDER BY dop.id DESC, dop.customer_id",
            $params,
        );

        return ['rows' => $rows, 'linemax' => $linemax];
    }

    /**
     * 指定IDの違約金請求情報を取得 (2系 getPenaltyData L293-302 相当).
     *
     * @return array<string,mixed>|null
     */
    public function find(int $id): ?array
    {
        $row = $this->em->getConnection()->fetchAssociative(
            "SELECT id, customer_id, name, tel, email, zip, pref, addr01, addr02, "
            . "payment_total, status, comment, "
            . "to_char(invoice_date, 'YYYY-MM-DD') AS invoice_date, "
            . "to_char(create_date,  'YYYY-MM-DD') AS create_date, "
            . "penalty_order_id, authori_error, penalty_vamos_id "
            . "FROM dtb_order_penalty_fee WHERE id = :id",
            ['id' => $id],
        );
        return $row === false ? null : $row;
    }

    /**
     * 違約金請求情報を登録 / 更新 (2系 lfRegister L227-280 相当).
     *
     * @param array<string,mixed> $data
     * @return int 登録/更新した ID
     */
    public function save(array $data, int $brandId): int
    {
        $conn = $this->em->getConnection();

        $payload = [
            'customer_id'   => (int) ($data['customer_id'] ?? 0),
            'name'          => (string) ($data['name'] ?? ''),
            'tel'           => (string) ($data['tel'] ?? ''),
            'email'         => (string) ($data['email'] ?? ''),
            'zip'           => (string) ($data['zip'] ?? ''),
            'pref'          => (int) ($data['pref'] ?? 0),
            'addr01'        => (string) ($data['addr01'] ?? ''),
            'addr02'        => (string) ($data['addr02'] ?? ''),
            'payment_total' => (int) str_replace(',', '', (string) ($data['payment_total'] ?? '0')),
            'invoice_date'  => (string) ($data['invoice_date'] ?? ''),
            'comment'       => (string) ($data['comment'] ?? ''),
            'brand_id'      => $brandId,
        ];

        $conn->beginTransaction();
        try {
            $id = (int) ($data['id'] ?? 0);
            if ($id === 0) {
                // 新規登録 (2系 L252-261)
                $id = (int) $conn->fetchOne("SELECT nextval('dtb_order_penalty_fee_id_seq')");
                $payload['id']               = $id;
                $payload['status']           = 0;  // 請求書発行待ち
                $payload['create_date']      = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
                $payload['update_date']      = $payload['create_date'];
                $payload['penalty_order_id'] = 'PN' . $payload['customer_id'] . $id;
                $payload['del_flg']          = 0;
                $conn->insert('dtb_order_penalty_fee', $payload);
            } else {
                // 更新 (2系 L262-266)
                $payload['update_date'] = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
                $conn->update('dtb_order_penalty_fee', $payload, ['id' => $id]);
            }
            $conn->commit();
        } catch (\Throwable $e) {
            $conn->rollBack();
            throw $e;
        }

        // 2系 lfRegister L269-277: status が空 (=請求書発行待ち) かつ請求書発行予定日が当日なら
        // その場で VAMOS 与信を発行する。★新規登録だけでなく **更新経路でも発火する**
        // (2系もこの if は新規/更新の分岐の外に置かれている)。
        //
        // 2系 L271-273 は `strtotime(date("Y/m/d")) === strtotime($arrList['invoice_date'])` と
        // strtotime 同士で比較しているため、入力が "Y/m/d" でも "Y-m-d" でも当日判定できる。
        // 文字列一致だと "2026/08/28" を取りこぼすので同じく strtotime で比較する。
        if (empty($data['status']) && $this->isToday($payload['invoice_date'])) {
            // ★実電文 (POST /transactions_ec + POST /shipments) を発行する (Issue #2854)。
            // 与信結果は VamosService 側が dtb_order_penalty_fee の
            // penalty_vamos_id / shipping_num / authori_error / status に書く。
            // 2系 lfRegister も戻り値 $ret を action() で使っていない (常に完了画面へ遷移する)
            // ため、ここでも失敗時に例外を投げず ログのみ残す。
            $ok = $this->vamosService->requestPenaltyCredit($id);
            if (!$ok) {
                $this->logger->warning('[OrderPenaltyFee] VAMOS 与信即時発行に失敗', ['id' => $id]);
            }
        }

        return $id;
    }

    /**
     * 2系 lfRegister L271-273 の当日判定 (`strtotime(date("Y/m/d")) === strtotime($target)`).
     */
    private function isToday(string $invoiceDate): bool
    {
        if ($invoiceDate === '') {
            return false;
        }
        $target = strtotime($invoiceDate);

        return $target !== false && $target === strtotime(date('Y/m/d'));
    }

    /**
     * 違約金請求キャンセル (2系 lfPenaltyFeeCancel L285-288 = `cancelPenaltyCredit($id)` のみ).
     *
     * ★取消電文 (PATCH /cancel) を発行する (Issue #2854)。status=3 への UPDATE は
     * `VamosService::cancelPenaltyCredit()` 側が行う (2系も同じく VAMOS ヘルパ内で更新する)。
     * penalty_vamos_id が空 (=VAMOS 未登録) のときは電文を送らず status=3 だけ更新される。
     */
    public function cancel(int $id): void
    {
        $this->vamosService->cancelPenaltyCredit($id);
    }
}
