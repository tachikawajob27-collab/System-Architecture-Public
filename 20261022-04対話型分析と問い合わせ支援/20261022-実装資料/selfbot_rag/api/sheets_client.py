"""運用シート（Google スプレッドシート）の読み書き。

「AI学習用正規QAリスト」タブを読み、用途ごとに
resources/<用途>チャットリソース/qa/正規QAリスト_snapshot.csv へ書き出す。
書き出したファイルは差分再インデックスの対象になり、1行＝1文書として索引に入る。

環境変数:
    OPS_SPREADSHEET_ID  運用シートのID（未設定なら何もしない）
    GOOGLE_TOKEN_PATH   認証ファイル（既定: api/google_token.json）
"""

import csv
import json
import logging
import os
from datetime import datetime
from pathlib import Path

logger = logging.getLogger(__name__)

BASE_DIR = os.path.dirname(os.path.abspath(__file__))

SPREADSHEET_ID = os.getenv("OPS_SPREADSHEET_ID", "").strip()


def _default_token_path() -> str:
    """認証ファイルの既定の場所。

    api/google_token.json が無い・空のときは、リポジトリ直下の
    api/google_token.json（他のレポート機能と共用。compose がマウントしている元）を使う。
    手元でスクリプトを動かすときに、空のプレースホルダーを読んで落ちるのを防ぐ。
    """
    local = os.path.join(BASE_DIR, "google_token.json")
    shared = os.path.join(os.path.dirname(os.path.dirname(BASE_DIR)), "api", "google_token.json")
    if (not os.path.exists(local) or os.path.getsize(local) == 0) and os.path.exists(shared):
        return shared
    return local


TOKEN_PATH = os.getenv("GOOGLE_TOKEN_PATH") or _default_token_path()

QA_SHEET_NAME = "AI学習用正規QAリスト"
QA_HEADERS = ["用途", "質問", "回答", "メモ"]
SNAPSHOT_FILENAME = "正規QAリスト_snapshot.csv"

# 用途が空欄のときの既定（仕様書 3章）
DEFAULT_PURPOSE = "エスカレーション用"
# 用途名 -> resources/ 直下のフォルダ名
PURPOSE_DIR_SUFFIX = "チャットリソース"


def _get_creds():
    from google.auth.transport.requests import Request
    from google.oauth2.credentials import Credentials

    if not os.path.exists(TOKEN_PATH) or os.path.getsize(TOKEN_PATH) == 0:
        raise FileNotFoundError(
            f"認証ファイルが無いか空です: {TOKEN_PATH}"
            "（GOOGLE_TOKEN_PATH で場所を指定してください）"
        )
    with open(TOKEN_PATH, encoding="utf-8") as f:
        token_data = json.load(f)
    creds = Credentials(
        token=token_data.get("token"),
        refresh_token=token_data.get("refresh_token"),
        token_uri=token_data.get("token_uri"),
        client_id=token_data.get("client_id") or os.getenv("GOOGLE_CLIENT_ID"),
        client_secret=token_data.get("client_secret") or os.getenv("GOOGLE_CLIENT_SECRET"),
        scopes=token_data.get("scopes"),
    )
    if not creds.valid:
        creds.refresh(Request())
        try:
            with open(TOKEN_PATH, "w", encoding="utf-8") as f:
                f.write(creds.to_json())
        except OSError as e:
            # 読み取り専用でマウントされている場合は保存できないが動作は続けられる
            logger.warning(f"認証ファイルを更新できませんでした: {e}")
    return creds


def fetch_qa_rows(spreadsheet_id: str = None) -> list:
    """正規QAリストを読み、[{用途, 質問, 回答, メモ}, ...] を返す（シート順）"""
    from googleapiclient.discovery import build

    spreadsheet_id = spreadsheet_id or SPREADSHEET_ID
    if not spreadsheet_id:
        raise ValueError("OPS_SPREADSHEET_ID が設定されていません")

    service = build("sheets", "v4", credentials=_get_creds())
    values = service.spreadsheets().values().get(
        spreadsheetId=spreadsheet_id, range=f"'{QA_SHEET_NAME}'!A1:D"
    ).execute().get("values", [])

    if not values:
        logger.warning(f"「{QA_SHEET_NAME}」が空です")
        return []

    header = [h.strip() for h in values[0]]
    # 先頭に空セルがある場合も許容し、用途列が空欄の場合は既定値を使う
    effective_header = [h for h in header if h]
    expected_without_purpose = QA_HEADERS[1:]
    if (
        effective_header != QA_HEADERS
        and header[: len(QA_HEADERS)] != QA_HEADERS
        and effective_header != expected_without_purpose
    ):
        raise ValueError(
            f"「{QA_SHEET_NAME}」の見出しが想定と違います。想定: {QA_HEADERS} / 実際: {header}"
        )

    rows = []
    for raw in values[1:]:
        padded = list(raw) + [""] * (len(QA_HEADERS) - len(raw))
        purpose, question, answer, memo = (c.strip() for c in padded[: len(QA_HEADERS)])
        if not question or not answer:
            continue
        rows.append({
            "用途": purpose or DEFAULT_PURPOSE,
            "質問": question,
            "回答": answer,
            "メモ": memo,
        })
    return rows


