<?php

namespace Customize\Service;

use Customize\Entity\Brand;
use Customize\Repository\BrandMailTemplateRepository;
use Customize\Repository\BrandRepository;
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
 * 支払方法変更完了メール送信 Service.
 *
 * 2系 LC_Page_Mypage_PaymentChange_Ex::sendMail (L908-933) を移植.
 * dtb_mailtemplate.template_id = 24 (件名「【ブランド名】お支払い方法の変更が完了いたしました。」)
 * ブランド別に header/footer が異なるため BrandMailTemplateRepository で取得.
 *
 * 2系呼出タイミング:
 *  - 代引き変更成功後 (lfSetDaibiki L430)
 *  - クレカ変更成功後 (lfSetPaygentCreditStock case 1 L527)
 *  - AmazonPay変更成功後 (lfSetAmazonPay L662)
 *
 * 2系 テンプレ変数 (complete_change_card_mail.tpl):
 *  - customerName  = name01 + ' ' + name02
 *  - updateDate    = date('Y/m/d')
 *  - paymentMethod = 変更後の支払方法名
 */
class PaymentChangeMailService
{
    /** 支払方法変更完了 (2系 template_id 24)。本 Service が描画する唯一のテンプレート。 */
    public const TEMPLATE_ID = 24;

    // ★`TEMPLATE_ID_VAMOS_SWITCH = 25` は削除した (Issue #1640)。
    //
    //   本 Service は `$templateId` を受け取るが**本文テンプレートは
    //   `Mail/payment_change_complete.twig` 固定**で、これは2系
    //   `mail_templates/complete_change_card_mail.tpl` (= template_id **24**) の移植。
    //   template_id=25 は2系では別物の `mail_templates/order_mail.tpl`
    //   (受注明細つき) なので、25 を渡しても件名/ヘッダ/フッタだけが 25 になり
    //   **本文は 24 のまま**という不整合が起きる。実際 `AssService` の
    //   VAMOS 切替がこれを踏み、受注明細と手数料加算後の総額が欠落していた。
    //
    //   template_id=25 を送りたい場合は {@see OrderNoticeMailService::send()} を使うこと。
    //   定数をここに残すと同じ誤用を誘うため、参照が無くなった時点で削除している。

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly BrandRepository $brandRepository,
        private readonly BrandMailTemplateRepository $brandMailTemplateRepository,
        private readonly CustomerRepository $customerRepository,
        private readonly MailerInterface $mailer,
        private readonly Environment $twig,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * 支払方法変更完了メールを送信する.
     *
     * @param int    $customerId    送信対象顧客ID
     * @param int    $brandId       顧客所属ブランドID
     * @param string $paymentMethod 変更後の支払方法名
     * @param int    $templateId    メールテンプレートID (デフォルト: TEMPLATE_ID=24)
     * @return bool 送信成功なら true
     */
    public function send(int $customerId, int $brandId, string $paymentMethod, int $templateId = self::TEMPLATE_ID): bool
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

        $template = $this->brandMailTemplateRepository->findByTemplateAndBrand($templateId, $brandId);
        if ($template === null) {
            return false;
        }

        $customerName = trim(((string) $customer->getName01()) . ' ' . ((string) $customer->getName02()));

        $body = $this->twig->render('Mail/payment_change_complete.twig', [
            'tpl_header'    => (string) $template->getHeader(),
            'tpl_footer'    => (string) $template->getFooter(),
            'customerName'  => $customerName,
            'updateDate'    => (new \DateTimeImmutable())->format('Y/m/d'),
            'paymentMethod' => $paymentMethod,
        ]);

        $brandEmail = (string) ($brand->getEmail() ?? '');
        $brandName  = (string) ($brand->getName()  ?? '');
        $toEmail    = (string) $customer->getEmail();
        $toName     = ((string) $customer->getName01()) . ' 様';

        // #3756: RFC 違反アドレス (ezweb 連続ドット等) で RfcComplianceException が投げられると
        //        呼び出し元のバッチごと落ちる。引用で救えるものは救い、それでも不正ならこのメールだけスキップ
        try {
            $to = RecipientAddress::of($toEmail, $toName);
        } catch (RfcComplianceException $e) {
            $this->logger->warning('[PaymentChangeMail] 宛先が RFC 違反のため送信スキップ', ['customer_id' => $customer->getId(), 'message' => $e->getMessage()]);

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
        $history->setMailSubject((string) $template->getSubject());
        $history->setMailBody($body);
        $history->setSendDate(new \DateTime());
        // @phpstan-ignore-next-line method.notFound
        $history->setTemplateId($templateId);
        $this->em->persist($history);
        $this->em->flush();

        return true;
    }
}
