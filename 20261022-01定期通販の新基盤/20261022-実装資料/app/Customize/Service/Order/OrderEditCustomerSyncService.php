<?php

declare(strict_types=1);

namespace Customize\Service\Order;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;

/**
 * 管理画面の受注編集で保存した「注文者」情報を `dtb_customer` へ書き戻す (Issue #2818)。
 *
 * ## 2系の典拠 (行単位で精読)
 *
 * `C:/src/cartDev/data/class_extends/page_extends/admin/order/LC_Page_Admin_Order_Edit_Ex.php`
 * `doRegister()` **L3375-3385** — `$objQuery->begin()` (L3387) の**前**:
 *
 * ```php
 * if (isset($arrValues['customer_id'])) {
 *     $arrCustomer['name01'] = $arrValues['name01'];
 *     $arrCustomer['kana01'] = $arrValues['name01'];   // ★kana にも name01 を入れている
 *     $arrCustomer['email']  = $arrValues['email'];
 *     $arrCustomer['tel01']  = $arrValues['tel01'];
 *     $arrCustomer['zip01']  = $arrValues['zip01'];
 *     $arrCustomer['pref']   = $arrValues['pref'];
 *     $arrCustomer['addr01'] = $arrValues['addr01'];
 *     $arrCustomer['addr02'] = $arrValues['addr02'];
 *     SC_Helper_Customer_Ex::sfEditCustomerData($arrCustomer, $arrValues['customer_id']);
 * }
 * ```
 *
 * ★Issue #2818 の本文は6列 (`email` / `tel01` / `zip01` / `pref` / `addr01` / `addr02`) しか
 *   引用していないが、現物には **`name01` と `kana01` も在る**ので計8列。
 *
 * 呼び先 `SC_Helper_Customer_Ex::sfEditCustomerData()`
 * (`C:/src/cartDev/data/class_extends/helper_extends/SC_Helper_Customer_Ex.php` L142-211) は、
 * 受け取った配列に **`update_date = CURRENT_TIMESTAMP` と `brand_id = $_SESSION['brand_id']`**
 * を足して `dtb_customer` を UPDATE する (L146-147 / L194)。
 *
 * ★`password` / `salt` / `reminder_answer` は**触らない**。同関数 L159-190 は
 *   `$array['password'] == DEFAULT_PASSWORD or $array['password'] == ''` で `unset()` するが、
 *   受注編集の呼び出しでは配列にキー自体が無く (未定義キーは `null`、`null == ''` は true)
 *   `unset()` 側へ落ちるため UPDATE 文に載らない。`salt` も `is_numeric($customer_id)` の
 *   編集分岐では `$array` へ入らない (新規登録分岐 L155-156 のみ)。
 *   つまり実際に書かれるのは **上記8列 + `update_date` + `brand_id` = 10列だけ**。
 *
 * ## 2系 → 4系 の列変換 (2026-08-28 に両側の `information_schema.columns` で実測)
 *
 * | 2系 `dtb_customer` | 4系 `dtb_customer` | 供給元 (4系 `dtb_order`) |
 * |---|---|---|
 * | `name01` | `name01` | `name01` |
 * | `kana01` | `kana01` | `kana01` (下記★) |
 * | `email` | `email` | `email` |
 * | `tel01` | **`phone_number`** | `phone_number` |
 * | `zip01` | **`postal_code`** | `postal_code` |
 * | `pref` | **`pref_id`** | `pref_id` |
 * | `addr01` | `addr01` | `addr01` |
 * | `addr02` | `addr02` | `addr02` |
 * | `update_date` | `update_date` | `CURRENT_TIMESTAMP` |
 * | `brand_id` | `brand_id` | 管理者セッションの `brand_id` |
 *
 * 4系 `dtb_customer` に `zip02` は無く単一の `postal_code` (実測値は `5010461` のように
 * ハイフン無し7桁で `dtb_order.postal_code` と同形式)。2系が `zip02` を触らないのと同じく、
 * 4系でも「郵便番号1列を上書きする」以上の意味は無い。
 *
 * ## ★`kana01` の供給元だけ 2系の字面から替えている (唯一の意図的な差異)
 *
 * 2系は `$arrCustomer['kana01'] = $arrValues['name01']` と**漢字氏名をカナ列へ入れている**。
 * これは2系の受注編集フォームに**カナの入力欄が無い**ため (同ファイル L2569-2575 の
 * `addParam()` は `name01` / `email` / `zip01` / `pref` / `addr01` / `addr02` / `tel01` の
 * 7項目のみで `kana01` は存在しない)。同じ `doRegister()` は L3395 で
 * `$arrOrderValues["order_kana01"] = $arrValues['name01']` とも書くので、
 * **2系では保存直後に必ず `dtb_order.order_kana01 == dtb_customer.kana01` が成立する**
 * (2系DB実測 2026-08-28: `order_kana01 = order_name01` が 1,256,507件中 1,243,752件 = 99.0%、
 *  `dtb_customer` 側も `kana01 = name01` が 440,388件中 438,488件 = 99.6%)。
 *
 * 4系の受注編集画面には `form.kana.kana01` の入力欄が実在した
 * (★2026-10-19 に2系どおり「お名前」1欄にしたため今は描画しない。送信時に
 * `OrderNameKanaTypeExtension` が kana01 = name01 を入れるので、結果は2系と一致する)。そこで本 Service は
 * **`dtb_order.kana01` を供給元にする**。2系と結果が変わるのは「管理者がカナ欄に
 * 氏名欄と違う値を入れている受注」だけで、その場合に2系の字面どおり `name01` を流し込むと
 * **画面に表示されているカナを黙って壊す**。
 *
 * `dtb_order.kana01` が空の受注 (4系DB実測 2026-08-28: 1,132,843件中 **8,289件**) では
 * `name01` へフォールバックするので、そこは2系と同一の値になる。
 *
 * ## `email` の NULL ガード
 *
 * 4系 `dtb_order.email` は nullable だが `dtb_customer.email` は **NOT NULL** (両方実測)。
 * 空の受注は 1,132,843件中 **3件**しか無いが、そこで NOT NULL 違反を起こすと同期SQLごと
 * 失敗し (呼出元の SAVEPOINT で受注編集自体は守られるものの) 他の9列の同期まで巻き添えで
 * 落ちるため、空なら**会員の既存値を残す**。
 *
 * ## なぜ Entity ではなく `dtb_order` からの `UPDATE ... FROM` なのか
 *
 * - コアが `flush()` した**直後の行そのもの**を供給元にするので、Form / PurchaseFlow /
 *   他の Subscriber が最終的に何を書いたかに依存しない。
 * - `Customer` Entity を書き換えて `flush()` する方式だと、同じイベントを購読する
 *   {@see \Customize\EventSubscriber\Admin\Order\AdminOrderCustomerPurchaseSummarySubscriber}
 *   が priority -1024 で `em->refresh($Customer)` するため、**flush 前の変更が捨てられる**
 *   (同 Subscriber は生SQLで書いた値を読み直すために refresh している)。
 *   生SQLで書いておけば、その refresh がそのまま本 Service の結果も読み直してくれる。
 * - `brand_id` は `CustomerTrait` (EntityExtension) 側の列で、`app/proxy/entity/` 未生成時に
 *   アクセサが存在せず**黙って欠ける** (CLAUDE.md #1344)。DBAL で書けばプロキシ生成状態に
 *   依存しない ({@see \Customize\EventSubscriber\Admin\Order\OrderEditReauthorizeSubscriber}
 *   が `brand_id` を DBAL で引いているのと同じ判断)。
 * - ゲスト受注 (`dtb_order.customer_id IS NULL`) は結合が成立せず 0行更新になる。
 *   2系の `isset($arrValues['customer_id'])` ガードは既定値 `'0'` のため実質 always true だが、
 *   `WHERE customer_id = 0` に該当する会員が居ないので同じく無更新
 *   (2系実測 2026-08-28: `dtb_order` 1,256,507件は**全件** `customer_id > 0`)。
 *
 * ## 同期**しない**列 (2系が書かないので4系でも書かない)
 *
 * `name02` / `kana02` / `company_name` / `sex_id` / `job_id` / `birth` / `country_id` /
 * `customer_status_id` / `point` / `buy_times` / `buy_total` / `first_buy_date` /
 * `last_buy_date` / `note` / `password` / `salt` / `secret_key` / `mailmaga_flg` ほか
 * `dtb_customer` の残り全列。購入サマリ4列は別経路
 * ({@see \Customize\Service\CustomerPurchaseSummaryService}、Issue #2815) の担当。
 *
 * ★`name02` / `kana02` について: 4系の受注編集画面には入力欄が在った
 * (2026-10-19 に削除) ので `kana01` と同じ論法なら同期の候補になるが、
 * **同期しない**。2系が書いていないうえ、4系 `dtb_order` の実データは
 * `name02` / `kana02` とも **1,132,843件すべて空** (2026-08-28 実測) で、
 * 同期しても会員側の姓名2/カナ2を空で潰すだけになるため。
 */
