<?php

declare(strict_types=1);

namespace Customize\Service\Shipping;

use Customize\Lib\Vamos\VamosClient;
use Customize\Lib\Vamos\VamosErrorCodes;
use Doctrine\DBAL\Connection;
use Eccube\Repository\BaseInfoRepository;
use Psr\Log\LoggerInterface;

/**
 * 出荷データ出力 - バモス請求書 CSV 生成 Service.
 *
 * 2系: `LC_Page_Admin_Shipping_Logi_Output_Ex::outputInvoiceVamosCSV` (L1147-1355) 完全踏襲.
 *
 * ロジック:
 *   1. `dtb_order` から出荷準備中 (order_status_id=8), payment_id=13 (VAMOS),
 *      memo07 IS NOT NULL, brand_id=X の注文を列挙。
 *      「出荷日」「出力日」いずれかの絞り込み指定があれば `dtb_shipping_ready`
 *      (`shipping_date`/`output_date`) との EXISTS 相関で追加絞り込みする (Issue #2936)
 *   2. 各注文の memo07 を va_transaction_id として VAMOS API `invoices_find` を **注文単位で 1 件ずつ呼ぶ**
 *      (2系 L1164-1166 と同じ挙動: バルクにしない)
 *   3. response.results[] を 108 列 CSV にマップ. `goods[]` を 4 列×N の可変列に展開
 *   4. response.errors[] があれば `VamosErrorCodes::convertToMessages()` で日本語化して
 *      Symfony logger (channel: `invoice_csv`) に記録. 処理は継続 (2系 L1304-1313 相当)
 *   5. maxGoodsCnt を計算してヘッダーに `商品名N,数量N,単価N,小計N` を追加
 */
class VamosInvoiceCsvService
{
    /** 2系 mdl_vamos define.php:123. */
    private const VAMOS_PAYMENT_ID = 13;

    /** 2系 outputInvoiceVamosCSV L1318 の 108 列ヘッダー. */
    public const CSV_HEADER = [
        'テンプレート', '請求書番号', '決済種別', 'Va取引ID', '請求書種別',
        '請求書発行日', '請求金額', '支払期限日', '支払期限日数', '請求案内',
        '会社名', '郵便番号', '住所', 'お問い合わせ先名', '電話番号',
        'FAX番号', 'メールアドレス', 'お問い合わせ番号', 'お問い合わせURL', '注意文言',
        '注文日', '購入店郵便番号', '購入店住所', '購入店電話番号', '購入店FAX番号',
        '購入店メールアドレス', '購入店名', '加盟店コード', '加盟店取引ID',
        '購入店情報1', '購入店情報2', '購入店情報3', '購入店情報4', '購入店情報5',
        '購入店情報6', '購入店情報7', '購入店情報8', '購入店情報9', '購入店情報10',
        '購入者郵便番号', '購入者住所', '購入者会社名', '購入者部署', '購入者名',
        '購入者電話番号', '購入者メールアドレス',
        '宛先郵便番号', '宛先住所', '宛先会社名', '宛先部署', '宛先名', '宛先電話番号',
        '収納会社名', 'バーコード', 'バーコード(1段目)', 'バーコード(2段目)',
        '収入印紙要否', '口座区分', '口座番号1', '口座番号2', '口座番号3', '加入者名',
        'OCR(1段目)', 'OCR(2段目)',
        '銀行名', '銀行名カナ', '銀行コード', '支店名', '支店名カナ', '支店コード',
        '口座種別', '口座番号', '口座名義人',
        '会員ID', 'ポイントコード', '獲得期限日', '獲得ポイント数', '保有ポイント数', 'サイトURL',
        'ポイントコード_prefix', 'ポイントコード_suffix', 'ポイントコード_注意文言',
        '獲得期限日_prefix', '獲得期限日_suffix', '獲得期限日_注意文言',
        '獲得ポイント_prefix', '獲得ポイント_suffix', '獲得ポイント_注意文言',
        '保有ポイント_prefix', '保有ポイント_suffix', '保有ポイント_注意文言',
        'サイトURL_prefix', 'サイトURL_suffix', 'サイトURL_注意文言',
        '自由記載欄1', '自由記載欄2', '自由記載欄3', '自由記載欄4', '自由記載欄5',
        '自由記載欄6', '自由記載欄7', '自由記載欄8', '自由記載欄9', '自由記載欄10',
        '加盟店自由使用欄1', '加盟店自由使用欄2', '加盟店自由使用欄3', '加盟店自由使用欄4',
    ];

