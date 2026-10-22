<?php

declare(strict_types=1);

namespace Customize\Service\AmazonPay;

use Psr\Log\LoggerInterface;

/**
 * 受注一覧「オーソリ状況」列 (AmazonPay, payment_id=9) の表示ステータス解決 (Issue #1292)。
 *
 * 2系 `LC_Page_Admin_Order_Ex::getAmazonpayStatus()` / `statusAmazonPay()` /
 * `refundStatusAmazonPay()` (cartDev L1251-1408) の逐語移植。
 *
 * ★性能について (Issue #1292 の設計論点): 2系も `findOrders()` (L1213-1223) で
 * **表示中ページの AmazonPay 行のみ** に絞ってから API を叩いており、全123,416件を
 * 毎回叩いているわけではない。4系もページ単位(既定100件)の呼び出し件数に留まるため、
 * 2系と同じ設計(ライブ取得)を踏襲しても新たな性能問題にはならない。
 *
 * ★4系固有の対応その1: 2系はエラー時に画面全体が壊れずPHP warningで済むが、Symfonyは
 * 未捕捉例外で一覧ページ全体が500になる。一覧表示という性質上、Amazon API疎通不良で
 * 全受注が見えなくなるのは本末転倒なため、行単位でtry/catchする。
 *
 * ★4系固有の対応その2 (opusレビュー指摘): Amazon SDK (`HttpCurl`) は 429/5xx を
 * 1.0+2.2+7.0秒 sleep しながら3回リトライしてから例外を投げる。行単位try/catchのみだと
 * Amazon側が劣化している間、ページ内のAmazonPay行数(最大500件)×10.2秒ブロックし続ける。
 * 1件でも例外が発生したら以降の行は**API呼び出し自体を打ち切り**、「(取得失敗)」を即返す
 * (簡易サーキットブレーカー、1リクエスト=1インスタンスの前提でプロパティに状態を持つ)。
 *
 * ★`getId===''`(memo01/02/04/06が全て空、未オーソリ)による空文字と、API失敗による
 * 「(取得失敗)」は表示上区別できるようにしている(空欄が正常な空欄か失敗かを見分けるため)。
 *
 * ★`$circuitOpen`は1リクエスト=1インスタンス前提(本プロジェクトの実行環境は標準php-fpm/apache、
 * FrankenPHP等の長寿命ワーカーではない)。ワーカーモード導入時はリクエスト単位でのリセットが必要。
 *
 * ★2系との2点目の乖離: 2系は chargeId が空でも `substr_replace('', 'C', 20, 1)` → `'C'` を
 * そのまま `getCharge('C')` に渡してしまうが、4系は明らかに無効なIDでの無駄なAPI呼び出しを
 * 避けるため早期returnする(空文字を返す、失敗ではないため通常の空欄)。
 *
 * ★受注編集画面 (Issue #2388) からも {@see resolveForOrderView()} 経由で本クラスを利用する。
 * こちらは2系 `process()` (受注編集画面表示時) の `getCharge`/`getRefund` 呼び出しに対応し、
 * 一覧用の `resolveDisplayStatus()` とは戻り値の形も AmazonPayError の扱いも異なる
 * (詳細は各メソッドの docblock)。マッピングロジック本体 (`statusAmazonPay`/
 * `refundStatusAmazonPay`) は両者で共有し、二重実装による食い違いを避けている。
 */
class AmazonPayOrderStatusService
{
    private bool $circuitOpen = false;

