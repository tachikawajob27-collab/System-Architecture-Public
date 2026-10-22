// 新規レポートのMCP版（2026-10-20〜並走）: Dropbox の AI用CSV（asptotal_ai_*.csv）の代わりに、同じ中身を eccube-mcp から取ってファイルに書く。
// 使い方: ECCUBE_MCP_TOKEN=<鍵> node shinki-report/mcp-csv.mjs --today YYYY-MM-DD --out <書き出すCSV> [--category 12]
//   --today は「その日に管理画面で AI用CSV を押した」とみなす日（JST）。過去の日を渡すと、その日の CSV と同じ範囲（8日前〜前日）を今の DB の値で返す。
// 書き出すファイルは UTF-8（先頭に BOM）。calc.mjs は BOM を見て UTF-8 として読む（Dropbox の CSV は Shift-JIS のまま読める）。
//
// 出力（JSON）:
//   lastDate   CSV の最終日付（--today の前日のはず）
//   updatedAt  見出しの「集計表更新日時」（例「10月20日 13時04分」）、updater は「集計表更新者」
//   monthlyTarget  最終日付の月の獲得目標（管理画面「月獲得目標設定」。メンズは月ごとに違う）。取れなければ null
//   ready      今日分として使ってよいか。最終日付が --today の前日 かつ 集計表更新日時が --today の日付 かつ 更新者が SYSTEM でない
//              （04:00 のバッチが SYSTEM で前日分を作り、そのあと担当者が広告費を登録・集計表を更新する。2026-10-14〜19 の dtb_asp_total で確認。
//               Dropbox 版では「CSV が置かれた＝入力が終わった」の合図だった。その代わり）
import fs from 'node:fs';
import { callEccubeTool } from './mcp-client.mjs';

const arg = (k, d = null) => { const i = process.argv.indexOf(k); return i > 0 ? process.argv[i + 1] : d; };
const today = arg('--today') ?? new Date(Date.now() + 9 * 3600e3).toISOString().slice(0, 10);
const out = arg('--out');
const category = arg('--category', '12');
if (!out) { console.error('usage: ECCUBE_MCP_TOKEN=... node mcp-csv.mjs --today YYYY-MM-DD --out <csv> [--category 12]'); process.exit(2); }
if (!/^\d{4}-\d{2}-\d{2}$/.test(today)) { console.error(`--today は YYYY-MM-DD: ${today}`); process.exit(2); }

let text;
try {
  text = await callEccubeTool('eccube_asp_total_ai_csv', { category, today });
} catch (e) {
  console.error(e.message);
  process.exit(1);
}
fs.writeFileSync(out, '﻿' + text, 'utf8');

const lines = text.split(/\r?\n/).map((l) => l.trim()).filter(Boolean);
const hi = lines.findIndex((l) => l.startsWith('ASP,'));
const dates = hi >= 0 ? lines[hi].split(',').slice(4) : [];
const lastDate = dates.at(-1) ?? null;
const u = text.match(/集計表更新日時：(\d+)月(\d+)日\s*(\d+)時(\d+)分/);
const updater = text.match(/集計表更新者：([^　\s"]*)/)?.[1] ?? null;
const yesterday = new Date(Date.parse(`${today}T00:00:00Z`) - 86400e3).toISOString().slice(0, 10);
const updatedToday = !!u && Number(u[1]) === Number(today.slice(5, 7)) && Number(u[2]) === Number(today.slice(8, 10));
const reasons = [];
if (lastDate !== yesterday) reasons.push(`最終日付が ${lastDate}（${yesterday} のはず）`);
if (!updatedToday) reasons.push(`集計表更新日時が今日でない（${u ? `${u[1]}月${u[2]}日 ${u[3]}時${u[4]}分` : '読めず'}）`);
if (updater === 'SYSTEM' || !updater) reasons.push(`集計表更新者が ${updater || '空'}（担当者の更新がまだ）`);

// 最終日付の月の獲得目標（管理画面「月獲得目標設定」）。calc.mjs の --target に渡す。取れなくても CSV は使えるので止めない（null）
let monthlyTarget = null;
if (lastDate) {
  try {
    const t = await callEccubeTool('eccube_monthly_target', { category, year: Number(lastDate.slice(0, 4)) });
    const row = t.split(/\r?\n/).find((l) => l.startsWith(`| ${category} |`));
    const cell = row?.split('|').map((s) => s.trim())[2 + Number(lastDate.slice(5, 7))];
    if (cell && /^\d+$/.test(cell) && Number(cell) > 0) monthlyTarget = Number(cell);
  } catch { /* null のまま */ }
}

console.log(JSON.stringify({
  out,
  today,
  category,
  lastDate,
  updatedAt: u ? `${u[1]}月${u[2]}日 ${u[3]}時${u[4]}分` : null,
  updater,
  monthlyCV: Number(text.match(/【月間CV】(\d+)/)?.[1] ?? NaN),
  monthlyTarget,
  ready: reasons.length === 0,
  notReadyReasons: reasons,
}, null, 1));
