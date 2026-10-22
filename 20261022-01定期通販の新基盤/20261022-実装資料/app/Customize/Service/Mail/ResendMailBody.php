<?php

declare(strict_types=1);

namespace Customize\Service\Mail;

/**
 * 2系 `mail_templates/resend.tpl` (template_id = 40 / 67) の本文組み立て (Issue #2821).
 *
 * ## 2系の典拠
 *
 * - `mtb_mail_tpl_path` 実測: `40 → mail_templates/resend.tpl` / `67 → mail_templates/resend.tpl`
 * - `C:/src/cartDev/data/Smarty/templates/admin/mail_templates/resend.tpl` の**中身は
 *   ライセンスコメントを除くと `<!--{$tpl_header}-->` と `<!--{$tpl_footer}-->` の2行だけ**。
 *   受注明細も配送情報も金額も出さない (`sphone/` 版も同一)。
 * - `C:/src/cartDev/data/class/helper/SC_Helper_Mail.php` L237-239:
 *   fetch した本文に対し `{uid}` → `dtb_customer_code.code`、`{name}` → `dtb_order.order_name01`
 *   を **この順で** `str_replace` する。
 *
 * ## 実データによる裏取り (2系 `ai-analysis-db`, 2026-08-27 実測)
 *
 * `dtb_mail_history` の template_id=40 は 3,158通。うち受注ブランドの
 * `dtb_mailtemplate` 行が引ける 3,144通に対し、本文が
 * `"\n" . header({name}置換済) . "\n\n" . footer . "\n"` と**完全一致**するのは
 * 2,291通。年別では 2026年が 1,208/1,226 = 98.5% 一致で、古い年の不一致は
 * `dtb_mailtemplate` が現行値しか持たない (途中でテンプレが編集された) ことによる。
 * → 本文の組み立て方はこの形で確定。
 *
 * `{uid}` を含むテンプレートは 47行中 **0行** (`{name}` は template_id 40 と 42 のみ)。
 * それでも 2系が無条件に置換しているため踏襲する (置換対象が無ければ何も起きない)。
 *
 * ★4系のこれまでの実装はテンプレ未対応で `Mail/order_brand.twig` (受注明細つき注文メール)
 * にフォールバックしており、送信されれば2系と全く違う本文が顧客に届く状態だった。
 */
final class ResendMailBody
{
    /**
     * 2系 `mtb_mail_tpl_path` で `mail_templates/resend.tpl` を指す template_id.
     *
     * - 40: 倉庫返品の通常返品案内 (2系実測 3,158通)
     * - 67: 違約金催促 (2系実測 0通。`dtb_mailtemplate` に brand_id=1 の行しか無い)
     */
    public const TEMPLATE_IDS = [40, 67];

    public static function supports(int $templateId): bool
    {
        return in_array($templateId, self::TEMPLATE_IDS, true);
    }

    /**
     * 本文を組み立てる.
     *
     * @param string $header       `dtb_mailtemplate.header`
     * @param string $footer       `dtb_mailtemplate.footer`
     * @param string $name         `dtb_order.name01` (2系 `order_name01`)
     * @param string $customerCode `dtb_customer_code.code` (該当なしなら空文字)
     */
    public static function render(string $header, string $footer, string $name, string $customerCode = ''): string
    {
        $body = "\n" . $header . "\n\n" . $footer . "\n";

        // 2系 L238-239 と同じ順序
        $body = str_replace('{uid}', $customerCode, $body);

        return str_replace('{name}', $name, $body);
    }
}
