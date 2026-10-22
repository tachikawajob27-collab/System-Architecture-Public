#!/usr/bin/env node
/*
 * sheet.mjs — 入力票（スプレッドシート）と、発注数SIM・収支SIMの橋渡し。依存パッケージなし。
 *
 *   node sheet.mjs build <spreadsheetId> <case.json | --blank>   入力票のタブを作って値を書く
 *   node sheet.mjs run   <spreadsheetId>                         入力票を読んで計算し、「結果」タブに書き戻す
 *   node sheet.mjs json  <spreadsheetId>                         入力票を読んでJSONを出す（確認用）
 *
 * 入力票のレイアウトはこのファイルが唯一の定義（書く側 toTabs と読む側 fromTabs）。
 * Googleスプレッドシートの読み書きは gsheets/gsheets.mjs（本人のOAuth）を使う。
 * 計算は lib.mjs（個数）と shushi-sim/scripts/lib.mjs（収支）。シートに式は入れない（二重計算を防ぐ）。
 */
import { readFileSync } from "node:fs";
import { execFileSync } from "node:child_process";
import { fileURLToPath } from "node:url";
import path from "node:path";
import { compute } from "./lib.mjs";
import { computePL } from "../../shushi-sim/scripts/lib.mjs";

const here = path.dirname(fileURLToPath(import.meta.url));
const GS = path.resolve(here, "../../../../gsheets/gsheets.mjs");

export const TAB = { basic: "①基本", oem: "②見積・OEM", ch: "③経路", sc: "④香り・発注案", cost: "⑤コスト", res: "結果" };
const MAXROWS = { teiki: 4, bulk: 12, ctn: 6, ch: 12, scent: 6, order: 28 };

const num = (v) => {
  if (v == null) return null;
  const s = String(v).replace(/[,，円個%％\s]/g, "");
  if (s === "") return null;
  const n = Number(s);
  return Number.isFinite(n) ? n : null;
};
const str = (v) => (v == null ? "" : String(v).trim());

