"""会話ログの CSV 追記（仕様書 3章・実装項目 #3）。

- logs/chat_logs/chat_log_YYYYMMDD.csv … 1質問＝1行。日別の全質問の記録

人が不足QAを探すための材料であり、× 行を除いた分は索引（会話ログ参照）にも使う。
Excel でそのまま開けるよう UTF-8 BOM 付きで書く。
"""

import csv
import logging
import os
import threading
from datetime import datetime, timedelta
from pathlib import Path

import test_marker  # 本番リリース前に削除

logger = logging.getLogger(__name__)

BASE_DIR = os.path.dirname(os.path.abspath(__file__))
PROJECT_ROOT = os.path.dirname(BASE_DIR)
LOGS_DIR = os.getenv("LOGS_DIR", os.path.join(PROJECT_ROOT, "logs"))
CHAT_LOG_RETENTION_DAYS = int(os.getenv("CHAT_LOG_RETENTION_DAYS", "7"))

CHAT_LOG_FILENAME = "chat_log.csv"
CHAT_LOG_DIRNAME = "chat_logs"

CHAT_LOG_HEADERS = [
    "日時",
    "利用者",
    "質問",
    "回答",
    "引用元",
    "回答できたか",
    "類似既存QA",
]

# 「回答できたか＝×」と判定する言い回し
# 資料が無いときにモデルが返しがちな文を対象にする
UNANSWERED_MARKERS = [
    "情報がないため回答できません",
    "情報が見つかりませんでした",
    "明記された情報源は見当たりません",
    "回答できません",
    "お答えできません",
    "安全上の理由から処理できません",
    "正常に処理を完了できませんでした",
]

_write_lock = threading.Lock()


def _log_path(filename: str) -> str:
    return os.path.join(LOGS_DIR, filename)


def _daily_chat_log_path(when: datetime = None) -> str:
    """日別の会話ログ CSV パスを返す。"""
    when = when or datetime.now()
    chat_logs_dir = os.path.join(LOGS_DIR, CHAT_LOG_DIRNAME)
    filename = f"chat_log_{when.strftime('%Y%m%d')}.csv"
    return os.path.join(chat_logs_dir, filename)


def _append_row(filename: str, headers: list, row: dict) -> None:
    """CSV に1行足す。ファイルが無ければ見出しから作る。"""
    path = _log_path(filename)
    try:
        with _write_lock:
            os.makedirs(LOGS_DIR, exist_ok=True)
            is_new = not os.path.exists(path) or os.path.getsize(path) == 0
            with open(path, "a", encoding="utf-8-sig", newline="") as f:
                writer = csv.DictWriter(f, fieldnames=headers, lineterminator="\n")
                if is_new:
                    writer.writeheader()
                writer.writerow({h: row.get(h, "") for h in headers})
    except Exception as e:
        # ログが書けなくても回答は返せるようにする
        logger.error(f"{filename} への追記に失敗しました: {e}")


def _append_row_to_path(path: str, headers: list, row: dict) -> None:
    """指定パスの CSV に1行足す。ファイルが無ければ見出しから作る。"""
    try:
        with _write_lock:
            os.makedirs(os.path.dirname(path), exist_ok=True)
            is_new = not os.path.exists(path) or os.path.getsize(path) == 0
            with open(path, "a", encoding="utf-8-sig", newline="") as f:
                writer = csv.DictWriter(f, fieldnames=headers, lineterminator="\n")
                if is_new:
                    writer.writeheader()
                writer.writerow({h: row.get(h, "") for h in headers})
    except Exception as e:
        logger.error(f"{path} への追記に失敗しました: {e}")


def judge_answered(answer: str) -> str:
    """回答できたかを ○ / × で返す"""
    if not answer or not answer.strip():
        return "×"
    for marker in UNANSWERED_MARKERS:
        if marker in answer:
            return "×"
    return "○"


def format_sources(sources: list) -> str:
    """引用元のリストを1つの文字列にまとめる"""
    if not sources:
        return ""
    parts = []
    for s in sources or []:
        if not isinstance(s, dict):
            parts.append(str(s))
            continue
        name = s.get("file_name") or s.get("source") or ""
        page = s.get("page_label") or ""
        parts.append(f"{name} (p.{page})" if page else name)
    # 同じ資料が何度も出るので重ねずに並べる
    unique = []
    for p in parts:
        if p and p not in unique:
            unique.append(p)
    return " / ".join(unique)


def log_chat(
    question: str,
    answer: str,
    sources: list = None,
    user: str = "",
    similar_qa: str = "",
    answered: str = None,
) -> None:
    """会話ログに1行足す（日別 CSV）"""
    now = datetime.now()
    path = _daily_chat_log_path(now)
    _append_row_to_path(path, CHAT_LOG_HEADERS, {
        "日時": now.strftime("%Y-%m-%d %H:%M:%S"),
        # テスト時は利用者欄に【テスト】を付ける（本番リリース前に削除）
        "利用者": test_marker.mark(user),
        "質問": question,
        "回答": answer,
        "引用元": format_sources(sources),
        "回答できたか": answered if answered is not None else judge_answered(answer),
        "類似既存QA": similar_qa,
    })


def read_log(filename: str) -> list:
    """ログCSVを読む（スプシ転記用）。無ければ空リスト。"""
    path = _log_path(filename)
    if not os.path.exists(path):
        return []
    with open(path, encoding="utf-8-sig", newline="") as f:
        return list(csv.DictReader(f))


def read_all_chat_logs() -> list:
    """日別の会話ログ CSV を全て読み込む（スプシ転記用）。"""
    chat_logs_dir = os.path.join(LOGS_DIR, CHAT_LOG_DIRNAME)
    if not os.path.exists(chat_logs_dir):
        return []
    rows = []
    for path in sorted(Path(chat_logs_dir).glob("chat_log_*.csv")):
        try:
            with open(path, encoding="utf-8-sig", newline="") as f:
                rows.extend(list(csv.DictReader(f)))
        except Exception as e:
            logger.error(f"会話ログの読み込みに失敗しました ({path}): {e}")
    return rows


def cleanup_old_chat_logs(retention_days: int = None) -> int:
    """保持期間を超えた日別会話ログ CSV を削除する。

    戻り値: 削除したファイル数
    """
    retention_days = retention_days if retention_days is not None else CHAT_LOG_RETENTION_DAYS
    if retention_days <= 0:
        return 0

    chat_logs_dir = os.path.join(LOGS_DIR, CHAT_LOG_DIRNAME)
    if not os.path.exists(chat_logs_dir):
        return 0

    cutoff = datetime.now() - timedelta(days=retention_days)
    deleted = 0
    for path in Path(chat_logs_dir).glob("chat_log_*.csv"):
        try:
            # ファイル名から日付を取得
            date_str = path.stem.replace("chat_log_", "")
            file_date = datetime.strptime(date_str, "%Y%m%d")
            if file_date < cutoff:
                path.unlink()
                deleted += 1
                logger.info(f"古い会話ログを削除しました: {path.name}")
        except Exception as e:
            logger.warning(f"会話ログの削除判定に失敗しました ({path}): {e}")
    return deleted
