/*
 * lib.mjs — 収支SIMの計算本体。shushi.mjs と hatchu-sim/scripts/sheet.mjs が共有する。
 * 件数・個数は hatchu-sim/scripts/lib.mjs の compute() の結果を渡す。
 */
import { price } from "../../hatchu-sim/scripts/lib.mjs";

export function computePL(inp, base, opts = {}) {
  const pl = inp.pl;
  if (opts.tsudo != null) pl.pricing.tsudoUnit = opts.tsudo;

  // 1個あたり原価（香り比率で加重。バルクは basisLots のロット単価、容器は containerBasisLot の単価）
  const bulkAvg = base.scents.reduce((s, sc) => s + sc.share * price(inp.costs.bulk[sc.name], pl.cogs.basisLots[sc.name] ?? 5000), 0);
  const ctnUnit = price(inp.costs.container.tiers, pl.cogs.containerBasisLot ?? inp.costs.container.min) + (inp.costs.container.adjust ?? 0);
  const cogsUnit = bulkAvg + ctnUnit;

  // 定期1件の売上・個数・出荷数
  const tdel = pl.pricing.teikiDeliveries;
  const teikiRev = tdel.reduce((s, d) => s + d.price, 0);
  const teikiUnits = tdel.reduce((s, d) => s + d.units, 0);
  const warn = [];
  if (teikiUnits !== base.U.teiki) warn.push(`定期の個数が食い違う：お届けの合計 ${teikiUnits} 個 ≠ unitsPerCv.teiki ${base.U.teiki} 個`);
  const v = pl.variable;

  const rows = base.channels.map((c) => {
    const tsudoRevPerCv = c.tsudoRevenuePerCv ?? pl.pricing.tsudoUnit * (c.unitsTsudo ?? base.U.tsudo);
    const cvT = c.cv * c.teiki, cvD = c.cv * (1 - c.teiki);
    const rev = cvT * teikiRev + cvD * tsudoRevPerCv;
    const ship = cvT * tdel.length + cvD * 1;              // 出荷回数
    const cogs = c.units * cogsUnit;
    const logistics = ship * (v.shippingPerShipment + v.packPerShipment);
    const pay = rev * v.paymentRate, cc = rev * v.ccRate;
    const p = c.promo ?? {};
    const promo = p.yen ?? (p.recipients != null ? p.recipients * (p.sends ?? 1) * (p.bubbles ?? 1) * (p.yenPerBubble ?? 0) : (p.pieces ?? 0) * (p.yenPerPiece ?? 0));
    const profit = rev - cogs - logistics - pay - cc - promo;
    return { name: c.name, cv: c.cv, units: c.units, strength: c.strength, rev, cogs, logistics, pay, cc, promo, profit };
  });
  const sum = (k) => rows.reduce((s, r) => s + r[k], 0);
  const tot = { rev: sum("rev"), cogs: sum("cogs"), logistics: sum("logistics"), pay: sum("pay"), cc: sum("cc"), promo: sum("promo"), profit: sum("profit"), units: base.totalUnits, cv: base.totalCv };
  const nonCogs = tot.logistics + tot.pay + tot.cc + tot.promo;
  const monthlyContribution = (tot.rev - nonCogs) / base.months; // 在庫を除いた現金の入り（月）

  const orders = base.orders.map((o) => {
    const payback = o.total / monthlyContribution;
    return { ...o, payback, soldOutFirst: o.coverTotal < payback };
  });
  return {
    tax: pl.tax, tsudoUnit: pl.pricing.tsudoUnit, tsudoUnitBasis: pl.pricing.tsudoUnitBasis, variableBasis: pl.variableBasis,
    teikiText: tdel.map((d) => `${d.units}個${Math.round(d.price).toLocaleString("ja-JP")}円`).join("＋") + `＝${Math.round(teikiRev).toLocaleString("ja-JP")}円`,
    teikiRev, teikiUnits, bulkAvg, ctnUnit, cogsUnit, warn, rows, tot, molds: pl.moldScenarios ?? [0], orders, monthlyContribution,
  };
}