// ---------- 書く側：JSON → タブの2次元配列 ----------
export function toTabs(inp, blank = false) {
  const B = (v) => (blank ? "" : v ?? "");
  const pl = inp.pl ?? {};
  const pad = (rows, n, w) => { const out = rows.slice(0, n); while (out.length < n) out.push(Array(w).fill("")); return out; };
  const tabs = {};

  // ①基本
  const td = pl.pricing?.teikiDeliveries ?? [];
  tabs[TAB.basic] = [
    ["①基本（黄色のセルを埋める。灰色の列はメモ）", "", ""],
    ["項目", "値", "メモ・出どころ"],
    ["商品名", B(inp.product), ""],
    ["見る月数", B(inp.months ?? 3), "発売から何か月分を試算するか。空欄なら3（リードタイム90日なら3〜4が目安）"],
    ["発売日（仮）", B(inp.launchDate ?? ""), "仮でよい。動いたら直す"],
    ["税区分", B(pl.tax ? (String(pl.tax).includes("税込") ? "税込" : "税別") : "税別"), "売値・コストの税の扱いを揃える"],
    ["都度1個の売値（円）", B(pl.pricing?.tsudoUnit), B(pl.pricing?.tsudoUnitBasis)],
    ["都度1件あたりの個数", B(inp.unitsPerCv?.tsudo ?? 1), "1個売りなら1"],
    ["", "", ""],
    ["定期のお届け", "個数", "売値（円）"],
    ...pad(td.map((d, i) => [`${i + 1}回目`, B(d.units), B(d.price)]), MAXROWS.teiki, 3).map((r, i) => (r[0] ? r : [`${i + 1}回目`, "", ""])),
    ["定期1件の個数（自動）", "=SUM(B11:B14)", "お届けの個数の合計。収支の売上にも使う"],
  ];

  // ②見積・OEM
  const bulkRows = [];
  if (!blank) for (const [name, tiers] of Object.entries(inp.costs?.bulk ?? {})) for (const t of tiers) bulkRows.push([name, t.min, t.price]);
  const ctnTiers = blank ? [] : (inp.costs?.container?.tiers ?? []).map((t) => [t.min, t.price]);
  const c = inp.costs?.container ?? {};
  const oem = inp.oem ?? {};
  tabs[TAB.oem] = [
    ["②見積・OEM（OEMに確認して書く。空欄は「未確認」として止まる）", "", "", "", ""],
    ["先に聞く項目", "内容", "確認日", "出典", "確度（○実績／△近い例／×仮置き）"],
    ["最小ロット（バルク・個）", B(oem.minBulk ?? ""), B(oem.confirmed?.minBulk), B(oem.src?.minBulk), B(oem.strength?.minBulk)],
    ["最小ロット（容器・個）", B(c.min), B(oem.confirmed?.minCtn), B(oem.src?.minCtn), B(oem.strength?.minCtn)],
    ["最小ロットの単位（香り別／合計）", B(c.minMode === "total" ? "合計" : "香り別"), B(oem.confirmed?.minMode), B(oem.src?.minMode), B(oem.strength?.minMode)],
    ["リードタイム（日数）", B(oem.leadDays ?? ""), B(oem.confirmed?.lead), B(oem.src?.lead), B(oem.strength?.lead)],
    ["リードタイムの対象（バルク／容器／完成品まで）", B(oem.leadScope ?? ""), B(oem.confirmed?.leadScope), B(oem.src?.leadScope), B(oem.strength?.leadScope)],
    ["金型代（円・オリジナル容器のとき）", B(oem.moldCost ?? ""), B(oem.confirmed?.mold), B(oem.src?.mold), B(oem.strength?.mold)],
    ["金型の累計条件", B(oem.moldCondition ?? ""), B(oem.confirmed?.moldCondition), B(oem.src?.moldCondition), B(oem.strength?.moldCondition)],
    ["ロット未達時の負担", B(oem.shortfall ?? ""), B(oem.confirmed?.shortfall), B(oem.src?.shortfall), B(oem.strength?.shortfall)],
    ["", "", "", "", ""],
    ["バルク単価", "ロット（個以上）", "単価（円/個）", "", ""],
    ...pad(bulkRows.map((r) => [...r, "", ""]), MAXROWS.bulk, 5),
    ["", "", "", "", ""],
    ["容器単価", "ロット（個以上）", "単価（円/個）", "", ""],
    ...pad(ctnTiers.map((r) => ["容器", ...r, "", ""]), MAXROWS.ctn, 5),
    ["容器のオプション増減（円/個）", B(c.adjust ?? ""), "フロスト加工＋、ポンプ色（金・銀・マット）−など", "", ""],
    ["容器の単価を引くロット（個）", B(pl.cogs?.containerBasisLot ?? ""), "原価は、このロットの単価で引く", "", ""],
  ];

  // ③経路
  const chHead = ["経路名", "件数の出し方", "月の件数", "母数", "率（%）", "定期比率（%）", "定期1件の個数（空=標準）", "都度1件の個数（空=標準）", "都度1件の売上（円・セット価格など。空=売値×個数）",
    "販促：受信者数", "販促：回数", "販促：吹き出し数", "販促：円/吹き出し", "販促：枚数", "販促：円/枚", "販促費（円・直接）", "根拠の強さ（○／△／×）", "根拠・出典"];
  const presets = [
    ["マイページのクロス枠", "月の件数", "", "", "", "", "", "", "", "", "", "", "", "", "", 0, "", "標準：1商品あたり月150〜230件（ウォッシュ9月204件）。定期比率はウォッシュ実績35.8%（ファクトDB）"],
    ["既存リストへのLINE一斉配信", "母数×率（1回）", "", "", "", 100, "", "", "", "", 1, 3, 3, "", "", "", "×", "実績なし。在庫捌きCP0.29%/配信（ファクトDB・解約者向け）"],
    ["新規リストのLINE", "母数×率（毎月）", "", "", "", 100, "", "", "", "", 1, 3, 3, "", "", "", "×", "自動配信の実績：ウォッシュ1.46%（ファクトDB）"],
    ["同梱物（F1）", "母数×率（毎月）", "", "", "", 100, "", "", "", "", "", "", "", "", "", "", "×", "近い実績：感謝特典0.3%（ファクトDB）"],
    ["ASS（web解約）", "母数×率（毎月）", "", "", "", 0, "", "", "", "", "", "", "", "", "", 0, "×", "実績なし"],
  ];
  const chRows = (blank ? presets : inp.channels).map((ch) => {
    if (Array.isArray(ch)) return ch;
    const how = ch.cvPerMonth != null ? "月の件数" : ch.mode === "once" ? "母数×率（1回）" : "母数×率（毎月）";
    const p = ch.promo ?? {};
    return [ch.name, how, ch.cvPerMonth ?? "", ch.population ?? "", ch.rate != null ? ch.rate * 100 : "", (ch.teikiShare ?? 0) * 100,
      ch.unitsTeiki ?? "", ch.unitsTsudo ?? "", ch.tsudoRevenuePerCv ?? "",
      p.recipients ?? "", p.recipients != null ? (p.sends ?? 1) : "", p.recipients != null ? (p.bubbles ?? 1) : "", p.yenPerBubble ?? "",
      p.pieces ?? "", p.yenPerPiece ?? "", p.yen ?? "", ch.strength ?? "", [ch.basis, p.basis].filter(Boolean).join("／")];
  });
  tabs[TAB.ch] = [["③経路（経路ごとに、件数の出し方と販促費を書く）", ...Array(17).fill("")], chHead, ...pad(chRows, MAXROWS.ch, 18)];

  // ④香り・発注案
  const scRows = (blank ? [] : inp.scents).map((s) => [s.name, s.share * 100, pl.cogs?.basisLots?.[s.name] ?? ""]);
  const orRows = [];
  if (!blank) for (const o of inp.orders ?? []) for (const [name, qty] of Object.entries(o.bulk)) orRows.push([o.name, name, qty]);
  tabs[TAB.sc] = [
    ["④香り・発注案", "", ""],
    ["香り", "比率（%）", "原価を引くバルクのロット（個）"],
    ...pad(scRows, MAXROWS.scent, 3),
    ["", "", ""],
    ["発注案（案名・香り・バルク数を1行ずつ）", "", ""],
    ["案名", "香り", "バルク数（個）"],
    ...pad(orRows, MAXROWS.order, 3),
  ];

  // ⑤コスト
  const v = pl.variable ?? {};
  tabs[TAB.cost] = [
    ["⑤コスト（正本は コスト基礎数値DB。どの行を採ったかを右に書く）", "", ""],
    ["項目", "値", "出典・採用理由"],
    ["送料（円/出荷）", v.shippingPerShipment ?? "", pl.variableBasis ?? ""],
    ["梱包（円/出荷）", v.packPerShipment ?? "", ""],
    ["決済手数料（%）", v.paymentRate != null ? v.paymentRate * 100 : "", ""],
    ["コールセンター費（%・対売上）", v.ccRate != null ? v.ccRate * 100 : "", ""],
    ["金型のLTT負担の場合分け（円・「、」区切り）", (pl.moldScenarios ?? [0]).join("、"), "例：0、800000（「、」で区切る）"],
  ];
  return tabs;
}

