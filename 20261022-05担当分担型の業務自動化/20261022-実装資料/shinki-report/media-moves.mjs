#!/usr/bin/env node
// 新規レポート「■ 背景」用。媒体別の日別CVを複数ファイル分重ねて、
//   ① 水準が変わった媒体（直近3日平均 vs その前の平均）
//   ② 最終日の急変（参考値）
//   ③ 単価変更の前後で件数が期待どおり動いたか（--change）
// を数字で出す。LLMに手計算させない。
//
// 使い方:
//   node shinki-report/media-moves.mjs <calc最新.json> <calc7日前ごろ.json> \
//     [--change "ASP|媒体|YYYY-MM-DD|up"] [--change ...]
//   calc.json は `node shinki-report/calc.mjs <csv> --today <今日> > calc.json` の出力。
//   重なる日は「新しいファイルの値」を採る（過去の日の数字は後から少し改訂されるため）。
import fs from 'node:fs';

const args = process.argv.slice(2);
const files = [];
const changes = [];
const looks = [];
for (let i = 0; i < args.length; i++) {
  if (args[i] === '--change') changes.push(args[++i]);
  else if (args[i] === '--look') looks.push(args[++i]);
  else files.push(args[i]);
}
if (files.length < 1) {
  console.error('usage: media-moves.mjs <latest.json> [older.json ...] [--change "ASP|媒体|YYYY-MM-DD|up|down"]');
  process.exit(1);
}

// 媒体名のバリエーション（おまけあり／なし・男性訴求・ABテスト用・ウォッシュ用）は外して、同じ媒体として足し合わせる。
// 括弧付きでも IF の ID や「かご落ち(アウトバウンド)」は別物なので、下の一覧に載せたものだけ外す。
const VARIANT = /[(（](おまけあり|おまけなし|男性訴求|ABテスト用|ウォッシュ用)[)）]\s*$/;
const norm = m => m.replace(VARIANT, '');

const load = f => JSON.parse(fs.readFileSync(f, 'utf8'));
const calcs = files.map(load).sort((a, b) => a.lastDate.localeCompare(b.lastDate)); // 古い順
const latest = calcs[calcs.length - 1];
const lastDate = latest.lastDate;

// 日付 → 媒体 → CV（新しいファイルで上書き）
const byKey = new Map();
const allDates = new Set();
for (const c of calcs) {
  const dates = c.series.dates;
  for (const d of dates) allDates.add(d);
  const fileSum = new Map(); // このファイル内で、媒体名の違いを足し合わせる
  for (const r of c.mediaSeries) {
    const key = `${r.asp}|${norm(r.media)}`;
    if (!fileSum.has(key)) fileSum.set(key, dates.map(() => 0));
    r.v.forEach((x, i) => { fileSum.get(key)[i] += x; });
  }
  for (const [key, v] of fileSum) {
    if (!byKey.has(key)) byKey.set(key, new Map());
    dates.forEach((d, i) => byKey.get(key).set(d, v[i]));
  }
  // この表に行が無い媒体はその日0件として扱う
  for (const [key, m] of byKey) {
    for (const d of dates) if (!m.has(d)) m.set(d, 0);
  }
}
const dates = [...allDates].sort();
const span = dates.slice(-14); // 直近14日（重ねた日数が足りなければある分だけ）
const val = (key, d) => byKey.get(key)?.get(d) ?? 0;
const mean = a => a.reduce((x, y) => x + y, 0) / (a.length || 1);
const sd = a => {
  const m = mean(a);
  return Math.sqrt(a.reduce((x, y) => x + (y - m) ** 2, 0) / Math.max(a.length - 1, 1));
};
const r1 = x => Math.round(x * 10) / 10;

