<?php

declare(strict_types=1);

namespace Customize\Service\External;

/**
 * 外部到達ゲート (`ExternalSendGuard`) が **接続そのもの** を遮断したときに投げる例外 (Issue #3333).
 *
 * ## なぜ「戻り値」ではなく例外なのか
 *
 * 他チャネル (#3283) は「送信直前に `allow()` を挟み、遮断なら呼出元の失敗値
 * (`httpCode=0` / `STATUS_ERROR` / `false`) を返す」形を採る。しかし本チャネルが守るのは
 * **DB接続の確立**であり、呼出元から見た「失敗」は元々 **例外**である:
 *
 * - 2系: `SC_Query` は接続失敗で `die` する
 *   (`cartDev/data/class/batch/` 全体で `try {` / `catch (` / `set_exception_handler` は
 *    0ヒット — `AlertMonitoringCommand::runAlerts()` 内の既存コメントに記録済み)
 * - 4系: `Doctrine\DBAL\DriverManager` の接続失敗も例外 (`Doctrine\DBAL\Exception`)
 *
 * よって「遮断＝接続できなかった」を例外で表すのが、呼出元にとって
 * **リモートDBが落ちていた場合と完全に同型**になる。`null` を返して呼出側に
 * 「0件」を積ませると、`AlertMonitoringCommand` mode=11 の Chatwork 本文
 * 「総売上金額」が**遮断されたブランドの分だけ黙って過少になる**ため採らない
 * (#3283 の「成功を騙らない」を接続チャネルに翻訳したもの)。
 *
 * ## 呼出元がこの例外をどう扱うか (どちらも既存コードのまま = 本Issueで制御構造を変えていない)
 *
 * | 呼出元 | 既存の扱い | 遮断時の結果 |
 * |---|---|---|
 * | `SalesResultCommand::execute()` | ブランド単位の `try/catch` が既にある | そのブランドは送信JSONに**キーごと現れない**。`$failed++` で終了コード 1 |
 * | `AlertMonitoringCommand` mode=11 | `try/catch` 無し (mode=11 はブランド×カテゴリのループ外で呼ばれる) | コマンドが例外で終了する (2系の `die` と同じ「大きく鳴る」側) |
 */
final class ExternalConnectionBlockedException extends \RuntimeException
{
    public function __construct(
        public readonly string $channel,
        public readonly string $endpoint,
        string $hint,
    ) {
        parent::__construct(sprintf(
            '外部接続ブロック (Issue #3333): channel=%s endpoint=%s / %s',
            $channel,
            $endpoint,
            $hint,
        ));
    }
}