// ---------- 読む側：タブ → JSON ----------
export function fromTabs(t) {
  const g = (tab, r, c) => (t[tab]?.[r - 1]?.[c - 1] ?? "");
  const inp = { product: str(g(TAB.basic, 3, 2)), months: num(g(TAB.basic, 4, 2)) ?? 3, launchDate: str(g(TAB.basic, 5, 2)) };
  const taxLabel = str(g(TAB.basic, 6, 2)) || "税別";
  const teikiDeliveries = [];
  for (let r = 11; r <= 14; r++) { const u = num(g(TAB.basic, r, 2)), p = num(g(TAB.basic, r, 3)); if (u && p != null) teikiDeliveries.push({ units: u, price: p }); }
  inp.unitsPerCv = { teiki: teikiDeliveries.reduce((s, d) => s + d.units, 0), tsudo: num(g(TAB.basic, 8, 2)) ?? 1 };

  // ②
  const oemKey = ["minBulk", "minCtn", "minMode", "lead", "leadScope", "mold", "moldCondition", "shortfall"];
  inp.oem = { missing: [] };
  const labels = ["最小ロット（バルク）", "最小ロット（容器）", "最小ロットの単位", "リードタイム（日数）", "リードタイムの対象", "金型代", "金型の累計条件", "ロット未達時の負担"];
  oemKey.forEach((k, i) => { if (str(g(TAB.oem, 3 + i, 2)) === "") inp.oem.missing.push(labels[i]); });
  inp.oem.minBulk = num(g(TAB.oem, 3, 2)); inp.oem.leadDays = num(g(TAB.oem, 6, 2)); inp.oem.leadScope = str(g(TAB.oem, 7, 2));
  inp.oem.moldCost = num(g(TAB.oem, 8, 2)); inp.oem.moldCondition = str(g(TAB.oem, 9, 2)); inp.oem.shortfall = str(g(TAB.oem, 10, 2));
  const bulk = {};
  for (let r = 13; r < 13 + MAXROWS.bulk; r++) { const n = str(g(TAB.oem, r, 1)), mn = num(g(TAB.oem, r, 2)), p = num(g(TAB.oem, r, 3)); if (n && mn != null && p != null) (bulk[n] ??= []).push({ min: mn, price: p }); }
  for (const k of Object.keys(bulk)) bulk[k].sort((a, b) => a.min - b.min);
  const tiers = [];
  const ctnStart = 13 + MAXROWS.bulk + 2;
  for (let r = ctnStart; r < ctnStart + MAXROWS.ctn; r++) { const mn = num(g(TAB.oem, r, 2)), p = num(g(TAB.oem, r, 3)); if (mn != null && p != null) tiers.push({ min: mn, price: p }); }
  tiers.sort((a, b) => a.min - b.min);
  const adjRow = ctnStart + MAXROWS.ctn;
  inp.costs = {
    bulk,
    container: { min: num(g(TAB.oem, 4, 2)) ?? tiers[0]?.min ?? 0, minMode: str(g(TAB.oem, 5, 2)) === "合計" ? "total" : "perScent", tierBy: "perScentMax", tiers, adjust: num(g(TAB.oem, adjRow, 2)) ?? 0 },
    moldBurden: 0,
  };
  const ctnBasis = num(g(TAB.oem, adjRow + 1, 2));

  // ③
  inp.channels = [];
  for (let r = 3; r < 3 + MAXROWS.ch; r++) {
    const name = str(g(TAB.ch, r, 1)); if (!name) continue;
    const how = str(g(TAB.ch, r, 2));
    const ch = { name, strength: str(g(TAB.ch, r, 17)) || "×", basis: str(g(TAB.ch, r, 18)) };
    if (how === "月の件数") ch.cvPerMonth = num(g(TAB.ch, r, 3)) ?? 0;
    else { ch.population = num(g(TAB.ch, r, 4)) ?? 0; ch.rate = (num(g(TAB.ch, r, 5)) ?? 0) / 100; ch.mode = how.includes("1回") ? "once" : "monthly"; }
    ch.teikiShare = (num(g(TAB.ch, r, 6)) ?? 0) / 100;
    const ut = num(g(TAB.ch, r, 7)), ud = num(g(TAB.ch, r, 8)), tr = num(g(TAB.ch, r, 9));
    if (ut != null) ch.unitsTeiki = ut; if (ud != null) ch.unitsTsudo = ud; if (tr != null) ch.tsudoRevenuePerCv = tr;
    const yenDirect = num(g(TAB.ch, r, 16));
    if (yenDirect != null) ch.promo = { yen: yenDirect };
    else if (num(g(TAB.ch, r, 10)) != null) ch.promo = { recipients: num(g(TAB.ch, r, 10)), sends: num(g(TAB.ch, r, 11)) ?? 1, bubbles: num(g(TAB.ch, r, 12)) ?? 1, yenPerBubble: num(g(TAB.ch, r, 13)) ?? 0 };
    else if (num(g(TAB.ch, r, 14)) != null) ch.promo = { pieces: num(g(TAB.ch, r, 14)), yenPerPiece: num(g(TAB.ch, r, 15)) ?? 0 };
    else ch.promo = { yen: 0 };
    inp.channels.push(ch);
  }

  // ④
  inp.scents = []; const basisLots = {};
  for (let r = 3; r < 3 + MAXROWS.scent; r++) { const n = str(g(TAB.sc, r, 1)); if (!n) continue; inp.scents.push({ name: n, share: (num(g(TAB.sc, r, 2)) ?? 0) / 100 }); const bl = num(g(TAB.sc, r, 3)); if (bl != null) basisLots[n] = bl; }
  const orders = new Map();
  const oStart = 3 + MAXROWS.scent + 3;
  for (let r = oStart; r < oStart + MAXROWS.order; r++) { const on = str(g(TAB.sc, r, 1)), sn = str(g(TAB.sc, r, 2)), q = num(g(TAB.sc, r, 3)); if (on && sn && q) { if (!orders.has(on)) orders.set(on, { name: on, bulk: {} }); orders.get(on).bulk[sn] = q; } }
  inp.orders = [...orders.values()];

  // ⑤
  const moldScenarios = str(g(TAB.cost, 7, 2)).split(/[、,，\/\s]+/).map((s) => num(s)).filter((n) => n != null);
  const ms = [...new Set(moldScenarios)].sort((a, b) => a - b);
  inp.pl = {
    tax: taxLabel,
    pricing: { teikiDeliveries, tsudoUnit: num(g(TAB.basic, 7, 2)) ?? 0, tsudoUnitBasis: str(g(TAB.basic, 7, 3)) },
    variable: { shippingPerShipment: num(g(TAB.cost, 3, 2)) ?? 0, packPerShipment: num(g(TAB.cost, 4, 2)) ?? 0, paymentRate: (num(g(TAB.cost, 5, 2)) ?? 0) / 100, ccRate: (num(g(TAB.cost, 6, 2)) ?? 0) / 100 },
    variableBasis: str(g(TAB.cost, 3, 3)),
    cogs: { basisLots, containerBasisLot: ctnBasis ?? inp.costs.container.min },
    moldScenarios: ms.length ? ms : [0],
  };
  return inp;
}

