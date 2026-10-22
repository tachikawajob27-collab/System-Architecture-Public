<?php

namespace Customize\Service;

use Doctrine\ORM\EntityManagerInterface;

/**
 * 日次バッチ セーフモード用 決済処理中データ取得サービス
 *
 * 2系 SC_Batch_Daily::lfGetSafeData (L1084-1168) を移植。
 *
 * セーフモード (mode=1) 起動時に前回バッチで「決済処理中 (order_status_id=7)」のまま
 * 残った受注を再取得し、payment_id ごとに Card / VA / AmazonPay の各与信配列を再構築する。
 * 再構築した配列は通常バッチの lfRegistTeikiOrder 後と同等の状態になり、
 * そのまま CardSettlement / AmazonPaySettlement / VASettlement に渡せる。
 *
 * **2系→4系 主要変換:**
 * - dtb_order.order_id → dtb_order.id
 * - dtb_order.status = 7 → dtb_order.order_status_id = 7
 * - dtb_order.del_flg = 0 → 4系 dtb_order に del_flg なし (条件除去)
 * - dtb_order.customer_id JOIN dtb_customer.customer_id → dtb_customer.id
 * - MDL_VAMOS_PAYMENT_ID = 13
 *
 * **NP後払い (payment_id=6/17) について:**
 * 2系の lfNPSettlement はコメントアウト済みのため、NP分は arrNPOrderId に格納するが
 * 使用されない。本Serviceでは NP 行も返り値に含めてスコープ明記する。
 *
 * **ChatWork 通知 (sendChatWork) はスコープ外:**
 * 2系 L1107 の sendChatWork(0) は呼出側 (DailyBatchCommand) で実施する。
 */
class DailySafeDataLoader
{
    /** 支払方法ID */
    public const PAYMENT_ID_CARD      = 5;
    public const PAYMENT_ID_NP        = 6;
    public const PAYMENT_ID_FREE      = 7;
    public const PAYMENT_ID_AMAZONPAY = 9;
    public const PAYMENT_ID_NP2       = 17; // 2系の別NP payment_id
    public const PAYMENT_ID_VAMOS     = 13;

    /** order_status_id = 7: 決済処理中 */
    public const ORDER_STATUS_PROCESSING = 7;

    /** device_type_id = 80: バッチ */
    public const DEVICE_TYPE_BATCH = 80;

    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {}

    /**
     * 決済処理中の受注を取得し、payment_id ごとに与信配列を構築する (2系 L1084-1168)
     *
     * @param int $brandId ブランドID
     * @return array{
     *   count: int,
     *   arrCardInfo: list<array{order_id:int, teiki_id:int, customer_id:int, payment_id:int, payment_total:int}>,
     *   arrVAOrderId: list<array{order_id:int, teiki_id:int, customer_id:int, payment_id:int}>,
     *   arrAmazonPayInfo: list<array{order_id:int, teiki_id:int, customer_id:int, payment_id:int, payment_total:int}>,
     *   arrNPOrderId: list<array{order_id:int, teiki_id:int, customer_id:int, payment_id:int}>,
     *   arrCardNgOrderId: list<int>,
     *   arrAmazonNgOrderId: list<int>,
     * }
     */
    public function load(int $brandId): array
    {
        $conn = $this->em->getConnection();

        // 2系 L1086-1096
        // del_flg は4系 dtb_order に存在しないため除去。
        // customer JOIN は 4系 dtb_customer.id で結合。
        $rows = $conn->fetchAllAssociative(
            <<<SQL
            SELECT DISTINCT
                o.id              AS order_id,
                o.customer_id,
                o.payment_id,
                o.payment_total,
                o.teiki_id,
                c.customer_card_id,
                c.payment_id      AS tk_payment_id,
                c.amazon_billing_agreement_id
            FROM dtb_order o
            INNER JOIN dtb_customer c
                ON o.customer_id = c.id
            WHERE o.order_status_id = :status
              AND o.device_type_id  = :device_type
              AND o.create_date    <= CURRENT_TIMESTAMP
              AND o.brand_id        = :brand_id
            SQL,
            [
                'status'      => self::ORDER_STATUS_PROCESSING,
                'device_type' => self::DEVICE_TYPE_BATCH,
                'brand_id'    => $brandId,
            ]
        );

        $retCnt = count($rows);

        $arrCardInfo        = [];
        $arrVAOrderId       = [];
        $arrAmazonPayInfo   = [];
        $arrNPOrderId       = [];
        $arrCardNgOrderId   = [];
        $arrAmazonNgOrderId = [];

        foreach ($rows as $order) {
            $orderId       = (int) $order['order_id'];
            $customerId    = (int) $order['customer_id'];
            $paymentId     = (int) $order['payment_id'];
            $teikiId       = (int) $order['teiki_id'];
            $paymentTotal  = (int) $order['payment_total'];
            $tkPaymentId   = (int) ($order['tk_payment_id'] ?? 0);

            if ($paymentId === self::PAYMENT_ID_CARD) {
                // クレジットカード (2系 L1116-1123)
                $arrCardInfo[] = [
                    'teiki_id'      => $teikiId,
                    'order_id'      => $orderId,
                    'customer_id'   => $customerId,
                    'payment_id'    => $paymentId,
                    'payment_total' => $paymentTotal,
                ];
            } elseif ($paymentId === self::PAYMENT_ID_NP || $paymentId === self::PAYMENT_ID_NP2) {
                // コンビニ後払い NP (2系 L1125-1139)
                // lfNPSettlement はコメントアウト済みのため arrNPOrderId は未使用
                $arrNPOrderId[] = [
                    'teiki_id'    => $teikiId,
                    'order_id'    => $orderId,
                    'customer_id' => $customerId,
                    'payment_id'  => $paymentId,
                ];
                if ($tkPaymentId === self::PAYMENT_ID_CARD) {
                    $arrCardNgOrderId[] = $orderId;
                }
                if ($tkPaymentId === self::PAYMENT_ID_AMAZONPAY) {
                    $arrAmazonNgOrderId[] = $orderId;
                }
            } elseif ($paymentId === self::PAYMENT_ID_VAMOS) {
                // VAMOS後払い (2系 L1141-1155)
                $arrVAOrderId[] = [
                    'teiki_id'    => $teikiId,
                    'order_id'    => $orderId,
                    'customer_id' => $customerId,
                    'payment_id'  => $paymentId,
                ];
                if ($tkPaymentId === self::PAYMENT_ID_CARD) {
                    $arrCardNgOrderId[] = $orderId;
                }
                if ($tkPaymentId === self::PAYMENT_ID_AMAZONPAY) {
                    $arrAmazonNgOrderId[] = $orderId;
                }
            } elseif ($paymentId === self::PAYMENT_ID_AMAZONPAY) {
                // AmazonPay (2系 L1157-1165)
                $arrAmazonPayInfo[] = [
                    'teiki_id'      => $teikiId,
                    'order_id'      => $orderId,
                    'customer_id'   => $customerId,
                    'payment_id'    => $paymentId,
                    'payment_total' => $paymentTotal,
                ];
            }
        }

        return [
            'count'              => $retCnt,
            'arrCardInfo'        => $arrCardInfo,
            'arrVAOrderId'       => $arrVAOrderId,
            'arrAmazonPayInfo'   => $arrAmazonPayInfo,
            'arrNPOrderId'       => $arrNPOrderId,
            'arrCardNgOrderId'   => $arrCardNgOrderId,
            'arrAmazonNgOrderId' => $arrAmazonNgOrderId,
        ];
    }
}
