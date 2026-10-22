<?php

namespace Customize\Service;

use Customize\Repository\TeikiRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * 定期次回受注作成バッチ用のデータ取得サービス
 *
 * 2系 SC_Batch_Daily の以下2メソッドを移植:
 * - lfGetTeikiCustomerList (L153-178): 対象顧客一覧取得
 * - lfGetTeikiData         (L637-666): 定期明細データ取得 + 次回お届け日でグルーピング
 *
 * lfRegistTeikiOrder の主処理本体 (L187-636 / 450行) は後続PRで段階的に移植する。
 * 本PRはデータ取得層のみを切り出して独立Service化。
 *
 * 2系→4系テーブル名対応:
 * - dtb_teiki / dtb_customer は4系でも同名
 * - dtb_products → dtb_product
 * - dt.next_shipping_date / dt.del_flg / dt.status / dt.brand_id はカスタム列で4系も継続使用
 * - dp.product_code / dp.name / dp.tax_status は dtb_product のカスタム列を OrderTrait 拡張で使用
 *
 * カスタム関数 func_get_teiki_course_count は Phase 3 で
 * TeikiRepository::getTeikiCourseCount に Repository 化済み。SQL中の関数呼出は
 * 後段で Service 側で計算して合成する設計に変更。
 */
class TeikiOrderDataLoader
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TeikiRepository $teikiRepository,
    ) {}

    /**
     * 対象顧客一覧取得 (2系 lfGetTeikiCustomerList L153-178)
     *
     * dtb_teiki + dtb_customer JOIN で「指定ブランド・status=1・次回お届け日 <= 対象日」の
     * 定期 + 顧客のペアを返す。
     *
     * @param int    $brandId    対象ブランドID
     * @param string $targetDate 対象日 (Y/m/d または Y-m-d)
     * @param int|null $customerId 顧客指定 (null なら全顧客)
     * @return list<array{teiki_id:int, customer_id:int, payment_id:int, customer_card_id:?string, amazon_billing_agreement_id:?string}>
     */
    public function getTargetCustomerList(int $brandId, string $targetDate, ?int $customerId = null): array
    {
        $conn = $this->em->getConnection();

        $sql = <<<SQL
            SELECT DISTINCT dt.teiki_id, dt.customer_id, dc.payment_id,
                            dc.customer_card_id, dc.amazon_billing_agreement_id
            FROM dtb_teiki dt
            INNER JOIN dtb_customer dc
                ON dt.customer_id = dc.id AND dt.brand_id = dc.brand_id
            WHERE dt.del_flg = 0
              AND dt.status = 1
              AND dt.brand_id = :brand_id
              AND dt.next_shipping_date <= :target_date
              AND dc.customer_status_id != 3
        SQL;

        $params = ['brand_id' => $brandId, 'target_date' => $targetDate];

        if ($customerId !== null) {
            $sql .= ' AND dt.customer_id = :customer_id';
            $params['customer_id'] = $customerId;
        }

        $sql .= ' ORDER BY dt.customer_id, dt.teiki_id';

        $rows = $conn->fetchAllAssociative($sql, $params);

        return array_map(static fn(array $r): array => [
            'teiki_id'                    => (int) $r['teiki_id'],
            'customer_id'                 => (int) $r['customer_id'],
            'payment_id'                  => (int) $r['payment_id'],
            'customer_card_id'            => $r['customer_card_id'] ?? null,
            'amazon_billing_agreement_id' => $r['amazon_billing_agreement_id'] ?? null,
        ], $rows);
    }

    /**
     * 定期明細データ取得 + 次回お届け日でグルーピング (2系 lfGetTeikiData L637-666)
     *
     * 戻り値の構造:
     *   [
     *     '2026-07-15' => [{teiki明細1}, {teiki明細2}, ...],  // 同日同梱
     *     '2026-07-20' => [{teiki明細3}],
     *     ...
     *   ]
     *
     * 各明細には2系互換のため course_count (同コース・同カテゴリ購入回数) を合成する。
     *
     * @param int    $teikiId
     * @param string $nextShippingDate Y-m-d 形式
     * @param int    $brandId
     * @return array<string, list<array<string, mixed>>>
     */
    public function getTeikiDataGroupedByShippingDate(int $teikiId, string $nextShippingDate, int $brandId): array
    {
        $conn = $this->em->getConnection();

        // 2系 SQL に存在した func_get_teiki_course_count() は Phase 3 で Repository 化済み。
        // SQL は dt.* + dp の必要列のみに留め、course_count は後段で合成する。
        $sql = <<<SQL
            SELECT dt.*,
                   dp.product_code AS product_code,
                   dp.name         AS product_name,
                   dp.tax_status   AS tax_status
            FROM dtb_teiki dt
            LEFT JOIN dtb_product dp
                ON dt.product_id = dp.id AND dt.brand_id = dp.brand_id
            WHERE dt.del_flg = 0
              AND dt.status = 1
              AND dt.teiki_id = :teiki_id
              AND dt.next_shipping_date <= :target_date
              AND dt.brand_id = :brand_id
            ORDER BY dt.next_shipping_date, dt.customer_id, dt.detail_id
        SQL;

        $rows = $conn->fetchAllAssociative($sql, [
            'teiki_id'    => $teikiId,
            'target_date' => $nextShippingDate,
            'brand_id'    => $brandId,
        ]);

        $grouped = [];
        foreach ($rows as $row) {
            // 2系互換: course_count を Repository から取得して合成
            // course_id は dtb_teiki で nullable。NULL の場合は 0 にフォールバックするが、
            // Repository SQL は WHERE dod.course_id = :course_id なので 0 でも NULL でも
            // 0件返却となり実害なし（2系の関数呼出も同等の挙動）。
            $row['course_count'] = $this->teikiRepository->getTeikiCourseCount(
                (int) $row['teiki_id'],
                (int) $row['detail_id'],
                (int) $row['brand_id'],
                (int) ($row['course_id'] ?? 0)
            );

            $shippingDateKey = (string) $row['next_shipping_date'];
            $grouped[$shippingDateKey][] = $row;
        }

        return $grouped;
    }

    /**
     * 初回後払い・代引き手数料取得 (2系 SC_Helper_Purchase_Ex::getFirstDeferredCharge L818-823)
     *
     * 顧客の定期初回受注 (is_first=1, payment_id IN (4,13), teiki_id IS NOT NULL) から
     * 当時の手数料額を返す。存在しない場合は null。
     *
     * @param int $customerId 顧客ID
     * @return int|null 初回後払い手数料 (null=初回受注なし)
     */
    public function getFirstDeferredCharge(int $customerId): ?int
    {
        $conn   = $this->em->getConnection();
        // 2系: del_flg = 0 の条件があったが4系 dtb_order に del_flg なし → 条件除去 (プロジェクト方針)
        $result = $conn->fetchOne(
            <<<SQL
            SELECT charge FROM dtb_order
            WHERE customer_id = :customer_id
              AND is_first = 1
              AND payment_id IN (4, 13)
              AND teiki_id IS NOT NULL
            ORDER BY id LIMIT 1
            SQL,
            ['customer_id' => $customerId]
        );

        return $result !== false ? (int) $result : null;
    }
}