// ---------- 結果タブの中身 ----------
const yen = (n) => Math.round(n).toLocaleString("ja-JP");
const r1 = (n) => Math.round(n * 10) / 10;
const pct = (n) => (Math.round(n * 1000) / 10).toFixed(1) + "%";
export function resultRows(inp, base, pl, stamp) {
  const R = [];
  R.push([`結果（${stamp} 生成。AIが書く。直接編集しない）`, "", "", "", "", "", "", "", "", "", ""]);
  if (inp.oem?.missing?.length) R.push([`⚠️ ②見積・OEMの未確認：${inp.oem.missing.join("、")}`]);
  R.push([""]);
  R.push([`前提：${inp.product}／${base.months}か月／${pl.tax}。定期1件＝${pl.teikiText}（${pl.teikiUnits}個）。都度1個${yen(pl.tsudoUnit)}円。1個あたり原価${yen(pl.cogsUnit)}円（バルク平均${yen(pl.bulkAvg)}円＋容器${yen(pl.ctnUnit)}円）`]);
  for (const w of pl.warn) R.push([`⚠️ ${w}`]);
  R.push([""]);
  R.push(["経路別", "件数", "個数", "売上", "原価", "送料・梱包", "決済・CC", "販促費", "営業利益", "利益率", "根拠"]);
  for (const r of pl.rows) R.push([r.name, Math.round(r.cv), Math.round(r.units), Math.round(r.rev), Math.round(r.cogs), Math.round(r.logistics), Math.round(r.pay + r.cc), Math.round(r.promo), Math.round(r.profit), pct(r.profit / r.rev), r.strength]);
  const t = pl.tot;
  R.push(["合計", Math.round(t.cv), Math.round(t.units), Math.round(t.rev), Math.round(t.cogs), Math.round(t.logistics), Math.round(t.pay + t.cc), Math.round(t.promo), Math.round(t.profit), pct(t.profit / t.rev), `根拠が△×の個数 ${Math.round((base.weakUnits / base.totalUnits) * 100)}%`]);
  R.push([""]);
  R.push(["金型のLTT負担", "営業利益", "利益率"]);
  for (const m of pl.molds) R.push([`${yen(m)}円`, Math.round(t.profit - m), pct((t.profit - m) / t.rev)]);
  R.push([""]);
  R.push(["香り別", "比率", `${base.months}か月の個数`, "月あたり"]);
  for (const s of base.scents) R.push([s.name, pct(s.share), Math.round(s.units), Math.round(s.perMonth)]);
  if (pl.orders.length) {
    R.push([""]);
    R.push(["発注案", "完成品", "容器", "容器の残り", "仕入れ総額", "完成品1個あたり", "全体で何か月分", "回収に必要な月数", "判定"]);
    for (const o of pl.orders) R.push([o.name, o.bulkQty, o.ctnQty, o.spareCtn, Math.round(o.total), Math.round(o.perFinished), r1(o.coverTotal), r1(o.payback), o.soldOutFirst ? "⚠️ 在庫が尽きても回収が終わらない" : "在庫が尽きるまでに回収できる"]);
    R.push([""]);
    R.push(["香り別に何か月分か"]);
    for (const o of pl.orders) R.push([o.name, ...o.rows.map((x) => `${x.name} ${yen(x.qty)}個＝${x.coverMonths == null ? "-" : r1(x.coverMonths) + "か月分"}`)]);
  }
  return R;
}

