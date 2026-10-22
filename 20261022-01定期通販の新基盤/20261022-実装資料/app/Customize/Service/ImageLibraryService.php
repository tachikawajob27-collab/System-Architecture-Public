<?php

declare(strict_types=1);

namespace Customize\Service;

use Doctrine\DBAL\Connection;
use Knp\Component\Pager\Pagination\PaginationInterface;
use Knp\Component\Pager\PaginatorInterface;

/**
 * チャット画像ライブラリ管理 (2系 LC_Page_Admin_Chat_Image_Library + Edit_Ex 移植).
 * dtb_image_library の検索/ページング/論理削除.
 */
class ImageLibraryService
{
    public function __construct(
        private readonly Connection $conn,
        private readonly PaginatorInterface $paginator,
    ) {}

    /**
     * 2系 doSearch 相当. brand_id スコープ + search_category_id / search_delete フィルタ + update_date DESC, id DESC.
     *
     * @param array<string, mixed> $searchData
     */
    public function getPagination(array $searchData, int $brandId, int $page, int $pageMax): PaginationInterface
    {
        $sql = 'SELECT * FROM dtb_image_library WHERE brand_id = :brand_id';
        $params = ['brand_id' => $brandId];

        if (!empty($searchData['search_category_id'])) {
            $sql .= ' AND category_id = :category_id';
            $params['category_id'] = (int) $searchData['search_category_id'];
        }

        // 2系 buildQuery 'search_delete' は「空 or 0 なら del_flg=0 を WHERE に追加」(削除表示しない)
        if (empty($searchData['search_delete'])) {
            $sql .= ' AND del_flg = 0';
        }

        $sql .= ' ORDER BY update_date DESC, id DESC';

        $rows = $this->conn->fetchAllAssociative($sql, $params);

        // ページ番号が範囲外(0以下 / 最終ページ超過)なら1ページ目に戻す。
        // 2系 SC_PageNavi::__construct (data/class/SC_PageNavi.php L59-73) と同じクランプ。
        // 2系 image_library は doSearch (L211-221) で SC_PageNavi の start_row を
        // setLimitOffset に渡すため、この補正が実際の取得行に効いていた。
        $maxPage = $pageMax > 0 ? (int) ceil(count($rows) / $pageMax) : 0;
        if ($page < 1 || ($maxPage > 0 && $page > $maxPage)) {
            $page = 1;
        }

        return $this->paginator->paginate($rows, $page, $pageMax);
    }
}
