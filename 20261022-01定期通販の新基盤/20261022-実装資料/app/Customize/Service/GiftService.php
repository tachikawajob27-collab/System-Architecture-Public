<?php

namespace Customize\Service;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Knp\Component\Pager\PaginatorInterface;
use Knp\Component\Pager\Pagination\PaginationInterface;

/**
 * おまけオファー (2系 LC_Page_Admin_Gift_Ex / LC_Page_Admin_Gift_Edit_Ex 移植の管理CRUD +
 * LP入口/タグ配信用の判定 findActiveOffer()、Issue #1862).
 */
class GiftService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly PaginatorInterface $paginator,
    ) {}

    private function conn(): Connection
    {
        return $this->entityManager->getConnection();
    }

    /**
     * ページング付き検索。2系の action()/search case 相当。
     * ソート: apply_type ASC, start_date DESC, end_date ASC, id ASC
     */
    public function getPagination(array $searchData, int $brandId, int $page, int $pageMax): PaginationInterface
    {
        $sql = <<<SQL
            SELECT dcsg.*, dp.name AS product_name
            FROM dtb_chat_scenario_gift dcsg
            INNER JOIN dtb_product dp ON dcsg.gift_id = dp.id
            WHERE dcsg.dlt_flg = 0 AND dcsg.brand_id = :brand_id
        SQL;
        $params = ['brand_id' => $brandId];

        if (!empty($searchData['search_offer_name'])) {
            $sql .= ' AND dcsg.offer_name LIKE :offer_name';
            $params['offer_name'] = '%' . $searchData['search_offer_name'] . '%';
        }
        if (!empty($searchData['search_product_name'])) {
            $sql .= ' AND dp.name LIKE :product_name';
            $params['product_name'] = '%' . $searchData['search_product_name'] . '%';
        }
        if (!empty($searchData['search_category_id'])) {
            $sql .= ' AND dcsg.category_id = :category_id';
            $params['category_id'] = (int) $searchData['search_category_id'];
        }
        if (isset($searchData['search_apply_type']) && $searchData['search_apply_type'] !== '') {
            $sql .= ' AND dcsg.apply_type = :apply_type';
            $params['apply_type'] = (int) $searchData['search_apply_type'];
        }
        if (!empty($searchData['search_is_omake'])) {
            $today = date('Y-m-d');
            $sql .= ' AND dcsg.start_date <= :today_s AND (dcsg.end_date IS NULL OR dcsg.end_date >= :today_e)';
            $params['today_s'] = $today;
            $params['today_e'] = $today;
        }

        $sql .= ' ORDER BY dcsg.apply_type ASC, dcsg.start_date DESC, dcsg.end_date ASC, dcsg.id ASC';

        $stmt = $this->conn()->executeQuery($sql, $params);
        $rows = $stmt->fetchAllAssociative();

        return $this->paginator->paginate($rows, $page, $pageMax);
    }

    /**
     * 1件取得（編集・複製用）。brand_id スコープ。
     */
    public function findById(int $id, int $brandId): ?array
    {
        $row = $this->conn()->fetchAssociative(
            'SELECT * FROM dtb_chat_scenario_gift WHERE id = :id AND brand_id = :brand_id AND dlt_flg = 0',
            ['id' => $id, 'brand_id' => $brandId],
        );
        return $row ?: null;
    }

    /**
     * ASP・媒体 mapping 取得 (2系 lfGetAspBaitaiData 相当).
     */
    public function getAspBaitaiMapping(int $id): array
    {
        $rows = $this->conn()->fetchAllAssociative(
            'SELECT asp_id, baitai_id FROM dtb_chat_scenario_gift_mapping WHERE offer_id = :id ORDER BY asp_id, baitai_id',
            ['id' => $id],
        );
        return [
            'asp_ids'    => array_column($rows, 'asp_id'),
            'baitai_ids' => array_column($rows, 'baitai_id'),
        ];
    }

    /**
     * カテゴリID=14 のプレゼント商品一覧 (2系 getExchangeProductList 相当).
     * key=product_id, value="product_id - name"
     */
    public function getPresentProducts(int $brandId): array
    {
        $rows = $this->conn()->fetchAllAssociative(
            'SELECT id AS product_id, name FROM dtb_product WHERE brand_id = :brand_id AND category_id = 14 ORDER BY id DESC',
            ['brand_id' => $brandId],
        );
        $result = [];
        foreach ($rows as $row) {
            $result[$row['product_id']] = $row['product_id'] . ' - ' . $row['name'];
        }
        return $result;
    }

    /**
     * LP入口/タグ配信用: 本日有効なおまけオファーを1件取得
     * (2系 request_tag.php L61-91 / LC_Page_Store::getGiftSetting L937-1006 の2段クエリ移植、Issue #1862)。
     *
     * 2系は同一のSQL2本が request_tag.php と LC_Page_Store の2箇所に重複していた
     * (WHERE句・ORDER BY・バインド順まで文字列レベルで同一)。4系ではここに1本化する。
     * 用途の違いは戻り値の使い方だけ:
     *   - request_tag: 該当有無を is_gift(0/1) に潰して LP の訴求画像切替に使う
     *   - LC_Page_Store: offer_id/gift_id/quantity/discount を session omake_setting に入れて実付与に使う
     *     (4系では StoreEntryService::resolveOmakeSetting() が本メソッドを呼び session を確立する、
     *      Issue #1809。その session を読んで受注明細へおまけ商品行を INSERT するのは
     *      StoreConfirmService::insertOmakeOrderItem()、Issue #1928)
     *
     * ①個別媒体 (dcsgm.baitai_id = 引数) → 0件なら ②ALL媒体 (dcsgm.baitai_id = 0) の順。
     * ★`ORDER BY dcsg.end_date` は必ず付ける (2系どおり。同一期間で複数オファーが同時に有効に
     *   なりうるため、先頭行の選択が変わる)。
     * ★start_date/end_date は date 型 (2系・4系とも)。$today は 'Y-m-d' 文字列で渡す。
     *
     * ★★同じ判定条件が OutputCsvService::getResultOrderRows() の $offerSubSql にも SQL で存在する
     * (分析用CSV「成果注文データ」の「おまけ有無」「おまけ商品名」列の導出、Issue #1900)。
     * あちらは基準日が「本日」ではなく「受注日」で、110k件規模の受注を1本のSQLで処理するため
     * 本メソッドを呼べず、条件が二重管理になっている。ここを変更したらあちらも必ず直すこと。
     *
     * @param string|null $today 判定日 (null なら本日 'Y-m-d')
     * @return array<string, mixed>|null 該当オファー行 (id / gift_id / quantity / discount 等)
     */
    public function findActiveOffer(int $categoryId, int $brandId, int $aspId, int $baitaiId, ?string $today = null): ?array
    {
        $today ??= date('Y-m-d');

        $sql = <<<SQL
            SELECT dcsg.*
            FROM dtb_chat_scenario_gift dcsg
            INNER JOIN dtb_chat_scenario_gift_mapping dcsgm ON dcsg.id = dcsgm.offer_id
            WHERE dcsg.dlt_flg = 0 AND dcsg.apply_type = 0
              AND dcsg.category_id = :category_id AND dcsg.brand_id = :brand_id
              AND dcsg.start_date <= :today AND (dcsg.end_date IS NULL OR dcsg.end_date >= :today)
              AND dcsgm.asp_id = :asp_id AND dcsgm.baitai_id = :baitai_id
            ORDER BY dcsg.end_date
        SQL;

        $params = [
            'category_id' => $categoryId,
            'brand_id' => $brandId,
            'today' => $today,
            'asp_id' => $aspId,
            'baitai_id' => $baitaiId,
        ];

        // ①個別媒体
        $row = $this->conn()->fetchAssociative($sql, $params);

        // ②個別設定がない場合はALL媒体設定を確認
        if ($row === false) {
            $row = $this->conn()->fetchAssociative($sql, ['baitai_id' => 0] + $params);
        }

        return $row === false ? null : $row;
    }

    /**
     * 登録・更新 (2系 lfRegister 相当).
     * フォーム名 → DB カラムマッピング: product_id→gift_id, product_count→quantity, discount_price→discount(JSON)
     */
    public function save(array $data, int $brandId): int
    {
        $conn = $this->conn();
        $conn->beginTransaction();

        try {
            $discount = null;
            if (isset($data['discount_price']) && $data['discount_price'] !== '') {
                $discount = json_encode([(int) $data['discount_price']]);
            }

            $vals = [
                'offer_name'      => $data['offer_name'] ?? null,
                'parameter_key'   => $data['parameter_key'] ?? null,
                'parameter_value' => $data['parameter_value'] ?? null,
                'category_id'     => isset($data['category_id']) && $data['category_id'] !== '' ? (int) $data['category_id'] : null,
                'gift_id'         => isset($data['product_id']) && $data['product_id'] !== '' ? (int) $data['product_id'] : null,
                'quantity'        => isset($data['product_count']) && $data['product_count'] !== '' ? (int) $data['product_count'] : 1,
                'lp_change_class' => $data['lp_change_class'] ?? null,
                'lp_change_url'   => $data['lp_change_url'] ?? null,
                'discount'        => $discount,
                'start_date'      => $data['start_date'] ?? null,
                'end_date'        => ($data['end_date'] ?? '') !== '' ? $data['end_date'] : null,
                'apply_type'      => isset($data['apply_type']) ? (int) $data['apply_type'] : 0,
                'brand_id'        => $brandId,
                'dlt_flg'         => 0,
                'updated_at'      => date('Y-m-d H:i:s'),
            ];

            $editId = isset($data['id']) && $data['id'] !== '' ? (int) $data['id'] : null;

            if ($editId === null) {
                $vals['created_at'] = date('Y-m-d H:i:s');
                $conn->insert('dtb_chat_scenario_gift', $vals);
                $id = (int) $conn->lastInsertId('dtb_chat_scenario_gift_id_seq');
            } else {
                $conn->update('dtb_chat_scenario_gift', $vals, ['id' => $editId]);
                $id = $editId;
                $conn->executeStatement('DELETE FROM dtb_chat_scenario_gift_mapping WHERE offer_id = :id', ['id' => $id]);
            }

            $aspIds    = $this->decodeJsonIds($data['asp_ids'] ?? '');
            $baitaiIds = $this->decodeJsonIds($data['baitai_ids'] ?? '');
            $count = max(count($aspIds), count($baitaiIds));
            for ($i = 0; $i < $count; $i++) {
                $aspId    = $aspIds[$i]    ?? '';
                $baitaiId = $baitaiIds[$i] ?? '';
                // 2系 lfInitFormParam L217 `if (empty($arrPost['asp_ids'][$i]) && empty($arrPost['baitai_ids'][$i]))`
                // で array_splice される行と同じ条件で捨てる。0=ALL を選べるようにした結果 (Issue #838)、
                // 「ASP=ALL かつ 媒体=ALL」の行が到達しうるようになったため厳密比較では足りない
                // ("0" は `=== ''` に該当しないので (0,0) が INSERT されてしまう)。
                if (empty($aspId) && empty($baitaiId)) {
                    continue;
                }
                $conn->insert('dtb_chat_scenario_gift_mapping', [
                    'offer_id'    => $id,
                    'asp_id'      => (int) $aspId,
                    'baitai_id'   => (int) $baitaiId,
                    'create_date' => date('Y-m-d H:i:s'),
                ]);
            }

            $conn->commit();
            return $id;
        } catch (\Throwable $e) {
            $conn->rollBack();
            throw $e;
        }
    }

    /**
     * 論理削除 (2系 delete case: dlt_flg=1, updated_at=NOW() 相当).
     */
    public function softDelete(int $id, int $brandId): bool
    {
        $affected = $this->conn()->executeStatement(
            'UPDATE dtb_chat_scenario_gift SET dlt_flg = 1, updated_at = :now WHERE id = :id AND brand_id = :brand_id',
            ['now' => date('Y-m-d H:i:s'), 'id' => $id, 'brand_id' => $brandId],
        );
        return $affected > 0;
    }

    private function decodeJsonIds(mixed $value): array
    {
        if (empty($value)) {
            return [];
        }
        $decoded = json_decode((string) $value, true);
        return is_array($decoded) ? $decoded : [];
    }
}
