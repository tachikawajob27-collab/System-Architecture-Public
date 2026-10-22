<?php

namespace Customize\Service;

use Customize\Entity\BrandMailTemplate;
use Customize\Repository\BrandMailTemplateRepository;
use Customize\Service\Mail\RecipientAddress;
use Doctrine\ORM\EntityManagerInterface;
use Eccube\Entity\MailHistory;
use Eccube\Entity\Order;
use Eccube\Repository\CustomerRepository;
use Eccube\Repository\OrderRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Exception\RfcComplianceException;
use Twig\Environment;

/**
 * 定期解約メール送信サービス（2系 SC_Helper_Mail_Ex::sfSendCancelMail 移植）
 *
 * 2系仕様 (cartDev L312-432):
 * - 解約完了後に呼ばれる（管理画面 admin/order/edit の cancel_mail_modal チェックON時のみ）
 * - メール本文に「解約した定期 + 継続中の定期 + 発送準備中/発送済の注文」を動的にまとめる
 * - template_id=58 固定
 *
 * 集計仕様:
 * 1. 解約データ: dtb_teiki.status=3 かつ cancel_date >= now() - 3min（直前の解約のみ）
 * 2. 継続中: dtb_teiki.status != 3
 * 3. 発送中: 以下3条件のOR
 *    a. status=5(発送済) かつ shipping_commit_date が過去10日〜本日
 *    b. status=8(出荷準備中) ※2系は「返品」と誤記するが実体は出荷準備中 (#961)
 *    c. status IN (1,217,9000,9001) かつ (shipping_date < cancel_ng_date後 OR is_teiki=0)
 *    ★本文に表示する「発送日/お届け予定日」は a/b/c いずれの分岐でも shipping_commit_date
 *      (2系 cartDev L367/376/385相当)。判定(WHERE)側は a=shipping_commit_date、c=shipping_date
 *      と2系のまま使い分ける（4系 dtb_shipping.shipping_commit_date は実在し、2026-08-21実測で
 *      1,132,805行中892,336行に値が入っている。2026-08-21 対比レビューで「4系コアに無い」という
 *      旧docblockの誤りを検出・修正した）。
 *
 * 4系対応の差分:
 * - dtb_order.status → order_status_id
 * - dtb_order_detail → dtb_order_item
 * - dtb_products → dtb_product
 * - dtb_brand.cancel_ng_date は smallint で日数
 * - 2系の dtb_shipping.shipping_id = 0 条件は4系では不要（オーダー対shipping は通常1件）
 *
 * ★2026-08-21 Issue #199 検証で修正 (PR f-0821-mail-service-verify):
 * 旧実装は EC-CUBE 4 標準コア `dtb_mail_template`(MailTemplateRepository) から template_id=58 を
 * 検索していたが、コアテーブルは標準9件(id 1-9)しか無く該当行が無いため find() が常に null を返し、
 * send() が毎回 false で即終了 = **解約メールが一切送信されない**状態だった。
 * 2系 sfSendCancelMail は brand_id 付きの `dtb_mailtemplate`(47件) を参照するため、
 * 他サービス(AssMailService 等)と同じ BrandMailTemplateRepository に統一する。
 * また 2系 sfSendCancelMail は sfSaveMailHistory($order_id, ...) で **`dtb_mail_history`
 * (order_id 紐付け)** に保存するが、旧実装は `dtb_ass_mail_history`(customer_id 紐付け) に
 * 保存しており不一致だったため、他の受注紐付けメール(NpConfirmMailService 等)と同じ
 * `Eccube\Entity\MailHistory` + `MailHistoryTrait::template_id` に統一する。
 */
class TeikiCancelMailService
{
    /** 解約メールテンプレートID (2系互換) */
    public const TEMPLATE_ID = 58;

    /** 本文レンダリングに使う Twig テンプレート (2系 mail_templates/cancel_mail.tpl の移植) */
    private const TWIG_TEMPLATE = 'Mail/teiki_cancel_mail.twig';

    /** 発送済み判定対象期間（過去N日） */
    public const SHIPPING_LOOKBACK_DAYS = 10;

    /** 2系 dtb_teiki.status の値 */
    public const TEIKI_STATUS_CANCELLED = 3;

