<?php

declare(strict_types=1);

namespace Customize\Service\External;

use Eccube\Service\PluginApiService;

/**
 * コア `PluginApiService` の外部通信を `ExternalSendGuard` に載せるサブクラス (Issue #3337).
 *
 * ## 何本塞げるのか — 数え直した結果 (2026-09-10 実測)
 *
 * `src/Eccube` 全体で `curl_init` / `curl_exec` は **`PluginApiService::requestApi()` の1箇所だけ**
 * (`grep -rn "curl_init\|curl_exec" src/Eccube` → 2行、いずれも同メソッド)。
 * つまりコア発の HTTP は本当にここに集約されている。そこを通る **エンドポイントは9本**:
 *
 * `getCategory()` `/v43/category` / `getPlugins()` `/v43/plugins` /
 * `getPurchased()` `/v43/plugins/purchased` / `getRecommended()` `/v43/plugins/recommended` /
 * `getPlugin()` `/v43/plugin/{id}` / `updatePluginStatus()` ×4
 * `/v43/status/{installed,enabled,disabled,uninstalled}` (**POST**)。
  * 呼出サイトは11箇所 (`OwnerStoreController` 4 / `Admin\Store\PluginController` 1 /
 * コア `AdminController` 1 / `PluginService` 4 / `ComposerRequireAlreadyInstalledPluginsCommand` 1)。
 *
 * ★#3322 / PR #3354 が塞いだのは `getRecommended()` の **Customize 側の呼出1箇所だけ**で、
 * コア `AdminController::index()` の呼出も残る8本も残っていた。
 * 本クラスは `requestApi()` を1つ override するだけで **9本すべて**を同時に塞ぐ。
 *
 * ## なぜコア (`src/Eccube`) を書き換えないのか
 *
 * CLAUDE.md の再同期手順を1回踏むと**足した行が黙って消え**、
 * `scripts/audit/verify_core_sync.sh` が恒久的に NG になる (赤を消す案内が
 * この修正を巻き戻すコマンドになる)。`MypagePasswordChangeAccessMap` と同じ判断。
 * 代わりに services.yaml でサービスIDを本クラスへエイリアスする
 * (`Eccube\Service\CsvExportService` → `LegacyCsvExportService` と同じ手口。
 *  `PluginApiService` はタグを持たない素のサービスなので alias が使える)。
 * 注入先6箇所はすべて具象クラス type-hint だが、本クラスはサブクラスなので型互換。
 *
 * ## ★コンストラクタを持たない理由
 *
 * 親のコンストラクタは `private readonly` の昇格プロパティ4本を取る。サブクラスで
 * `parent::__construct(...)` を書くと **コアの署名が変わった瞬間に黙って壊れる**
 * (`php -l` も CI も検知できない型の壊れ方)。よって `ExternalSendGuard` は DI ではなく
 * `ExternalSendGuardLocator` から引く。ロケータは未登録でも同じ入力
 * (環境変数 / `app/config/eccube/external_send_policy`) を読むので判定はDI経由と一致する。
 *
 * ## ★遮断時に例外を投げない
 *
 * 親の `requestApi()` は失敗時 `PluginApiException` を投げる設計だが、本クラスは
 * **投げずに空の応答を返す**。`ExternalSendGuard` の既定 (mail / 決済系ほか) と揃えるため。
 * 返す JSON は呼出元ごとに違う形が要るので `blockedPayload()` が URL で出し分ける
 * (全部 `'[]'` にすると `getPlugin()` → `buildInfo()` → `supportedVersion()` の
 * `in_array(..., $plugin['supported_versions'])` が TypeError になり管理画面が500になる)。
 *
 * ## 2系との関係
 *
 * 2系にプラグインストアの概念そのものが無い (#2433 / #3322)。`dtb_plugin` は実測0件、
 * `app/Plugin` は `.gitkeep` のみ。よって遮断しても2系比較で失われる挙動は無い。
 */
final class GuardedPluginApiService extends PluginApiService
{
    /**
     * 外部通信の唯一の出口 (`curl_exec` はコア全体でこのメソッド内の1箇所だけ).
     *
     * @param array<string, mixed> $data
     */
    #[\Override]
    public function requestApi(string $url, array $data = [], bool $post = false): string|bool
    {
        $allowed = ExternalSendGuardLocator::allow(
            ExternalSendGuard::CHANNEL_ECCUBE_STORE,
            $url,
            ['method' => $post ? 'POST' : 'GET'],
        );

        if ($allowed) {
            return parent::requestApi($url, $data, $post);
        }

        return self::blockedPayload($url);
    }

    /**
     * 遮断時に返す「通信はしたが中身が空だった」相当の JSON.
     *
     * ★呼出元ごとに要求する形が違うので URL で出し分ける。根拠は
     * `PluginApiService` の各メソッドとその呼出元 (2026-09-10 に行単位で確認):
     *
     * - `/category`            … `OwnerStoreController:91` `json_decode` → `array_column()` → 空配列
     * - `/plugins/{purchased,recommended}` … `buildPlugins(array &$plugins)` が **配列必須**
     * - `/plugins`             … `OwnerStoreController:151-152` が `$data['total']` `$data['plugins']` を読む
     * - `/plugin/{id}`         … `buildInfo()` → `supportedVersion()` が `$plugin['supported_versions']` を
     *                            `in_array()` に渡す。キーが無いと **TypeError で500**
     * - `/status/*` (POST)     … `updatePluginStatus()` が戻り値を捨てるので何でもよい
     *
     * ★`public` なのは単体テスト (`GuardedPluginApiServiceTest`) から呼ぶため。
     *   テストできない形にすると「呼出元が壊れないこと」を外部通信なしに確認できなくなる。
     */
    public static function blockedPayload(string $url): string
    {
        $path = parse_url($url, PHP_URL_PATH);
        $path = is_string($path) ? $path : $url;

        return match (true) {
            str_contains($path, '/plugins/purchased'),
            str_contains($path, '/plugins/recommended') => '[]',
            str_ends_with($path, '/plugins') => '{"total":0,"plugins":[]}',
            str_contains($path, '/plugin/') => '{"id":0,"code":"","name":"","version":"","supported_versions":[],"purchased":false}',
            default => '[]',
        };
    }
}
