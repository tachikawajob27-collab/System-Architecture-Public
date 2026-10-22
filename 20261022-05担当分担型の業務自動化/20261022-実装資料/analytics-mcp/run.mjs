#!/usr/bin/env node
// analytics-mcp/run.mjs — Google公式 GA4 MCPサーバー（analytics-mcp）のランチャー
//
// ローカル（Windows）でもクラウド（Ubuntu）でも同じように動かすための薄い皮。
//   1. Python venv を ~/.cache/analytics-mcp/venv に作り、analytics-mcp を入れる（初回だけ）
//   2. 認証情報を環境変数から受け取り、サーバーを stdio で起動する
//
// 認証情報（どちらか一方。ファイルにもコードにも書かない）
//   GA4_SA_KEY_B64                 サービスアカウントのJSONキーを base64 にしたもの（クラウド向け・推奨。
//                                  環境変数は .env 形式なので、JSONの引用符や改行を気にしなくて済む）
//   GA4_SA_KEY_JSON                同じくJSONキーの中身そのもの（1行のJSONならこれでも可）
//   GOOGLE_APPLICATION_CREDENTIALS JSONキー or ADC ファイルのパス（ローカル向け）
//
// venv の置き場所（上から順に採用）
//   ANALYTICS_MCP_HOME             明示指定
//   /opt/analytics-mcp             Linux で存在すれば（クラウドの Setup script が root で作る場所）
//   ~/.cache/analytics-mcp         それ以外（ローカル）
//
// ⚠️ クラウドでは MCP の接続待ちが 30 秒しかないので、venv を初回起動時に作ると間に合わない。
//    環境設定の Setup script で事前に作っておくこと（README「クラウド」参照）。
//
// 使い方
//   node analytics-mcp/run.mjs doctor      繋がるかの点検。まずこれ
//   node analytics-mcp/run.mjs install     venv を作るだけ（Setup script / 事前準備用）
//   node analytics-mcp/run.mjs             MCPサーバーとして起動（.mcp.json から呼ばれる）
//   node analytics-mcp/run.mjs --reinstall venv を作り直す

import { spawn, spawnSync } from 'node:child_process';
import { existsSync, mkdirSync, writeFileSync, rmSync } from 'node:fs';
import { homedir, tmpdir } from 'node:os';
import { join } from 'node:path';

const IS_WIN = process.platform === 'win32';
const OPT_BASE = '/opt/analytics-mcp';
const BASE = process.env.ANALYTICS_MCP_HOME
  || (!IS_WIN && existsSync(OPT_BASE) ? OPT_BASE : join(homedir(), '.cache', 'analytics-mcp'));
const VENV = join(BASE, 'venv');
const BIN = IS_WIN ? join(VENV, 'Scripts') : join(VENV, 'bin');
const PY = join(BIN, IS_WIN ? 'python.exe' : 'python');
const SERVER = join(BIN, IS_WIN ? 'analytics-mcp.exe' : 'analytics-mcp');
// 鍵の書き出し先は venv と分ける（/opt 配下が読み取り専用でも動くように）
const KEY_FILE = join(process.env.ANALYTICS_MCP_HOME || tmpdir(), 'analytics-mcp-sa-key.json');
const PROJECT_ID = process.env.GOOGLE_PROJECT_ID || 'ga4-mcp-507608';

const log = (...a) => console.error('[analytics-mcp]', ...a);

function findSystemPython() {
  for (const cand of IS_WIN ? ['python', 'py'] : ['python3', 'python']) {
    const r = spawnSync(cand, ['--version'], { encoding: 'utf8' });
    if (r.status === 0 && /Python 3\.(1[0-9]|[2-9][0-9])/.test(r.stdout + r.stderr)) return cand;
  }
  return null;
}

function ensureVenv({ reinstall = false } = {}) {
  if (reinstall && existsSync(VENV)) rmSync(VENV, { recursive: true, force: true });
  if (existsSync(SERVER)) return true;
  const sys = findSystemPython();
  if (!sys) { log('Python 3.10以上が見つかりません。python3 / python を入れてください'); return false; }
  mkdirSync(BASE, { recursive: true });
  log(`初回セットアップ: venv を作成して analytics-mcp を入れます (${VENV})`);
  let r = spawnSync(sys, ['-m', 'venv', VENV], { stdio: 'inherit' });
  if (r.status !== 0) { log('venv の作成に失敗'); return false; }
  // pip の証明書は PIP_CERT / SSL_CERT_FILE を尊重する（Norton等のTLS検査対策。fix-norton-tls.ps1 参照）
  const pipArgs = ['-m', 'pip', 'install', '--quiet', '--disable-pip-version-check'];
  const cert = process.env.PIP_CERT || process.env.SSL_CERT_FILE;
  if (cert) pipArgs.push('--cert', cert);
  r = spawnSync(PY, [...pipArgs, 'analytics-mcp'], { stdio: 'inherit' });
  if (r.status !== 0) { log('pip install analytics-mcp に失敗'); return false; }
  return existsSync(SERVER);
}

