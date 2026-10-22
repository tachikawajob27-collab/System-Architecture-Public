"""会話ログのスプレッドシート転記を手元で1回だけ実行する。

通常はコンテナ内で毎日 DAILY_SYNC_TIME（既定 18:00）に自動で動く。
このスクリプトは、動作確認や、時刻を待たずに反映したいときに使う。

使い方:
    python selfbot_rag/scripts/sync_logs.py --spreadsheet-id <ID>
"""
try:
    import truststore
    truststore.inject_into_ssl()
except ImportError:
    pass

import argparse
import logging
import sys
from pathlib import Path

SELFBOT_DIR = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(SELFBOT_DIR / "api"))

# 他の手動スクリプトと同じく .env を読む（OPS_SPREADSHEET_ID など）
try:
    from dotenv import load_dotenv
    load_dotenv(SELFBOT_DIR / ".env")
    load_dotenv(SELFBOT_DIR.parent / ".env")
except ImportError:
    pass

import log_sync  # noqa: E402


def main() -> int:
    parser = argparse.ArgumentParser(description="会話ログをスプレッドシートへ転記する")
    parser.add_argument("--spreadsheet-id", help="運用シートのID（未指定なら OPS_SPREADSHEET_ID）")
    args = parser.parse_args()

    logging.basicConfig(level=logging.INFO, format="%(levelname)s %(message)s")

    result = log_sync.run_sync(args.spreadsheet_id)
    if not result:
        print("運用シートのIDが設定されていません。--spreadsheet-id を指定してください。")
        return 1

    print("")
    print(f"会話ログ    : {result['会話ログ']} 行を転記")
    for err in result.get("エラー", []):
        print(f"失敗        : {err}")
    print("")
    print("管理者の作業：会話ログで「回答できたか」が × の行を見て、")
    print("　　　　　　　必要なものを正規QAリストに足してください。")
    return 0


if __name__ == "__main__":
    sys.exit(main())
