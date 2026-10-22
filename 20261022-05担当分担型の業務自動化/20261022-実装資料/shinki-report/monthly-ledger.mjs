// 月次CSV（asptotal_femcare-oil_YYYY-MM_*.csv, Shift-JIS）→ 新規月次DBの1行分のJSON。
// 数字はここで確定させ、LLMは計算しない。使い方: node shinki-report/monthly-ledger.mjs <csv>
import { readFileSync } from 'node:fs';

const path = process.argv[2];
if (!path) { console.error('usage: monthly-ledger.mjs <csv>'); process.exit(2); }

const text = new TextDecoder('shift_jis').decode(readFileSync(path));
const rows = [];
for (const line of text.split(/\r?\n/)) {
  // 値にカンマ・引用符は入らない前提（実測済み）。入っていたら検算で落ちる。
  rows.push(line.split(','));
}
const title = rows[0][0] || '';
const hi = rows.findIndex(r => r[0] === 'ASP' && r[4] === '項目');
if (hi < 0) { console.error('ヘッダ行(ASP,…,項目)が見つかりません'); process.exit(1); }
const body = rows.slice(hi + 1).filter(r => r.length > 8 && r[4]);
const num = v => Number(v || 0);

const sum = k => {
  const a = body.filter(r => r[4] === k);
  return { month: a.reduce((s, r) => s + num(r[5]), 0), daily: a.reduce((s, r) => s + r.slice(8).reduce((t, v) => t + num(v), 0), 0), n: a.length };
};
const cv = sum('全体CV'), ad = sum('全体広告費'), ap = sum('承認CV'), na = sum('非承認CV');
const dates = rows[hi].slice(8);
const ym = (dates[0] || '').slice(0, 7);
const m = title.match(/更新日時：([^　]+)/);

const out = {
  月: ym,
  全体CV: cv.month, 承認CV: ap.month, 非承認CV: na.month,
  承認率: Math.round(ap.month / cv.month * 1000) / 10,
  全体広告費: ad.month,
  全体CPA税込: Math.round(ad.month * 1.1 / cv.month),
  集計表更新日時: m ? m[1] : '',
  日数: dates.length,
  検算: {
    月合計と日別合計が一致: cv.month === cv.daily && ad.month === ad.daily && ap.month === ap.daily && na.month === na.daily,
    全体CVは承認と非承認の和: cv.month === ap.month + na.month,
    広告費の月合計と日別合計の差: ad.month - ad.daily,
  },
};
console.log(JSON.stringify(out, null, 1));
