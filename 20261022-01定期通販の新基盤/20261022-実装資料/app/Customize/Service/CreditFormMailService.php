<?php

namespace Customize\Service;

use Customize\Entity\Brand;
use Customize\Entity\CustomerCode;
use Customize\Repository\BrandMailTemplateRepository;
use Customize\Repository\BrandRepository;
use Customize\Repository\CustomerCodeRepository;
use Customize\Service\Mail\RecipientAddress;
use Doctrine\ORM\EntityManagerInterface;
use Eccube\Entity\Customer;
use Eccube\Entity\MailHistory;
use Eccube\Repository\CustomerRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Exception\RfcComplianceException;
use Twig\Environment;

/**
 * カード情報入力フォーム送信メールサービス.
 *
 * 2系 LC_Page_Admin_Order_Edit_Ex::case 'send_mail_credit_form' (L842-866) を移植。
 * dtb_mailtemplate.template_id = 23 (件名「お支払い方法の変更について」) を使う。
 *
 * 2系の主な仕様:
 *  - dtb_customer_code から code を取得。無ければ "C{customer_id}{rand8}" を生成して INSERT
 *  - $objMailHelper->sfSendTemplateMail($to, $to_name, 23, $this) で本文に
 *    {$arrCustomer.name01} 様 / {$tpl_header} / URL / {$tpl_footer} を埋める
 *  - メール送信後 onload で window.alert("カード情報入力フォームを送信しました")
 *
 * 4系対応の差分:
 *  - SC_Helper_Mail → Symfony Mailer + Twig + dtb_mail_history 保存
 *  - URL は CreditReAuthoriMailService と同じ /mypage/credit.php レガシーURL を踏襲
 *  - 既存 [[CreditReAuthoriMailService]] と分離 (templateId 違い + code 自動生成の有無の違い)
 *
 * スコープ外:
 *  - /mypage/credit.php → 4系ルート差し替え (NPng と同じ後続PR)
 */
class CreditFormMailService
{
    /** カード情報入力フォーム送信 */
    public const TEMPLATE_ID = 23;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly BrandRepository $brandRepository,
        private readonly BrandMailTemplateRepository $brandMailTemplateRepository,
        private readonly CustomerRepository $customerRepository,
        private readonly CustomerCodeRepository $customerCodeRepository,
        private readonly MailerInterface $mailer,
        private readonly Environment $twig,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * 2系 send_mail_credit_form 相当のメール送信処理.
     *
     * @return bool 送信成功なら true。顧客/ブランド/テンプレ未存在で false
     */
    public function send(int $customerId, int $orderId, int $brandId): bool
    {
        /** @var Customer|null $customer */
        $customer = $this->customerRepository->find($customerId);
        if ($customer === null) {
            return false;
        }

        /** @var Brand|null $brand */
        $brand = $this->brandRepository->find($brandId);
        if ($brand === null) {
            return false;
        }

        $template = $this->brandMailTemplateRepository->findByTemplateAndBrand(self::TEMPLATE_ID, $brandId);
        if ($template === null) {
            return false;
        }

        // 2系 L851-858: dtb_customer_code から code を取得。無ければ生成して INSERT
        $code = $this->resolveCustomerCode($customerId);

        $body = $this->twig->render('Mail/credit_form.twig', [
            'tpl_header'  => (string) $template->getHeader(),
            'tpl_footer'  => (string) $template->getFooter(),
            'arrCustomer' => [
                'name01'   => (string) $customer->getName01(),
                'brand_id' => $brandId,
            ],
            'code'        => $code,
        ]);

        $brandEmail = (string) ($brand->getEmail() ?? '');
        $brandName  = (string) ($brand->getName()  ?? '');
        $toEmail    = (string) $customer->getEmail();
        $toName     = (string) $customer->getName01() . ' 様';

        // #3756: RFC 違反アドレス (ezweb 連続ドット等) で RfcComplianceException が投げられると
        //        呼び出し元のバッチごと落ちる。引用で救えるものは救い、それでも不正ならこのメールだけスキップ
        try {
            $to = RecipientAddress::of($toEmail, $toName);
        } catch (RfcComplianceException $e) {
            $this->logger->warning('[CreditFormMail] 宛先が RFC 違反のため送信スキップ', ['customer_id' => $customer->getId(), 'message' => $e->getMessage()]);

            return false;
        }

        $message = (new Email())
            ->subject((string) $template->getSubject())
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

        $history = new MailHistory();
        // 2系は order_id をキーに mail_history を残す。受注詳細画面からの送信なので order を紐づける
        $order = $this->em->getRepository(\Eccube\Entity\Order::class)->find($orderId);
        if ($order !== null) {
            $history->setOrder($order);
        }
        $history->setMailSubject((string) $template->getSubject());
        $history->setMailBody($body);
        $history->setSendDate(new \DateTime());
        // @phpstan-ignore-next-line method.notFound
        $history->setTemplateId(self::TEMPLATE_ID);
        $this->em->persist($history);
        $this->em->flush();

        return true;
    }

    /**
     * 2系 L851-858 移植: dtb_customer_code を顧客IDで取得、なければ "C{customer_id}{rand8}" を生成して INSERT.
     *
     * 2系 makeRandStr (LC_Page_Admin_Order_Edit_Ex L4066-4073) は a-z/0-9/A-Z (計62文字) からの rand 抽出。
     * 4系では URL token として使うため CSPRNG (random_bytes) で 8文字の hex 文字列を生成する。
     * 文字種は a-f/0-9 (16文字) と狭まるが、長さ8 で 2^32 通り (≒43億) あり token として十分。
     */
    private function resolveCustomerCode(int $customerId): string
    {
        /** @var CustomerCode|null $existing */
        $existing = $this->customerCodeRepository->findOneBy(['customer_id' => $customerId]);
        if ($existing !== null && $existing->getCode() !== '') {
            return $existing->getCode();
        }

        $code = 'C' . $customerId . bin2hex(random_bytes(4));
        $entity = new CustomerCode();
        $entity->setCustomerId($customerId);
        $entity->setCode($code);
        $entity->setCreateDate(new \DateTime());
        $this->em->persist($entity);
        $this->em->flush();

        return $code;
    }
}