    public function __construct(
        private readonly Connection $connection,
        private readonly VamosClient $vamosClient,
        private readonly BaseInfoRepository $baseInfoRepository,
        private readonly LoggerInterface $invoiceCsvLogger,
    ) {}

    /**
     * @param list<int>|null $orderIds 受注一覧から明示指定された注文ID群
     *   (2系 `outputInvoiceVamosCSV` の `$arrParams["order_ids"]` 相当)。
     *   null / 空 = 従来経路 (出荷データ出力画面)。
     * @param string|null $shippingDate 出荷データ出力画面の「出荷日」絞り込み (`Y-m-d`)。
     *   2系 `outputInvoiceVamosCSV` L1438 `!empty($arrParams["shipping_date"])` 相当 (Issue #2936)。
     * @param string|null $outputDate 出荷データ出力画面の「出力日」絞り込み (`Y-m-d`)。
     *   2系 `outputInvoiceVamosCSV` L1438/L1448 `!empty($arrParams["output_date"])` 相当 (Issue #2936)。
     *   `dtb_shipping_ready.output_date` (WMS/バモス出力時に `registShippingReady` が記録する
     *   出力実行日) との一致で絞り込む。$orderIds 指定時は 2系同様に無視される。
     *
     * ★2系は order_ids 指定時に **`status = 8` 条件を外す** (出荷準備中に限らず
     *   選択された注文の請求書を取りにいく)。空条件の分岐は
     *   `LC_Page_Admin_Shipping_Logi_Output_Ex::outputInvoiceVamosCSV`
     *   (`if (empty($arrParams["order_ids"]))` の else 側) を参照。
     *   2系にある `del_flg = 0` は 4系 `dtb_order` に del_flg 列が存在しない
     *   (2026-08-10 実測: information_schema に無い) ため落としている。
     *
     * ★2系 (`outputInvoiceVamosCSV` L1438-1454) は `shipping_date`/`output_date` のいずれかが
     *   非空のときだけ `dtb_shipping_ready` への `EXISTS` 相関サブクエリを追加する。両方空なら
     *   従来通り `dtb_order` 単体の SELECT (日付条件なし)。4系 `dtb_order.shipping_ready_id` は
     *   2系 `dtb_order.shipping_ready_id` と同じ FK 列 (`registShippingReady`/
     *   `WmsShippingCsvService::registShippingReady()` が書く)。
     *
     * @return list<list<int|string>> CSV rows (先頭がヘッダー).
     */
    public function buildRows(
        int $brandId,
        ?array $orderIds = null,
        ?string $shippingDate = null,
        ?string $outputDate = null,
    ): array {
        if ($orderIds !== null && $orderIds !== []) {
            // 2系 order_ids 経路: order_status_id フィルタ無し + id IN (...)。日付条件も無視 (2系同様)。
            $orders = $this->connection->fetchAllAssociative(
                'SELECT id AS order_id, memo07 FROM dtb_order
                 WHERE payment_id = :payment_id
                   AND id IN (:order_ids)
                   AND memo07 IS NOT NULL
                   AND brand_id = :brand_id
                 ORDER BY id',
                [
                    'payment_id' => self::VAMOS_PAYMENT_ID,
                    'order_ids'  => $orderIds,
                    'brand_id'   => $brandId,
                ],
                ['order_ids' => \Doctrine\DBAL\ArrayParameterType::INTEGER],
            );
        } elseif (($shippingDate !== null && $shippingDate !== '') || ($outputDate !== null && $outputDate !== '')) {
            // 2系 L1438-1450: shipping_date / output_date のいずれか指定時のみ
            // dtb_shipping_ready への EXISTS 相関サブクエリを追加する。
            $sql = 'SELECT o.id AS order_id, o.memo07 FROM dtb_order o
                    WHERE o.order_status_id = 8
                      AND o.payment_id = :payment_id
                      AND o.memo07 IS NOT NULL
                      AND o.brand_id = :brand_id
                      AND EXISTS (
                          SELECT NULL FROM dtb_shipping_ready dsr
                          WHERE dsr.shipping_ready_id = o.shipping_ready_id';
            $params = ['payment_id' => self::VAMOS_PAYMENT_ID, 'brand_id' => $brandId];
            if ($shippingDate !== null && $shippingDate !== '') {
                $sql .= ' AND dsr.shipping_date = :shipping_date';
                $params['shipping_date'] = $shippingDate;
            }
            if ($outputDate !== null && $outputDate !== '') {
                $sql .= ' AND dsr.output_date = :output_date';
                $params['output_date'] = $outputDate;
            }
            $sql .= ') ORDER BY o.id';
            $orders = $this->connection->fetchAllAssociative($sql, $params);
        } else {
            // 2系: 対象注文列挙 (order_status_id=8 出荷準備中, payment_id=13 VAMOS)
            $orders = $this->connection->fetchAllAssociative(
                'SELECT id AS order_id, memo07 FROM dtb_order
                 WHERE order_status_id = 8
                   AND payment_id = :payment_id
                   AND memo07 IS NOT NULL
                   AND brand_id = :brand_id
                 ORDER BY id',
                ['payment_id' => self::VAMOS_PAYMENT_ID, 'brand_id' => $brandId],
            );
        }

        $shopName = (string) ($this->baseInfoRepository->get()->getShopName() ?? '');

        $dataRows     = [];
        $maxGoodsCnt  = 0;

        foreach ($orders as $o) {
            $vaTransactionId = (string) ($o['memo07'] ?? '');
            $orderId         = (int) $o['order_id'];
            if ($vaTransactionId === '') {
                continue;
            }

            // 2系 L1165-1166: 1 件ずつ invoices_find を呼ぶ
            $response = $this->vamosClient->invoicesFind([
                ['va_transaction_id' => $vaTransactionId],
            ]);

            // 通信失敗時は無視して次へ (2系 L1167 !empty(...) の裏返し)
            if (!is_array($response) || empty($response)) {
                continue;
            }

            // results[] を CSV 行に変換 (2系 L1169-1301)
            if (!empty($response['results']) && is_array($response['results'])) {
                foreach ($response['results'] as $result) {
                    if (!is_array($result)) {
                        continue;
                    }
                    $row      = $this->buildRowFromResult($result);
                    $goods    = is_array($result['goods'] ?? null) ? $result['goods'] : [];
                    $goodsCnt = count($goods);

                    // 商品情報を 4 列×N で展開 (2系 L1285-1293)
                    foreach ($goods as $item) {
                        if (!is_array($item)) {
                            continue;
                        }
                        $row[] = (string) ($item['goods_name']  ?? '');
                        $row[] = (string) ($item['quantity']    ?? '');
                        $row[] = (string) ($item['goods_price'] ?? '');
                        $row[] = (string) ($item['subtotal']    ?? '');
                    }

                    if ($goodsCnt > $maxGoodsCnt) {
                        $maxGoodsCnt = $goodsCnt;
                    }
                    $dataRows[] = $row;
                }
            }

            // errors[] があれば invoice_csv.log に書き出し (2系 L1304-1313)
            if (!empty($response['errors']) && is_array($response['errors'])) {
                foreach ($response['errors'] as $errorBlock) {
                    if (!is_array($errorBlock)) {
                        continue;
                    }
                    $messages = VamosErrorCodes::convertToMessages(
                        $errorBlock,
                        adminError: true,
                        shopName: $shopName,
                    );
                    if (empty($messages)) {
                        continue;
                    }
                    $errMessage = implode('　', $messages); // 2系 L1308: 全角スペース区切り
                    $this->invoiceCsvLogger->info('注文番号：' . $orderId);
                    $this->invoiceCsvLogger->info('エラー内容：' . $errMessage);
                    $this->invoiceCsvLogger->info('------------------------------------');
                }
            }
        }

        // ヘッダー組立 (2系 L1318-1328): 108 列 + 商品情報 可変列
        $header = self::CSV_HEADER;
        for ($i = 1; $i <= $maxGoodsCnt; $i++) {
            $header[] = '商品名' . $i;
            $header[] = '数量'   . $i;
            $header[] = '単価'   . $i;
            $header[] = '小計'   . $i;
        }

        return array_merge([$header], $dataRows);
    }

    /**
     * 2系 L1173-1282 の 108 列マップ (byte-for-byte 順序踏襲).
     *
     * @param array<string, mixed> $result invoices_find response の 1 件
     * @return list<int|string> 108 列の基本情報行 (商品列は含まない)
     */
    private function buildRowFromResult(array $result): array
    {
        $billed        = $this->arr($result, 'billed_info');
        $biller        = $this->arr($result, 'biller');
        $shop          = $this->arr($result, 'shop');
        $customer      = $this->arr($result, 'customer');
        $dest          = $this->arr($result, 'dest_customer');
        $cvs           = $this->arr($result, 'cvs_receipt_info');
        $post          = $this->arr($result, 'post_receipt_info');
        $bank          = $this->arr($result, 'bank_receipt_info');
        $point         = $this->arr($result, 'point_info');

        return [
            '0',                                                    // テンプレート (2系 L1174: 定数 "0")
            (string) ($billed['invoice_number']       ?? ''),       // 請求書番号
            (string) ($billed['settlement_name']      ?? ''),       // 決済種別
            (string) ($result['va_transaction_id']    ?? ''),       // VA取引ID
            (string) ($billed['billed_type']          ?? ''),       // 請求書種別
            (string) ($billed['bill_issued_date']     ?? ''),       // 請求書発行日
            (string) ($billed['billed_amount']        ?? ''),       // 請求金額
            (string) ($billed['receipt_limit_date']   ?? ''),       // 支払期限
            (string) ($billed['receipt_limit_days']   ?? ''),       // 支払期限日数
            (string) ($billed['billed_information']   ?? ''),       // 請求案内
            (string) ($biller['biller_name']          ?? ''),       // 会社名（販売元）
            (string) ($biller['zip_code']             ?? ''),       // 郵便番号（販売元）
            (string) ($biller['address']              ?? ''),       // 住所（販売元）
            (string) ($biller['information_name']     ?? ''),       // お問合せ先名
            (string) ($biller['tel']                  ?? ''),       // 電話番号（販売元）
            (string) ($biller['fax']                  ?? ''),       // FAX番号
            (string) ($biller['email']                ?? ''),       // メールアドレス
            (string) ($biller['information_number']   ?? ''),       // お問合せ番号
            (string) ($biller['site_url']             ?? ''),       // お問合せURL
            (string) ($biller['attention']            ?? ''),       // 注意文言
            (string) ($shop['shop_order_date']        ?? ''),       // 注文日（購入店）
            (string) ($shop['zip_code']               ?? ''),       // 郵便番号（購入店）
            (string) ($shop['address']                ?? ''),       // 住所（購入店）
            (string) ($shop['tel']                    ?? ''),       // 電話番号（購入店）
            (string) ($shop['fax']                    ?? ''),       // FAX番号（購入店）
            (string) ($shop['email']                  ?? ''),       // メールアドレス（購入店）
            (string) ($shop['shop_name']              ?? ''),       // 購入店名
            (string) ($shop['shop_code']              ?? ''),       // 加盟店コード
            (string) ($shop['shop_transaction_id']    ?? ''),       // 加盟店取引ID
            '', '', '', '', '', '', '', '', '', '',                 // 購入店情報1-10 (2系 L1203-1212)
            (string) ($customer['zip_code']           ?? ''),       // 郵便番号（購入者）
            (string) ($customer['address']            ?? ''),       // 住所（購入者）
            (string) ($customer['company_name']       ?? ''),       // 会社名（購入者）
            (string) ($customer['department_name']    ?? ''),       // 部署名（購入者）
            (string) ($customer['customer_name']      ?? ''),       // 購入者名
            (string) ($customer['tel']                ?? ''),       // 電話番号（購入者）
            (string) ($customer['email']              ?? ''),       // メールアドレス（購入者）
            (string) ($dest['zip_code']               ?? ''),       // 郵便番号（配送先）
            (string) ($dest['address']                ?? ''),       // 住所（配送先）
            (string) ($dest['company_name']           ?? ''),       // 会社名（配送先）
            (string) ($dest['department_name']        ?? ''),       // 部署名（配送先）
            (string) ($dest['customer_name']          ?? ''),       // 購入者名（配送先）
            (string) ($dest['tel']                    ?? ''),       // 電話番号（配送先）
            (string) ($result['receipt_company_name'] ?? ''),       // 収納会社名
            (string) ($cvs['barcode']                 ?? ''),       // バーコード
            (string) ($cvs['barcode1']                ?? ''),       // バーコード(1段目)
            (string) ($cvs['barcode2']                ?? ''),       // バーコード(2段目)
            (string) ($cvs['stamp_tax_print']         ?? ''),       // 収入印紙要否
            (string) ($post['account_kind']           ?? ''),       // 口座区分
            (string) ($post['account_number1']        ?? ''),       // 口座番号1
            (string) ($post['account_number2']        ?? ''),       // 口座番号2
            (string) ($post['account_number3']        ?? ''),       // 口座番号3
            (string) ($post['account_name']           ?? ''),       // 加入者名
            (string) ($post['ocr1']                   ?? ''),       // OCR1
            (string) ($post['ocr2']                   ?? ''),       // OCR2
            (string) ($bank['bank_name']              ?? ''),       // 銀行名
            (string) ($bank['bank_name_kana']         ?? ''),       // 銀行名カナ
            (string) ($bank['bank_code']              ?? ''),       // 銀行コード
            (string) ($bank['branch_name']            ?? ''),       // 支店名
            (string) ($bank['branch_name_kana']       ?? ''),       // 支店名カナ
            (string) ($bank['branch_code']            ?? ''),       // 支店コード
            (string) ($bank['account_kind_name']      ?? ''),       // 口座種別
            (string) ($bank['account_number']         ?? ''),       // 口座番号
            (string) ($bank['account_name']           ?? ''),       // 口座名義人
            (string) ($point['member_id']             ?? ''),       // 会員ID
            (string) ($point['point_code']            ?? ''),       // ポイントコード
            (string) ($point['limit_date']            ?? ''),       // 獲得期限日
            (string) ($point['get_point']             ?? ''),       // 獲得ポイント数
            (string) ($point['hold_point']            ?? ''),       // 保有ポイント数
            (string) ($point['site_url']              ?? ''),       // サイトURL
            (string) ($point['point_code_prefix']     ?? ''),       // ポイントコードprefix
            (string) ($point['point_code_suffix']     ?? ''),       // ポイントコードsuffix
            (string) ($point['point_code_attention']  ?? ''),       // ポイントコード注意文言
            (string) ($point['limit_date_prefix']     ?? ''),       // 獲得期限日prefix
            (string) ($point['limit_date_suffix']     ?? ''),       // 獲得期限日suffix
            (string) ($point['limit_date_attention']  ?? ''),       // 獲得期限日注意文言
            (string) ($point['get_point_prefix']      ?? ''),       // 獲得ポイントprefix
            (string) ($point['get_point_suffix']      ?? ''),       // 獲得ポイントsuffix
            (string) ($point['get_point_attention']   ?? ''),       // 獲得ポイント注意文言
            (string) ($point['hold_point_prefix']     ?? ''),       // 保有ポイントprefix
            (string) ($point['hold_point_suffix']     ?? ''),       // 保有ポイントsuffix
            (string) ($point['hold_point_attention']  ?? ''),       // 保有ポイント注意文言
            (string) ($point['site_url_prefix']       ?? ''),       // サイトURLprefix
            (string) ($point['site_url_suffix']       ?? ''),       // サイトURLsuffix
            (string) ($point['site_url_attention']    ?? ''),       // サイトURL注意文言
            '', '', '', '', '', '', '', '', '', '',                 // 自由記載欄1-10 (2系 L1268-1277)
            '', '', '', '',                                          // 加盟店自由使用欄1-4 (2系 L1278-1281)
        ];
    }

    /**
     * @param array<string, mixed> $result
     * @return array<string, mixed>
     */
    private function arr(array $result, string $key): array
    {
        $val = $result[$key] ?? [];
        return is_array($val) ? $val : [];
    }
}
