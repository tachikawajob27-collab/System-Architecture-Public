// 自社広告の週次の「異常の線」を引き直すための集計（3か月に1回、人が頼んだときに回す。Routineでは使わない）。
// 使い方: node shinki-report/jisha-lines.mjs --brand fem|mens --week-end YYYY-MM-DD [--weeks 15] <csv> <csv> …
//   CSVは同じブランドのものを並べる（日付が重なれば、より新しい日付まで入っているファイルが勝つ。順番は問わない）。月次CSV（995_AI分析官/自社EC新規履歴/
//   asptotal_<ブランド>_YYYY-MM_*.csv）でも日別CSV（996_日別レポートcsvデータ）でもよい。週は水〜火、--week-end は最後の週の火曜。
// 出すもの: 週ごとの表、媒体ごとの CPA の中央値と上位1割、構成比の前週差の上位1割、全体CPA、
//   いまの線（jisha-lib.mjs の LINES）でその期間を判定したら何週・何件⚠が出たか。
// 線はここで出た上位1割を丸めて、jisha-lib.mjs の LINES に手で書く（自動では書き換えない）。
import { mergeDays, sumWeek, addDays, GROUPS, yen, cpaNum, allCpaNum, shareNum, flags } from './jisha-lib.mjs';

const args = process.argv.slice(2);
const opt = (k) => { const i = args.indexOf(k); return i >= 0 ? args[i + 1] : null; };
const brand = opt('--brand'); const end = opt('--week-end'); const n = Number(opt('--weeks') || 15);
const files = args.filter((a, i) => !a.startsWith('--') && !args[i - 1]?.startsWith('--'));
if (!['fem', 'mens'].includes(brand) || !end || !files.length || !Number.isInteger(n) || n < 2 || args.some(a => /^--\w[\w-]*=/.test(a)) || (args.includes('--weeks') && !/^\d+$/.test(opt('--weeks') || ''))) {
  console.error('usage: node jisha-lines.mjs --brand fem|mens --week-end YYYY-MM-DD [--weeks 15] <csv>...');
  process.exit(2);
}

const days = mergeDays(files);
const weeks = Array.from({ length: n }, (_, i) => sumWeek(days, addDays(end, -7 * (n - 1 - i))));

// 上位・下位の割合の点（線形補間。Python なら statistics.quantiles(method='inclusive')、numpy の既定と同じ取り方。statistics の既定 'exclusive' とは違う）
const q = (xs, p) => {
  const a = [...xs].sort((x, y) => x - y); if (!a.length) return null;
  const k = (a.length - 1) * p; const f = Math.floor(k); const c = Math.min(f + 1, a.length - 1);
  return a[f] + (a[c] - a[f]) * (k - f);
};
const pick = (w, k) => (k === '自社' ? w.self : w.groups[k]);
const KEYS = ['自社', ...GROUPS.filter(k => k !== 'その他')];

console.log(`### ${brand} ${weeks[0].start}〜${weeks[n - 1].end}（${n}週）`);
console.log(`週末 | 全体CV | 全体CPA | ${KEYS.map(k => `${k} CV/構成比/CPA`).join(' | ')}`);
for (const w of weeks) {
  console.log([w.end.slice(5), w.totalCV, yen(allCpaNum(w)),
    ...KEYS.map(k => { const x = pick(w, k); const c = cpaNum(x); return `${x.cv}/${shareNum(x.cv, w.totalCV)}%/${c === null ? (x.cost > 0 ? `CV0・${yen(x.cost)}` : '-') : yen(c)}`; })].join(' | '));
}
console.log('');
console.log(`全体CPA：中央値 ${yen(q(weeks.map(allCpaNum), 0.5))}（直近週 ${yen(allCpaNum(weeks[n - 1]))}）`);
for (const k of KEYS) {
  const cpas = weeks.map(w => pick(w, k)).filter(x => x.cv > 0 && x.cost > 0).map(cpaNum);
  const sh = weeks.map(w => shareNum(pick(w, k).cv, w.totalCV));
  const dsh = sh.slice(1).map((v, i) => Math.abs(v - sh[i]));
  console.log(`${k}：CPA ${cpas.length ? `中央値 ${yen(q(cpas, 0.5))}・上位1割 ${yen(q(cpas, 0.9))}・最大 ${yen(Math.max(...cpas))}（${cpas.length}週）` : '費用ありの週なし'}`
    + `／構成比 中央値 ${q(sh, 0.5).toFixed(1)}%・前週差の上位1割 ${q(dsh, 0.9).toFixed(2)}pt・最大 ${Math.max(...dsh).toFixed(2)}pt`);
}
console.log('');
let fired = 0; let total = 0;
for (let i = 0; i < n; i++) {
  const f = flags(brand, weeks[i], i ? weeks[i - 1] : null);
  if (f.length) { fired++; total += f.length; console.log(`${weeks[i].end.slice(5)} ${f.join(' ／ ')}`); }
}
console.log(`いまの線で判定すると：${n}週のうち${fired}週・計${total}件の⚠`);