const MIN = 5; // 日平均がこの件数未満の媒体は対象外（仮）
const level = [];
const lastDayOutliers = [];
for (const key of byKey.keys()) {
  const s = span.map(d => val(key, d));
  if (s.length < 7) continue;
  if (/IF/.test(key)) continue; // インフルエンサーは単発で山になる。水準変化には入れない
  const recent = s.slice(-3);
  const base = s.slice(0, -3);
  const mr = mean(recent), mb = mean(base);
  if (Math.max(mr, mb) >= MIN && Math.abs(mr - mb) >= 3) {
    const ratio = mb ? mr / mb : Infinity;
    if (ratio >= 1.5 || ratio <= 1 / 1.5) {
      level.push({ key, base: r1(mb), recent: r1(mr), ratio: r1(ratio), series: s.slice(-8) });
    }
  }
  // 最終日の急変：最終日を除いた日々の平均±標準偏差で見る
  const prior = s.slice(0, -1);
  const mp = mean(prior), sp = sd(prior);
  if (mp >= MIN && sp > 0) {
    const z = (s[s.length - 1] - mp) / sp;
    if (Math.abs(z) >= 2) lastDayOutliers.push({ key, lastDay: s[s.length - 1], mean: r1(mp), z: r1(z) });
  }
}
level.sort((a, b) => Math.abs(b.recent - b.base) - Math.abs(a.recent - a.base));

const changeResults = changes.map(spec => {
  const [asp, media, from, dir] = spec.split('|');
  const key = `${asp}|${norm(media)}`;
  if (!byKey.has(key)) return { spec, error: '媒体の行が無い（件数の行なし）' };
  const idx = dates.indexOf(from);
  if (idx < 0) return { spec, error: `開始日 ${from} が重ねた期間に無い` };
  const before = dates.slice(Math.max(0, idx - 7), idx).map(d => val(key, d));
  const after = dates.slice(idx).map(d => val(key, d));
  const mbf = mean(before), maf = mean(after);
  const ratio = mbf ? maf / mbf : (maf ? Infinity : 1);
  const expected = dir === 'down' ? ratio <= 0.9 : ratio >= 1.1; // 仮：引き上げは+10%以上で「増えた」
  const tooSmall = Math.max(mbf, maf) < 3; // 件数が少なすぎて判定にならない（仮）
  return {
    spec, key, beforeDays: before.length, afterDays: after.length,
    beforeMean: r1(mbf), afterMean: r1(maf), ratio: Number.isFinite(ratio) ? r1(ratio) : null,
    moved: tooSmall ? '動いていない（件数が少なく判定できない）' : expected ? '期待方向に動いた' : '動いていない',
  };
});

// 指定した媒体の最終日が、直近の揺れの範囲内かを見る（ランキング上位の「急変」が揺れ内かの確認用）
const lookResults = looks.map(spec => {
  const [asp, media] = spec.split('|');
  const key = `${asp}|${norm(media)}`;
  if (!byKey.has(key)) return { spec, error: '媒体の行が無い' };
  const s = span.map(d => val(key, d));
  const prior = s.slice(0, -1);
  const m = mean(prior), sdv = sd(prior);
  return {
    key, lastDay: s[s.length - 1], priorMin: Math.min(...prior), priorMax: Math.max(...prior),
    priorMean: r1(m), priorSd: r1(sdv), z: sdv ? r1((s[s.length - 1] - m) / sdv) : null,
    withinNormal: sdv ? Math.abs((s[s.length - 1] - m) / sdv) < 2 : null,
  };
});

console.log(JSON.stringify({
  lastDate, period: [span[0], span[span.length - 1]], days: span.length,
  files: calcs.map(c => c.lastDate),
  thresholds: { minPerDay: MIN, levelRatio: 1.5, levelMinDiff: 3, lastDayZ: 2, changeMoved: 1.1 },
  note: '最終日は取り込み遅れで少なめに出ることがある。lastDayOutliers は参考値',
  level, lastDayOutliers, changes: changeResults, look: lookResults,
}, null, 2));
