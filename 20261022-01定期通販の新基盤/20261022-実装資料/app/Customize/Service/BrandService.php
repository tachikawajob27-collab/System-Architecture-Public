<?php

namespace Customize\Service;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;

/**
 * 管理画面：ブランド設定 (2系 SC_Helper_Brand 移植).
 * dtb_brand は単一PK (id) + 論理削除 (del_flg).
 * 新規時は dtb_member_brand_mapping にシステム管理者 (authority=0) の権限を自動付与.
 */
class BrandService
{
    /**
     * 2系 BrandInput の lfInitParam 'edit' モードで扱う 23カラム + member_id 連動.
     */
    public const EDITABLE_COLUMNS = [
        'name', 'zip', 'pref', 'addr01', 'addr02', 'tel', 'email',
        'lp_path', 'url', 'tokusyo_url', 'privacy_url', 'kiyaku_url', 'mypage_url',
        'default_category_id', 'cancel_ng_date', 'free_rule',
        'kaiyaku_ass', 'deliverynote_qr_url', 'ass_tel',
        'asp_prefix', 'media_prefix', 'baitai_prefix', 'code_prefix',
    ];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {}

    private function conn(): Connection
    {
        return $this->entityManager->getConnection();
    }

    /**
     * 一覧 (2系 getList: ORDER BY id DESC, del_flg=0).
     * @return array<int, array<string, mixed>>
     */
    public function getList(): array
    {
        return $this->conn()->fetchAllAssociative(
            'SELECT * FROM dtb_brand WHERE del_flg = 0 ORDER BY id DESC',
        );
    }

    /**
     * 1件取得 (2系 get).
     */
    public function get(int $id): ?array
    {
        $row = $this->conn()->fetchAssociative(
            'SELECT * FROM dtb_brand WHERE id = :id AND del_flg = 0',
            ['id' => $id],
        );
        return $row === false ? null : $row;
    }

    /**
     * 編集ブランドのカテゴリ一覧 (2系 getBrandCategoryList: is_realtime=1).
     * @return array<int, string>
     */
    public function getCategoryList(int $brandId): array
    {
        if ($brandId <= 0) {
            return [];
        }
        // 2系 dtb_category.category_id → 4系 dtb_category.id (PK リネーム済、category_id列は存在しない)
        $rows = $this->conn()->fetchAllAssociative(
            'SELECT id, category_name FROM dtb_category'
            . ' WHERE brand_id = :brand_id AND is_realtime = 1'
            . ' ORDER BY id',
            ['brand_id' => $brandId],
        );
        $list = [];
        foreach ($rows as $row) {
            $list[(int) $row['id']] = (string) $row['category_name'];
        }
        return $list;
    }

    /**
     * 都道府県マスタ (mtb_pref はコア).
     * @return array<int, string>
     */
    public function getPrefList(): array
    {
        $rows = $this->conn()->fetchAllAssociative(
            'SELECT id, name FROM mtb_pref ORDER BY sort_no',
        );
        $list = [];
        foreach ($rows as $row) {
            $list[(int) $row['id']] = (string) $row['name'];
        }
        return $list;
    }

    /**
     * 登録/更新 (2系 SC_Helper_Brand::save).
     * 新規時はシステム管理者 (authority=0) に dtb_member_brand_mapping を自動付与.
     * @return int 登録/更新した brand id
     */
    public function save(array $data, int $memberId): int
    {
        $conn = $this->conn();
        $conn->beginTransaction();
        try {
            $id = (int) ($data['id'] ?? 0);
            $now = date('Y-m-d H:i:s');

            $vals = [];
            foreach (self::EDITABLE_COLUMNS as $col) {
                $vals[$col] = ($data[$col] ?? '') === '' && in_array($col, ['pref', 'default_category_id', 'cancel_ng_date', 'free_rule'], true)
                    ? null
                    : (string) ($data[$col] ?? '');
            }
            $vals['update_date'] = $now;

            if ($id > 0) {
                // 更新 (creator_id / create_date は触らない)
                $conn->update('dtb_brand', $vals, ['id' => $id]);
            } else {
                // 新規
                $vals['create_date'] = $now;
                $vals['creator_id']  = $memberId;
                $conn->insert('dtb_brand', $vals);
                $id = (int) $conn->lastInsertId('dtb_brand_id_seq');

                // システム管理者 (authority_id=0, work_id=1) に新ブランドへの権限を付与
                // 4系 dtb_member は 2系の del_flg → work_id, authority → authority_id に rename
                $admins = $conn->fetchAllAssociative(
                    'SELECT id FROM dtb_member WHERE work_id = 1 AND authority_id = 0',
                );
                foreach ($admins as $admin) {
                    $conn->insert('dtb_member_brand_mapping', [
                        'member_id'   => (int) $admin['id'],
                        'brand_id'    => $id,
                        'create_date' => $now,
                    ]);
                }
            }
            $conn->commit();
            return $id;
        } catch (\Throwable $e) {
            $conn->rollBack();
            throw $e;
        }
    }

