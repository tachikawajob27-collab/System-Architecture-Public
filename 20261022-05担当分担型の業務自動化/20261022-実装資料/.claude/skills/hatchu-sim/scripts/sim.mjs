#!/usr/bin/env node
/*
 * sim.mjs — 発注数SIM（hatchu-sim）の計算。依存パッケージなし。
 * 使い方: node sim.mjs <入力.json> [--json]
 * 入力の書き方は ../SKILL.md の「入力票」と ../examples/hair-oil.json を見る。
 * 足し算・掛け算はここでやる。人（AI）が暗算で合計を出さない。
 */
import { readFileSync } from "node:fs";

const file = process.argv[2];
if (!file) { console.error("usage: node sim.mjs <input.json> [--json]"); process.exit(1); }
const inp = JSON.parse(readFileSync(file, "utf8"));
const asJson = process.argv.includes("--json");

import { compute } from "./lib.mjs";
const { months, channels, totalCv, totalUnits, weakUnits, scents, orders } = compute(inp);
const yen = (n) => Math.round(n).toLocaleString("ja-JP");
const round1 = (n) => Math.round(n * 10) / 10;
const round2 = (n) => Math.round(n * 100) / 100;

if (asJson) { console.log(JSON.stringify({ months, channels, totalCv, totalUnits, scents, orders }, null, 2)); process.exit(0); }

// 4. 出力（Markdown）
const L = [];
L.push(`# ${inp.product}｜発注数SIM（${months}か月）`);
L.push(`\n## 経路別（件数→個数）\n`);
L.push(`| 経路 | 件数 | 定期比率 | 1件あたり個数 | 個数 | 根拠 |`);
L.push(`|---|---|---|---|---|---|`);
for (const c of channels) L.push(`| ${c.name} | ${yen(c.cv)} | ${Math.round(c.teiki * 100)}% | ${round2(c.unitsPerCv)} | ${yen(c.units)} | ${c.strength ?? "？"} ${c.basis ?? ""} |`);
L.push(`| **合計** | **${yen(totalCv)}** | | | **${yen(totalUnits)}** | |`);
L.push(`\n根拠が「△近い例」「×仮置き」の経路が個数の ${Math.round((weakUnits / totalUnits) * 100)}% を占める。`);
L.push(`\n## 香り別（${months}か月）\n`);
L.push(`| 香り | 比率 | ${months}か月の個数 | 月あたり |`); L.push(`|---|---|---|---|`);
for (const s of scents) L.push(`| ${s.name} | ${Math.round(s.share * 100)}% | ${yen(s.units)} | ${yen(s.perMonth)} |`);
if (orders.length) {
  L.push(`\n## 発注案\n`);
  L.push(`| 案 | 完成品 | 容器 | 容器の残り | 仕入れ総額 | 完成品1個あたり | 全体で何か月分 |`); L.push(`|---|---|---|---|---|---|---|`);
  for (const o of orders) L.push(`| ${o.name} | ${yen(o.bulkQty)} | ${yen(o.ctnQty)}（${yen(o.ctnPrice)}円） | ${yen(o.spareCtn)} | ${yen(o.total)}円 | ${yen(o.perFinished)}円 | ${round1(o.coverTotal)}か月 |`);
  L.push(`\n### 香り別に何か月分か\n`);
  for (const o of orders) { L.push(`- **${o.name}**：` + o.rows.map((r) => `${r.name} ${yen(r.qty)}個＝${r.coverMonths == null ? "-" : round1(r.coverMonths) + "か月分"}`).join("／")); }
  L.push(`\n（金型の負担を含む案は仕入れ総額に含めている。税別。）`);
}
console.log(L.join("\n"));
