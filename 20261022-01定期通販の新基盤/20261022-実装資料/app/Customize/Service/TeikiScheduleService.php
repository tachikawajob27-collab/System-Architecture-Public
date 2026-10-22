<?php

namespace Customize\Service;

use Doctrine\DBAL\Connection;

/**
 * 定期 (`dtb_teiki`) の「次回お届け日変更」「休止」を **2系のバッチ実行時の挙動そのまま** で行う。
 *
 * ------------------------------------------------------------------------------------------
 * ★移植元の特定 (2系には同名メソッドが4本ある)
 * ------------------------------------------------------------------------------------------
 * 本 Service が踏襲するのは **`SC_Helper_Teiki_Ex::sfRestartTeiki($row, $next_shipping_date)`**
 * (cart 本番 `data/class_extends/helper_extends/SC_Helper_Teiki_Ex.php:578-601`) の1本だけ。
 * 呼び出し元から次のとおり辿って確定した:
 *
 *   `data/script/execute_batch_shipping_mainte.php:21`  `$objHelperTeiki = new SC_Helper_Teiki_Ex($brand_id);`
 *   `data/script/execute_batch_shipping_mainte.php:96`  `$objHelperTeiki->sfRestartTeiki($row, $upd_next_shipping_date);`
 *   `data/script/execute_batch_shipping_mainte.php:98`  `$objHelperTeiki->sfPeddingTeiki($row);`
 *
 * 同名だが**別物**なので混同しないこと:
 * - `SC_Helper_Teiki::sfRestartTeiki($teiki_id, $next_shipping_date)` (cart `class/helper/SC_Helper_Teiki.php:331`)
 *   … note 文言が「次回配送予定日を…に設定」、UPDATE は `teiki_id` 1本のみ。`_Ex` に上書きされ未使用。
 * - `SC_Helper_Teiki_Renew::sfRestartTeiki($teiki_id, $teiki_detail_id, $next_shipping_date)`
 *   (shop `class/helper/SC_Helper_Teiki_Renew.php:752`) … マイページ経路。操作者ラベルが `：MYPAGE`
 *   になり `brand_id` を WHERE に含めない。4系では `PaymentChangeOrderUpdater::restartTeikiDetail()`
 *   が別途これを移植済みで、**本 Service とは統合できない**。
 * - `SC_Helper_Teiki_Ex.php.bak*` 内の同名メソッド … 退避ファイルであり実行されない。
 *
 * ------------------------------------------------------------------------------------------
 * ★2系の非対称な UPDATE をそのまま踏襲する
 * ------------------------------------------------------------------------------------------
 * - `note` は `teiki_id` + `brand_id` 一致の **全明細** へ書く (`detail_id` で絞らない)
 * - `status` / `next_shipping_date` は `teiki_id` + `detail_id` + `brand_id` かつ `status <> 3`
 *
 * 操作者ラベルは 2系 `SC_Helper_Teiki::sfGetSupportName()`
 * (cart `class/helper/SC_Helper_Teiki.php:847-860`) に由来する。CLI バッチ実行時は
 * `REQUEST_URI` に `mypage` を含まず `$_SESSION['login_name']` も無いため **常に `：SYSTEM`**。
 */
class TeikiScheduleService
{
    /** 定期ステータス: 通常 (2系 dtb_teiki.status = 1) */
    public const TEIKI_STATUS_ACTIVE = 1;

    /** 定期ステータス: 休止 (2系 dtb_teiki.status = 2) */
    public const TEIKI_STATUS_PAUSED = 2;

    /** 定期ステータス: 解約 (2系 dtb_teiki.status = 3) */
    public const TEIKI_STATUS_CANCELLED = 3;

    /** 2系が PHP・PostgreSQL ともに稼働していたタイムゾーン */
    private const APP_TIMEZONE = 'Asia/Tokyo';

    /** 2系 sfGetSupportName() の CLI バッチ実行時の戻り値 */
    private const OPERATOR_LABEL = '：SYSTEM';