    /**
     * 削除ガードで見る参照元テーブル (テーブル名 => 画面表示ラベル).
     *
     * `brand_id` 列を持つテーブルは 4系 **97件** / 2系 **94件**
     * (2026-08-28 実測、`information_schema.columns` の
     *  `table_schema='public' AND column_name='brand_id'`)。全件を数えるのは現実的でないため、
     * 次の2種類に絞る:
     *
     *  (a) **FK の子** — `pg_constraint` で `confrelid='dtb_brand'::regclass` を引くと
     *      4系は3本 / 2系は1本 (2026-08-28 実測):
     *      | 制約名 | 子テーブル | 4系 | 2系 |
     *      |---|---|---|---|
     *      | `dtb_brand_annotation_dtb_brand_fk` | `dtb_brand_annotation` | ○ | ○ |
     *      | `fk_76c6ae2744f5d008` | `dtb_course` | ○ | - |
     *      | `fk_51aa5f944f5d008` | `dtb_course_bonus_point` | ○ | - |
     *      ★**FK があっても DB は削除を止めない**。本サービスの削除は
     *        `del_flg = 1` の**論理削除**で行を消さないため FK は発火しない。
     *        止まらないからこそアプリ側でガードする必要がある。
     *  (b) **値コピー参照の中心テーブル** — FK は無いが「1件でもあれば運用中のブランド」と
     *      言い切れるもの (Issue #2884 が挙げた4つ)。
     *
     * ★特に `dtb_member_brand_mapping` は {@see save()} が新規ブランド作成時に
     * システム管理者 (authority_id=0) 全員分を自動 INSERT するため、
     * **画面から作ったブランドには必ず1件以上ある**。
     * つまりこのガードにより、2系で一度も成立しなかった「ブランド削除」は
     * 4系でも実質的に到達しない (下記 delete() の docblock 参照)。
     *
     * ここに無い残り 90 テーブルの `brand_id` は値コピー参照のまま残る。
     * 上記7つが全ブランドで真である以上、実データでは漏れは生じない
     * (2026-08-28 実測: 3ブランドとも複数の参照元で真)。
     */
    public const REFERENCE_TABLES = [
        // (b) 値コピー参照の中心テーブル (Issue #2884 記載の4つ)
        'dtb_order' => '受注',
        'dtb_customer' => '会員',
        'dtb_category' => 'カテゴリ',
        'dtb_member_brand_mapping' => '管理者のブランド権限',
        // (a) FK の子 (論理削除なので FK は発火しない = ここで見る必要がある)
        'dtb_course' => '定期コース',
        'dtb_course_bonus_point' => '定期コース継続ボーナス',
        'dtb_brand_annotation' => 'ブランド注釈',
    ];

    /**
     * この brand_id を値で参照している行数 (テーブル名 => 件数).
     *
     * 削除 POST でのみ呼ぶ (エラーメッセージに実件数を出すため)。
     * 一覧画面の削除可否表示は件数ではなく {@see getReferenceExistsMap()} を使う。
     * dtb_order は 4系 dev DB で 1,132,843件 あり、`GROUP BY brand_id` の実測が
     * 302ms (2026-08-28) と一覧描画に載せるには重いため。
     *
     * @return array<string, int> テーブル名 => 件数
     */
    public function countReferences(int $id): array
    {
        $counts = [];
        foreach (array_keys(self::REFERENCE_TABLES) as $table) {
            // テーブル名は上記 const のリテラルのみ (外部入力は :id だけ)。
            $counts[$table] = (int) $this->conn()->fetchOne(
                sprintf('SELECT count(*) FROM %s WHERE brand_id = :id', $table),
                ['id' => $id],
            );
        }

        return $counts;
    }