    /** 2系 dtb_order.status の値 */
    public const ORDER_STATUS_SHIPPED  = 5;
    /** 8=出荷準備中 (2系は「返品」と誤記していたが実体は出荷準備中、#961) */
    public const ORDER_STATUS_SHIPPING_READY = 8;
    /** 発送準備中とみなすステータス (1=処理中, 217=保留, 9000/9001=カスタム) */
    public const ORDER_STATUS_PREPARING = [1, 217, 9000, 9001];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly CustomerRepository $customerRepository,
        private readonly OrderRepository $orderRepository,
        private readonly BrandMailTemplateRepository $brandMailTemplateRepository,
        private readonly MailerInterface $mailer,
        private readonly Environment $twig,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * 定期解約メールを送信する（2系 sfSendCancelMail 相当）
     *
     * @param int $orderId    解約対象のorder_id（履歴記録用）
     * @param int $customerId 解約した顧客ID
     * @param int $brandId    ブランドID
     * @return bool 送信成功なら true（履歴も保存される）
     */
    public function send(int $orderId, int $customerId, int $brandId): bool
    {
        $Customer = $this->customerRepository->find($customerId);
        if ($Customer === null) {
            return false;
        }

        $brandRow = $this->fetchBrand($brandId);
        if ($brandRow === null) {
            return false;
        }

        // 2系 dtb_mailtemplate は brand_id 付き複合キー。
        // BrandMailTemplateRepository (dtb_mailtemplate) を参照する（EC-CUBE 4 標準コアの
        // dtb_mail_template ではない。コアテーブルには template_id=58 が存在しないため
        // 旧実装は必ず null を返し送信されない状態だった）。
        /** @var BrandMailTemplate|null $MailTemplate */
        $MailTemplate = $this->brandMailTemplateRepository->findByTemplateAndBrand(self::TEMPLATE_ID, $brandId);
        if ($MailTemplate === null) {
            return false;
        }

        // 集計データを準備（2系 fnSendCancelMail の "arrData" 相当）
        $data = $this->collectMailData($customerId, $brandId, $brandRow);

        // 本文を Twig レンダリング（2系 mail_templates/cancel_mail.tpl 移植）
        $body = $this->twig->render(self::TWIG_TEMPLATE, [
            'tpl_header' => (string) $MailTemplate->getHeader(),
            'tpl_footer' => (string) $MailTemplate->getFooter(),
            'arrData'    => $data,
        ]);
        $subject = (string) $MailTemplate->getSubject();

        $brandEmail = (string) ($brandRow['email'] ?? '');
        $brandName  = (string) ($brandRow['name']  ?? '');

        // #3756: RFC 違反アドレス (ezweb 連続ドット等) で RfcComplianceException が投げられると
        //        呼び出し元のバッチごと落ちる。引用で救えるものは救い、それでも不正ならこのメールだけスキップ
        try {
            $to = RecipientAddress::of((string) $Customer->getEmail(), $Customer->getName01() . ' 様');
        } catch (RfcComplianceException $e) {
            $this->logger->warning('[TeikiCancelMail] 宛先が RFC 違反のため送信スキップ', ['customer_id' => $Customer->getId(), 'message' => $e->getMessage()]);

            return false;
        }

        $message = (new Email())
            ->subject($subject)
            ->from(new Address($brandEmail, $brandName))
            ->to($to)
            ->bcc($brandEmail)
            ->replyTo($brandEmail)
            ->returnPath($brandEmail)
            ->text($body);

        try {
            $this->mailer->send($message);
        } catch (TransportExceptionInterface) {
            return false;
        }

        // dtb_mail_history に履歴記録（2系 sfSaveMailHistory($order_id, ...) 相当。
        // order_id 紐付けの MailHistory Entity + MailHistoryTrait::template_id 拡張を使う）。
        // `dtb_mail_history.order_id` は NULL 許容・`MailHistory::setOrder()` も nullable のため、
        // Order を送信条件のガードにはしない(CreditFormMailService::send() L112-116 と同型。
        // 対比レビュー指摘: 旧実装は Order 不在で送信自体を中止しており、2系にない送信ブロックだった)。
        $history = new MailHistory();
        /** @var Order|null $Order */
        $Order = $this->orderRepository->find($orderId);
        if ($Order !== null) {
            $history->setOrder($Order);
        }
        $history->setMailSubject($subject);
        $history->setMailBody($body);
        $history->setSendDate(new \DateTime());
        // @phpstan-ignore-next-line method.notFound
        $history->setTemplateId(self::TEMPLATE_ID);
        $this->em->persist($history);
        $this->em->flush();

        return true;
    }