    public function __construct(
        private readonly AmazonPayRequestService $amazonPayRequestService,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @param array<string, mixed> $row memo01(chargePermissionId)/memo02(chargeId)/memo04(captureId)/memo06(refundId) を含む受注行
     */
    public function resolveDisplayStatus(array $row): string
    {
        if ($this->circuitOpen) {
            return '(取得失敗)';
        }

        try {
            return $this->resolve($row);
        } catch (\Throwable $e) {
            $this->circuitOpen = true;
            $this->logger->error('[AmazonPayOrderStatusService] ステータス取得に失敗。以降の行はAPI呼び出しを打ち切ります', [
                'order_id' => $row['order_id'] ?? null,
                'message'  => $e->getMessage(),
            ]);

            return '(取得失敗)';
        }
    }

    /**
     * 受注編集画面「AmazonPay ステータス」カード向けの状態を1回のAPI照会でまとめて解決する
     * (Issue #2388)。
     *
     * 2系 `process()` (cartDev L226-281) は `getCharge()` を1回、refund_id があれば
     * `getRefund()` をもう1回呼ぶだけで、見出し `$amazonpay_status` (edit.tpl L3129
     * 「オーソリ状況 ( {$amazonpay_status} )」) と本文の分岐 (edit.tpl L3132-3141) を
     * **同じ変数**から出している。
     *
     * ★対比レビューで検出 (2026-08-21、1回目): 当初は見出し用に `resolveDisplayStatus()`、
     * ボタン用に本メソッドの前身 `canShowRefundButton()` をそれぞれ独立に呼んでおり、
     * `getCharge`/`getRefund` を2倍叩いていた。往復コストに加え、2回の照会の間に Amazon 側の
     * 状態が変わると見出しとボタンが不整合になるリスクがあったため、1回の照会結果を
     * {@see AmazonPayOrderViewStatus} にまとめ、見出し・本文・ボタン判定の全てをそこから
     * 導出するよう統合した。
     *
     * 表示分岐 (edit.tpl L3132-3141) の再現:
     * ```
     * {if $amazonpay_status=='Closed'}
     *   {if $amazonpay_refund_status=='Completed'}    返金完了 (ボタン無)
     *   {elseif $amazonpay_status=='Pending'}          返金処理中 (ボタン無) ※$amazonpay_status は
     *                                                  外側 if で既に 'Closed' 確定のため実際には
     *                                                  到達しないデッドコード (2系のバグ)
     *   {elseif $amazon_capture_id==null}              オーソリ取消済み (ボタン無)
     *   {else}                                         売上取消ボタン
     * {/if}
     * ```
     * 上記デッドコードにより、2系は **返金処理中 (RefundInitiated) の間もボタンが出続ける**
     * (Completed 以外なら常にボタン表示)。本メソッドもその挙動をそのまま踏襲する
     * (2系バグも踏襲: CLAUDE.md「2系バグも踏襲」)。二重送信自体は
     * `AmazonPayRefundService::refund()` の DB 冪等ガード (memo05/06 済みなら再送しない) が防ぐ。
     *
     * ★対比レビューで検出 (2026-08-21、1回目): 2系 `process()` L260-264 は
     * `memo01=='AmazonPayError'` のとき `statusAmazonPay()` の戻り値に関係なく
     * `$amazonpay_status` を無条件で `'オーソリ失敗'` へ上書きする
     * (`if ($amazon_charge_permission_id == "AmazonPayError") { ... } else {
     * $this->amazonpay_status = $status; }` という if/else 構造で、`$status=='Closed'` でも
     * 例外なし)。tpl の外側条件は `{if $amazonpay_status=='Closed'}` なので、AmazonPayError の
     * 受注は見出し・本文ともに必ずこの分岐を外れボタンは出ない
     * (実データ実測: `payment_id=9 AND memo01='AmazonPayError'` は12,404件、うち `memo04`
     * (captureId) が非空なのは5件のみ)。本メソッドは見出し (`status`) にもこの上書きを
     * 適用するため、`resolveDisplayStatus()`(一覧表示用、Issue #1292) で指摘されていた
     * 「見出しとボタンで AmazonPayError の扱いが食い違う」問題は本メソッドの範囲では発生しない。
     *
     * ★2系との唯一の呼び出し回数差: 2系は AmazonPayError の受注でも `getCharge()` を呼んでから
     * 結果を破棄して上書きするが、4系は表示に使われない API 呼び出しを避けるため
     * `chargePermissionId==='AmazonPayError'` を先に判定し、該当すれば `getCharge()` 自体を
     * スキップする (意図的な効率化。表示結果は2系と同一)。
     *
     * @param array<string, mixed> $row memo01/02/04/06 を含む受注行 (OrderViewService::findOrder 相当)
     */
    public function resolveForOrderView(array $row): AmazonPayOrderViewStatus
    {
        if ($this->circuitOpen) {
            return AmazonPayOrderViewStatus::empty();
        }

        try {
            return $this->doResolveForOrderView($row);
        } catch (\Throwable $e) {
            $this->circuitOpen = true;
            $this->logger->error('[AmazonPayOrderStatusService] 受注編集画面のステータス取得に失敗', [
                'order_id' => $row['order_id'] ?? null,
                'message'  => $e->getMessage(),
            ]);

            return AmazonPayOrderViewStatus::empty();
        }
    }

    private function doResolveForOrderView(array $row): AmazonPayOrderViewStatus
    {
        $chargePermissionId = (string) ($row['memo01'] ?? '');
        $chargeId  = (string) ($row['memo02'] ?? '');
        $captureId = (string) ($row['memo04'] ?? '');
        $refundId  = (string) ($row['memo06'] ?? '');

        if ($chargeId !== '') {
            $chargeId = AmazonPayRequestService::normalizeChargeIdForCancel($chargeId);
        }

        $getId = $captureId !== '' ? $captureId : $chargeId;
        if ($getId === '') {
            return AmazonPayOrderViewStatus::empty();
        }

        // 2系 process() L260-264: memo01=='AmazonPayError' は getCharge の結果に関係なく
        // 'オーソリ失敗' へ強制上書きされ、外側の Closed 分岐に入らない (ボタンも出ない)。
        // 4系は表示に使われない API 呼び出しを避けるため、この判定を getCharge より先に行う。
        if ($chargePermissionId === 'AmazonPayError') {
            return AmazonPayOrderViewStatus::create('オーソリ失敗', null, $captureId, false);
        }

        $chargeResult = $this->amazonPayRequestService->getCharge($getId);
        if ($chargeResult->skipped) {
            return AmazonPayOrderViewStatus::empty();
        }

        $status = $this->statusAmazonPay($chargeResult->getState(), $captureId, $refundId);

        // refund_status は Closed かつ refund_id が有るときだけ2系も照会する (process() L268)。
        $refundState = null;
        if ($status === 'Closed' && $refundId !== '') {
            $refundResult = $this->amazonPayRequestService->getRefund($refundId);
            if ($refundResult->skipped) {
                // 見出し(status)は既に取得済みなのでそのまま返すが、ボタンは判定不能として隠す。
                return AmazonPayOrderViewStatus::create($status, null, $captureId, false);
            }
            $refundState = $this->refundStatusAmazonPay($refundResult->getState());
        }

        // ボタン表示可否 (edit.tpl L3132-3141 の複合条件)。
        // - $status!=='Closed' : 2系の外側 if 自体に入らないため常に非表示
        // - $captureId===''    : elseif $amazon_capture_id==null (取消済みは非表示)
        // - $refundState==='Completed' : 返金完了は非表示。Pending/Declined はデッドコードにより表示のまま
        $canRefund = $status === 'Closed' && $captureId !== '' && $refundState !== 'Completed';

        return AmazonPayOrderViewStatus::create($status, $refundState, $captureId, $canRefund);
    }

    private function resolve(array $row): string
    {
        $chargePermissionId = (string) ($row['memo01'] ?? '');
        $chargeId           = (string) ($row['memo02'] ?? '');
        $captureId          = (string) ($row['memo04'] ?? '');
        $refundId           = (string) ($row['memo06'] ?? '');

        // 2系 L1264-1265: v1 対応で 21 文字目を 'A' から 'C' へ置換
        if ($chargeId !== '') {
            $chargeId = AmazonPayRequestService::normalizeChargeIdForCancel($chargeId);
        }

        $getId = $captureId !== '' ? $captureId : $chargeId;
        if ($getId === '') {
            return '';
        }

        $chargeResult = $this->amazonPayRequestService->getCharge($getId);
        if ($chargeResult->skipped) {
            return '';
        }

        $state = $chargeResult->getState();

        $status = $this->statusAmazonPay($state, $captureId, $refundId);

        if ($status === 'Closed') {
            if ($refundId !== '') {
                $refundResult = $this->amazonPayRequestService->getRefund($refundId);
                if ($refundResult->skipped) {
                    return '';
                }

                $refundStatus = $this->refundStatusAmazonPay($refundResult->getState());

                return match ($refundStatus) {
                    'Completed' => '返金完了',
                    'Pending'   => '返金処理中',
                    default     => $refundStatus,
                };
            }

            if ($captureId === '') {
                return 'オーソリ取消済み';
            }

            return $status;
        }

        if ($chargePermissionId === 'AmazonPayError') {
            return 'オーソリ失敗';
        }

        return $status;
    }

    /**
     * 2系 `statusAmazonPay()` (cartDev L1350-1386) の逐語移植。
     *
     * ```
     * AmazonCaptureIdを使用したGet Chargeの場合:
     *   Pending = CaptureInitiated / Closed = Canceled / Declined = Declined
     * CV1 Authorizationの状態とCV2 Chargeの状態の対応:
     *   Pending = AuthorizationInitiated / Open = Authorized /
     *   Closed = ReasonCodeがMaxAmountCapturedの場合はCaptured、その他は Canceled /
     *   Declined = Declined
     * ```
     */
    private function statusAmazonPay(?string $status, string $captureId, string $refundId): string
    {
        if ($captureId !== '' && $refundId === '') {
            return match ($status) {
                'CaptureInitiated' => 'Pending',
                'Captured', 'Canceled' => 'Closed',
                default => 'Declined',
            };
        }

        return match ($status) {
            'AuthorizationInitiated' => 'Pending',
            'Declined' => 'Declined',
            'Captured', 'Canceled' => 'Closed',
            default => 'Open',
        };
    }

    /**
     * 2系 `refundStatusAmazonPay()` (cartDev L1397-1408) の逐語移植。
     *
     * ```
     * Pending = RefundInitiated / Completed = Refunded / Declined = それ以外全て
     * ```
     */
    private function refundStatusAmazonPay(?string $status): string
    {
        return match ($status) {
            'RefundInitiated' => 'Pending',
            'Refunded' => 'Completed',
            default => 'Declined',
        };
    }
}