    /**
     * 一覧に出す全ブランドについて「参照が1件でもあるか」を返す (一覧画面の削除可否表示用).
     *
     * 件数ではなく EXISTS で見るのは上記 countReferences() の docblock のとおり性能上の理由。
     * `dtb_order.brand_id` には index (`idx_dtb_order_brand_id`) があり、
     * 本クエリ全体 (7テーブル分の EXISTS) の実測は **14ms**
     * (2026-08-28、4系 dev DB / dtb_brand 3件。同条件で dtb_order の
     *  `GROUP BY brand_id` は 302ms)。
     *
     * @return array<int, bool> brand id => 参照が1件でもあるか
     */
    public function getReferenceExistsMap(): array
    {
        // テーブル名は上記 const のリテラルのみ。外部入力は SQL に一切載らない。
        $selects = [];
        foreach (array_keys(self::REFERENCE_TABLES) as $i => $table) {
            // boolean ではなく `::int` で 0/1 を返す。
            // ★本スタック (PHP 8.3.31 / pdo_pgsql) は boolean 列を **native bool** で返すことを
            //   実測済み (2026-08-28、`var_dump` で `bool(true)` / `bool(false)`)。
            //   つまり `(bool)` キャストでも今は正しく動く。`::int` は将来/環境差への保険で、
            //   ドライバによっては boolean が `'t'` / `'f'` の**文字列**で返り
            //   `(bool) 'f'` が **true** になる (「参照なし」が「参照あり」に化けて、
            //   参照の無いブランドまで削除できなくなる) ため。
            //   0/1 にしておけば PDO が int を返しても '0'/'1' を返しても同じ判定になる。
            $selects[] = sprintf(
                'EXISTS(SELECT 1 FROM %s r%d WHERE r%d.brand_id = b.id)::int AS t%d',
                $table,
                $i,
                $i,
                $i
            );
        }
        $aliases = array_map(static fn (int $i): string => 't' . $i, array_keys(array_values(self::REFERENCE_TABLES)));

        $exists = [];
        $rows = $this->conn()->fetchAllAssociative(
            'SELECT b.id, ' . implode(', ', $selects) . ' FROM dtb_brand b WHERE b.del_flg = 0',
        );
        foreach ($rows as $row) {
            $hit = false;
            foreach ($aliases as $alias) {
                if ((int) $row[$alias] === 1) {
                    $hit = true;
                    break;
                }
            }
            $exists[(int) $row['id']] = $hit;
        }

        return $exists;
    }

    /**
     * 論理削除 (dtb_brand.del_flg = 1).
     *
     * ★2系 SC_Helper_Brand::delete() (L140-152) は **一度も実行できない死にコード**。
     *   `$objQuery` をメソッド内で一度も代入しないまま
     *   `$objQuery->update('dtb_brand', $sqlval, $where, array($id));` を呼ぶため
     *   PHP 8 では `Call to a member function update() on null` (Error) になる。
     *   `C:/src/cartDev/data/class/` 内に `set_exception_handler` は 0件 (grep 実測) なので
     *   捕捉されず、UPDATE も後続の
     *   `$objQuery->delete("dtb_member_brand_mapping", "brand_id = ?", array($id));` も
     *   一切実行されない。傍証として 2系 `dtb_brand` は3行すべて `del_flg = 0`
     *   (2026-08-28 実測 = 削除実績ゼロ)。
     *
     * ★同型の問題は {@see PostbackTypeService::delete()} で既に検出・対策済み
     *   (2系で成立しなかった破壊的操作が4系で新たに可能になる → countReferences() で拒否)。
     *   Brand も同じ構造なので同じ形に揃える (Issue #2884)。
     *
     * ★2系との意図的な差分2点:
     *   1. **参照ガード**。本メソッドは `del_flg = 1` の**論理削除**で行を消さないため、
     *      `dtb_brand` を親とする FK 3本 (4系。{@see REFERENCE_TABLES} の表参照) は
     *      **発火しない**。加えて `brand_id` 列を持つ97テーブル (2026-08-28 実測) の大半は
     *      FK 無しの値コピー参照。つまり **DB は削除を一切止めてくれない**ので、
     *      {@see countReferences()} を使って**呼び出し側**で拒否する。
     *      ★ガードは {@see \Customize\Controller\Admin\BrandController::delete()} にあり、
     *        本メソッド自体は無防備。現状 `delete()` の呼び出しはその1箇所だけ
     *        (2026-08-28 に `grep -rn 'brandService->delete(' app/` で確認) だが、
     *        **呼び出しを増やすときは同じガードを通すこと**。
     *   2. **`dtb_member_brand_mapping` の物理 DELETE を行わない**。
     *      同テーブルには `del_flg` 相当の列が無く (`id / member_id / brand_id / create_date` の
     *      4列のみ。2026-08-28 に `\d` で実測)、消すと `del_flg` を 0 に戻しても
     *      権限行は復元できない。上記1のガードにより
     *      `dtb_member_brand_mapping` に行があるブランドは削除自体が拒否されるため、
     *      ここで消す対象はそもそも 0件 = 削除しても意味が無い。
     *
     * ※ `getList()` / `get()` は `del_flg = 0` 固定で、一度削除すると一覧にも編集画面にも
     *   出てこない (復旧UIが無い) 点も、削除をガードで実質到達不能にする理由。
     *
     * @return bool 1行更新できたら true (存在しない / 既に削除済みなら false)
     */
    public function delete(int $id): bool
    {
        return $this->conn()->executeStatement(
            'UPDATE dtb_brand SET del_flg = 1, update_date = :now WHERE id = :id AND del_flg = 0',
            ['now' => date('Y-m-d H:i:s'), 'id' => $id],
        ) > 0;
    }
}