    /**
     * メール本文用の集計データを構築する
     *
     * 戻り値構造 (2系 arrData 互換):
     *   [
     *     'mail_pattern' => 1,
     *     'customer_nm' => string,            // dtb_customer.name01 の実値
     *     'teiki' => [...],                   // 解約データ
     *     'keizoku' => [...],                 // 継続中
     *     'shipping' => [...],                // 発送済（過去N日）
     *     'shipping_today' => [...],          // 発送準備中
     *     'is_shipping' => 1 or null,
     *     'is_shipping_today' => 1 or null,
     *   ]
     *
     * @param array<string,mixed> $brandRow dtb_brand の取得結果
     * @return array<string,mixed>
     */
    private function collectMailData(int $customerId, int $brandId, array $brandRow): array
    {
        $conn = $this->em->getConnection();

        $today = (new \DateTimeImmutable('today'));
        $shippingFromDate = $today->modify('-' . self::SHIPPING_LOOKBACK_DAYS . ' days');
        $cancelNgDays = (int) ($brandRow['cancel_ng_date'] ?? 0);
        $shippingStopDate = $today->modify('+' . $cancelNgDays . ' days');

        // 顧客名
        $customerName = $conn->fetchOne(
            'SELECT name01 FROM dtb_customer WHERE id = :id',
            ['id' => $customerId]
        );

        $data = [
            'mail_pattern' => 1,
            'customer_nm'  => $customerName !== false ? (string) $customerName : '',
        ];

        // 1. 解約データ（3分以内）
        $data['teiki'] = $this->fetchCancelledTeiki($customerId, $brandId);

        // 2. 継続中データ
        $data['keizoku'] = $this->fetchActiveTeiki($customerId, $brandId);

        // 3. 発送中データ（発送済 / 発送準備中 で分類）
        $arrOrder = $this->fetchShippingOrders($customerId, $brandId, $shippingFromDate, $today, $shippingStopDate);

        $shipping = [];
        $shippingToday = [];
        foreach ($arrOrder as $row) {
            // 2系 L392/L399: 発送済み/発送準備中いずれの表示欄も shipping_commit_date を使う
            // (cartDev L367 arrData["shipping"][i]["shipping_date"] = $data["shipping_commit_date"] / L376 も同様)
            $entry = [
                'course_name'   => (string) ($row['course_name']   ?? ''),
                'product_name'  => (string) ($row['product_name']  ?? ''),
                'shipping_date' => $row['shipping_commit_date'] ?? null,
            ];
            if ((int) $row['order_status_id'] === self::ORDER_STATUS_SHIPPED) {
                $shipping[] = $entry;
            } else {
                $shippingToday[] = $entry;
            }
        }
        $data['shipping'] = $shipping;
        $data['shipping_today'] = $shippingToday;
        $data['is_shipping'] = empty($shipping) ? null : 1;
        $data['is_shipping_today'] = empty($shippingToday) ? null : 1;

        return $data;
    }

    /** dtb_brand 1行取得 */
    private function fetchBrand(int $brandId): ?array
    {
        $row = $this->em->getConnection()->fetchAssociative(
            'SELECT * FROM dtb_brand WHERE id = :id',
            ['id' => $brandId]
        );
        return $row !== false ? $row : null;
    }

