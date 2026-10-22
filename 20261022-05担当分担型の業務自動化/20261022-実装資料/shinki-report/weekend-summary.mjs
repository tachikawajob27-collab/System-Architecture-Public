#!/usr/bin/env node
// 休み明けの新規レポート用：「前の営業日の翌日〜昨日」をまとめた総括を数字で出す。
//   月曜 → 土日の2日分／月曜が祝日で火曜 → 土日月の3日分／金曜が祝日で月曜 → 金土日の3日分
//   合計CV・CPA（広告費の合計÷CVの合計）、前週の同じ曜日との比、直前の営業日5日の平均との比
// 使い方:
//   node shinki-report/weekend-summary.mjs <calc最新.json> [<calc7日前ごろ.json>] [--today YYYY-MM-DD] [--holidays <jp-holidays.md>]
//   祝日は chatwork-watch/jp-holidays.md の表から読む（土日に加えて、載っている日を休みとする）。
//   表に今日の年が無いときは holidayTableMissing を true にして出す（土日だけで判定する）。
//   前週の同じ曜日は8日分のCSVに収まらないので、約7日前のCSVも渡す。重なる日は新しいファイルの値を採る。
//   最終日（昨日）の全体CVは、calc.mjs の採用値（reconcile.adopted）を使う。
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const argv = process.argv.slice(2);
const opt = n => { const i = argv.indexOf(n); return i >= 0 ? argv[i + 1] : null; };
const flagVals = new Set(['--today', '--holidays'].flatMap(n => { const i = argv.indexOf(n); return i >= 0 ? [i, i + 1] : []; }));
const files = argv.filter((a, i) => !a.startsWith('--') && !flagVals.has(i));
if (!files.length) { console.error('usage: weekend-summary.mjs <latest.json> [older.json] [--today YYYY-MM-DD] [--holidays file]'); process.exit(2); }

const here = path.dirname(fileURLToPath(import.meta.url));
const holidayFile = opt('--holidays') || path.join(here, '..', 'chatwork-watch', 'jp-holidays.md');
const today = opt('--today') || new Date(Date.now() + 9 * 3600e3).toISOString().slice(0, 10);

