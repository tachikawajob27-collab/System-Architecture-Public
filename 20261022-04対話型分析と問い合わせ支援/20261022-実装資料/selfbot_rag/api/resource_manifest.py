"""resources/ 配下のファイル台帳（差分再インデックス用）。

索引に入れたファイルの「中身のハッシュ」と「索引側の文書ID」を JSON に記録する。
起動のたびにこの台帳と実ファイルを突き合わせ、変わったファイルだけを入れ直す。
台帳は索引と同じ storage/ に置く（索引を消したら台帳も消える＝必ず作り直しになる）。
"""

import hashlib
import json
import logging
import os
from datetime import datetime
from pathlib import Path

logger = logging.getLogger(__name__)

MANIFEST_VERSION = 1
MANIFEST_FILENAME = "resource_manifest.json"


def manifest_path(persist_dir: str) -> str:
    return os.path.join(persist_dir, MANIFEST_FILENAME)


def load_manifest(persist_dir: str) -> dict:
    """台帳を読み込む。無い・壊れている場合は空の台帳を返す。"""
    path = manifest_path(persist_dir)
    if not os.path.exists(path):
        return {"version": MANIFEST_VERSION, "files": {}}
    try:
        with open(path, encoding="utf-8") as f:
            data = json.load(f)
        if not isinstance(data, dict) or not isinstance(data.get("files"), dict):
            raise ValueError("unexpected manifest format")
        if data.get("version") != MANIFEST_VERSION:
            logger.warning(
                f"台帳のバージョンが違います（{data.get('version')} != {MANIFEST_VERSION}）。"
                "全ファイルを入れ直します。"
            )
            return {"version": MANIFEST_VERSION, "files": {}}
        return data
    except Exception as e:
        logger.warning(f"台帳の読み込みに失敗しました: {e}. 全ファイルを入れ直します。")
        return {"version": MANIFEST_VERSION, "files": {}}


def save_manifest(persist_dir: str, files: dict) -> None:
    """台帳を保存する。書き込み途中で壊れないよう一時ファイル経由で置き換える。"""
    os.makedirs(persist_dir, exist_ok=True)
    path = manifest_path(persist_dir)
    tmp_path = f"{path}.tmp"
    payload = {
        "version": MANIFEST_VERSION,
        "updated_at": datetime.now().isoformat(timespec="seconds"),
        "files": files,
    }
    with open(tmp_path, "w", encoding="utf-8") as f:
        json.dump(payload, f, ensure_ascii=False, indent=2)
    os.replace(tmp_path, path)


def file_fingerprint(path) -> dict:
    """ファイルの中身から指紋（SHA-256 とサイズ）を作る。

    更新日時ではなく中身で判定する。git pull や再配置で更新日時だけ変わっても
    中身が同じなら入れ直さないため。
    """
    p = Path(path)
    h = hashlib.sha256()
    size = 0
    with open(p, "rb") as f:
        for chunk in iter(lambda: f.read(1024 * 1024), b""):
            h.update(chunk)
            size += len(chunk)
    return {"hash": h.hexdigest(), "size": size}


def row_fingerprint(text: str) -> dict:
    """文字列（QAリストの1行）から指紋を作る"""
    encoded = text.encode("utf-8")
    return {"hash": hashlib.sha256(encoded).hexdigest(), "size": len(encoded)}


def clear_storage(persist_dir: str) -> None:
    """索引ファイルと台帳を削除する（索引を作り直すとき用）。"""
    if not os.path.isdir(persist_dir):
        return
    for name in os.listdir(persist_dir):
        if not name.endswith(".json"):
            continue
        try:
            os.remove(os.path.join(persist_dir, name))
        except OSError as e:
            logger.warning(f"索引ファイルの削除に失敗しました ({name}): {e}")
