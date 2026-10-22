<?php

namespace Customize\Service;

use Customize\Entity\CategoryGroup;
use Customize\Repository\CategoryGroupRepository;
use Doctrine\ORM\EntityManagerInterface;
use Eccube\Repository\CategoryRepository;

/**
 * カテゴリグループ管理のビジネスロジック
 * (2系 SC_Helper_Category_Ex::getCategoryGroupList/getCategoryGroup/checkCategoryGroup/
 *  saveGroup/deleteGroup 移植, Issue #821)。
 *
 * 2系の挙動をそのまま踏襲:
 *   - 一覧は brand_id スコープ + del_flg=0 + category_group_id ASC
 *   - 削除ブロック判定は dtb_category.category_group_id の紐付け有無 (del_flg=0 のみ)
 *   - 新規登録時のみ brand_id を確定 (セッションの現在ブランド)。編集時は brand_id を変更しない
 *   - ★削除は del_flg 更新でなく実レコード DELETE
 *     (2系 deleteGroup が `$objQuery->delete("dtb_category_group", ...)` のハードデリートのため。
 *      一覧の取得条件に del_flg=0 はあるが、削除処理自体はソフトデリートではない点に注意)
 */
class CategoryGroupService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly CategoryGroupRepository $categoryGroupRepository,
        private readonly CategoryRepository $categoryRepository,
    ) {
    }

    /**
     * カテゴリグループ一覧取得 (2系 getCategoryGroupList 相当)。
     *
     * @return CategoryGroup[]
     */
    public function findListByBrand(int $brandId): array
    {
        return $this->categoryGroupRepository->findBy(
            ['del_flg' => 0, 'brand_id' => $brandId],
            ['category_group_id' => 'ASC']
        );
    }

    /**
     * カテゴリグループ情報取得 (2系 getCategoryGroup 相当)。
     * ★2系同様、brand_id/del_flgでは絞らない (category_group_id のみで取得)。
     */
    public function findById(int $id): ?CategoryGroup
    {
        return $this->categoryGroupRepository->find($id);
    }

    /**
     * 削除対象グループに紐付くカテゴリの有無 (2系 checkCategoryGroup 相当)。
     * true の場合は削除をブロックする。
     *
     * ★Eccube\Entity\Category に del_flg 列は存在しない (4系はカテゴリを物理削除する設計)。
     *   del_flg 条件を付けると count() が例外を投げ削除操作自体が 500 になるため、
     *   category_group_id のみで判定する (レビュー指摘、実測で4系 dtb_category=18件が
     *   2系の有効カテゴリ数18件と一致することを確認済み)。
     */
    public function hasLinkedCategories(int $categoryGroupId): bool
    {
        return $this->categoryRepository->count([
            'category_group_id' => $categoryGroupId,
        ]) > 0;
    }

    /**
     * カテゴリグループ登録/更新 (2系 saveGroup 相当)。
     *
     * @param array<string, mixed> $data
     */
    public function save(array $data, int $brandId, ?int $categoryGroupId): int
    {
        $now = new \DateTime();

        if ($categoryGroupId === null) {
            // 新規登録: brand_id をセッションの現在ブランドで確定
            $CategoryGroup = new CategoryGroup();
            $CategoryGroup->setBrandId($brandId);
            $CategoryGroup->setDelFlg(0);
            $CategoryGroup->setCreateDate($now);
        } else {
            // 更新: brand_id は変更しない (2系 saveGroup は更新時 brand_id を書き換えない)
            $CategoryGroup = $this->categoryGroupRepository->find($categoryGroupId);
            if ($CategoryGroup === null) {
                throw new \RuntimeException(sprintf('CategoryGroup (id=%d) not found', $categoryGroupId));
            }
        }

        $CategoryGroup->setCategoryGroupName((string) ($data['category_group_name'] ?? ''));
        $lpPath = $data['lp_path'] ?? null;
        $CategoryGroup->setLpPath($lpPath !== null && $lpPath !== '' ? (string) $lpPath : null);
        $logoPath = $data['logo_path'] ?? null;
        $CategoryGroup->setLogoPath($logoPath !== null && $logoPath !== '' ? (string) $logoPath : null);
        $CategoryGroup->setDisplayFlg((int) ($data['display_flg'] ?? 0));
        $CategoryGroup->setUpdateDate($now);

        $this->entityManager->persist($CategoryGroup);
        $this->entityManager->flush();

        return $CategoryGroup->getCategoryGroupId();
    }

    /**
     * カテゴリグループ削除 (2系 deleteGroup 相当)。
     * ★実レコード DELETE (del_flg 更新ではない)。呼び出し側で hasLinkedCategories() を
     * 事前チェックし、削除をブロックすべきかどうかを判定すること。
     */
    public function delete(int $categoryGroupId): void
    {
        $CategoryGroup = $this->categoryGroupRepository->find($categoryGroupId);
        if ($CategoryGroup === null) {
            return;
        }
        $this->entityManager->remove($CategoryGroup);
        $this->entityManager->flush();
    }
}
