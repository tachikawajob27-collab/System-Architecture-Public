import sqlite3
import os
import json
import logging

logger = logging.getLogger(__name__)

BASE_DIR = os.path.dirname(os.path.abspath(__file__))
DB_PATH = os.path.join(BASE_DIR, "analysis_history.db")

class AnalysisHistory:
    def __init__(self):
        self._init_db()

    def _init_db(self):
        """DBの初期化。メッセージIDと解析クエリ・SQLの紐付けを管理"""
        conn = sqlite3.connect(DB_PATH)
        cursor = conn.cursor()
        cursor.execute("""
            CREATE TABLE IF NOT EXISTS history (
                message_id TEXT PRIMARY KEY,
                query TEXT,
                sql TEXT,
                timestamp DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        """)
        conn.commit()
        conn.close()

    def save_result(self, message_id: str, query: str, sql: str):
        """解析結果のメッセージIDと内容を保存"""
        try:
            conn = sqlite3.connect(DB_PATH)
            cursor = conn.cursor()
            cursor.execute(
                "INSERT OR REPLACE INTO history (message_id, query, sql) VALUES (?, ?, ?)",
                (message_id, query, sql)
            )
            conn.commit()
            conn.close()
            logger.info(f"Analysis result saved to history: {message_id}")
        except Exception as e:
            logger.error(f"Failed to save history: {e}")

    def get_result(self, message_id: str):
        """メッセージIDから過去の解析内容を取得"""
        try:
            conn = sqlite3.connect(DB_PATH)
            cursor = conn.cursor()
            cursor.execute("SELECT query, sql FROM history WHERE message_id = ?", (message_id,))
            row = cursor.fetchone()
            conn.close()
            if row:
                return {"query": row[0], "sql": row[1]}
            return None
        except Exception as e:
            logger.error(f"Failed to fetch history: {e}")
            return None

    def cleanup_old_history(self, days: int = 30):
        """指定された日数より前の履歴を削除"""
        try:
            conn = sqlite3.connect(DB_PATH)
            cursor = conn.cursor()
            cursor.execute(
                "DELETE FROM history WHERE timestamp < datetime('now', ?)",
                (f'-{days} days',)
            )
            count = cursor.rowcount
            conn.commit()
            conn.close()
            if count > 0:
                logger.info(f"Cleaned up {count} old history records (older than {days} days).")
        except Exception as e:
            logger.error(f"Failed to cleanup history: {e}")

history_manager = AnalysisHistory()
