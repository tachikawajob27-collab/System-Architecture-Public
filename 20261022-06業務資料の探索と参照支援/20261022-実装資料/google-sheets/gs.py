#!/usr/bin/env python3
"""大きな表から指定した範囲を読み取る道具。冒頭説明と商品名の例を一般化。"""
import json
import os
import sys
import urllib.parse
import urllib.request

TOKEN_URL = "https://oauth2.googleapis.com/token"
API = "https://sheets.googleapis.com/v4/spreadsheets"
ENV_VARS = ("GSHEETS_CLIENT_ID", "GSHEETS_CLIENT_SECRET", "GSHEETS_REFRESH_TOKEN")

_token = None

def die(msg, code=1):
    print(msg, file=sys.stderr)
    sys.exit(code)

def token():
    """リフレッシュトークンからアクセストークンを取る（プロセス内でキャッシュ）。"""
    global _token
    if _token:
        return _token
    missing = [v for v in ENV_VARS if not os.environ.get(v)]
    if missing:
        die(
            "環境変数が足りない: " + ", ".join(missing) + "\n"
            "  クラウドセッションでは環境設定に登録済みのはず。ローカルで動かすときは\n"
            "  Google Cloud の OAuth クライアント（デスクトップ）を作り、\n"
            "  スコープ https://www.googleapis.com/auth/spreadsheets.readonly で\n"
            "  取得したリフレッシュトークンを設定する。"
        )
    data = urllib.parse.urlencode(
        {
            "client_id": os.environ["GSHEETS_CLIENT_ID"],
            "client_secret": os.environ["GSHEETS_CLIENT_SECRET"],
            "refresh_token": os.environ["GSHEETS_REFRESH_TOKEN"],
            "grant_type": "refresh_token",
        }
    ).encode()
    try:
        with urllib.request.urlopen(urllib.request.Request(TOKEN_URL, data=data)) as r:
            _token = json.load(r)["access_token"]
    except urllib.error.HTTPError as e:
        die(f"トークンの取得に失敗（HTTP {e.code}）: {e.read().decode('utf-8', 'replace')[:400]}")
    return _token

def api(sheet_id, path="", params=None):
    url = f"{API}/{sheet_id}{path}"
    if params:
        url += "?" + urllib.parse.urlencode(params, doseq=True)
    req = urllib.request.Request(url, headers={"Authorization": "Bearer " + token()})
    try:
        with urllib.request.urlopen(req) as r:
            return json.load(r)
    except urllib.error.HTTPError as e:
        body = e.read().decode("utf-8", "replace")[:400]
        if e.code == 403:
            die(f"権限がない（HTTP 403）。このGoogleアカウントで開けるシートか確認する。\n{body}")
        if e.code == 404:
            die(f"シートが見つからない（HTTP 404）。sheetId を確認する。\n{body}")
        die(f"HTTP {e.code}: {body}")

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

def cmd_doctor():
    print("Googleスプレッドシート接続の点検")
    missing = [v for v in ENV_VARS if not os.environ.get(v)]
    for v in ENV_VARS:
        print(("  OK  " if v not in missing else "  NG  ") + v + (" 未設定" if v in missing else ""))
    if missing:
        die("  → 環境変数が足りないので接続できない。上の使い方を参照。")
    token()
    print("  OK  アクセストークンを取得できた")
    print("  ※ 実際にシートが読めるかは `tabs <sheetId>` で確認する")

def main():
    argv = sys.argv[1:]
    if not argv or argv[0] in ("-h", "--help", "help"):
        print(__doc__)
        return
    cmd = argv[0]
    if cmd == "doctor":
        return cmd_doctor()
    if len(argv) < 2:
        die(f"sheetId が要る。`{sys.argv[0]} --help` を見る。")
    sheet_id = argv[1]
    if cmd == "tabs":
        return cmd_tabs(sheet_id)
    if cmd == "values":
        if len(argv) < 3:
            die("範囲（A1記法）が要る。例: \"'商品群A'!A1:D50\"")
        return cmd_values(sheet_id, argv[2], argv)
    if cmd == "batch":
        ranges = [a for a in argv[2:] if not a.startswith("--")]
        if not ranges:
            die("範囲を1つ以上渡す")
        return cmd_batch(sheet_id, ranges, argv)
    die(f"知らないコマンド: {cmd}")

if __name__ == "__main__":
    main()
