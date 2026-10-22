<?php

declare(strict_types=1);

namespace Customize\Service\Order;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;

/**
 * 管理画面の受注編集で、明細ごとの商品オプション (香り等 = `dtb_order_detail_option`) を
 * 読み書きする (Issue #2938 / 親 #2818 の2件目)。
 *
 * ## 2系の典拠 (行単位で精読。2026-08-28)
 *
 * `C:/src/cartDev/data/class_extends/page_extends/admin/order/LC_Page_Admin_Order_Edit_Ex.php`
 * `doRegister()` L3444-3502。同リポジトリの作業ツリーは `fix-deploy-filemask-cart` に
 * checkout されているが、`git diff HEAD origin/main` の差分は L3956 の ChatWork 宛先1箇所だけで、
 * 本件の該当行は HEAD と origin/main で同一 (`.bak*` は典拠にしていない)。
 *
 * - **L3448** 明細ループの先頭で `$objQuery->delete("dtb_order_detail_option",
 *   "order_id = ? AND order_detail_id = ?", ...)` — 明細ごとに丸ごと DELETE
 * - **L3456-3468** `SC_Product_Ex::getProductOption($product_id)` の各 option について
 *   `$arrValues["option_id"][$option["option_id"]][$i]` が `!empty()` なら再 INSERT
 *   (`order_id` / `order_detail_id` / `option_id` / `option_detail_id` /
 *   `create_date = CURRENT_TIMESTAMP`)
 * - **L3490-3502** 「残りデータ」(新規追加した明細) ループも同じ INSERT
 * - 明細ごと消えるケース (L3475) は、上の DELETE が先に走っているのでオプションも道連れになる
 *
 * つまり **明細ごとに丸ごと DELETE → POST から再 INSERT**。POST が空なら行は復活しない。
 *
 * 選択肢マスタは `C:/src/cartDev/data/class_extends/SC_Product_Ex.php`:
 *
 * - `getProductOption($product_id)` L84-94 … 保存側。`DISTINCT option_id` を返す
 * - `getProductOptionDetailList()` (`LC_Page_Admin_Order_Edit_Ex` L4267-4281) … 表示側。
 *   ブランド内の全商品ぶんを `[product_id][option_id]['detail'][]` に積む
 *
 * どちらも `dtb_products_option` × `_mapping` × `_disp` の3表 INNER JOIN で、
 * **絞り込みは `dpo.brand_id = $_SESSION['brand_id']` だけ**
 * (`del_flg` / `is_display_*` での絞り込みは持たない → 4系でも足さない)。
 *
 * UI は `C:/src/cartDev/data/Smarty/templates/admin/order/edit.tpl` L3421-3433 の
 * `<select name="option_id[<option_id>][<行番号>]">`。**空 (未選択) の `<option>` は無い**ので
 * 選択肢が1つでも在れば必ず値が POST される。
 *
 * ## 2系 → 4系 の対応 (2026-08-28 に両DBの `information_schema.columns` で実測)
 *
 * | 2系 | 4系 | 備考 |
 * |---|---|---|
 * | `dtb_order_detail.order_detail_id` | **`dtb_order_item.id`** | 移行 transform も `i.id = o.order_detail_id` で突合している |
 * | `dtb_order_detail.product_id` | `dtb_order_item.product_id` | 4系にも同名列が実在 |
 * | `dtb_order_detail_option` の5列 | 同名5列 | 4系は先頭に代理キー `id` (IDENTITY) が増えて6列 |
 * | `dtb_products_option` / `_mapping` / `_disp` | 同名 | 3表とも4系DBに実在 |
 *
 * ★4系 `dtb_order_detail_option` に **`discriminator_type` 列は無い** (実測6列:
 * `id` / `order_id` / `order_detail_id` / `option_id` / `option_detail_id` / `create_date`)。
 * 値の取り違えで行が無音消失する型の事故 ([[feedback_doctrine_discriminator_silent_row_loss]]) は
 * 本テーブルでは起こらない。
 *
 * ## なぜ Entity ではなく DBAL の生SQLなのか
 *
 * 2系がやっているのは「同じ論理キー `(order_id, order_detail_id, option_id)` を
 * **全DELETE → 同一キーで再INSERT**」で、これは Doctrine の識別子管理を壊す既知パターン
 * ([[feedback_doctrine_delete_then_insert_same_pk]])。同一 `flush()` 内で
 * remove → persist すると UnitOfWork が消したはずの識別子を再利用しうる。
 * DBAL で撃てば ORM の識別子マップに一切載らない。
 *
 * ## 金額への副作用 (Issue #2938 の要件3)
 *
 * 本 Service が書き込むテーブルは **`dtb_order_detail_option` だけ**。`dtb_order` /
 * `dtb_order_item` へは1文も書かない (SELECT のみ)。したがって
 * `subtotal` / `charge` / `discount` / `delivery_fee_total` / `total` / `payment_total` は
 * 本 Service では変化しない。コアが明細から金額を再計算して破壊する既知問題
 * (#1513 / PR #1525) は「明細の price / quantity を触ったとき」の話で本経路とは独立。
 * この不変条件は {@see \Eccube\Tests\Customize\Service\OrderDetailOptionEditSqlTest} が
 * SQL 定数を機械検査して固定している。
 */
