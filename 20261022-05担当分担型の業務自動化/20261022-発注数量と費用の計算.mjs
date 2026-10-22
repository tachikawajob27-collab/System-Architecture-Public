// 元の計算処理からの抜粋。会社固有の入力値や実データは含まない。
export const price = (tiers, qty) => { let p = tiers[0].price; for (const t of tiers) if (qty >= t.min) p = t.price; return p; };

export function compute(inp) {
  const months = inp.months ?? 3;
  const U = { teiki: inp.unitsPerCv?.teiki ?? 3, tsudo: inp.unitsPerCv?.tsudo ?? 1 };

  // 1. 経路ごと：件数(CV) → 個数
  const channels = inp.channels.map((c) => {
    let cv;
    if (c.cvPerMonth != null) cv = c.cvPerMonth * months;
    else if (c.mode === "once") cv = c.population * c.rate;
    else cv = c.population * c.rate * months; // monthly
    const teiki = c.teikiShare ?? inp.teikiShare ?? 0;
    const uT = c.unitsTeiki ?? U.teiki;
    const uD = c.unitsTsudo ?? U.tsudo;
    const unitsPerCv = teiki * uT + (1 - teiki) * uD;
    return { ...c, cv, teiki, unitsPerCv, units: cv * unitsPerCv };
  });
  const totalCv = channels.reduce((s, c) => s + c.cv, 0);
  const totalUnits = channels.reduce((s, c) => s + c.units, 0);
  const weakUnits = channels.filter((c) => c.strength === "×" || c.strength === "△").reduce((s, c) => s + c.units, 0);

  // 2. 香り別
  const scents = inp.scents.map((s) => ({ ...s, units: totalUnits * s.share, perMonth: (totalUnits * s.share) / months }));

  // 3. 発注案ごとの仕入れ・消化
  const orders = (inp.orders ?? []).map((o) => {
    let bulkCost = 0, bulkQty = 0, ctnQty = 0, ctnCost = 0;
    const rows = [];
    const totalBulk = Object.values(o.bulk).reduce((a, b) => a + b, 0);
    for (const [name, qty] of Object.entries(o.bulk)) {
      if (!qty) continue;
      const sc = scents.find((s) => s.name === name);
      const bp = price(inp.costs.bulk[name], qty);
      bulkCost += bp * qty; bulkQty += qty;
      rows.push({ name, qty, bp, coverMonths: sc && sc.perMonth ? qty / sc.perMonth : null });
    }
    const c = inp.costs.container;
    if (c.minMode === "total") ctnQty = Math.max(c.min, totalBulk);
    else ctnQty = Object.values(o.bulk).filter(Boolean).reduce((s, q) => s + Math.max(c.min, q), 0);
    const tierQty = c.tierBy === "total" ? ctnQty : Math.max(c.min, ...Object.values(o.bulk));
    const cp = price(c.tiers, tierQty) + (c.adjust ?? 0);
    ctnCost = cp * ctnQty;
    const mold = o.moldBurden ?? inp.costs.moldBurden ?? 0;
    const total = bulkCost + ctnCost + mold;
    return { name: o.name, rows, bulkQty, bulkCost, ctnQty, ctnCost, ctnPrice: cp, spareCtn: ctnQty - bulkQty, mold, total,
      perFinished: total / bulkQty, perFinishedExMold: (bulkCost + ctnCost) / bulkQty,
      coverTotal: bulkQty / (totalUnits / months) };
  });

  return { months, U, channels, totalCv, totalUnits, weakUnits, scents, orders };
}