def append_qa_rows(rows: list, spreadsheet_id: str = None) -> int:
    """正規QAリストに行を足す。[{用途, 質問, 回答, メモ}, ...]

    すでに同じ質問文がある行は飛ばす。追記した件数を返す。
    """
    from googleapiclient.discovery import build

    spreadsheet_id = spreadsheet_id or SPREADSHEET_ID
    if not spreadsheet_id:
        raise ValueError("OPS_SPREADSHEET_ID が設定されていません")
    if not rows:
        return 0

    service = build("sheets", "v4", credentials=_get_creds())
    existing = {
        v[0].strip()
        for v in service.spreadsheets().values().get(
            spreadsheetId=spreadsheet_id, range=f"'{QA_SHEET_NAME}'!B2:B"
        ).execute().get("values", [])
        if v and v[0].strip()
    }

    values = []
    seen = set()
    for row in rows:
        question = (row.get("質問") or "").strip()
        answer = (row.get("回答") or "").strip()
        if not question or not answer:
            continue
        if question in existing or question in seen:
            logger.info(f"すでにある質問なので飛ばします: {question[:40]}")
            continue
        seen.add(question)
        values.append([
            (row.get("用途") or "").strip() or DEFAULT_PURPOSE,
            question,
            answer,
            (row.get("メモ") or "").strip(),
        ])

    if not values:
        return 0

    append_rows_after_last(service, spreadsheet_id, QA_SHEET_NAME, values)
    logger.info(f"正規QAリストに {len(values)} 件追記しました")
    return len(values)


def append_rows_after_last(service, spreadsheet_id: str, sheet_name: str, values: list) -> int:
    """シートの「最後にデータがある行」の次の行から values を書き込む。

    Sheets API の append は「A1 を起点にデータのかたまりを探し、その下に書く」仕様で、
    A1（見出しの先頭セル）が空だと 1 行目に書き込んで見出しを上書きしてしまう
    （実際に正規QAリスト・会話ログの見出しが消えた）。
    そのため、A列〜末尾列を読んで最終行を自分で求め、その次の行へ update で書く。
    """
    if not values:
        return 0
    last_col = chr(ord("A") + max(len(v) for v in values) - 1)
    existing = service.spreadsheets().values().get(
        spreadsheetId=spreadsheet_id, range=f"'{sheet_name}'!A:{last_col}"
    ).execute().get("values", [])
    next_row = len(existing) + 1
    service.spreadsheets().values().update(
        spreadsheetId=spreadsheet_id,
        range=f"'{sheet_name}'!A{next_row}",
        valueInputOption="RAW",
        body={"values": values},
    ).execute()
    return len(values)


def snapshot_path(resources_dir: str, purpose: str) -> Path:
    folder = purpose if purpose.endswith(PURPOSE_DIR_SUFFIX) else purpose + PURPOSE_DIR_SUFFIX
    return Path(resources_dir) / folder / "qa" / SNAPSHOT_FILENAME


def write_snapshots(rows: list, resources_dir: str) -> dict:
    """用途ごとに snapshot.csv を書き出す。

    中身が同じときはファイルを書き換えない（ハッシュが変わらず、
    差分再インデックスがムダに走らないようにするため）。

    戻り値: {用途: 件数}
    """
    by_purpose = {}
    for row in rows:
        purpose = (row.get("用途") or "").strip() or DEFAULT_PURPOSE
        by_purpose.setdefault(purpose, []).append({**row, "用途": purpose})

    # シートから消えた用途の snapshot は空にせず削除する
    existing = {
        p.parent.parent.name.replace(PURPOSE_DIR_SUFFIX, ""): p
        for p in Path(resources_dir).glob(f"*/qa/{SNAPSHOT_FILENAME}")
    }
    for purpose, path in existing.items():
        if purpose not in by_purpose:
            logger.info(f"シートに用途「{purpose}」が無くなったため snapshot を削除します")
            path.unlink(missing_ok=True)

    counts = {}
    for purpose, purpose_rows in by_purpose.items():
        path = snapshot_path(resources_dir, purpose)
        path.parent.mkdir(parents=True, exist_ok=True)

        buffer = []
        writer = csv.DictWriter(_ListWriter(buffer), fieldnames=QA_HEADERS, lineterminator="\n")
        writer.writeheader()
        for row in purpose_rows:
            writer.writerow({k: row[k] for k in QA_HEADERS})
        content = "".join(buffer)

        if path.exists() and path.read_text(encoding="utf-8-sig") == content:
            logger.info(f"{purpose}: snapshot に変更なし（{len(purpose_rows)} 件）")
        else:
            path.write_text(content, encoding="utf-8-sig", newline="")
            logger.info(f"{purpose}: snapshot を書き出しました（{len(purpose_rows)} 件）")
        counts[purpose] = len(purpose_rows)

    return counts


class _ListWriter:
    """csv.DictWriter の出力を文字列として受け取るための入れ物"""

    def __init__(self, buffer: list):
        self.buffer = buffer

    def write(self, text: str) -> int:
        self.buffer.append(text)
        return len(text)


def sync_qa_snapshots(resources_dir: str, spreadsheet_id: str = None) -> dict:
    """シートを読んで snapshot.csv を更新する。失敗しても例外は投げない。"""
    spreadsheet_id = spreadsheet_id or SPREADSHEET_ID
    if not spreadsheet_id:
        logger.info("OPS_SPREADSHEET_ID が未設定のため、運用シートの取り込みはしません")
        return {}
    if not os.path.exists(TOKEN_PATH):
        logger.warning(f"認証ファイルが無いため運用シートを読めません: {TOKEN_PATH}")
        return {}

    try:
        rows = fetch_qa_rows(spreadsheet_id)
    except Exception as e:
        logger.error(f"運用シートの読み込みに失敗しました: {e}")
        return {}

    try:
        counts = write_snapshots(rows, resources_dir)
    except Exception as e:
        logger.error(f"snapshot の書き出しに失敗しました: {e}")
        return {}

    logger.info(f"運用シートから {len(rows)} 件の Q&A を取り込みました: {counts}")
    return counts