// ---------- gsheets.mjs 呼び出し ----------
const gs = (...args) => execFileSync("node", [GS, ...args], { encoding: "utf8", maxBuffer: 20 * 1024 * 1024 }).trim();
const readTab = (id, tab) => { const out = gs("read", id, `${tab}!A1:R60`); try { return JSON.parse(out); } catch { return []; } };
const readAll = (id) => Object.fromEntries([TAB.basic, TAB.oem, TAB.ch, TAB.sc, TAB.cost].map((t) => [t, readTab(id, t)]));
const tabExists = (id, name) => gs("info", id).includes(`] ${name} `) || gs("info", id).includes(name);

const [cmd, id, arg] = process.argv.slice(2);
if (import.meta.url === `file://${process.argv[1]}` || process.argv[1]?.endsWith("sheet.mjs")) {
  if (cmd === "build") {
    const blank = arg === "--blank";
    const inp = blank ? JSON.parse(readFileSync(path.resolve(here, "../examples/hair-oil.json"), "utf8")) : JSON.parse(readFileSync(arg, "utf8"));
    if (blank) { inp.channels = []; inp.orders = []; inp.scents = []; }
    // ヘアオイルのOEM確認メモ（見本）
    if (!blank && !inp.oem) inp.oem = {};
    const tabs = toTabs(blank ? { ...inp, product: "", pl: { ...inp.pl, pricing: { teikiDeliveries: [], tsudoUnit: "", tsudoUnitBasis: "" } } } : inp, blank);
    for (const [tab, rows] of Object.entries(tabs)) {
      if (!gs("info", id).includes(tab)) gs("addsheet", id, tab);
      const w = Math.max(...rows.map((r) => r.length));
      const col = (n) => { let s = ""; while (n > 0) { const m = (n - 1) % 26; s = String.fromCharCode(65 + m) + s; n = Math.floor((n - 1) / 26); } return s; };
      gs("update", id, `${tab}!A1:${col(w)}${rows.length}`, JSON.stringify(rows.map((r) => [...r, ...Array(w - r.length).fill("")])), "--force");
    }
    console.log("入力票を書きました:", Object.keys(tabs).join(" / "));
  } else if (cmd === "json") {
    console.log(JSON.stringify(fromTabs(readAll(id)), null, 2));
  } else if (cmd === "run") {
    const inp = fromTabs(readAll(id));
    const base = compute(inp);
    const pl = computePL(inp, base);
    const stamp = new Date().toISOString().slice(0, 16).replace("T", " ") + " UTC";
    const rows = resultRows(inp, base, pl, stamp);
    if (!gs("info", id).includes(TAB.res)) gs("addsheet", id, TAB.res);
    const w = Math.max(...rows.map((r) => r.length));
    const col = (n) => String.fromCharCode(64 + n);
    gs("update", id, `${TAB.res}!A1:${col(w)}${rows.length}`, JSON.stringify(rows.map((r) => [...r, ...Array(w - r.length).fill("")])), "--force");
    console.log(`結果を書きました（${rows.length}行）。合計 件数${Math.round(pl.tot.cv)}／個数${Math.round(pl.tot.units)}／売上${yen(pl.tot.rev)}円／営業利益${yen(pl.tot.profit)}円`);
  } else {
    console.error("usage: node sheet.mjs build <id> <case.json|--blank> | run <id> | json <id>");
    process.exit(1);
  }
}