class OrderDetailOptionEditService
{
    /**
     * 明細ごとの選択肢マスタ (2系 `getProductOptionDetailList()` / `getProductOption()` の
     * 3表 INNER JOIN を、当該受注の明細に絞って引いたもの)。
     *
     * 2系はブランド内の**全商品**ぶんを一度に積んでテンプレートで
     * `$arrProductOption[product_id]` を引くが、描画に使うのは当該受注の明細ぶんだけなので
     * 結果は同じ。`ORDER BY` も2系 (`dpo.option_id, dpo.option_detail_id`) と同じ並び。
     */
    public const SQL_MASTER = <<<'SQL'
        SELECT oi.id AS order_detail_id,
               dpo.option_id,
               dpo.option_name,
               dpo.option_detail_id,
               dpo.option_detail_name
          FROM dtb_order_item oi
         INNER JOIN dtb_products_option_mapping dpom
                 ON dpom.product_id = oi.product_id
         INNER JOIN dtb_products_option dpo
                 ON dpo.option_id = dpom.option_id
         INNER JOIN dtb_products_option_disp dpod
                 ON dpod.product_id = dpom.product_id
                AND dpod.option_id = dpom.option_id
                AND dpod.option_detail_id = dpo.option_detail_id
         WHERE oi.order_id = :order_id
           AND dpo.brand_id = :brand_id
         ORDER BY oi.id, dpo.option_id, dpo.option_detail_id
        SQL;

    /**
     * ブランド内の**全商品**ぶんの選択肢マスタ (2系 `getProductOptionDetailList()` の移植。Issue #2976)。
     *
     * `LC_Page_Admin_Order_Edit_Ex` L4267-4281 の SQL そのまま
     * (`dtb_products_option_mapping` × `dtb_products_option` × `dtb_products_option_disp` の
     * 3表 INNER JOIN、絞り込みは `dpo.brand_id` だけ、`ORDER BY dpom.product_id, dpo.option_id,
     * dpo.option_detail_id`)。2系は L167 でページ描画のたびに無条件で組み立て、
     * `edit.tpl` L3421 が `$arrProductOption[product_id]` で引く。
     *
     * {@see self::SQL_MASTER} との違いは**キーが `dtb_order_item.id` ではなく `product_id`** な点だけ。
     * 受注編集で「商品を追加」した直後の明細行はまだ `dtb_order_item` に存在しない
     * (id が無い) ので、id 起点の {@see self::SQL_MASTER} では原理的に引けない。
     *
     * 4系実測 (2026-08-28): 3表 JOIN で組が成立するのは全ブランド合わせて **17組**
     * (brand 3 = 香り**14商品** / 香り(メンズ)2商品、brand 2 = 染め色1商品)。
     * ★Issue #2976 本文と PR #2972 の「香り15商品」は誤り (15+2+1=18 で合計17組と合わない)。
     * 実測は `SELECT dpo.option_id, count(DISTINCT dpom.product_id) ... WHERE dpo.brand_id=3
     * GROUP BY dpo.option_id` → option_id 2 が 14 / option_id 4 が 2。
     * ブランド内全件を持っても数十行なので、2系どおり毎回まとめて引く。
     */
    public const SQL_MASTER_BY_PRODUCT = <<<'SQL'
        SELECT dpom.product_id,
               dpo.option_id,
               dpo.option_name,
               dpo.option_detail_id,
               dpo.option_detail_name
          FROM dtb_products_option_mapping dpom
         INNER JOIN dtb_products_option dpo
                 ON dpo.option_id = dpom.option_id
         INNER JOIN dtb_products_option_disp dpod
                 ON dpod.product_id = dpom.product_id
                AND dpod.option_id = dpom.option_id
                AND dpod.option_detail_id = dpo.option_detail_id
         WHERE dpo.brand_id = :brand_id
         ORDER BY dpom.product_id, dpo.option_id, dpo.option_detail_id
        SQL;

