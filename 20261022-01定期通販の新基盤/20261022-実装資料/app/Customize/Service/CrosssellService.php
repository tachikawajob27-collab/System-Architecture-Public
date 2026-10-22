<?php

namespace Customize\Service;

use Doctrine\ORM\EntityManagerInterface;

/**
 * サンクスページのアップセル/クロスセル表示サービス
 *
 * 2系 LC_Page_Store_Complete_Crosssell_Ex::lfSetCrossModel の移植。
 *
 * **重要**: 2系ファイル名は "Crosssell" だが、dtb_course.is_cross の値で内部分岐する:
 *   - is_cross = 2 → アップセル（メイン+サブ コースで上位プラン提案）
 *   - is_cross ≠ 2 → クロスセル（関連商品の追加提案）
 *
 * 本Serviceの責務: 表示用データの組み立てのみ。購入処理は lfCrossRegistart 別Service で。
 *
 * 戻り値構造:
 *   [
 *     'template_path' => 'crosssell_xxx.tpl',         // dtb_model_template.path
 *     'customer_name' => 'REMOVED_PERSONAL_VALUE',                       // 元注文の order_name01
 *     'cta_buttons' => [cta_id => next_action, ...],   // 各CTAボタンの遷移先
 *     'confirm_layouts' => [cta_id => '<html...>', ...], // 確認ポップのHTML
 *   ]
 *
 * スコープ外（後続PR）:
 * - 購入処理 (lfCrossRegistart 681行のうち主要部分)
 * - func_get_order_product_option カスタム関数の置換（アップセル分岐の商品名取得）
 *   → 現段階では PHP 側で dtb_order_detail_option を直接JOIN する SQL に置換予定
 */
class CrosssellService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {}

    /**
     * サンクスページの表示データを組み立てる（2系 lfSetCrossModel 移植）
     *
     * @param int $thanksId   dtb_model_thanks.id
     * @param int $orderId    元注文 dtb_order.id
     * @param int $customerId 顧客ID（現状未使用だが2系シグネチャ踏襲のため受ける）
     * @return array{
     *   template_path: ?string,
     *   customer_name: ?string,
     *   cta_buttons: array<int|string, int>,
     *   confirm_layouts: array<int|string, string>
     * }
     */
    public function buildThanksDisplay(int $thanksId, int $orderId, int $customerId): array
    {
        $conn = $this->em->getConnection();

        // 1. dtb_model_thanks から該当行 + テンプレートパス
        $thanksRow = $conn->fetchAssociative(
            'SELECT * FROM dtb_model_thanks WHERE id = :id',
            ['id' => $thanksId]
        );
        $templatePath = null;
        if ($thanksRow !== false && !empty($thanksRow['model_template_id'])) {
            $templatePath = $conn->fetchOne(
                'SELECT path FROM dtb_model_template WHERE id = :id',
                ['id' => (int) $thanksRow['model_template_id']]
            );
            if ($templatePath === false) {
                $templatePath = null;
            }
        }

        // 2. 元注文情報取得 (4系: dtb_order.id, customer name は order_name01)
        $orderRow = $conn->fetchAssociative(
            'SELECT * FROM dtb_order WHERE id = :id',
            ['id' => $orderId]
        );

        // 3. レイアウトID解決
        //    dtb_confirm_setting (asp_id + baitai_id + brand_id) → なければ dtb_confirm_default (brand_id)
        $layoutId = null;
        if ($orderRow !== false) {
            $layoutId = $conn->fetchOne(
                'SELECT layout_thanks_id_sp FROM dtb_confirm_setting
                 WHERE asp_id = :asp AND baitai_id = :baitai AND brand_id = :brand',
                [
                    'asp'    => $orderRow['asp_id']    ?? null,
                    'baitai' => $orderRow['baitai_id'] ?? null,
                    'brand'  => $orderRow['brand_id']  ?? null,
                ]
            );
            if ($layoutId === false || $layoutId === null) {
                $layoutId = $conn->fetchOne(
                    'SELECT layout_thanks_id_sp FROM dtb_confirm_default WHERE brand_id = :brand',
                    ['brand' => $orderRow['brand_id'] ?? null]
                );
                if ($layoutId === false) {
                    $layoutId = null;
                }
            }
        }

        // 4. お客様氏名
        $customerName = $orderRow !== false ? ($orderRow['name01'] ?? null) : null;

        // 5. クロス明細とCTAボタン
        $ctaButtons = [];
        $confirmLayouts = [];

        $details = $conn->fetchAllAssociative(
            'SELECT * FROM dtb_model_thanks_detail WHERE thanks_id = :tid AND (del_flg = 0 OR del_flg IS NULL)',
            ['tid' => $thanksId]
        );

        foreach ($details as $detail) {
            $ctaId = $detail['cta_id'];
            $nextAction = (int) $detail['thanks_next_action'];
            $ctaButtons[$ctaId] = $nextAction;

            // thanks_next_action: 0 = 通常購入 / 2 = 確認後購入 → 確認ポップを生成
            if (($nextAction === 0 || $nextAction === 2) && $layoutId !== null) {
                $layoutTag = $this->buildLayoutTag((int) $layoutId);
                // 確認ポップに埋め込むデータは2系 fnSetConfirm 相当の出力
                // 本PRでは表示構造のみ移植し、置換ロジック (___EMAIL___ 等) は後続PRに切り出し
                $confirmLayouts[$ctaId] = $layoutTag;
            }
        }

        return [
            'template_path'   => $templatePath !== null ? (string) $templatePath : null,
            'customer_name'   => $customerName,
            'cta_buttons'     => $ctaButtons,
            'confirm_layouts' => $confirmLayouts,
        ];
    }

    /**
     * dtb_confirm_layout_detail の customize_code を rank 昇順で連結する
     * （2系 lfSetCrossModel L189-193 と同じ）
     */
    private function buildLayoutTag(int $layoutId): string
    {
        $conn = $this->em->getConnection();
        $rows = $conn->fetchAllAssociative(
            'SELECT customize_code FROM dtb_confirm_layout_detail
             WHERE layout_id = :lid ORDER BY rank',
            ['lid' => $layoutId]
        );
        $tag = '';
        foreach ($rows as $row) {
            $tag .= (string) $row['customize_code'];
        }
        return $tag;
    }
}
