<?php

namespace Customize\Service;

use Customize\Repository\TeikiContainerQuantityRepository;
use Customize\Repository\TeikiRepository;
use Customize\Repository\WkDailyBatchRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * 定期次回お届け日 / 容器情報更新サービス
 *
 * 2系 SC_Batch_Daily の以下メソッドを移植:
 * - lfUpdateNextShippingDate (L687-704): エラー時のお届け日延長 (+N日)
 * - lfUpdateTeikiInfo (L1008-1079): 注文成立後の定期情報更新
 *
 * 2系 lfUpdateNextShippingDate:
 *   dtb_wk_daily_batch から対象を取得し、last_shipping_date + interval日 を
 *   定期の next_shipping_date に設定。クレカ/AmazonPay再オーソリ時の +10日リトライで使用。
 *
 * 2系 lfUpdateTeikiInfo:
 *   定期次回受注作成成功後に呼ばれる「定期サイクル前進処理」。
 *   - next_shipping_date を interval_type (0=月/1=週/2=日) に応じて加算
 *   - discount を 0 にリセット
 *   - 次回容器情報更新: ボトル(container_id=1) + レフィル(container_id=2) を統合
 *     * 両方あり → ボトル削除 + レフィル数量加算
 *     * ボトルのみ → ボトルを container_id=2 (レフィル) に変更
 *   - dtb_wk_daily_batch から処理済みレコードを削除
 *
 * 既存資産活用:
 * - TeikiService::setNextShippingDate / setDiscount
 * - TeikiRepository::findOneBy (teiki_id + detail_id)
 *
 * スコープ外:
 * - 商品ID変更 / 数量変更 / 間隔変更 (2系 L1062-1075 はコメントアウト済み)
 * - dtb_wk_daily_batch INSERT (定期次回受注作成時に行うが lfRegistTeikiOrder 大物PR側)
 */
class DailyTeikiUpdateService
{
    /** dtb_teiki_container_quantity.container_id: ボトル */
    public const CONTAINER_ID_BOTTLE = 1;
    /** dtb_teiki_container_quantity.container_id: レフィル */
    public const CONTAINER_ID_REFILL = 2;

    /** 2系 dtb_teiki.interval_type: 0=月 */
    public const INTERVAL_TYPE_MONTH = 0;
    /** 1=週 */
    public const INTERVAL_TYPE_WEEK  = 1;
    /** 2=日 */
    public const INTERVAL_TYPE_DAY   = 2;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TeikiRepository $teikiRepository,
        private readonly TeikiContainerQuantityRepository $containerRepository,
        private readonly WkDailyBatchRepository $wkDailyBatchRepository,
        private readonly TeikiService $teikiService,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * 2系 lfUpdateNextShippingDate (L687-704) 相当
     *
     * dtb_wk_daily_batch から order_id 一致レコードを取得し、各レコードの
     * last_shipping_date + $intervalDays を定期の next_shipping_date に設定。
     *
     * @param int $orderId      対象注文ID
     * @param int $teikiId      対象定期ID
     * @param int $customerId   対象顧客ID（2系では未使用引数だが互換維持）
     * @param int $intervalDays お届け日延長日数（再オーソリ時は10日）
     * @return int 処理した明細件数
     */
    public function updateNextShippingDate(int $orderId, int $teikiId, int $customerId, int $intervalDays): int
    {
        $works = $this->wkDailyBatchRepository->findBy(['order_id' => $orderId]);
        $count = 0;
        foreach ($works as $work) {
            $lastShipping = $work->getLastShippingDate();
            if ($lastShipping === null) {
                continue;
            }

            $detailId = $work->getDetailId();
            $teiki = $this->teikiRepository->findOneBy([
                'teiki_id'  => $teikiId,
                'detail_id' => $detailId,
            ]);
            if ($teiki === null) {
                continue;
            }

            // last_shipping_date + intervalDays
            $nextShipping = (clone $lastShipping)->modify("+{$intervalDays} day");
            // 2系 sfRestartTeiki (L597) は WHERE status <> 3 で解約済みを無音スキップする。
            // 4系 TeikiService::resumeTeiki は解約済み (status=3) に LogicException を投げるため、
            // updateTeikiInfo (L169-179) と同様に try-catch でスキップ可能にする。
            // これが無いと同一受注内に解約済み明細が混在した場合、以降の継続明細の日付延長
            // および wk_daily_batch 削除までバッチ全体が中断してしまう。
            try {
                $this->teikiService->resumeTeiki($teiki, $nextShipping);
            } catch (\LogicException $e) {
                $this->logger->warning(sprintf(
                    '[DailyTeikiUpdate] 定期再開スキップ (解約済): order_id=%d, teiki_id=%d, detail_id=%d',
                    $orderId,
                    $teikiId,
                    $detailId
                ));
                continue;
            }
            $this->logger->info(sprintf(
                '[DailyTeikiUpdate] 定期ID:%d 明細ID:%d 次回お届け日:%s',
                $teikiId,
                $detailId,
                $nextShipping->format('Y-m-d')
            ));
            $count++;
        }

        // 2系 L703: ワークテーブルから対象削除 (lfUpdateTeikiInfo と同パターン)
        $this->em->getConnection()->executeStatement(
            'DELETE FROM dtb_wk_daily_batch WHERE order_id = :oid',
            ['oid' => $orderId]
        );

        return $count;
    }