    /** 現在の選択値 (2系 `SC_Helper_Purchase::getOrderDetailOption()` を受注単位でまとめたもの)。 */
    public const SQL_SELECTED = <<<'SQL'
        SELECT order_detail_id, option_id, option_detail_id
          FROM dtb_order_detail_option
         WHERE order_id = :order_id
        SQL;

    /** 当該受注の明細ID (2系 `select('*', 'dtb_order_detail', 'order_id = ?')` 相当)。 */
    public const SQL_ITEM_IDS = <<<'SQL'
        SELECT id FROM dtb_order_item WHERE order_id = :order_id ORDER BY id
        SQL;

    /** 2系 L3448「商品オプション削除」。明細ごとに丸ごと消す。 */
    public const SQL_DELETE = <<<'SQL'
        DELETE FROM dtb_order_detail_option
         WHERE order_id = :order_id
           AND order_detail_id = :order_detail_id
        SQL;

    /**
     * 消えた明細に取り残されたオプション行の掃除。
     *
     * 2系は明細ループの先頭で**全ての既存明細**に対して DELETE を撃つので、
     * その後 `dtb_order_detail` ごと消される明細のオプションも一緒に消える。4系は
     * `dtb_order_detail_option` に FK が無く、コアの `remove($Item)` はオプション行を
     * 残すため、同じ結果にするにはこの1文が要る。
     */
    public const SQL_DELETE_ORPHAN = <<<'SQL'
        DELETE FROM dtb_order_detail_option
         WHERE order_id = :order_id
           AND order_detail_id NOT IN (:item_ids)
        SQL;

    /** 2系 L3459-3467 / L3493-3501 の INSERT。`create_date` は再INSERTのたびに現在時刻。 */
    public const SQL_INSERT = <<<'SQL'
        INSERT INTO dtb_order_detail_option
                    (order_id, order_detail_id, option_id, option_detail_id, create_date)
             VALUES (:order_id, :order_detail_id, :option_id, :option_detail_id, CURRENT_TIMESTAMP)
        SQL;

    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {}

    /**
     * 受注編集画面に描画する選択肢と現在値を組み立てる。
     *
     * `$brandId` が取れないとき (セッションに `brand_id` が無い) は空を返す。2系は
     * `$_SESSION['brand_id']` を無条件で使うので同じ状況では選択肢0件になるが、
     * 4系は**保存側もここを見て「1件も出さない=何も消さない」に倒す**ため、
     * 空を返すことがそのまま安全側のガードになる ({@see save()} 参照)。
     *
     * @return array<int, array<int, array{option_name: string, selected: int|null, details: array<int, string>}>>
     *         `[order_detail_id][option_id] => ['option_name' =>, 'selected' =>, 'details' => [option_detail_id => name]]`
     */
    public function loadEditableOptions(int $orderId, ?int $brandId, ?Connection $conn = null): array
    {
        if ($orderId <= 0 || $brandId === null || $brandId <= 0) {
            return [];
        }

        $conn ??= $this->em->getConnection();

        $tree = [];
        foreach ($conn->fetchAllAssociative(self::SQL_MASTER, ['order_id' => $orderId, 'brand_id' => $brandId]) as $row) {
            $detailId = (int) $row['order_detail_id'];
            $optionId = (int) $row['option_id'];
            if (!isset($tree[$detailId][$optionId])) {
                $tree[$detailId][$optionId] = [
                    'option_name' => (string) ($row['option_name'] ?? ''),
                    'selected' => null,
                    'details' => [],
                ];
            }
            $tree[$detailId][$optionId]['details'][(int) $row['option_detail_id']]
                = (string) ($row['option_detail_name'] ?? '');
        }

        foreach ($conn->fetchAllAssociative(self::SQL_SELECTED, ['order_id' => $orderId]) as $row) {
            $detailId = (int) $row['order_detail_id'];
            $optionId = (int) $row['option_id'];
            if (isset($tree[$detailId][$optionId])) {
                $tree[$detailId][$optionId]['selected'] = $row['option_detail_id'] === null
                    ? null
                    : (int) $row['option_detail_id'];
            }
        }

        return $tree;
    }