class OrderEditCustomerSyncService
{
    /**
     * 同期SQL本体。テストから同じ文字列を検査できるよう定数にしてある。
     *
     * 列の並びは2系 `doRegister()` L3376-3383 の代入順 + `sfEditCustomerData()` が足す2列。
     *
     * `:brand_id` は明示 CAST する。NULL を渡したときに PostgreSQL が
     * `COALESCE` の引数型を決められず `could not determine data type of parameter` に
     * なるのを避けるため。
     */
    public const SQL = <<<'SQL'
        UPDATE dtb_customer c
           SET
               name01       = o.name01,
               kana01       = COALESCE(NULLIF(o.kana01, ''), o.name01),
               email        = COALESCE(NULLIF(o.email, ''), c.email),
               phone_number = o.phone_number,
               postal_code  = o.postal_code,
               pref_id      = o.pref_id,
               addr01       = o.addr01,
               addr02       = o.addr02,
               update_date  = CURRENT_TIMESTAMP,
               brand_id     = COALESCE(CAST(:brand_id AS INTEGER), c.brand_id)
          FROM dtb_order o
         WHERE o.id = :order_id
           AND c.id = o.customer_id
        SQL;

    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {}

    /**
     * 受注の注文者情報を、その受注の会員 (`dtb_order.customer_id`) へ書き戻す。
     *
     * @param int             $orderId 保存直後の受注ID
     * @param int|null        $brandId 管理者セッションの `brand_id`。null なら会員の既存 `brand_id` を維持
     *                                 (4系 `dtb_customer.brand_id` は NOT NULL のため NULL は書けない)
     * @param Connection|null $conn    呼出元が明示トランザクション/SAVEPOINT を張っている場合に渡す
     *
     * @return int 更新した会員数 (0 = ゲスト受注、または該当会員なし)
     */
    public function sync(int $orderId, ?int $brandId, ?Connection $conn = null): int
    {
        if ($orderId <= 0) {
            return 0;
        }

        return (int) ($conn ?? $this->em->getConnection())->executeStatement(
            self::SQL,
            ['order_id' => $orderId, 'brand_id' => $brandId],
        );
    }
}
