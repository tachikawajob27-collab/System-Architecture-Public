<?php

namespace Customize\Service;

use Customize\Repository\CourseDetailRepository;
use Customize\Repository\TeikiContainerQuantityRepository;
use Customize\Repository\TeikiOptionRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * 定期次回受注の注文明細構築サービス
 *
 * 2系 SC_Batch_Daily::lfRegistOrder の **明細作成部** (L232-409) を移植。
 *
 * 担当範囲:
 * 1. 容器情報の有無で2分岐した注文明細配列の構築
 * 2. 商品オプションの合成 (dtb_teiki_option)
 * 3. 「今回のみ品」(is_onetime=1) の dtb_teiki 解約処理 (del_flg=1, status=3)
 * 4. 次回定期データの dtb_wk_daily_batch INSERT
 * 5. tax_rate 判定 (tax_status=1 → 8% / それ以外 → 10%)
 * 6. 単価取得 (func_get_course_detail → CourseDetailRepository::getCourseDetail)
 * 7. 配送方法手動設定の検出
 *
 * **スコープ外（後続PR）:**
 * - 合計金額計算 (taxfree_total / taxtotal / subtotal / discount)
 * - 送料計算 (deliv_fee)
 * - 手数料計算 (charge / getFirstDeferredCharge)
 * - ポイント値引き (getDiscountPoint)
 * - dtb_order / dtb_shipping INSERT
 *
 * 既存資産活用:
 * - Phase 3 TeikiContainerQuantity / TeikiOption Entity + Repository
 * - Phase 3 CourseDetailRepository::getCourseDetail (func_get_course_detail 代替)
 */
class TeikiOrderDetailBuilder
{
    /** 支払方法ID: 無料 (単価0円強制) */
    public const PAYMENT_ID_FREE = 7;

    /** 2系 dtb_teiki.tax_status: 軽減税率対象 → 8% */
    public const TAX_STATUS_REDUCED = 1;
    public const TAX_RATE_REDUCED   = 8;
    public const TAX_RATE_STANDARD  = 10;

    /** 2系 CourseDetailRepository::getCourseDetail の mode=2: 定期2回目以降の単価 */
    public const COURSE_DETAIL_MODE_REPEAT = 2;

    /** 2系 dtb_teiki.status: 解約 */
    public const TEIKI_STATUS_CANCELLED = 3;

    /** 2系 dtb_wk_daily_batch.status: 未処理 */
    public const WK_STATUS_PENDING = 0;

