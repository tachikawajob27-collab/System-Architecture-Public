import os
import logging
import time
from typing import List, Callable, Any
import google.api_core.exceptions
from google import genai
from google.genai import types
from llama_index.llms.gemini import Gemini

logger = logging.getLogger(__name__)

# ユーザー指定のモデルリスト（優先順位順）
FALLBACK_MODELS = [
    "models/gemini-3.1-flash-lite",
    "models/gemini-3-flash-preview", # 優先：安定性と推論精度を重視
]

class ModelFallbackManager:
    def __init__(self, api_key: str):
        self.api_key = api_key
        self.models = FALLBACK_MODELS

    def run_with_fallback(self, func: Callable[[str], Any], *args, **kwargs) -> Any:
        """
        指定された関数を、モデルを切り替えながら実行します。
        """
        import json
        last_exception = None
        for model_name in self.models:
            try:
                logger.info(f"Trying model: {model_name}")
                return func(model_name, *args, **kwargs)
            except Exception as e:
                error_str = str(e)
                # レート制限エラー (429)、またはAPIがHTMLを返した場合の JSONDecodeError
                is_rate_limit = "429" in error_str or "ResourceExhausted" in error_str
                is_decode_error = "JSONDecodeError" in error_str or "invalid json" in error_str.lower()
                
                if is_rate_limit or is_decode_error:
                    wait_time = 10 if is_rate_limit else 3
                    logger.warning(f"Model {model_name} failed: {error_str}. Waiting {wait_time}s and trying next...")
                    last_exception = e
                    time.sleep(wait_time)
                    continue
                else:
                    # モデルが存在しない (404) などの致命的エラーも次のモデルへ
                    logger.error(f"Fatal error with model {model_name}: {error_str}")
                    last_exception = e
                    continue
        
        logger.error("All models in fallback list failed.")
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
    return Gemini(model_name=f"models/{model_name}", api_key=api_key)


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

