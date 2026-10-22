<?php

namespace Customize\Service;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;

/**
 * 支払方法変更チャットシナリオ描画 Service.
 *
 * 2系 LC_Page_Mypage_PaymentChange_Ex::getScenarioQuestion (L273-297) +
 *      getScenarioTag (L327-406) を Service 化。
 *
 * 対象は scenario_no = 95 (支払方法変更専用シナリオ) 固定。
 * 2系 SC_Helper_Chat とは異なり tag_layout はソース中にインラインで持ち、
 * dtb_chat_tag からは読まない (2系原本踏襲)。
 *
 * scenario_no=95 シナリオ (2026-06-30 時点):
 *   group_no=0  : 支払方法選択 (代引/クレカ/AmazonPay)
 *   group_no=5  : クレカ入力 (Paygent トークン)
 *   group_no=9  : AmazonPay ボタン
 *   group_no=99 : 変更完了メッセージ (AmazonPay 戻り時のみ表示)
 *
 * $mode の意味 (2系踏襲):
 *   0 = 通常表示 (default アクション) — 全 group を hidden で先出し
 *   1 = AmazonPay 戻り時 — group_no IN (0,9,99) のみ hidden 無しで表示
 */
class PaymentChangeScenarioService
{
    /** 支払方法変更シナリオ No (2系 $payment_change_scenario = 95) */
    public const SCENARIO_NO = 95;

    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {}

    private function conn(): Connection
    {
        return $this->em->getConnection();
    }

    /**
     * 2系 getScenarioQuestion L273-297 移植. シナリオデータを取得する.
     *
     * @return list<array<string,mixed>>
     */
    public function getScenarioQuestion(int $mode): array
    {
        if ($mode === 0) {
            $sql = 'SELECT dcsi.scenario_no, dcsi.icon_image, dcs.display_order, dcs.group_no, dcs.message,'
                . ' dcs.input_id, dcs.tag_type, dct.tag_layout, dci.input_detail'
                . ' FROM dtb_chat_scenario_info dcsi'
                . ' INNER JOIN dtb_chat_scenario dcs ON dcsi.scenario_no = dcs.scenario_no'
                . ' INNER JOIN dtb_chat_tag dct ON dcs.tag_type = dct.tag_type'
                . ' LEFT JOIN dtb_chat_input dci ON dcs.input_id = dci.input_id'
                . ' WHERE dcsi.scenario_no = :sno AND dcs.del_flg = 0'
                . ' ORDER BY dcs.group_no, dcs.display_order';
        } else {
            $sql = 'SELECT dcsi.scenario_no, dcsi.icon_image, dcs.display_order, dcs.group_no, dcs.message,'
                . ' dcs.input_id, dcs.tag_type, dct.tag_layout, dci.input_detail'
                . ' FROM dtb_chat_scenario_info dcsi'
                . ' INNER JOIN dtb_chat_scenario dcs ON dcsi.scenario_no = dcs.scenario_no'
                . ' INNER JOIN dtb_chat_tag dct ON dcs.tag_type = dct.tag_type'
                . ' LEFT JOIN dtb_chat_input dci ON dcs.input_id = dci.input_id'
                . ' WHERE dcsi.scenario_no = :sno AND dcs.del_flg = 0 AND dcs.group_no IN (0,9,99)'
                . ' ORDER BY dcs.group_no, dcs.display_order';
        }
        return $this->conn()->fetchAllAssociative($sql, ['sno' => self::SCENARIO_NO]);
    }

