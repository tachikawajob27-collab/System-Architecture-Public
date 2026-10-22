#!/usr/bin/env node
/*
 * shushi.mjs — 収支SIM（shushi-sim）の計算と表示。依存パッケージなし。
 * 使い方: node shushi.mjs <入力.json> [--json] [--tsudo=<円>]
 * 入力は hatchu-sim と同じ JSON に、売値・コスト（pl）と経路ごとの販促費（promo）を足したもの。
 * 件数・個数は hatchu-sim/scripts/lib.mjs、収支は ./lib.mjs の計算。
 */
import { readFileSync } from "node:fs";
import { compute } from "../../hatchu-sim/scripts/lib.mjs";
import { computePL } from "./lib.mjs";

const file = process.argv.find((a, i) => i >= 2 && !a.startsWith("--"));
if (!file) { console.error("usage: node shushi.mjs <input.json> [--json] [--tsudo=<円>]"); process.exit(1); }
const inp = JSON.parse(readFileSync(file, "utf8"));
const asJson = process.argv.includes("--json");
const tsudoArg = process.argv.find((a) => a.startsWith("--tsudo="));
if (!inp.pl) { console.error("入力に pl（売値・コスト）がありません"); process.exit(1); }

const base = compute(inp);
const R = computePL(inp, base, { tsudo: tsudoArg ? Number(tsudoArg.split("=")[1]) : null });
const yen = (n) => Math.round(n).toLocaleString("ja-JP");
const pct = (n) => (Math.round(n * 1000) / 10).toFixed(1) + "%";
const { rows, tot, orders, molds, monthlyContribution } = R;

if (asJson) { console.log(JSON.stringify({ months: base.months, cogsUnit: R.cogsUnit, rows, tot, orders, monthlyContribution }, null, 2)); process.exit(0); }

const L = [];
L.push(`# ${inp.product}｜収支SIM（${base.months}か月）`);
L.push(`\n前提：売値は${R.tax}。定期1件＝${R.teikiText}（${R.teikiUnits}個）。都度1個＝${yen(R.tsudoUnit)}円（${R.tsudoUnitBasis ?? ""}）。`);
L.push(`1個あたり原価＝バルク平均${yen(R.bulkAvg)}円＋容器${yen(R.ctnUnit)}円＝**${yen(R.cogsUnit)}円**。${R.variableBasis ?? ""}`);
for (const w of R.warn) L.push(`\n⚠️ ${w}`);
L.push(`\n## 経路別（${base.months}か月）\n`);
L.push(`| 経路 | 件数 | 個数 | 売上 | 原価 | 送料・梱包 | 決済・CC | 販促費 | 営業利益 | 利益率 | 根拠 |`);
L.push(`|---|---|---|---|---|---|---|---|---|---|---|`);
for (const r of rows) L.push(`| ${r.name} | ${yen(r.cv)} | ${yen(r.units)} | ${yen(r.rev)} | ${yen(r.cogs)} | ${yen(r.logistics)} | ${yen(r.pay + r.cc)} | ${yen(r.promo)} | ${yen(r.profit)} | ${pct(r.profit / r.rev)} | ${r.strength ?? "？"} |`);
L.push(`| **合計** | **${yen(tot.cv)}** | **${yen(tot.units)}** | **${yen(tot.rev)}** | ${yen(tot.cogs)} | ${yen(tot.logistics)} | ${yen(tot.pay + tot.cc)} | ${yen(tot.promo)} | **${yen(tot.profit)}** | ${pct(tot.profit / tot.rev)} | |`);
L.push(`\n（営業利益＝売上−原価−送料・梱包−決済手数料−コールセンター費−販促費。金型は下で別に引く。）`);
L.push(`\n## 金型の負担（${base.months}か月の営業利益から引く）\n`);
L.push(`| 金型のLTT負担 | 営業利益 | 利益率 |`); L.push(`|---|---|---|`);
for (const m of molds) L.push(`| ${yen(m)}円 | ${yen(tot.profit - m)} | ${pct((tot.profit - m) / tot.rev)} |`);
if (orders.length) {
  L.push(`\n## 発注案と回収\n`);
  L.push(`在庫を除いた現金の入り（売上−送料・梱包−手数料−販促費）：月 ${yen(monthlyContribution)} 円。`);
  L.push(`\n| 案 | 仕入れ総額 | 回収に必要な月数 | 在庫が尽きるまで | 判定 |`); L.push(`|---|---|---|---|---|`);
  for (const o of orders) L.push(`| ${o.name} | ${yen(o.total)}円 | ${(Math.round(o.payback * 10) / 10)}か月 | ${(Math.round(o.coverTotal * 10) / 10)}か月 | ${o.soldOutFirst ? "⚠️ 在庫が尽きても回収が終わらない" : "在庫が尽きるまでに回収できる"} |`);
}
console.log(L.join("\n"));
