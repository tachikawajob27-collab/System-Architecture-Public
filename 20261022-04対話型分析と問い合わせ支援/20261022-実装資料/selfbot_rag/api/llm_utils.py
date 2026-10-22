import os
import logging
import time
from typing import List, Callable, Any
from concurrent.futures import ThreadPoolExecutor, TimeoutError as FutureTimeoutError
from google import genai


logger = logging.getLogger(__name__)

# ユーザー指定のモデルリスト（優先順位順）
# 最新の Gemini 3.7 Flash を優先。失敗時は新しい順に Gemini 3.5 Flash、
# Gemini 3.1 Flash へ順次フォールバックする。
FALLBACK_MODELS = [
    "models/gemini-3.7-flash",
    "models/gemini-3.5-flash",
    "models/gemini-3.1-flash",
]

# フォールバック全体のタイムアウト上限（秒）
FALLBACK_TOTAL_TIMEOUT = 150
# 1モデルあたりの最大待ち時間（秒）
PER_MODEL_TIMEOUT = 45

class ModelFallbackManager:
    def __init__(self, api_key: str):
        self.api_key = api_key
        self.models = FALLBACK_MODELS

    def _is_model_specific_error(self, error: Exception, model_name: str) -> bool:
        """
        モデル固有の失敗かどうかを判定する。
        モデル固有：レート制限、一時的なサービス不全、モデル未登録、JSONデコード失敗など。
        非モデル固有：認証・権限・不正リクエストなど、どのモデルでも発生しうるもの。
        """
        error_str = str(error)
        error_type = type(error).__name__

        # レート制限・リソース枯渇
        if any(k in error_str for k in ["429", "ResourceExhausted", "Quota exceeded"]):
            return True
        # 一時的なサービス不全・内部エラー
        if any(k in error_str for k in ["503", "ServiceUnavailable", "500", "InternalServerError", "504"]):
            return True
        # モデル個別の未登録・無効
        if "not found" in error_str.lower() or "does not exist" in error_str.lower():
            return model_name in error_str
        # そのモデルのエンドポイントがHTMLを返すなどのデコード失敗
        if "JSONDecodeError" in error_str or "invalid json" in error_str.lower():
            return True
        # 例外型で判断
        if error_type in ("ResourceExhausted", "ServiceUnavailable", "InternalServerError"):
            return True

        return False

    def run_with_fallback(self, func: Callable[[str], Any], *args, **kwargs) -> Any:
        """
        指定された関数を、モデルを切り替えながら実行します。
        モデル固有の失敗のみ別モデルへフォールバックします。
        全体タイムアウトを超えた場合、または非モデル固有のエラー発生時は即座に失敗します。
        """
        start_time = time.time()
        last_exception = None

        for model_name in self.models:
            elapsed = time.time() - start_time
            if elapsed >= FALLBACK_TOTAL_TIMEOUT:
                logger.error(f"Fallback total timeout exceeded after {elapsed:.1f}s")
                raise TimeoutError(f"Fallback total timeout exceeded after {elapsed:.1f}s")

            remaining = FALLBACK_TOTAL_TIMEOUT - elapsed
            timeout = min(remaining, PER_MODEL_TIMEOUT)

            try:
                logger.info(f"Trying model: {model_name}")
                with ThreadPoolExecutor(max_workers=1) as executor:
                    future = executor.submit(func, model_name, *args, **kwargs)
                    return future.result(timeout=timeout)
            except FutureTimeoutError:
                logger.warning(f"Model {model_name} timed out after {timeout:.1f}s. Switching to next model...")
                last_exception = TimeoutError(f"Model {model_name} timed out after {timeout:.1f}s")
                continue
            except Exception as e:
                if self._is_model_specific_error(e, model_name):
                    logger.warning(f"Model {model_name} failed with model-specific error: {e}. Switching to next model...")
                    last_exception = e
                    continue
                else:
                    logger.error(f"Non-model-specific error with {model_name}: {e}")
                    raise e

        logger.error("All models in fallback list failed.")
        if last_exception is None:
            raise TimeoutError("All models timed out or no model was attempted.")
        raise last_exception

class _GeminiModelWrapper:
    def __init__(self, model_name: str, api_key: str):
        self._client = genai.Client(api_key=api_key)
        self._model_name = model_name

    def generate_content(self, prompt: str):
        return self._client.models.generate_content(model=self._model_name, contents=prompt)

def get_gemini_model(model_name: str, api_key: str):
    return _GeminiModelWrapper(model_name, api_key)

def get_llama_gemini(model_name: str, api_key: str):
    # FALLBACK_MODELS には既に "models/..." 形式で格納されている想定
    if not model_name.startswith("models/"):
        model_name = f"models/{model_name}"
    return Gemini(model_name=model_name, api_key=api_key)


def parse_campaign_params(body: str, api_key: str) -> dict:
    """
    ユーザーのメッセージ本文から施策検証に必要なパラメータ(title, start_date, details)をAIで抽出する。
    """
    import json
    import re
    
    prompt = f"""
以下のテキストから、施策検証レポート（LTVや継続率などの集計）の作成に必要な情報を抽出して、指定のJSON形式で出力してください。

【抽出項目】
1. title (施策のタイトル・名前。テキストから適切に判断してください。例: 「2026年6月オイルパウチ施策」など)
2. start_date (施策の開始日。必ず 'YYYY-MM-DD' 形式に変換してください。年が省略されている場合は、現在の文脈から適切な年（基本的には2025年または2026年）を推測して補うこと。例: 「12/1」なら「2025-12-01」, 「1/15」なら「2026-01-15」など)
3. details (施策の詳細・目的・対象者・キャンペーンの条件など。テキスト全体から施策の意図や集計対象の特定に役立つ説明（adcodeや商品コード等）を漏れなく抽出してください)

【入力テキスト】
{body}

【出力フォーマット】
以下のキーを持つJSONオブジェクトのみを出力してください。余計なマークアップや ```json などのフェンス、解説は一切不要です。
{{
  "title": "施策タイトル",
  "start_date": "YYYY-MM-DD",
  "details": "施策の詳細内容"
}}
"""
    fallback = ModelFallbackManager(api_key=api_key)

    def _call(model_name, p):
        model = get_gemini_model(model_name, api_key)
        resp = model.generate_content(p)
        if not resp or not hasattr(resp, "text"):
            raise ValueError("No response text from Gemini")
        
        # ```json や ``` などのフェンスを除去
        text = resp.text.strip()
        text = re.sub(r"^```(?:json)?\s*", "", text)
        text = re.sub(r"\s*```$", "", text)
        
        # パースを試みる
        parsed = json.loads(text)
        # 必要なキーが含まれているか確認
        for key in ["title", "start_date", "details"]:
            if key not in parsed:
                parsed[key] = ""
        return parsed

    return fallback.run_with_fallback(_call, prompt)

