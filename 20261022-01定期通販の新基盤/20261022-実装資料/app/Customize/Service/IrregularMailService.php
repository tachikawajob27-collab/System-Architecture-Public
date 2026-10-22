<?php

namespace Customize\Service;

use Customize\Entity\AssMailHistory;
use Customize\Repository\BrandMailTemplateRepository;
use Customize\Repository\BrandRepository;
use Customize\Service\Mail\RecipientAddress;
use Doctrine\ORM\EntityManagerInterface;
use Eccube\Repository\CustomerRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Exception\RfcComplianceException;
use Twig\Environment;

/**
 * イレギュラーメール送信サービス（2系 SC_Helper_Mail_Ex::sfSendIregularMail 移植）
 *
 * **本Serviceは「メール送信枠組み」のリファレンス実装**として、最もシンプルな
 * sfSendIregularMail 1メソッドだけを移植する。後続PRで他メール
 * (sfSendCancelMail / sfSendNpngConfirmMail 等) を順次追加する。
 *
 * 2系仕様 (cartDev SC_Helper_Mail_Ex L437-494):
 * - dtb_brand から brand 情報（送信元アドレス・店舗名）取得
 * - dtb_customer から顧客取得
 * - **dtb_mailtemplate**(brand_id 付き複合キー) から subject/header/footer 取得
 * - $arrTplVar には arrInfo/tpl_header/tpl_footer/customerName の4つしか assign されないため、
 *   実際の呼出元(execute_batch_iregular_mail.php, 2系本番で実行実績0件)がどの template_id を
 *   渡しても、本文テンプレートが参照できる変数はこの4つのみ
 * - 送信成功時に dtb_ass_mail_history へ履歴INSERT
 *
 * ★2026-08-21 Issue #199 検証で修正 (PR f-0821-mail-service-verify):
 * 旧実装は EC-CUBE 4 標準コア `dtb_mail_template`(MailTemplateRepository) から検索していたが、
 * 2系は brand_id 付きの `dtb_mailtemplate` を参照するため、他サービス(AssMailService 等)と
 * 同じ BrandMailTemplateRepository に統一する。本文レンダリングも「MailTemplate.fileName の
 * Twig を render」という誤った設計（コアの file_name はこのテーブルと無関係）をやめ、
 * customerName + header + footer を組み立てる汎用テンプレートに統一する。
 * ★2系 `mtb_mail_tpl_path`(24行)は template_id ごとに別の .tpl (refund.tpl/resend.tpl 等)を
 * 参照し、それぞれ固有の静的文言(「ご返金額」行の有無等)を持つため、本文の**静的テキストまで**
 * 2系と一致するわけではない。一致が保証できるのは「参照可能な変数が
 * arrInfo/tpl_header/tpl_footer/customerName の4つに限られる」という構造のみ。
 * ★本経路は2系本番で実行実績0件(`dtb_iregular_mail_send` 0行)のため実害は無かったが、
 * 静かに誤ったテンプレートを引く/送信しない型の乖離だったので合わせて修正する。
 * 特定の template_id で本経路が実際に使われる際は、対応する2系 .tpl を精読の上で
 * 専用テンプレートに差し替えること。
 *
 * 4系対応の差分:
 * - SC_SendMail_Ex → Symfony Mailer + Email (Mime)
 * - SC_SiteView_Ex の fetch → Twig::render
 *
 * **SMS は本Service には含まない**（新プロバイダー未確定のため Phase 4 後半に別Serviceで実装）。
 */
class IrregularMailService
{
    /** 本文レンダリングに使う Twig テンプレート（2系の customerName+header+footer 構造を汎用化） */
    private const TWIG_TEMPLATE = 'Mail/irregular_mail.twig';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly BrandRepository $brandRepository,
        private readonly CustomerRepository $customerRepository,
        private readonly BrandMailTemplateRepository $brandMailTemplateRepository,
        private readonly MailerInterface $mailer,
        private readonly Environment $twig,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * イレギュラーメールを送信する（2系 sfSendIregularMail 相当）
     *
     * @param int $customerId 顧客ID
     * @param int $templateId mail template ID (dtb_mailtemplate.template_id)
     * @param int $brandId    ブランドID (dtb_brand.id / dtb_mailtemplate.brand_id)
     * @return bool 送信成功なら true（履歴も保存される）
     */
    public function send(int $customerId, int $templateId, int $brandId): bool
    {
        $Customer = $this->customerRepository->find($customerId);
        if ($Customer === null) {
            return false;
        }
        $Brand = $this->brandRepository->find($brandId);
        if ($Brand === null) {
            return false;
        }
        // 2系 dtb_mailtemplate は brand_id 付き複合キー。
        // BrandMailTemplateRepository (dtb_mailtemplate) を参照する（EC-CUBE 4 標準コアの
        // dtb_mail_template ではない。コアテーブルは標準9件しか無く該当行が無いため
        // 旧実装は必ず null を返し送信されない状態だった）。
        $MailTemplate = $this->brandMailTemplateRepository->findByTemplateAndBrand($templateId, $brandId);
        if ($MailTemplate === null) {
            return false;
        }

        // 件名・本文の組立
        $subject = (string) $MailTemplate->getSubject();
        $body = $this->twig->render(self::TWIG_TEMPLATE, [
            'tpl_header'   => (string) $MailTemplate->getHeader(),
            'tpl_footer'   => (string) $MailTemplate->getFooter(),
            'customerName' => (string) $Customer->getName01(),
        ]);

        // ブランドのメールアドレス・店舗名から From を組立
        // Brand エンティティの email/name は Customize\Entity\Brand に存在する想定
        $brandEmail = $this->getBrandEmail($Brand);
        $brandName  = $this->getBrandName($Brand);

        // #3756: RFC 違反アドレス (ezweb 連続ドット等) で RfcComplianceException が投げられると
        //        呼び出し元のバッチごと落ちる。引用で救えるものは救い、それでも不正ならこのメールだけスキップ
        try {
            $to = RecipientAddress::of((string) $Customer->getEmail(), $Customer->getName01() . ' 様');
        } catch (RfcComplianceException $e) {
            $this->logger->warning('[IrregularMail] 宛先が RFC 違反のため送信スキップ', ['customer_id' => $Customer->getId(), 'message' => $e->getMessage()]);

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

        // 送信履歴を dtb_ass_mail_history に INSERT
        $history = new AssMailHistory();
        $history->setCustomerId($Customer->getId());
        $history->setEmail((string) $Customer->getEmail());
        $history->setTemplateId($templateId);
        $history->setSubject($subject);
        $history->setMailBody($body);
        $history->setSendDate(new \DateTime());
        $this->em->persist($history);
        $this->em->flush();

        return true;
    }

    /**
     * Brand から送信元メールアドレスを取得。
     * Customize\Entity\Brand に email カラムが proxy 経由で注入されている前提。
     */
    private function getBrandEmail(object $brand): string
    {
        $conn = $this->em->getConnection();
        $val = $conn->fetchOne(
            'SELECT email FROM dtb_brand WHERE id = :id',
            ['id' => $brand->getId()]
        );
        return $val !== false && $val !== null ? (string) $val : '';
    }

    private function getBrandName(object $brand): string
    {
        $conn = $this->em->getConnection();
        $val = $conn->fetchOne(
            'SELECT name FROM dtb_brand WHERE id = :id',
            ['id' => $brand->getId()]
        );
        return $val !== false && $val !== null ? (string) $val : '';
    }
}