    /**
     * 2系 lfUpdateTeikiInfo (L1008-1079) 相当
     *
     * 注文成立後の定期サイクル前進処理:
     * - next_shipping_date を interval_type に応じて加算
     * - discount を 0 にリセット
     * - 次回容器情報の統合
     * - 処理済み wk_daily_batch レコードを削除
     *
     * @param int $orderId 対象注文ID
     * @return int 処理した明細件数
     */
    public function updateTeikiInfo(int $orderId): int
    {
        $this->logger->info(sprintf('[DailyTeikiUpdate] 変更注文番号:%d', $orderId));

        $works = $this->wkDailyBatchRepository->findBy([
            'order_id' => $orderId,
            'status'   => 0,
        ]);

        $count = 0;
        foreach ($works as $work) {
            $teikiId  = $work->getTeikiId();
            $detailId = $work->getDetailId();

            $teiki = $this->teikiRepository->findOneBy([
                'teiki_id'  => $teikiId,
                'detail_id' => $detailId,
            ]);
            if ($teiki === null) {
                continue;
            }

            // 次回お届け日計算 (interval_type に応じて加算単位を変える)
            // ★Teiki::getInterval()/getIntervalType()/getNextShippingDate() はいずれも非nullable
            //   (DB列がNOT NULL。2系 lfUpdateTeikiInfo L1024-1026 も $arrDetail から無条件取得するのみで
            //   null チェック自体が無い)。?? やnullチェックは4系実装時に足された過剰な防御であり、
            //   実際には到達不能なのでPHPStan通りに削る。$interval <= 0 のガードのみ実質的な意味を持つため残す。
            $interval     = $teiki->getInterval();
            $intervalType = $teiki->getIntervalType();
            $currentNext  = $teiki->getNextShippingDate();
            if ($interval <= 0) {
                continue;
            }

            $modifier = match ($intervalType) {
                self::INTERVAL_TYPE_DAY   => "+{$interval} day",
                self::INTERVAL_TYPE_WEEK  => "+{$interval} week",
                default                   => "+{$interval} month",
            };
            $newNext = (clone $currentNext)->modify($modifier);

            // 2系 L1043 sfRestartTeiki は status=1 強制リセット + next_shipping_date 設定。
            // 4系 resumeTeiki が同等だが解約済みは例外。注文成立後の処理上発生しないはずだが
            // 防御的に try-catch でスキップ可能にする。
            try {
                $this->teikiService->resumeTeiki($teiki, $newNext);
            } catch (\LogicException $e) {
                $this->logger->warning(sprintf(
                    '[DailyTeikiUpdate] 定期再開スキップ (解約済): order_id=%d, teiki_id=%d, detail_id=%d',
                    $orderId,
                    $teikiId,
                    $detailId
                ));
                continue;
            }
            $this->teikiService->setDiscount($teiki, 0);

            // 次回容器情報の統合
            $this->mergeNextContainer($teikiId, $detailId);

            $count++;
        }

        // ワークテーブル削除 (2系 L1078)
        $conn = $this->em->getConnection();
        $conn->executeStatement(
            'DELETE FROM dtb_wk_daily_batch WHERE order_id = :oid AND status = 0',
            ['oid' => $orderId]
        );

        return $count;
    }

    /**
     * 2系 L1047-1059: 次回容器情報の統合
     *
     * - ボトル(1) + レフィル(2) 両方あり → ボトル削除 + レフィル数量加算
     * - ボトル(1) のみ → ボトルを container_id=2 に変更
     */
    private function mergeNextContainer(int $teikiId, int $detailId): void
    {
        $bottle = $this->containerRepository->findOneBy([
            'teiki_id'     => $teikiId,
            'detail_id'    => $detailId,
            'container_id' => self::CONTAINER_ID_BOTTLE,
        ]);
        if ($bottle === null) {
            // 新ボトル情報なし → 統合不要
            return;
        }

        $refill = $this->containerRepository->findOneBy([
            'teiki_id'     => $teikiId,
            'detail_id'    => $detailId,
            'container_id' => self::CONTAINER_ID_REFILL,
        ]);

        if ($refill !== null) {
            // 両方あり → ボトル削除 + レフィル数量加算
            $newQuantity = (int) $refill->getQuantity() + (int) $bottle->getQuantity();
            $refill->setQuantity($newQuantity);
            $this->em->remove($bottle);
            $this->em->flush();
        } else {
            // ボトルのみ → container_id=2 に変更
            // 複合PK の一部 (container_id) を変更するため UPDATE 直接実行
            $this->em->getConnection()->executeStatement(
                <<<SQL
                UPDATE dtb_teiki_container_quantity
                SET container_id = :refill
                WHERE teiki_id = :tid AND detail_id = :did AND container_id = :bottle
                SQL,
                [
                    'refill' => self::CONTAINER_ID_REFILL,
                    'tid'    => $teikiId,
                    'did'    => $detailId,
                    'bottle' => self::CONTAINER_ID_BOTTLE,
                ]
            );
        }
    }
}