    /**
     * ブランド内の全商品ぶんの選択肢を `[product_id][option_id]` で返す
     * (2系 `getProductOptionDetailList()` = `$arrProductOption` 相当。Issue #2976)。
     *
     * 「商品を追加」した直後の明細行は `dtb_order_item.id` を持たないため
     * {@see loadEditableOptions()} では引けない。その行の `<select>` はこちらを
     * `OrderItem.Product.id` で引いて描く。
     *
     * ★現在値 (`selected`) を含めないのは、新規行には定義上まだ DB の選択が無いから。
     * 2系も `course_product_new` の再描画では `getOrderDetailOption($order_id, $order_detail_id)`
     * を引くが、新規行の `order_detail_id` は空なので何も当たらず、結果として
     * ブラウザ既定の先頭 `<option>` が選ばれた状態になる (`edit.tpl` L3421-3433 は
     * 空の `<option>` を置かない)。4系も同じ見た目・同じ POST になる。
     *
     * @return array<int, array<int, array{option_name: string, details: array<int, string>}>>
     *         `[product_id][option_id] => ['option_name' =>, 'details' => [option_detail_id => name]]`
     */
    public function loadBrandOptionMaster(?int $brandId, ?Connection $conn = null): array
    {
        if ($brandId === null || $brandId <= 0) {
            return [];
        }

        $conn ??= $this->em->getConnection();

        $master = [];
        foreach ($conn->fetchAllAssociative(self::SQL_MASTER_BY_PRODUCT, ['brand_id' => $brandId]) as $row) {
            $productId = (int) $row['product_id'];
            $optionId = (int) $row['option_id'];
            if (!isset($master[$productId][$optionId])) {
                $master[$productId][$optionId] = [
                    'option_name' => (string) ($row['option_name'] ?? ''),
                    'details' => [],
                ];
            }
            $master[$productId][$optionId]['details'][(int) $row['option_detail_id']]
                = (string) ($row['option_detail_name'] ?? '');
        }

        return $master;
    }

