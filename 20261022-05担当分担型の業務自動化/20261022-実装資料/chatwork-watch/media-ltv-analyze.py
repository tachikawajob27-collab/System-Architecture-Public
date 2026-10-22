#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""媒体別LTV（フェムケア）の集計。media-ltv-report-cloud-prompt.md の §2 が呼ぶ。

使い方: python3 chatwork-watch/media-ltv-analyze.py ltv.json
  ltv.json = シート「媒体別LTV」の値の2次元配列（見出し3行目を含んでも、4行目からでもよい）
  出力は4ブロック＋年間LTV検証。レポートの数字はここの出力だけから取る。
  niks（ASP・メディア・媒体のどれかに niks を含む行）は除外し、冒頭に行数を出す。
"""
import json, re, sys
from collections import defaultdict

if len(sys.argv) < 2:
    sys.exit("usage: media-ltv-analyze.py ltv.json")
raw = json.load(open(sys.argv[1], encoding="utf-8"))
if isinstance(raw, dict):
    raw = raw.get("values", [])

HEAD = ["年", "期間", "F2更新", "F3更新", "備考", "ASP", "メディア", "媒体", "実CPA", "成果単価", "N",
        "F2売上", "F3売上", "年間売上", "NG%", "アップセル率", "クロス％", "キャンセル％", "10日解約％",
        "ＮＧ％*10日解約", "F2継続率", "F3継続率", "F2LTV", "F3LTV", "F2予測LTV", "F2予測限界CPA",
        "F2予測利益", "F3予測LTV", "F3予測限界CPA", "F3予測利益", "年間LTV", "実利益", "営業利益率", "限界CPA"]
ix = {h: i for i, h in enumerate(HEAD)}
for r in raw:  # 見出し行が入っていれば列位置を実測で採用
    if r and str(r[0]).strip() == "年" and "ASP" in r:
        ix = {str(h).strip(): i for i, h in enumerate(r) if str(h).strip()}
        break


def num(s):
    if s is None:
        return None
    s = str(s).replace("¥", "").replace(",", "").replace("%", "").replace("　", "").strip()
    if s in ("", "-", "#N/A", "#DIV/0!"):
        return None
    try:
        return float(s)
    except ValueError:
        return None


def get(r, h):
    i = ix.get(h)
    return r[i] if i is not None and i < len(r) else ""


rows, niks = [], 0
for r in raw:
    if not r or not re.fullmatch(r"\d{4}", str(r[0]).strip()):
        continue
    m = re.match(r"(\d+)/(\d+)", str(get(r, "期間")))
    if not m:
        continue
    if any("niks" in str(get(r, k)).lower() for k in ("ASP", "メディア", "媒体")):
        niks += 1
        continue
    key = (int(r[0]), int(m.group(1)), int(m.group(2)))
    d = {"p": key, "per": str(get(r, "期間")), "asp": str(get(r, "ASP")).strip(),
         "med": str(get(r, "媒体")).strip(), "mdia": str(get(r, "メディア")).strip()}
    for h, k in [("成果単価", "cpa"), ("N", "n"), ("F2予測限界CPA", "lim"), ("F2予測利益", "p2"),
                 ("F3予測利益", "p3"), ("F2継続率", "c2"), ("F3継続率", "c3"), ("アップセル率", "up"),
                 ("クロス％", "cx"), ("キャンセル％", "cn"), ("10日解約％", "d10"), ("F2予測LTV", "l2"),
                 ("年間LTV", "ann"), ("NG%", "ng")]:
        d[k] = num(get(r, h))
    if d["n"] is None:
        continue
    rows.append(d)

if not rows:
    sys.exit("データ行が読めない")

periods = sorted({r["p"] for r in rows})
by_p = defaultdict(list)
for r in rows:
    by_p[r["p"]].append(r)
latest = periods[-1]
pl = lambda p: next(x["per"] for x in by_p[p][:1]) and f"{p[0]}/{by_p[p][0]['per']}"
yen = lambda v: "-" if v is None else f"{v:,.0f}"
pct = lambda v: "-" if v is None else f"{v:.1f}%"
sgn = lambda v: "-" if v is None else f"{v:+,.0f}"
key_of = lambda r: (r["asp"], r["med"])
hist = defaultdict(dict)  # (asp,med) -> {period: row}
for r in rows:
    hist[key_of(r)][r["p"]] = r
pidx = {p: i for i, p in enumerate(periods)}


def wavg(rs, k):
    z = [(r[k], r["n"]) for r in rs if r[k] is not None]
    t = sum(n for _, n in z)
    return sum(v * n for v, n in z) / t if t else None


def f3_diffs(k):
    """過去の期で F3予測利益 - F2予測利益 の列（F3が入っている期のみ）"""
    return [(p, r["p3"] - r["p2"]) for p, r in sorted(hist[k].items()) if r["p3"] is not None and r["p2"] is not None]


print(f"# 媒体別LTV 集計（行数 {len(rows)}／除外した niks 行 {niks}／期間 {len(periods)} 期 最新 {pl(latest)}）")

# ---- 検算
bad = 0
for r in rows:
    if r["lim"] is None or r["cpa"] is None or r["p2"] is None:
        continue
    if abs((r["lim"] - r["cpa"]) * 1.1 - r["p2"]) > 2.0:
        bad += 1
        if r["p"] == latest or bad <= 5:
            print(f"⚠検算外れ {pl(r['p'])} {r['asp']}×{r['med']}: (限界CPA−成果単価)×1.1={(r['lim']-r['cpa'])*1.1:,.0f} ≠ F2予測利益 {r['p2']:,.0f}")
print(f"検算: {'OK' if bad == 0 else f'⚠検算外れ {bad}行'}（余力×1.1＝F2予測利益、±2円）")

# ---- 同値チェック（同一期間・同一ASPで3媒体以上が同じF2継続率）
for p in periods[-4:]:
    g = defaultdict(list)
    for r in by_p[p]:
        if r["c2"] is not None:
            g[(r["asp"], r["c2"])].append(r["med"])
    for (asp, v), ms in g.items():
        if len(ms) >= 3:
            print(f"⚠ 同値 {pl(p)} {asp}: {len(ms)}媒体でF2継続率が{v}%（{'・'.join(ms)}）")

# ---- 1. 時系列
print("\n## 1. 時系列（全体）")
print("期間 | N | 成果単価(税別,N加重) | F2継続率 | F3継続率 | F2予測利益/人(税込) | F3予測利益/人(税込) | F2予測総損益(円) | F3予測総損益(円) | F3入り")
tl = []
for p in periods:
    rs = by_p[p]
    n = sum(r["n"] for r in rs)
    pp = wavg(rs, "p2")
    tot = sum(r["p2"] * r["n"] for r in rs if r["p2"] is not None)
    f3 = sum(1 for r in rs if r["p3"] is not None)
    full = f3 == len(rs)
    p3 = wavg(rs, "p3") if full else None
    tot3 = sum(r["p3"] * r["n"] for r in rs) if full else None
    tl.append((p, pp))
    print(f"{pl(p)} | {n:,.0f} | {yen(wavg(rs, 'cpa'))} | {pct(wavg(rs, 'c2'))} | {pct(wavg(rs, 'c3'))} | {yen(pp)} | {yen(p3)} | {yen(tot)} | {yen(tot3)} | {f3}/{len(rs)}行")
last3 = [x[1] for x in tl[-3:]]
if len(last3) == 3 and None not in last3:
    dec = last3[0] > last3[1] > last3[2]
    print(f"直近3期の全体F2予測利益/人: {' → '.join(yen(v) for v in last3)}（連続低下={dec}、合計{sgn(last3[2]-last3[0])}）")

# ---- 2. 最新期間の全媒体
print(f"\n## 2. 最新期間 {pl(latest)} の全媒体（総損益の大きい順。N≥300=高/100〜299=中/100未満=低）")
print("ASP | 媒体 | N | 成果単価(税別) | F2予測限界CPA(税別) | 余力 | F2予測利益(税込) | 総損益(概算) | 前期比 | F3差(過去の中央値) | 信頼度 | Nシェア")
prev = periods[-2] if len(periods) > 1 else None
cur = by_p[latest]
tn = sum(r["n"] for r in cur)
tot_all = 0.0
for r in sorted(cur, key=lambda r: -((r["p2"] or 0) * r["n"])):
    k = key_of(r)
    pr = hist[k].get(prev) if prev else None
    dprev = sgn(r["p2"] - pr["p2"]) if pr and pr["p2"] is not None and r["p2"] is not None else "前期なし"
    yoyu = r["lim"] - r["cpa"] if r["lim"] is not None and r["cpa"] is not None else None
    tot = (r["p2"] or 0) * r["n"]
    tot_all += tot
    fd = [v for p, v in f3_diffs(k) if p != latest]
    fdm = sorted(fd)[len(fd) // 2] if fd else None
    down = sum(1 for v in fd if v < 0)
    flag = "" if not fd else ("⚠下がる傾向" if down * 2 >= len(fd) and down else ("質よい(上がる)" if down == 0 and len(fd) >= 2 else ""))
    conf = "高" if r["n"] >= 300 else ("中" if r["n"] >= 100 else "低")
    print(f"{r['asp']} | {r['med']} | {r['n']:,.0f} | {yen(r['cpa'])} | {yen(r['lim'])} | {yen(yoyu)} | {yen(r['p2'])} | {yen(tot)} | {dprev} | {sgn(fdm)} {flag} | {conf} | {r['n']/tn*100:.1f}%")
print(f"合計: N {tn:,.0f}／成果単価(N加重) {yen(wavg(cur, 'cpa'))}／F2予測総損益 {yen(tot_all)}円")
red = [r for r in cur if r["n"] >= 300 and r["p2"] is not None and r["p2"] < 0]
thin = [r for r in cur if r["n"] >= 300 and r["lim"] is not None and r["cpa"] is not None and 0 <= r["lim"] - r["cpa"] < 300]
big = max(cur, key=lambda r: r["n"])
print(f"赤字(N≥300でF2予測利益<0): {len(red)}件 " + "、".join(f"{r['asp']}×{r['med']}(N{r['n']:.0f},総損益{(r['p2']*r['n']):,.0f})" for r in red))
print(f"薄利(N≥300で余力<¥300): {len(thin)}件 " + "、".join(f"{r['asp']}×{r['med']}" for r in thin))
print(f"最大媒体: {big['asp']}×{big['med']} N{big['n']:.0f}（{big['n']/tn*100:.1f}%）F2予測利益 {yen(big['p2'])}／余力 {yen(big['lim']-big['cpa']) if big['lim'] is not None and big['cpa'] is not None else '-'}")
f3missing = sum(1 for r in cur if r["p3"] is None)
print(f"F3未入力: {f3missing}/{len(cur)}行（赤字は『仮の赤字』。空欄は0扱いしない）")

# ---- 3. 傾向転換
print("\n## 3. 傾向転換（同一ASP×媒体・連続した期間・2期以上連続で低下/上昇・合計±¥500以上・最新期N≥300）")
found = 0
for k, h in sorted(hist.items()):
    if latest not in h or h[latest]["n"] < 300:
        continue
    chain = [latest]
    while True:  # 最新から遡って連続した期間だけ
        i = pidx[chain[-1]] - 1
        if i < 0 or periods[i] not in h or h[periods[i]]["p2"] is None:
            break
        chain.append(periods[i])
    chain = chain[::-1]
    if len(chain) < 3 or h[latest]["p2"] is None:
        continue
    v = [h[p]["p2"] for p in chain]
    for sign, name in ((-1, "低下"), (1, "上昇")):
        j = len(v) - 1
        while j > 0 and (v[j] - v[j - 1]) * sign > 0:
            j -= 1
        if len(v) - 1 - j >= 2 and (v[-1] - v[j]) * sign >= 500:
            found += 1
            s, e = h[chain[j]], h[latest]
            print(f"- {k[0]}×{k[1]} {name}: " + " → ".join(f"{h[p]['per']}:{yen(h[p]['p2'])}" for p in chain[j:]) + f"（合計{sgn(v[-1]-v[j])}、{len(chain)-j}期）")
            dcpa = (s["cpa"] - e["cpa"]) * 1.1 if s["cpa"] is not None and e["cpa"] is not None else None
            dlim = (e["lim"] - s["lim"]) * 1.1 if s["lim"] is not None and e["lim"] is not None else None
            print(f"    内訳(税込換算・通算): CPA側(成果単価 {yen(s['cpa'])}→{yen(e['cpa'])}) {sgn(dcpa)}／LTV側(F2予測限界CPA {yen(s['lim'])}→{yen(e['lim'])}) {sgn(dlim)}")
            parts = []
            for kk, lab in (("up", "アップセル率"), ("cx", "クロス率"), ("c2", "F2継続率"), ("d10", "10日解約率"), ("cn", "キャンセル率")):
                if s[kk] is not None and e[kk] is not None:
                    parts.append((abs(e[kk] - s[kk]), f"{lab} {s[kk]:.1f}→{e[kk]:.1f}({e[kk]-s[kk]:+.1f}pt)"))
            print("    変化の大きい順: " + "／".join(t for _, t in sorted(parts, reverse=True)))
if not found:
    print("- 該当なし")
# ---- 4. 投資余地
print("\n## 4. 投資余地（余力×伸びしろ件数。『過去に出た件数まで戻せたら』の上限の概算で、単価を上げれば件数が増える根拠ではない）")
print("ASP | 媒体 | 余力(円) | 現N | 過去12か月の最大N(時期,当時の成果単価→今) | 伸びしろ件数 | 目安(円) | F3差の傾向 | 注意")
win = periods[-25:-1] if len(periods) > 1 else []  # 直近12か月=24半月（今期を除く）
out = []
for r in cur:
    k = key_of(r)
    if r["lim"] is None or r["cpa"] is None:
        continue
    yoyu = r["lim"] - r["cpa"]
    h = hist[k]
    past = [h[p] for p in win if p in h]
    note = []
    if len(h) <= 2:
        note.append("判断不可(掲載1〜2期)")
    mx = max(past, key=lambda x: x["n"]) if past else None
    if yoyu < 0:
        note.append("投資ではなく単価の見直し側(余力マイナス)")
    if mx is None or mx["n"] <= r["n"]:
        note.append("戻る先が無い(最大Nが今期)")
        gain, mark = 0, None
    else:
        gain = mx["n"] - r["n"]
    fd = [v for p, v in f3_diffs(k) if p != latest]
    down = sum(1 for v in fd if v < 0)
    ft = "データなし" if not fd else (f"下がる({down}/{len(fd)}期)⚠" if down * 2 >= len(fd) and down else f"上がる/横ばい(下がる{down}/{len(fd)}期)")
    out.append((max(yoyu, 0) * gain, r, yoyu, mx, gain, ft, note))
for score, r, yoyu, mx, gain, ft, note in sorted(out, key=lambda x: -x[0]):
    mxs = f"{mx['n']:.0f}({mx['per']},{yen(mx['cpa'])}→{yen(r['cpa'])})" if mx else "-"
    print(f"{r['asp']} | {r['med']} | {yen(yoyu)} | {r['n']:.0f} | {mxs} | {gain:.0f} | {yen(score)} | {ft} | {'／'.join(note) or '-'}")

# ---- 5. 年間LTV検証
print("\n## 5. 年間LTVでの検証（F2予測LTV と 年間LTV実績の差。年間が空の期間は判断できない）")
ann_p = [p for p in periods if any(r["ann"] is not None and r["l2"] is not None for r in by_p[p])]
if not ann_p:
    print("年間LTVが入っている期間なし")
else:
    la = ann_p[-1]
    print(f"年間が入っている最新期間: {pl(la)}（それ以降の約{len(periods)-1-pidx[la]}期は年間が空＝年間では判断できない）")

    def gap(rs):
        z = [r for r in rs if r["ann"] and r["l2"]]
        a, b = sum(r["l2"] * r["n"] for r in z), sum(r["ann"] * r["n"] for r in z)
        return (a - b) / b * 100 if b else None
    allr = [r for p in ann_p for r in by_p[p]]
    rec = [r for p in ann_p[-12:] for r in by_p[p]]
    g1, g2 = gap(allr), gap(rec)
    print(f"全体: F2予測LTVが年間実績より {g1:+.1f}%／直近半年相当(年間入り最新12期): {g2:+.1f}%（プラス＝予測が過大）")
    pm = defaultdict(list)
    for r in rec:
        pm[key_of(r)].append(r)
    for k, rs in sorted(pm.items(), key=lambda x: -sum(r["n"] for r in x[1])):
        g = gap(rs)
        if g is not None and sum(r["n"] for r in rs) >= 300:
            print(f"- {k[0]}×{k[1]}: {g:+.1f}%" + ("  ← ±5%超：F2予測利益は" + ("過大" if g > 0 else "過小") + "の可能性" if abs(g) > 5 else ""))
