// 新規レポートの並走（2026-10-19〜）: Dropbox の AI用CSV の中身と、eccube-mcp が返す同じ集計を突き合わせる。
// 使い方: ECCUBE_MCP_TOKEN=<鍵> node shinki-report/shadow-compare.mjs --dropbox <asptotal_ai_*.csv> [--today YYYY-MM-DD] [--category 12]
//
// eccube-mcp の eccube_asp_total_ai_csv は、管理画面「ASP集計表」の「AI用CSV」と同じ中身を文字列で返す。
// Dropbox の CSV（Shift-JIS）を読んで、「ASP,予算/成果,媒体,項目」の行ごとに日別の数字と月間CVを比べる。
// 中身が全部同じなら、calc.mjs の出す数字も同じになる（calc.mjs は書き換えない・ここでは回さない）。
// 鍵は環境変数 ECCUBE_MCP_TOKEN だけから読む（引数・ファイル・ログに出さない）。
//
// 出力（JSON）の verdict:
//   match        中身が全部同じ
//   same_numbers 数字は同じ。違うのは「集計表更新日時」（取った時刻の違い）か、全部0の行が片方にしか無いことだけ
//   diff         数字に違いがある。diffs に「どの行・どの日・Dropbox / MCP の値」を出す
// 数字の違いは、片方を取った後に管理画面で更新された（予算行の追加・単価の登録など）ことが多い。どちらが正しいかはここでは決めない。
import fs from 'node:fs';
import { callEccubeTool } from './mcp-client.mjs';

const arg = (k, d = null) => { const i = process.argv.indexOf(k); return i > 0 ? process.argv[i + 1] : d; };
const dropboxFile = arg('--dropbox');
const category = arg('--category', '12');
const today = arg('--today') ?? new Date(Date.now() + 9 * 3600e3).toISOString().slice(0, 10);
const token = process.env.ECCUBE_MCP_TOKEN || '';
if (!dropboxFile) { console.error('usage: ECCUBE_MCP_TOKEN=... node shadow-compare.mjs --dropbox <csv> [--today YYYY-MM-DD] [--category 12]'); process.exit(2); }
if (!token) { console.error('ECCUBE_MCP_TOKEN が無い（ルーティンの環境変数に入れる）'); process.exit(2); }

function parseCsvLine(line) {
  const r = []; let cur = ''; let q = false;
  for (const c of line) {
    if (c === '"') { q = !q; continue; }
    if (c === ',' && !q) { r.push(cur); cur = ''; } else cur += c;
  }
  r.push(cur); return r;
}

/** CSV の文字列 → { header: 先頭の説明行, monthlyCV, dates, rows: Map("ASP|予算/成果|媒体|項目" → 日別の数字) } */
function parse(text) {
  const lines = text.split(/\r?\n/).map((l) => l.trim()).filter(Boolean);
  const hi = lines.findIndex((l) => l.startsWith('ASP,'));
  if (hi < 0) throw new Error('ヘッダー行（ASP,）が無い');
  const dates = parseCsvLine(lines[hi]).slice(4);
  const rows = new Map();
  for (let i = hi + 1; i < lines.length; i++) {
    const c = parseCsvLine(lines[i]);
    if (c.length < 5) continue;
    let key = c.slice(0, 4).join('|');
    while (rows.has(key)) key += '#';
    rows.set(key, c.slice(4).map((x) => (x === '' ? 0 : Number(x))));
  }
  const m = text.match(/【月間CV】(\d+)/);
  return { header: lines.slice(0, hi), monthlyCV: m ? Number(m[1]) : null, dates, rows };
}

let mcpText;
try {
  mcpText = await callEccubeTool('eccube_asp_total_ai_csv', { category, today });
} catch (e) {
  console.error(e.message);
  process.exit(1);
}

const A = parse(new TextDecoder('shift_jis').decode(fs.readFileSync(dropboxFile)));
const B = parse(mcpText);

const diffs = [];
if (A.dates.join(',') !== B.dates.join(',')) diffs.push({ what: '日付の並び', dropbox: A.dates.join(','), mcp: B.dates.join(',') });
if (A.monthlyCV !== B.monthlyCV) diffs.push({ what: '月間CV', dropbox: A.monthlyCV, mcp: B.monthlyCV });
for (const key of new Set([...A.rows.keys(), ...B.rows.keys()])) {
  const a = A.rows.get(key);
  const b = B.rows.get(key);
  if (!a || !b) {
    // 片方にしか無い行。全部0なら数字には効かない（予算行を後から足した、など）
    const v = a ?? b;
    diffs.push({ what: '片方にしか無い行', row: key, only: a ? 'dropbox' : 'mcp', allZero: v.every((x) => x === 0) });
    continue;
  }
  A.dates.forEach((d, j) => { if ((a[j] ?? 0) !== (b[j] ?? 0)) diffs.push({ what: '数字', row: key, date: d, dropbox: a[j] ?? 0, mcp: b[j] ?? 0 }); });
}
const headerDiffers = A.header.join('\n') !== B.header.join('\n');
const numeric = diffs.filter((d) => !(d.what === '片方にしか無い行' && d.allZero));
const verdict = numeric.length > 0 ? 'diff' : (headerDiffers || diffs.length > 0) ? 'same_numbers' : 'match';

// 「集計表更新日時：10月20日 13時04分」の日時。見出しが同じ日も両方出す（2026-10-20：同じ日に null を返し、通知が「取得できず」と書いた）
const updatedAt = (h) => h.join(' ').match(/集計表更新日時：(\d+月\d+日\s*\d+時\d+分)/)?.[1] ?? null;
// ChatWork は半角の「:D」「:)」「:p」などを絵文字に変える（2026-10-20：「2026-10-12:Dropbox」の「:D」が絵文字になった）。通知の行は全角の「：」にする
const cwSafe = (s) => String(s).replace(/:/g, '：');
const noticeLines = numeric.map((d) => cwSafe(
  d.what === '数字' ? `・${d.row} ${d.date}：Dropbox ${d.dropbox} → MCP ${d.mcp}`
    : d.what === '片方にしか無い行' ? `・${d.row}：${d.only === 'dropbox' ? 'Dropbox' : 'MCP'}にしか無い行`
      : `・${d.what}：Dropbox ${d.dropbox} → MCP ${d.mcp}`));

console.log(JSON.stringify({
  verdict,
  today,
  lastDate: { dropbox: A.dates.at(-1), mcp: B.dates.at(-1) },
  header: { differs: headerDiffers, dropbox: A.header.join(' / '), mcp: B.header.join(' / ') },
  updatedAt: { dropbox: updatedAt(A.header), mcp: updatedAt(B.header) },
  rows: { dropbox: A.rows.size, mcp: B.rows.size },
  zeroOnlyRows: diffs.filter((d) => d.what === '片方にしか無い行' && d.allZero).map((d) => `${d.row}（${d.only}のみ・全部0）`),
  diffCount: numeric.length,
  diffs: numeric.slice(0, 40),
  // 通知の本文にそのまま貼る行（先頭10件と残りの件数）。言い換えない
  notice: { lines: noticeLines.slice(0, 10), more: Math.max(0, noticeLines.length - 10) },
}, null, 1));