    /**
     * 2系 `doRegister()` の「明細ごと DELETE → POST から再 INSERT」を再現する。
     *
     * @param array<int, array<int, int>> $posted `[order_detail_id][option_id] => option_detail_id`
     *                                            ({@see transposePosted()} で転置済みのもの)
     *
     * @return array{deleted: int, inserted: int}
     */
    public function save(int $orderId, ?int $brandId, array $posted, Connection $conn): array
    {
        $result = ['deleted' => 0, 'inserted' => 0];

        if ($orderId <= 0 || $brandId === null || $brandId <= 0) {
            return $result;
        }

        // ★保存側も**描画に使ったのと同じクエリ**で選択肢を引く (呼出元の SAVEPOINT / トランザクションを
        //   使うため `$conn` を明示的に渡す)。ここがズレると「画面に出ていない値を消す」が起きる。
        $master = $this->loadEditableOptions($orderId, $brandId, $conn);

        // 画面に1つも <select> が出ない受注 (オプションを持つ商品を含まない / ブランド不一致) では
        // 何も消さない。2系はこの場合でも DELETE だけ撃って行を消すが、4系では
        // 「画面が提示していない値を黙って消す」ことになるので踏襲しない (差異1。下記★)。
        if ($master === []) {
            return $result;
        }

        /** @var list<int> $itemIds */
        $itemIds = array_map('intval', $conn->fetchFirstColumn(self::SQL_ITEM_IDS, ['order_id' => $orderId]));

        // ★参照先が空のまま NOT IN を撃つと全件成立して全消しになる
        //   ([[feedback_empty_parent_makes_not_exists_true]])。明細0件の受注では何もしない。
        if ($itemIds === []) {
            return $result;
        }

        $result['deleted'] += (int) $conn->executeStatement(
            self::SQL_DELETE_ORPHAN,
            ['order_id' => $orderId, 'item_ids' => $itemIds],
            ['item_ids' => ArrayParameterType::INTEGER],
        );

        foreach ($itemIds as $itemId) {
            // 選択肢を持たない明細 (送料・手数料・ポイント値引き等) には触れない
            if (!isset($master[$itemId])) {
                continue;
            }

            $result['deleted'] += (int) $conn->executeStatement(
                self::SQL_DELETE,
                ['order_id' => $orderId, 'order_detail_id' => $itemId],
            );

            foreach ($master[$itemId] as $optionId => $option) {
                $optionDetailId = $posted[$itemId][$optionId] ?? null;

                // 2系 `if (!empty($option_detail_id))`。0 / '' / null は INSERT しない
                if (empty($optionDetailId)) {
                    continue;
                }

                // ★差異2 (意図的)。2系は POST された値をそのまま INSERT するため、細工した POST で
                //   マスタに無い option_detail_id が入りうる。入ると次の描画でどの <option> にも
                //   一致せず「先頭が選ばれているように見える」ので、画面が提示した選択肢に限る。
                //   正常操作では <select> の値しか来ないので差は出ない。
                if (!isset($option['details'][(int) $optionDetailId])) {
                    continue;
                }

                $result['inserted'] += (int) $conn->executeStatement(self::SQL_INSERT, [
                    'order_id' => $orderId,
                    'order_detail_id' => $itemId,
                    'option_id' => $optionId,
                    'option_detail_id' => (int) $optionDetailId,
                ]);
            }
        }

        return $result;
    }

    /**
     * 描画用ツリーの `selected` を、POST された選択で上書きする (POST 再描画用)。
     *
     * バリデーションエラー等で画面が描き直されるとき、DBの値だけを出すと管理者が
     * 選び直した香りが黙って元へ戻る。2系も再描画系のモードでは POST を優先している
     * (`LC_Page_Admin_Order_Edit_Ex` L2779 `mode=recalculate`)。
     *
     * ★マスタに無い値では上書きしない ({@see save()} の差異2と同じ理由)。
     *
     * @param array<int, array<int, array{option_name: string, selected: int|null, details: array<int, string>}>> $tree
     * @param array<int, array<int, int>>                                                                         $posted
     *
     * @return array<int, array<int, array{option_name: string, selected: int|null, details: array<int, string>}>>
     */
    public static function applyPostedSelection(array $tree, array $posted): array
    {
        foreach ($posted as $detailId => $byOption) {
            foreach ($byOption as $optionId => $optionDetailId) {
                if (isset($tree[$detailId][$optionId]['details'][$optionDetailId])) {
                    $tree[$detailId][$optionId]['selected'] = $optionDetailId;
                }
            }
        }

        return $tree;
    }

    /**
     * POST の `option_id[<option_id>][<order_detail_id>]` を `[order_detail_id][option_id]` へ転置する。
     *
     * ★差異3 (意図的)。2系の内側キーは**明細の行番号 (0 起点)** だが、4系は
     * `dtb_order_item.id` (= 2系 `order_detail_id`) にしている。4系の明細は Symfony の
     * CollectionType で、行の追加・削除がクライアント側 JS で起きるため行番号と保存後の
     * 並びが一致する保証が無い。IDキーなら並び替えや途中削除があっても取り違えない。
     * 外側キー (`option_id`) と POST 名 `option_id[...]` は2系のまま。
     *
     * @param array<mixed> $raw
     *
     * @return array<int, array<int, int>>
     */
    public static function transposePosted(array $raw): array
    {
        $posted = [];
        foreach ($raw as $optionId => $byDetail) {
            if (!is_array($byDetail) || (int) $optionId <= 0) {
                continue;
            }
            foreach ($byDetail as $detailId => $value) {
                if ((int) $detailId <= 0 || !is_scalar($value) || (string) $value === '') {
                    continue;
                }
                $posted[(int) $detailId][(int) $optionId] = (int) $value;
            }
        }

        return $posted;
    }

