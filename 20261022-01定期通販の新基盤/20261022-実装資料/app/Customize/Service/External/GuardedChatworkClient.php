<?php

declare(strict_types=1);

namespace Customize\Service\External;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\ResponseInterface;

/**
 * Chatwork 通知用の HTTP 送信口. `ExternalSendGuard` が許可したときだけ実送信する.
 *
 * `ChatWorkNotificationService` には `$this->httpClient->post(...)` が **10箇所** あり,
 * 呼出側を1つずつ書き換えると1箇所の付け忘れが即「本番Chatworkへ実送信」になる.
 * そこで **`post()` という単一の入口**を持つ薄いラッパを注入し, ここを1つ塞ぐことで
 * 現在の11箇所も将来追加される箇所も自動的に対象になるようにした (Issue #3283).
 *
 * ★継承ではなく委譲にしている理由: `GuzzleHttp\Client` は phpDoc で `@final` が付いており,
 * 継承すると PHPStan が `class.extendsFinalByPhpDoc` で落ちる (CI 実測 2026-09-02).
 *
 * ★2系にこの仕組みは無い (`SC_Utils::sendChatwork` は無条件で cURL する).
 * 検証環境が本番DBのコピー = `dtb_base_info.chat_work_apikey` / `chat_work_apikey2` が
 * **本番の実APIキー** (2026-09-02 実測: どちらも32桁の値が入っている) であるという,
 * 2系には無かった前提に対する意図的な追加. 許可時の挙動は 2系と完全に一致する.
 *
 * 遮断時は例外ではなく HTTP 599 (非標準 = ネットワーク層エラーの慣用値) のレスポンスを返す.
 * 呼出側 10箇所はすべて `try/catch` + `http_errors => false` 前提で「2xx かどうか」で成否を
 * 判定しているため, 599 は正しく失敗として扱われ「送信できていないのに成功扱い」にはならない.
 * 遮断理由そのものは `ExternalSendGuard::allow()` が `[外部送信ブロック]` として同時にログへ出す.
 */
class GuardedChatworkClient
{
    // ★`final` を付けていない。`ChatWorkNotificationDedupFileStateTest` が
    //   `createStub(GuardedChatworkClient::class)` でダブルを作るため
    //   (PHPUnit は final クラスをダブルにできない)。

    /** 遮断時に返すステータス (RFC 非定義。cURL 失敗相当を意味する慣用値). */
    public const BLOCKED_STATUS = 599;

    public function __construct(
        private readonly ExternalSendGuard $externalSendGuard,
        private readonly GuzzleClient $httpClient,
    ) {}

    /**
     * Chatwork へ POST する. 遮断されている場合は実送信せず 599 を返す.
     *
     * 引数は `GuzzleHttp\Client::post()` と同一 (呼出側を書き換えずに差し替えられるように).
     *
     * @param array<string, mixed> $options
     */
    public function post(string $uri, array $options = []): ResponseInterface
    {
        if (!$this->externalSendGuard->allow(
            ExternalSendGuard::CHANNEL_CHATWORK,
            $uri,
            ['caller' => 'GuardedChatworkClient::post'],
        )) {
            return new Response(
                self::BLOCKED_STATUS,
                [],
                '外部送信ブロック (Issue #3283): ' . ExternalSendGuard::CHANNEL_CHATWORK,
            );
        }

        return $this->httpClient->post($uri, $options);
    }
}