    /** interval_type */
    public const INTERVAL_TYPE_MONTH = 0;
    public const INTERVAL_TYPE_WEEK  = 1;
    public const INTERVAL_TYPE_DAY   = 2;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly CourseDetailRepository $courseDetailRepository,
        private readonly TeikiContainerQuantityRepository $containerRepository,
        private readonly TeikiOptionRepository $optionRepository,
    ) {}

    /**
     * 注文明細配列を構築する (2系 lfRegistOrder L232-409 相当)
     *
     * 副作用:
     * - 今回のみ品 (is_onetime=1 かつ interval なし) は dtb_teiki を解約状態に更新
     * - 各 teiki 行で dtb_wk_daily_batch に INSERT (次回サイクル前進用)
     *
     * @param list<array<string, mixed>> $teikiList lfGetTeikiData の同梱グループ1件分
     * @param int                        $paymentId 支払方法ID
     * @param int                        $brandId   ブランドID
     * @param string                     $taxRule   baseInfo.tax_rule (端数処理規則)
     * @return array{
     *   details: list<array<string, mixed>>,
     *   is_delivfee: bool,
     *   deliv_id_manual: ?int,
     *   time_id_manual: ?int
     * }
     */
    public function build(array $teikiList, int $paymentId, int $brandId, string $taxRule): array
    {
        $details = [];
        $isDelivFee = true;
        $delivIdManual = null;
        $timeIdManual = null;

        foreach ($teikiList as $arrTeiki) {
            $teikiId   = (int) $arrTeiki['teiki_id'];
            $detailId  = (int) $arrTeiki['detail_id'];

            $containers = $this->containerRepository->findBy(
                ['teiki_id' => $teikiId, 'detail_id' => $detailId],
                ['container_id' => 'ASC']
            );

            if (count($containers) === 0) {
                // 容器情報なし: 1明細
                $details[] = $this->buildDetailRow($arrTeiki, null, $paymentId, $taxRule);
                $this->handleOneTimeOrContinuation($arrTeiki);
            } else {
                // 容器情報あり: 容器ごとに明細を分割
                foreach ($containers as $container) {
                    $details[] = $this->buildDetailRow($arrTeiki, $container, $paymentId, $taxRule);
                }
                $this->handleOneTimeOrContinuation($arrTeiki);
            }

            // 商品オプション合成 (容器なし/あり共通)
            $options = $this->optionRepository->findBy([
                'teiki_id'        => $teikiId,
                'teiki_detail_id' => $detailId,
            ]);
            if (count($options) > 0) {
                // 直前で追加した明細群に option を付ける。
                // - 容器なし (containers=0件): 直前1明細のみ → max(1, 0) = 1 件遡る
                // - 容器あり (containers=N件): 直前N明細すべて → max(1, N) = N 件遡る
                // 2系 L281-291 (容器なし) / L341-351 (容器あり) で同じ option を全 idx に付与する動作と等価。
                $startIdx = count($details) - max(1, count($containers));
                $optionList = [];
                foreach ($options as $opt) {
                    $optionList[] = [
                        'option_id'        => $opt->getOptionId(),
                        'option_detail_id' => $opt->getOptionDetailId(),
                    ];
                }
                for ($i = $startIdx; $i < count($details); $i++) {
                    $details[$i]['option'] = $optionList;
                }
            }

            // wk_daily_batch INSERT + 次回お届け日計算
            $this->queueNextCycle($arrTeiki, $brandId);

            // 初回（コース1回目）は送料無し (2系 L362-365)
            $courseCount = (int) ($arrTeiki['course_count'] ?? 0) + 1;
            if ($courseCount <= 1) {
                $isDelivFee = false;
            }

            // 配送方法手動設定検出 (2系 L404-408)
            if (!empty($arrTeiki['is_deliv_manual'])) {
                $delivIdManual = (int) ($arrTeiki['deliv_id']      ?? 0) ?: null;
                $timeIdManual  = (int) ($arrTeiki['deliv_time_id'] ?? 0) ?: null;
            }
        }

        return [
            'details'         => $details,
            'is_delivfee'     => $isDelivFee,
            'deliv_id_manual' => $delivIdManual,
            'time_id_manual'  => $timeIdManual,
        ];
    }

    /**
     * 容器行数を取得 (discount 集計用, 2系 L233-234/297 の $arrContainer 件数相当)
     *
     * 容器情報なし: 1 (2系 L277-279 で discount を1回加算)
     * 容器情報あり: 容器件数分 (2系 L337-339 で容器ループ内 discount を都度加算)
     */
    public function getContainerCount(int $teikiId, int $detailId): int
    {
        $containers = $this->containerRepository->findBy([
            'teiki_id'  => $teikiId,
            'detail_id' => $detailId,
        ]);
        return max(1, count($containers));
    }

    /**
     * 1明細行を構築 (2系 L239-291 / L297-352 共通部)
     *
     * @param array<string, mixed>            $teiki    定期明細データ
     * @param \Customize\Entity\TeikiContainerQuantity|null $container 容器情報 (なし時 null)
     * @return array<string, mixed>
     */
    private function buildDetailRow(array $teiki, $container, int $paymentId, string $taxRule): array
    {
        $row = [
            'product_id'   => (int) $teiki['product_id'],
            'product_code' => (string) ($teiki['product_code'] ?? ''),
            'product_name' => (string) ($teiki['product_name'] ?? ''),
            'course_id'    => (int) ($teiki['course_id'] ?? 0),
            'tax_rate'     => ((int) ($teiki['tax_status'] ?? 0) === self::TAX_STATUS_REDUCED)
                ? self::TAX_RATE_REDUCED
                : self::TAX_RATE_STANDARD,
            'tax_rule'     => $taxRule,
        ];

        // 容器あり: 数量は container 単位
        if ($container !== null) {
            $row['quantity']     = (int) ($container->getQuantity() ?? 0);
            $row['container_id'] = $container->getContainerId();
        } else {
            $row['quantity'] = (int) ($teiki['quantity'] ?? 0);
        }

        // 継続品: teiki_detail_id を紐付け (2系 L240-242 / L299-301)
        if ((int) ($teiki['is_onetime'] ?? 0) === 0 && !empty($teiki['interval'])) {
            $row['teiki_detail_id'] = (int) $teiki['detail_id'];
        }

        // 単価取得
        if ($paymentId === self::PAYMENT_ID_FREE) {
            // 支払方法「無料」は強制0円
            $row['price'] = 0;
        } else {
            $priceStr = $this->courseDetailRepository->getCourseDetail(
                (int) ($teiki['course_id']    ?? 0),
                (int) $teiki['product_id'],
                (int) ($teiki['course_count'] ?? 0),
                self::COURSE_DETAIL_MODE_REPEAT
            );
            $row['price'] = $priceStr !== null ? (int) $priceStr : 0;
        }

        return $row;
    }

    /**
     * 今回のみ品 (is_onetime=1 かつ interval なし) は dtb_teiki を解約状態に更新
     * 2系 L243-249 / L302-308
     */
    private function handleOneTimeOrContinuation(array $teiki): void
    {
        $isOnetime = (int) ($teiki['is_onetime'] ?? 0) === 1
                  || empty($teiki['interval']);
        if (!$isOnetime) {
            return;
        }
        $this->em->getConnection()->executeStatement(
            <<<SQL
            UPDATE dtb_teiki
            SET del_flg = 1, status = :st, update_date = CURRENT_TIMESTAMP
            WHERE teiki_id = :tid AND detail_id = :did
            SQL,
            [
                'st'  => self::TEIKI_STATUS_CANCELLED,
                'tid' => (int) $teiki['teiki_id'],
                'did' => (int) $teiki['detail_id'],
            ]
        );
    }

    /**
     * dtb_wk_daily_batch に次回サイクル前進データを INSERT
     * 2系 L378-401
     *
     * 注: 2系 L373-377 (course_detail から upd_quantity 等を反映) は
     *     コメントアウト済みのためスキップ。
     */
    private function queueNextCycle(array $teiki, int $brandId): void
    {
        $intervalType = (int) ($teiki['interval_type'] ?? self::INTERVAL_TYPE_MONTH);
        $interval     = (int) ($teiki['interval']      ?? 0);
        $currentNext  = (string) $teiki['next_shipping_date'];

        $modifier = match ($intervalType) {
            self::INTERVAL_TYPE_DAY   => "+{$interval} day",
            self::INTERVAL_TYPE_WEEK  => "+{$interval} week",
            default                   => "+{$interval} month",
        };
        $nextDate = (new \DateTimeImmutable($currentNext))->modify($modifier);

        $this->em->getConnection()->executeStatement(
            <<<SQL
            INSERT INTO dtb_wk_daily_batch (
                teiki_id, detail_id, customer_id, brand_id, course_id, product_id,
                next_shipping_date, last_shipping_date, status, create_date, update_date
            ) VALUES (
                :tid, :did, :cid, :bid, :course, :prod,
                :next, :last, :st, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
            )
            SQL,
            [
                'tid'    => (int) $teiki['teiki_id'],
                'did'    => (int) $teiki['detail_id'],
                'cid'    => (int) $teiki['customer_id'],
                'bid'    => $brandId,
                'course' => (int) ($teiki['course_id']  ?? 0),
                'prod'   => (int) $teiki['product_id'],
                'next'   => $nextDate->format('Y-m-d'),
                'last'   => $currentNext,
                'st'     => self::WK_STATUS_PENDING,
            ]
        );
    }
}