    /**
     * 新規明細行の POST `option_id_new[<option_id>][<行キー>]` を
     * `[行キー][option_id]` へ転置する (Issue #2976)。
     *
     * ★{@see transposePosted()} と分けている理由 (共通化しない):
     *
     * - 内側キーの**意味が違う**。あちらは `dtb_order_item.id`、こちらは Symfony
     *   CollectionType の行キー (`form.OrderItems` の子フォーム名)。
     * - したがって**下限が違う**。id は 1 以上だが、行キーは **0 が正当**
     *   (`order[OrderItems][0]` は先頭行)。`(int) $key <= 0` で捨てる
     *   {@see transposePosted()} の判定をそのまま使うと先頭行の選択が黙って消える。
     *
     * 0 を通す代わりに、キーが**10進数字だけ**であることを明示的に確かめる
     * (`(int) 'abc' === 0` を行キー 0 と誤認しないため)。
     *
     * 2系はこの区別を持たない。`edit.tpl` L3421 の内側キーは既存行も新規行も
     * `$product_index` (行番号) 一本で、`doRegister()` L3456 / L3492 が
     * 同じ `$i` で拾っている。4系は既存行を id キーにしている (PR #2972 の差異3) ので、
     * 行キーが要るのは新規行だけになる。
     *
     * @param array<mixed> $raw
     *
     * @return array<int, array<int, int>> `[行キー][option_id] => option_detail_id`
     */
    public static function transposePostedNewRows(array $raw): array
    {
        $posted = [];
        foreach ($raw as $optionId => $byRow) {
            if (!is_array($byRow) || (int) $optionId <= 0) {
                continue;
            }
            foreach ($byRow as $rowKey => $value) {
                if (!ctype_digit((string) $rowKey) || !is_scalar($value) || (string) $value === '') {
                    continue;
                }
                $posted[(int) $rowKey][(int) $optionId] = (int) $value;
            }
        }

        return $posted;
    }

    /**
     * 新規明細行ぶんの選択を、採番済みの `dtb_order_item.id` キーへ移して既存ぶんに合流させる
     * (Issue #2976)。
     *
     * `$rowKeyToItemId` は保存直後 (`ADMIN_ORDER_EDIT_INDEX_COMPLETE`) のフォームから作る
     * 「行キー → 採番済み明細ID」。詳細は
     * {@see \Customize\EventSubscriber\Admin\Order\OrderDetailOptionEditSubscriber} を参照。
     *
     * ★合流は「既存 (`option_id`) が勝つ」。`option_id_new` は
     * **`OrderItem.id` が無い行にしか描かない**ので正常操作で衝突は起きず、
     * 衝突しうるのは細工した POST だけ。既存側を優先しておけば
     * `option_id_new` が無い POST では {@see save()} への入力が PR #2972 と1バイトも変わらない。
     *
     * @param array<int, array<int, int>> $posted         `[order_detail_id][option_id] => option_detail_id`
     * @param array<int, array<int, int>> $postedNewRows  `[行キー][option_id] => option_detail_id`
     * @param array<int, int>             $rowKeyToItemId `[行キー] => dtb_order_item.id`
     *
     * @return array<int, array<int, int>>
     */
    public static function mergeNewRowSelection(array $posted, array $postedNewRows, array $rowKeyToItemId): array
    {
        foreach ($postedNewRows as $rowKey => $byOption) {
            $itemId = $rowKeyToItemId[$rowKey] ?? null;
            if ($itemId === null || $itemId <= 0) {
                continue;
            }
            foreach ($byOption as $optionId => $optionDetailId) {
                if (!isset($posted[$itemId][$optionId])) {
                    $posted[$itemId][$optionId] = $optionDetailId;
                }
            }
        }

        return $posted;
    }
}