    /**
     * 2系 `SC_Helper_Teiki_Ex::sfRestartTeiki` (L578-601) 相当。
     *
     * note 行の書式: `Y/m/d H:i :商品ID：{product_id}の次回お届け日を{date}に設定：SYSTEM`
     *
     * @param array<string, mixed> $teikiRow `teiki_id` / `detail_id` / `product_id` / `note` を含む行
     */
    public function restartTeiki(Connection $conn, array $teikiRow, string $nextShippingDate, int $brandId): void
    {
        // 2系 L579-581: 次回お届け日が空なら何もしない
        if ($nextShippingDate === '') {
            return;
        }

        // 2系 L586-592
        $note = $this->appendNote(
            $teikiRow['note'] !== null ? (string) $teikiRow['note'] : '',
            sprintf('商品ID：%sの次回お届け日を%sに設定', (string) $teikiRow['product_id'], $nextShippingDate),
        );

        // 2系 L596: note は teiki_id + brand_id の全明細へ
        $this->updateNote($conn, $note, $teikiRow['teiki_id'], $brandId);

        // 2系 L597: status / next_shipping_date は detail_id 単位 + status <> 3
        $conn->executeStatement(
            <<<'SQL'
            UPDATE dtb_teiki SET status = :st, next_shipping_date = :date, update_date = CURRENT_TIMESTAMP
            WHERE teiki_id = :tid AND detail_id = :did AND brand_id = :bid AND status <> :cancelled
            SQL,
            [
                'st'        => self::TEIKI_STATUS_ACTIVE,
                'date'      => $nextShippingDate,
                'tid'       => $teikiRow['teiki_id'],
                'did'       => $teikiRow['detail_id'],
                'bid'       => $brandId,
                'cancelled' => self::TEIKI_STATUS_CANCELLED,
            ],
        );
    }

    /**
     * 2系 `SC_Helper_Teiki_Ex::sfPeddingTeiki` (L115-135) 相当。
     *
     * note 行の書式: `Y/m/d H:i :[休止]商品ID：{product_id}を休止に変更。：SYSTEM`
     *
     * ★引数 `$teikiRow` の note は `restartTeiki()` 実行**前**の値なので、直前に書いた
     *   「次回お届け日を…に設定」行を上書きして消す。2系の挙動をそのまま踏襲している。
     * ★status 側に `status <> 3` ガードは無い (2系 L131 の WHERE に条件が無いため)。
     *
     * @param array<string, mixed> $teikiRow
     */
    public function peddingTeiki(Connection $conn, array $teikiRow, int $brandId): void
    {
        $note = $this->appendNote(
            $teikiRow['note'] !== null ? (string) $teikiRow['note'] : '',
            sprintf('[休止]商品ID：%sを休止に変更。', (string) $teikiRow['product_id']),
        );

        $this->updateNote($conn, $note, $teikiRow['teiki_id'], $brandId);

        $conn->executeStatement(
            <<<'SQL'
            UPDATE dtb_teiki SET status = :st, update_date = CURRENT_TIMESTAMP
            WHERE teiki_id = :tid AND detail_id = :did AND brand_id = :bid
            SQL,
            [
                'st'  => self::TEIKI_STATUS_PAUSED,
                'tid' => $teikiRow['teiki_id'],
                'did' => $teikiRow['detail_id'],
                'bid' => $brandId,
            ],
        );
    }

    /**
     * 2系の note 追記書式を再現する (2系 L586-592)。
     *
     * 既存 note が非空なら改行を足し、`date('Y/m/d H:i ') . ':' . $comment . '：SYSTEM' . 改行` を追記する。
     *
     * @param \DateTimeImmutable|null $now テスト用。省略時は現在時刻 (Asia/Tokyo)
     */
    public function appendNote(string $note, string $comment, ?\DateTimeImmutable $now = null): string
    {
        if ($note !== '') {
            $note .= PHP_EOL;
        }

        $now ??= new \DateTimeImmutable('now', new \DateTimeZone(self::APP_TIMEZONE));

        return $note . $now->format('Y/m/d H:i ') . ':' . $comment . self::OPERATOR_LABEL . PHP_EOL;
    }

    /**
     * 2系 L596 (sfRestartTeiki) / L130 (sfPeddingTeiki): note だけは `teiki_id` + `brand_id` 一致の
     * 全明細へ書く。両者は完全に同一の UPDATE。
     */
    private function updateNote(Connection $conn, string $note, mixed $teikiId, int $brandId): void
    {
        $conn->executeStatement(
            'UPDATE dtb_teiki SET note = :note, update_date = CURRENT_TIMESTAMP WHERE teiki_id = :tid AND brand_id = :bid',
            ['note' => $note, 'tid' => $teikiId, 'bid' => $brandId],
        );
    }
}