    /**
     * 2系 getScenarioTag L327-406 移植. シナリオデータから HTML タグ列を構築する.
     *
     * @param list<array<string,mixed>> $arrQuestion getScenarioQuestion の戻り値
     * @param string $customerName01 __NAME__ 置換に使う顧客の名 (name01)
     */
    public function buildScenarioTag(int $mode, array $arrQuestion, string $customerName01): string
    {
        // ベースタグ (2系原本 L329-337 完全踏襲)
        if ($mode === 0) {
            $baseMessage = "<div style='display:none;' class='message-area chat-left chat-left-active' tag-group='__TAGID__'><div class='avatar'><img src='__OPIMG__' alt=''></div><div class='message-body'><p class='message'>__MSG__</p></div></div>";
            $baseImage   = "<div style='display:none;' class='message-area chat-left chat-left-active' tag-group='__TAGID__'><div class='message-body'><p class='image'><img src='__IMG__' width='100%' alt=''></p></div></div>";
            $baseInput   = "<div style='display:none;' class='message-area chat-right chat-right-active' tag-group='__TAGID__'><div class='message-body'>__INPUT__</div></div>";
        } else {
            $baseMessage = "<div class='message-area chat-left chat-left-active' tag-group='__TAGID__'><div class='avatar'><img src='__OPIMG__' alt=''></div><div class='message-body'><p class='message'>__MSG__</p></div></div>";
            $baseImage   = "<div class='message-area chat-left chat-left-active' tag-group='__TAGID__'><div class='message-body'><p class='image'><img src='__IMG__' width='100%' alt=''></p></div></div>";
            $baseInput   = "<div class='message-area chat-right chat-right-active' tag-group='__TAGID__'><div class='message-body'>__INPUT__</div></div>";
        }

        $questionTag = '';
        foreach ($arrQuestion as $data) {
            $tagId   = (string) ($data['group_no'] ?? '');
            $tagType = (int) ($data['tag_type'] ?? 0);

            if ($tagType === 1) {
                // メッセージ
                $tag = str_replace('__TAGID__', $tagId, $baseMessage);
                $tag = str_replace('__OPIMG__', (string) ($data['icon_image'] ?? ''), $tag);
                $tag = str_replace('__MSG__', (string) ($data['message'] ?? ''), $tag);
                $tag = str_replace('__NAME__', $customerName01, $tag);
                $questionTag .= $tag;
            } elseif ($tagType === 3) {
                // 画像
                $tag = str_replace('__TAGID__', $tagId, $baseImage);
                $tag = str_replace('__IMG__', (string) ($data['message'] ?? ''), $tag);
                $questionTag .= $tag;
            } elseif ($tagType === 4) {
                // 入力項目
                $tag = str_replace('__TAGID__', $tagId, $baseInput);

                $inputTag = (string) ($data['input_detail'] ?? '');
                // クレカ有効期限 (現在年 + 15年、01-12月) を置き換え (2系 L383-398)
                //
                // ★年は**西暦下2桁**。4桁を出すと Paygent のトークン発行が
                //   result=1401「有効期限(年)の書式が不正です。」で必ず失敗する
                //   (2026-08-17 実測: card_expire_year=27 → 0000 / =2027 → 1401)。
                //   2系は `new SC_Date_Ex(date('Y'), date('Y') + 15)` → `getZeroYear()` が
                //   `substr($i, -2)` で下2桁を返し、label と value の**両方**が下2桁になる
                //   (`SC_Date::getZeroYear()` data/class/SC_Date.php L111-129)。
                $currentYear  = (int) date('Y');
                $yearOptions  = '';
                for ($y = $currentYear; $y <= $currentYear + 15; $y++) {
                    $yv = substr((string) $y, -2);
                    $yearOptions .= "<option label='{$yv}' value='{$yv}'>{$yv}</option>";
                }
                $inputTag = str_replace('__Year__', $yearOptions, $inputTag);

                $monthOptions = '';
                for ($m = 1; $m <= 12; $m++) {
                    $mv = str_pad((string) $m, 2, '0', STR_PAD_LEFT);
                    $monthOptions .= "<option label='{$mv}' value='{$mv}'>{$mv}</option>";
                }
                $inputTag = str_replace('__Month__', $monthOptions, $inputTag);

                $tag = str_replace('__INPUT__', $inputTag, $tag);
                $questionTag .= $tag;
            }
        }
        return $questionTag;
    }
}
