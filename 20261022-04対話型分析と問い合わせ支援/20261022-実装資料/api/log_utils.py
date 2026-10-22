import os
import logging
from logging.handlers import RotatingFileHandler

def setup_logging(is_batch=False):
    """
    ロギング設定を初期化する。
    is_batch=True の場合、'batch' ロガーを batch.log 向けにセットアップする。
    is_batch=False の場合、ルートロガーを api.log 向けに、'batch' ロガーを batch.log 向けにセットアップする（FastAPI用）。
    """
    base_dir = os.path.dirname(os.path.abspath(__file__))
    storage_dir = os.path.join(base_dir, "storage")
    os.makedirs(storage_dir, exist_ok=True)

    # ログフォーマット
    formatter = logging.Formatter('%(asctime)s - %(name)s - %(levelname)s - %(message)s')

    if is_batch:
        # バッチ単体起動時（または GitHub Actions での単体起動）
        batch_log_path = os.path.join(storage_dir, "batch.log")
        handler = RotatingFileHandler(batch_log_path, maxBytes=10*1024*1024, backupCount=5, encoding='utf-8')
        handler.setFormatter(formatter)
        
        # 'batch' ロガーの設定
        batch_logger = logging.getLogger("batch")
        batch_logger.setLevel(logging.INFO)
        
        # ハンドラの重複追加を防ぐ
        if not any(isinstance(h, RotatingFileHandler) and h.baseFilename == handler.baseFilename for h in batch_logger.handlers):
            batch_logger.addHandler(handler)
        
        if not any(isinstance(h, logging.StreamHandler) for h in batch_logger.handlers):
            batch_logger.addHandler(logging.StreamHandler())
            
        batch_logger.propagate = False
    else:
        # FastAPI アプリ起動時
        api_log_path = os.path.join(storage_dir, "api.log")
        api_handler = RotatingFileHandler(api_log_path, maxBytes=10*1024*1024, backupCount=5, encoding='utf-8')
        api_handler.setFormatter(formatter)
        
        # ルートロガー（APIサーバー全般とライブラリ用）の設定
        root_logger = logging.getLogger()
        root_logger.setLevel(logging.INFO)
        
        # 既存のハンドラを一旦クリアして再アタッチ
        for h in root_logger.handlers[:]:
            root_logger.removeHandler(h)
            
        root_logger.addHandler(api_handler)
        root_logger.addHandler(logging.StreamHandler())

        # batch.log 用のハンドラ設定
        batch_log_path = os.path.join(storage_dir, "batch.log")
        batch_handler = RotatingFileHandler(batch_log_path, maxBytes=10*1024*1024, backupCount=5, encoding='utf-8')
        batch_handler.setFormatter(formatter)
        
        # 'batch' ロガーの設定
        batch_logger = logging.getLogger("batch")
        batch_logger.setLevel(logging.INFO)
        
        for h in batch_logger.handlers[:]:
            batch_logger.removeHandler(h)
            
        batch_logger.addHandler(batch_handler)
        batch_logger.addHandler(logging.StreamHandler())
        
        # api.log への伝播を防止
        batch_logger.propagate = False
