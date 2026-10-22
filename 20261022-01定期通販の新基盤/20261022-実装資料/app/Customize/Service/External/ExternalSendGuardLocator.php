<?php

declare(strict_types=1);

namespace Customize\Service\External;

use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;

/**
 * 同梱SDK (DI が届かない場所) から `ExternalSendGuard` を引くための静的ロケータ (Issue #3332).
 *
 * ## なぜ必要か — Paygent / AmazonPay は「送信が同梱SDKの中」にある
 *
 * 他チャネルは Service 層 (`VamosClient::request()` / `GuardedChatworkClient::post()` …) に
 * 送信口があり `ExternalSendGuard` をコンストラクタ注入できるが、決済2社は違う:
 *
 * | 決済 | 実際に送る場所 | 生成箇所 |
 * |---|---|---|
 * | Paygent | `HttpsRequestSender::send()` L469 `curl_exec` | `PaygentB2BModule::post()` L406 `new HttpsRequestSender($url)` |
 * | AmazonPay | `HttpCurl::execute()` L95 `curl_exec` (`httpSend()` 経由) | `Client::apiCall()` L494 `new HttpCurl()` |
 *
 * どちらも **SDK が SDK 自身の中で `new` する**ので、コンテナから引数を差し込む余地が無い。
 * SDK はグローバル namespace (`HttpsRequestSender`) / `include_once` ベースで、
 * PHPStan の `excludePaths` 対象でもある (`phpstan.neon`)。
 *
 * ## 却下した案
 *
 * - **呼出側 (Service 層) を列挙して塞ぐ**: `new \PaygentB2BModule()` は 11箇所、
 *   AmazonPay の `createClient()` は 2箇所ある。手で維持する列挙は必ず母集団とズレる
 *   (memory `feedback_registry_vs_population_gap`。#3332 の起票文自体が AmazonPay を
 *   「1箇所」と書いて実際は2箇所という列挙漏れを起こしている)。**採らない。**
 * - **SDK クラスを継承して差し替える**: 生成が SDK 内部の `new` なので、
 *   結局 SDK 本体 (`PaygentB2BModule` / `Client`) を書き換える必要があり、
 *   書き換え範囲が「送信直前の1箇所」より広くなる。
 * - **`ExternalSendGuard` のコンストラクタから自分を登録する**: 登録は確実だが、
 *   「決済より前に誰かが `ExternalSendGuard` を必要とする」保証が無い
 *   (他の利用者は全て遅延生成のイベント購読者/コマンド)。
 *
 * ## 採った形 — 「注入された物を使う。無ければ同じ設定で自前に組む」
 *
 * `ExternalSendGuardLocatorSubscriber` が `kernel.request` / `console.command` で
 * DI 生成のインスタンスを `set()` する (HTTP 経路と CLI 経路の両方の入口)。
 *
 * ★**それでも `set()` されていない経路が万一あっても判定は変わらない**。
 * `get()` は未設定なら `ExternalSendGuard` を**自前で組んで**返す。
 * `ExternalSendGuard` の依存は「ロガー」と「project_dir」だけで、
 * ポリシーの解決 (環境変数 → `app/config/eccube/external_send_policy` → 既定 off) は
 * インスタンスに依らず同じ入力を見るため、**DI 経由でも自前でも同じ結論**になる。
 * 違うのはログの出口だけ (DI なら monolog、自前なら `error_log()`)。
 *
 * ＝「ロケータが未初期化だから素通り (fail-open)」も
 * 「未初期化だから全遮断 (fail-close)」も起きない。どちらもポリシー無視の事故になるので採らない。
 */
final class ExternalSendGuardLocator
{
    /** DI 生成のインスタンス (Subscriber が入れる). */
    private static ?ExternalSendGuard $guard = null;

    /** `set()` されなかった場合に組む代替 (組み直しを避けるため保持). */
    private static ?ExternalSendGuard $fallback = null;

    /**
     * DI 生成の `ExternalSendGuard` を登録する.
     *
     * `null` を渡すと登録解除 (テスト用).
     */
    public static function set(?ExternalSendGuard $guard): void
    {
        self::$guard = $guard;
    }

    /**
     * 現在有効な `ExternalSendGuard` を返す (未登録なら同じ設定で組んだ代替).
     */
    public static function get(): ExternalSendGuard
    {
        if (self::$guard !== null) {
            return self::$guard;
        }

        if (self::$fallback === null) {
            self::$fallback = new ExternalSendGuard(self::fallbackLogger(), self::guessProjectDir());
        }

        return self::$fallback;
    }

    /**
     * 送信直前のゲート. 同梱SDK からはこれを呼ぶ.
     *
     * @param string               $channel  `ExternalSendGuard::CHANNEL_PAYMENT_*`
     * @param string               $endpoint 送信先URL
     * @param array<string, mixed> $context  ログに添える追加情報 (資格情報を入れないこと)
     */
    public static function allow(string $channel, string $endpoint, array $context = []): bool
    {
        return self::get()->allow($channel, $endpoint, $context);
    }

    /**
     * project_dir を自力で求める.
     *
     * 本ファイルは `<project_dir>/app/Customize/Service/External/` に在るので4階層上.
     * (`%kernel.project_dir%` と同じ値になる。DI 経由なら Subscriber がそちらを渡す)
     */
    private static function guessProjectDir(): string
    {
        return dirname(__DIR__, 4);
    }

    /**
     * 代替ロガー. monolog が無い経路でも「遮断したこと」を必ず残す
     * (黙って握り潰さないのが `ExternalSendGuard` の原則なので、NullLogger は使わない).
     */
    private static function fallbackLogger(): LoggerInterface
    {
        return new class extends AbstractLogger {
            /**
             * @param mixed              $level
             * @param string|\Stringable $message
             * @param array<mixed>       $context
             */
            public function log($level, $message, array $context = []): void
            {
                $encoded = json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                error_log(sprintf(
                    '[ExternalSendGuard/%s] %s %s',
                    (string) $level,
                    (string) $message,
                    $encoded === false ? '' : $encoded,
                ));
            }
        };
    }
}