// 祝日表：行頭が「| YYYY-MM-DD |」の行を読む。年の見出し「## YYYY年」で、表にある年を知る
const holidays = new Map();
const yearsInTable = new Set();
try {
  for (const line of fs.readFileSync(holidayFile, 'utf8').split('\n')) {
    const y = line.match(/^##\s*(\d{4})年/);
    if (y) yearsInTable.add(y[1]);
    const m = line.match(/^\|\s*(\d{4}-\d{2}-\d{2})\s*\|[^|]*\|\s*([^|]+?)\s*\|/);
    if (m) holidays.set(m[1], m[2]);
  }
} catch { /* 読めないときは土日だけで判定し、holidayTableMissing を立てる */ }
const holidayTableMissing = !yearsInTable.has(today.slice(0, 4));

const WD = ['日', '月', '火', '水', '木', '金', '土'];
const dow = d => new Date(d + 'T00:00:00Z').getUTCDay();
const addDays = (d, n) => new Date(new Date(d + 'T00:00:00Z').getTime() + n * 86400e3).toISOString().slice(0, 10);
const isBiz = d => dow(d) !== 0 && dow(d) !== 6 && !holidays.has(d);
const label = d => `${Number(d.slice(5, 7))}/${Number(d.slice(8))}(${WD[dow(d)]})`;

const calcs = files.map(f => JSON.parse(fs.readFileSync(f, 'utf8'))).sort((a, b) => a.lastDate.localeCompare(b.lastDate));
const latest = calcs[calcs.length - 1];
const lastDate = latest.lastDate;

// 前の営業日 P（今日より前で最も近い営業日）。まとめる範囲は P の翌日〜最終日
let P = addDays(today, -1);
for (let i = 0; i < 30 && !isBiz(P); i++) P = addDays(P, -1);
const blockStart = addDays(P, 1);
if (lastDate < blockStart) {
  console.log(JSON.stringify({ applicable: false, reason: `前の営業日 ${P} の翌日以降に最終日が無い（最終日 ${lastDate}）。通常の総括にする`, holidayTableMissing }, null, 2));
  process.exit(0);
}
const block = [];
for (let d = blockStart; d <= lastDate; d = addDays(d, 1)) block.push(d);

// 日付 → { cv, cost }（新しいファイルで上書き。最終日は採用値）
const day = new Map();
for (const c of calcs) {
  const s = c.series;
  s.dates.forEach((d, i) => {
    let cv = s.totalCV[i], cpa = s.cpaTax[i];
    if (c === latest && d === c.lastDate && c.reconcile?.adopted?.totalCV) {
      cv = c.reconcile.adopted.totalCV; cpa = c.reconcile.adopted.cpaTax;
    }
    day.set(d, { cv, cost: cv * cpa });
  });
}
const sum = ds => {
  const rows = ds.map(d => day.get(d));
  if (rows.some(r => !r)) return null;
  const cv = rows.reduce((a, r) => a + r.cv, 0), cost = rows.reduce((a, r) => a + r.cost, 0);
  return { cv, cpa: cv ? Math.round(cost / cv) : 0, days: ds.length };
};
const pct = (a, b) => (b ? Number(((a / b - 1) * 100).toFixed(1)) : null);

const prevBlock = block.map(d => addDays(d, -7));
const bizBefore = []; // 直前の営業日5日（P を含む）
for (let d = P; bizBefore.length < 5 && d > addDays(P, -30); d = addDays(d, -1)) if (isBiz(d)) bizBefore.push(d);
bizBefore.reverse();

const cur = sum(block), prev = sum(prevBlock), biz = sum(bizBefore);
const n = block.length;
const nonWeekendHolidays = block.filter(d => holidays.has(d) && dow(d) !== 0 && dow(d) !== 6).map(d => `${label(d)} ${holidays.get(d)}`);
const out = {
  applicable: true,
  title: `${label(block[0])}〜${label(block[n - 1])}・${n}日分`,
  note: nonWeekendHolidays.length ? `祝日を含む：${nonWeekendHolidays.join('、')}` : null,
  holidayTableMissing,
  block: { dates: block, labels: block.map(label), daily: block.map(d => day.get(d)?.cv ?? null), ...cur },
  prevWeek: prev ? { dates: prevBlock, labels: prevBlock.map(label), daily: prevBlock.map(d => day.get(d)?.cv ?? null), ...prev } : { error: '前週の同じ曜日のデータが無い（7日前ごろのCSVも渡す）' },
  bizBefore: biz ? { dates: [bizBefore[0], bizBefore[bizBefore.length - 1]], cvPerDay: Number((biz.cv / bizBefore.length).toFixed(1)), cpa: biz.cpa } : { error: '直前の営業日のデータが無い' },
  vsPrevWeek: prev && cur ? { cvDiff: cur.cv - prev.cv, cvPct: pct(cur.cv, prev.cv), cpaDiff: cur.cpa - prev.cpa, cpaPct: pct(cur.cpa, prev.cpa) } : null,
  vsBizBefore: biz && cur ? { cvPerDay: Number((cur.cv / n).toFixed(1)), cvPct: pct(cur.cv / n, biz.cv / bizBefore.length), cpaDiff: cur.cpa - biz.cpa, cpaPct: pct(cur.cpa, biz.cpa) } : null,
  warn: holidayTableMissing ? `⚠️ 祝日表に ${today.slice(0, 4)} 年が無い。土日だけで判定した（祝日は考慮していない）` : null,
  summaryNote: '合計CPAは広告費の合計÷CVの合計。最終日のCVは採用値（月間CVからの逆算）',
};
console.log(JSON.stringify(out, null, 2));