// 認証情報を整えて、サーバーに渡す環境変数を返す
function buildEnv() {
  const env = { ...process.env, GOOGLE_PROJECT_ID: PROJECT_ID, GOOGLE_CLOUD_PROJECT: PROJECT_ID, PYTHONWARNINGS: 'ignore' };
  if (!env.GOOGLE_APPLICATION_CREDENTIALS) {
    const raw = env.GA4_SA_KEY_B64 ? Buffer.from(env.GA4_SA_KEY_B64.replace(/\s+/g, ''), 'base64').toString('utf8')
              : env.GA4_SA_KEY_JSON;
    if (raw) {
      try { JSON.parse(raw); } catch { log('GA4_SA_KEY_B64 / GA4_SA_KEY_JSON がJSONとして読めません（貼り付けが欠けていないか確認）'); }
      writeFileSync(KEY_FILE, raw, { mode: 0o600 });
      env.GOOGLE_APPLICATION_CREDENTIALS = KEY_FILE;
    }
  }
  // gRPC は certifi と別の証明書ストアを見るので、SSL_CERT_FILE があればそちらにも同じものを渡す
  if (env.SSL_CERT_FILE && !env.GRPC_DEFAULT_SSL_ROOTS_FILE_PATH) env.GRPC_DEFAULT_SSL_ROOTS_FILE_PATH = env.SSL_CERT_FILE;
  return env;
}

function credentialSource(env) {
  if (process.env.GOOGLE_APPLICATION_CREDENTIALS) return `GOOGLE_APPLICATION_CREDENTIALS=${process.env.GOOGLE_APPLICATION_CREDENTIALS}`;
  if (process.env.GA4_SA_KEY_B64) return `GA4_SA_KEY_B64（${KEY_FILE} に書き出し）`;
  if (process.env.GA4_SA_KEY_JSON) return `GA4_SA_KEY_JSON（${KEY_FILE} に書き出し）`;
  return null;
}

const DOCTOR_PY = `
import json, sys
from google.analytics.admin import AnalyticsAdminServiceClient
import google.auth
creds, proj = google.auth.default()
kind = type(creds).__name__
who = getattr(creds, "service_account_email", None) or "(OAuthユーザー)"
print(f"  認証: {kind} {who}")
props = []
for s in AnalyticsAdminServiceClient().list_account_summaries():
    for p in s.property_summaries:
        props.append((p.property.split('/')[-1], s.display_name, p.display_name))
print(f"  見えるGA4プロパティ: {len(props)} 件")
if not props:
    print(f"    → 認証は通っているが、このアカウント（{who}）にGA4側の閲覧権限がまだ無い。")
    print( "      GA4管理 → アカウントのアクセス管理 → ＋ → 上のメールアドレスを「閲覧者」で追加する")
for pid, acc, name in props[:8]:
    print(f"    {pid}  {acc} / {name}")
if len(props) > 8: print(f"    ... 他 {len(props)-8} 件")
sys.exit(0 if props else 2)
`;

function doctor() {
  console.log('analytics-mcp doctor');
  const sys = findSystemPython();
  console.log(`  Python: ${sys ? 'OK (' + sys + ')' : 'NG - Python 3.10以上を入れてください'}`);
  if (!sys) return 1;
  console.log(`  venv: ${existsSync(SERVER) ? 'OK' : '未作成（今から作ります）'} ${VENV}`);
  if (!ensureVenv()) return 1;
  const env = buildEnv();
  const src = credentialSource(env);
  console.log(`  認証情報: ${src || 'NG - GA4_SA_KEY_B64 か GOOGLE_APPLICATION_CREDENTIALS を設定してください'}`);
  if (!src) {
    console.log('    クラウド: 環境設定の「環境変数」に GA4_SA_KEY_B64=<JSONキーをbase64にしたもの> を追加');
    console.log('    ローカル: GOOGLE_APPLICATION_CREDENTIALS=<JSONキーのパス> を環境変数に');
    return 1;
  }
  const r = spawnSync(PY, ['-'], { input: DOCTOR_PY, env, encoding: 'utf8' });
  process.stdout.write(r.stdout);
  if (r.status !== 0) {
    const err = (r.stderr || '').split('\n').filter(l => l && !/Warning|warnings\.warn/.test(l)).slice(-6).join('\n');
    console.log('  GA4接続: NG');
    console.log(err.replace(/^/gm, '    '));
    if (/CERTIFICATE_VERIFY_FAILED/.test(err)) console.log('    → TLS検査（Norton等）。analytics-mcp/fix-norton-tls.ps1 を実行し、SSL_CERT_FILE を設定してください');
    if (/invalid_grant/.test(err)) console.log('    → トークン失効。OAuthのADCはテストモードだと7日で切れます。サービスアカウントキーに切り替えてください');
    if (/PERMISSION_DENIED|403/.test(err)) console.log('    → GA4側でこのアカウントに閲覧権限が付いていません。GA4管理 → プロパティのアクセス管理 で追加');
    if (/SERVICE_DISABLED/.test(err)) console.log(`    → GCPプロジェクト ${PROJECT_ID} で Analytics Data API / Admin API を有効化してください`);
    return 1;
  }
  console.log('  GA4接続: OK');
  return 0;
}

const mode = process.argv[2];
if (mode === 'doctor') {
  process.exit(doctor());
}
if (!ensureVenv({ reinstall: mode === '--reinstall' })) process.exit(1);
if (mode === 'install' || mode === '--reinstall') { log(`venv 準備完了: ${VENV}`); process.exit(0); }

const child = spawn(SERVER, [], { stdio: 'inherit', env: buildEnv() });
child.on('exit', (code, sig) => process.exit(code ?? (sig ? 1 : 0)));
process.on('SIGTERM', () => child.kill('SIGTERM'));
process.on('SIGINT', () => child.kill('SIGINT'));
