# 元の処理から、表示方法と必要範囲の取得部分だけを抜粋。
# api、json、urllibは元の実行環境から渡されるため、この抜粋だけでは動作しない。
# 接続先、認証処理、実際の文書番号と資料名は収録していない。
def render_option(argv):
    if "--formula" in argv:
        return "FORMULA"
    if "--raw" in argv:
        return "UNFORMATTED_VALUE"
    return "FORMATTED_VALUE"

def out(rows, as_json):
    if as_json:
        print(json.dumps(rows, ensure_ascii=False))
        return
    for row in rows:
        print("\t".join("" if c is None else str(c) for c in row))

def cmd_tabs(sheet_id):
    """タブ一覧。gid・行数・列数まで出す（列数はどこまで読めるかの目安になる）。"""
    meta = api(sheet_id, "", {"fields": "properties.title,sheets.properties"})
    print(f"# {meta['properties']['title']}")
    print("idx\tgid\trows\tcols\tタブ名")
    for s in meta["sheets"]:
        p = s["properties"]
        g = p.get("gridProperties", {})
        print(f"{p['index']}\t{p['sheetId']}\t{g.get('rowCount','?')}\t{g.get('columnCount','?')}\t{p['title']}")

def cmd_values(sheet_id, rng, argv):
    res = api(sheet_id, "/values/" + urllib.parse.quote(rng), {"valueRenderOption": render_option(argv)})
    out(res.get("values", []), "--json" in argv)

def cmd_batch(sheet_id, ranges, argv):
    res = api(sheet_id, "/values:batchGet", {"ranges": ranges, "valueRenderOption": render_option(argv)})
    if "--json" in argv:
        print(json.dumps(res.get("valueRanges", []), ensure_ascii=False))
        return
    for vr in res.get("valueRanges", []):
        print(f"### {vr.get('range','')}")
        out(vr.get("values", []), False)
