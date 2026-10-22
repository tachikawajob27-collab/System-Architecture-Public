<?php

namespace Customize\Service;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;

/**
 * 管理画面：ブランド注釈設定 (2系 LC_Page_Admin_Basis_BrandAnnotation 移植).
 * 1ブランド = 1レコード (PK=brand_id) で 8つの注釈テキストを保持.
 */
class BrandAnnotationService
{
    private const ANNOTATION_COLUMNS = [
        'payment_annotation',
        'cregit_annotation',
        'deliverygoods_annotation_teiki',
        'deliverygoods_annotation_onetime',
        'cancel_annotation',
        'returngoods_annotation',
        'refund_annotation',
        'service_annotation',
    ];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {}

    private function conn(): Connection
    {
        return $this->entityManager->getConnection();
    }

    /**
     * ブランド注釈データ取得 (2系 SC_Helper_Brand::getAnnotation).
     */
    public function getAnnotation(int $brandId): ?array
    {
        $row = $this->conn()->fetchAssociative(
            'SELECT * FROM dtb_brand_annotation WHERE brand_id = :brand_id',
            ['brand_id' => $brandId],
        );
        return $row === false ? null : $row;
    }

    /**
     * ブランド情報取得 (2系 SC_Helper_Brand::get).
     */
    public function getBrand(int $brandId): ?array
    {
        $row = $this->conn()->fetchAssociative(
            'SELECT id, name FROM dtb_brand WHERE id = :id AND del_flg = 0',
            ['id' => $brandId],
        );
        return $row === false ? null : $row;
    }

    /**
     * upsert (2系 lfRegistData: count → INSERT or UPDATE, トランザクション付き).
     */
    public function save(int $brandId, array $data, int $memberId): void
    {
        $conn = $this->conn();
        $conn->beginTransaction();
        try {
            $vals = [];
            foreach (self::ANNOTATION_COLUMNS as $col) {
                $vals[$col] = (string) ($data[$col] ?? '');
            }
            $vals['member_id']   = $memberId;
            $vals['update_date'] = date('Y-m-d H:i:s');

            $exists = (int) $conn->fetchOne(
                'SELECT COUNT(*) FROM dtb_brand_annotation WHERE brand_id = :brand_id',
                ['brand_id' => $brandId],
            );
            if ($exists === 0) {
                $vals['brand_id']    = $brandId;
                $vals['create_date'] = date('Y-m-d H:i:s');
                $conn->insert('dtb_brand_annotation', $vals);
            } else {
                $conn->update('dtb_brand_annotation', $vals, ['brand_id' => $brandId]);
            }
            $conn->commit();
        } catch (\Throwable $e) {
            $conn->rollBack();
            throw $e;
        }
    }

    /**
     * @return string[]
     */
    public static function annotationColumns(): array
    {
        return self::ANNOTATION_COLUMNS;
    }
}