    /** 直前の解約定期（cancel_date >= now() - 3min） */
    private function fetchCancelledTeiki(int $customerId, int $brandId): array
    {
        $conn = $this->em->getConnection();
        $sql = <<<SQL
            SELECT dt.next_shipping_date AS shipping_date,
                   dc.name_official      AS course_name,
                   dp.name_official      AS product_name
            FROM dtb_teiki dt
            INNER JOIN dtb_course dc ON dt.course_id = dc.id
            INNER JOIN dtb_product dp ON dt.product_id = dp.id
            WHERE dt.customer_id = :customer_id
              AND dt.brand_id = :brand_id
              AND dt.status = :cancelled
              AND dt.cancel_date >= (CURRENT_TIMESTAMP + INTERVAL '-3 min')
            ORDER BY dt.next_shipping_date
        SQL;
        return $conn->fetchAllAssociative($sql, [
            'customer_id' => $customerId,
            'brand_id'    => $brandId,
            'cancelled'   => self::TEIKI_STATUS_CANCELLED,
        ]);
    }

    /** 継続中の定期（status != 3） */
    private function fetchActiveTeiki(int $customerId, int $brandId): array
    {
        $conn = $this->em->getConnection();
        $sql = <<<SQL
            SELECT dt.next_shipping_date AS shipping_date,
                   dc.name_official      AS course_name,
                   dp.name_official      AS product_name
            FROM dtb_teiki dt
            INNER JOIN dtb_course dc ON dt.course_id = dc.id
            INNER JOIN dtb_product dp ON dt.product_id = dp.id
            WHERE dt.customer_id = :customer_id
              AND dt.brand_id = :brand_id
              AND dt.status != :cancelled
            ORDER BY dt.next_shipping_date
        SQL;
        return $conn->fetchAllAssociative($sql, [
            'customer_id' => $customerId,
            'brand_id'    => $brandId,
            'cancelled'   => self::TEIKI_STATUS_CANCELLED,
        ]);
    }

    /** 発送中の注文（3条件OR） */
    private function fetchShippingOrders(
        int $customerId,
        int $brandId,
        \DateTimeInterface $shippingFromDate,
        \DateTimeInterface $today,
        \DateTimeInterface $shippingStopDate
    ): array {
        $conn = $this->em->getConnection();
        $preparingIds = implode(',', self::ORDER_STATUS_PREPARING);
        // NOTE: 2系では `do2.del_flg = 0` を WHERE に含めていたが、
        //       4系 EC-CUBE コア dtb_order には del_flg カラムが存在しない
        //       (論理削除は Soft-deletable で行わない仕様)。そのため省略する。
        // 2系 L367-385: WHERE の絞り込みは a(発送済)=shipping_commit_date / c(準備中)=shipping_date
        // で使い分ける。本文表示は a/b/c いずれも shipping_commit_date (呼出元 collectMailData 側)。
        $sql = <<<SQL
            SELECT do2.order_status_id,
                   dp.name_official AS product_name,
                   ds.shipping_date,
                   ds.shipping_commit_date,
                   doi.teiki_detail_id,
                   dc.name_official AS course_name
            FROM dtb_order do2
            INNER JOIN dtb_order_item doi ON do2.id = doi.order_id
            INNER JOIN dtb_shipping ds ON do2.id = ds.order_id
            INNER JOIN dtb_product dp ON doi.product_id = dp.id
            INNER JOIN dtb_course dc ON doi.course_id = dc.id
            WHERE do2.customer_id = :customer_id
              AND do2.brand_id = :brand_id
              AND (
                    (do2.order_status_id = :shipped AND ds.shipping_commit_date >= :from AND ds.shipping_commit_date <= :today)
                 OR (do2.order_status_id = :shipping_ready)
                 OR (do2.order_status_id IN ({$preparingIds}) AND ds.shipping_date < :stop)
                 OR (do2.order_status_id IN ({$preparingIds}) AND dp.is_teiki = 0)
              )
            ORDER BY do2.order_status_id, ds.shipping_date DESC, doi.teiki_detail_id
        SQL;
        return $conn->fetchAllAssociative($sql, [
            'customer_id' => $customerId,
            'brand_id'    => $brandId,
            'shipped'       => self::ORDER_STATUS_SHIPPED,
            'shipping_ready' => self::ORDER_STATUS_SHIPPING_READY,
            'from'        => $shippingFromDate->format('Y-m-d 00:00:00'),
            'today'       => $today->format('Y-m-d 00:00:00'),
            'stop'        => $shippingStopDate->format('Y-m-d 00:00:00'),
        ]);
    }
}
