"""会話ログのスプレッドシート転記（仕様書 実装項目 #5）。

1日1回、logs/chat_logs/chat_log_YYYYMMDD.csv の未転記分を
運用シート「会話ログ」タブへ追記する。
すでにシートにある行は追記しない（何度流しても増えない）。

転記後、保持期間（既定 7日）を超えた古い会話ログ CSV を削除する。

AI が答えられなかった質問は「回答できたか」列が × になる。
シート側の条件付き書式で色が付くので、管理者はそこを見て
正規QAリストに Q&A を足す。

環境変数:
    OPS_SPREADSHEET_ID  運用シートのID（未設定なら何もしない）
    GOOGLE_TOKEN_PATH   認証ファイル
    CHAT_LOG_RETENTION_DAYS  会話ログの保持日数（既定: 7）
"""

import logging

import chat_logger
import sheets_client

logger = logging.getLogger(__name__)

CHAT_SHEET_NAME = "会話ログ"


def _service():
    from googleapiclient.discovery import build

    return build("sheets", "v4", credentials=sheets_client._get_creds())


def _read_column(service, spreadsheet_id: str, sheet_name: str, column: str) -> list:
    """指定列を2行目から読む（見出しは飛ばす）"""
    values = service.spreadsheets().values().get(
        spreadsheetId=spreadsheet_id, range=f"'{sheet_name}'!{column}2:{column}"
    ).execute().get("values", [])
    return [(v[0].strip() if v else "") for v in values]


def _append(service, spreadsheet_id: str, sheet_name: str, values: list) -> int:
    # API の append は A1 が空だと見出しを上書きするため、最終行の次に自分で書く
    return sheets_client.append_rows_after_last(service, spreadsheet_id, sheet_name, values)


def build_new_log_rows(log_rows: list, headers: list, existing_keys: set) -> list:
    """まだシートに無い行だけを、見出しの並びで返す。

    同じ日時・同じ質問の行は「転記済み」とみなす。
    """
    new_rows = []
    seen = set(existing_keys)
    for row in log_rows:
        key = ((row.get("日時") or "").strip(), (row.get("質問") or "").strip())
        if not key[0] and not key[1]:
            continue
        if key in seen:
            continue
        seen.add(key)
        new_rows.append([(row.get(h) or "") for h in headers])
    return new_rows


def _existing_keys(service, spreadsheet_id: str, sheet_name: str, question_column: str) -> set:
    """シート上の (日時, 質問) の組を集める"""
    dates = _read_column(service, spreadsheet_id, sheet_name, "A")
    questions = _read_column(service, spreadsheet_id, sheet_name, question_column)
    # 列の長さが揃わないことがあるので短い方に合わせず、足りない分は空で埋める
    length = max(len(dates), len(questions))
    dates += [""] * (length - len(dates))
    questions += [""] * (length - len(questions))
    return set(zip(dates, questions))


def sync_log_sheet(service, spreadsheet_id: str, sheet_name: str,
                   headers: list, question_column: str) -> int:
    """日別会話ログ CSV の未転記分をシートへ追記し、追記件数を返す"""
    rows = chat_logger.read_all_chat_logs()
    if not rows:
        return 0
    existing = _existing_keys(service, spreadsheet_id, sheet_name, question_column)
    new_rows = build_new_log_rows(rows, headers, existing)
    added = _append(service, spreadsheet_id, sheet_name, new_rows)
    if added:
        logger.info(f"「{sheet_name}」に {added} 行転記しました")
    return added


def run_sync(spreadsheet_id: str = None) -> dict:
    """会話ログをスプレッドシートへ転記する。失敗しても例外は投げない。

    転記後、保持期間を超えた古い会話ログ CSV を削除する。

    戻り値: {"会話ログ": 転記行数, "エラー": [失敗内容, ...]}
    失敗はログに書くだけでなく「エラー」に入れて返す（呼び出し側が結果に出せるように）。
    """
    spreadsheet_id = spreadsheet_id or sheets_client.SPREADSHEET_ID
    if not spreadsheet_id:
        logger.info("OPS_SPREADSHEET_ID が未設定のため、ログ転記はしません")
        return {}

    result = {"会話ログ": 0, "エラー": []}
    try:
        service = _service()
    except Exception as e:
        logger.error(f"運用シートに接続できませんでした: {e}")
        result["エラー"].append(f"運用シートに接続できませんでした: {e}")
        return result

    try:
        result["会話ログ"] = sync_log_sheet(
            service, spreadsheet_id, CHAT_SHEET_NAME,
            chat_logger.CHAT_LOG_HEADERS, "C",
        )
    except Exception as e:
        logger.error(f"「{CHAT_SHEET_NAME}」への転記に失敗しました: {e}")
        result["エラー"].append(f"「{CHAT_SHEET_NAME}」への転記に失敗しました: {e}")
        # 転記できていないログを消すと失われるので、削除は次回成功時に回す
        logger.warning("転記に失敗したため、古い会話ログの削除は見送ります")
        return result

    # 転記に成功したときだけ古い会話ログを削除
    try:
        deleted = chat_logger.cleanup_old_chat_logs()
        if deleted:
            logger.info(f"古い会話ログを {deleted} 件削除しました")
    except Exception as e:
        # 転記は済んでいるので処理は続けるが、失敗したことは結果に残す
        # （消せなかった CSV は次回また転記対象になる。二重転記はシート側の重複判定で防ぐ）
        logger.error(f"古い会話ログの削除に失敗しました: {e}")
        result["エラー"].append(f"古い会話ログの削除に失敗しました: {e}")

    logger.info(f"ログ転記 会話ログ={result['会話ログ']}")
    return result
