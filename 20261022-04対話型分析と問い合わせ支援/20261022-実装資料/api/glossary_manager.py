import os
import json
import logging

logger = logging.getLogger(__name__)

DEFAULT_GLOSSARY_PATH = os.path.join(os.path.dirname(os.path.abspath(__file__)), "dynamic_glossary.json")

class GlossaryManager:
    def __init__(self, glossary_path: str = DEFAULT_GLOSSARY_PATH):
        self.glossary_path = glossary_path
        self.glossary = {}
        self.load()

    def load(self):
        if os.path.exists(self.glossary_path):
            try:
                with open(self.glossary_path, "r", encoding="utf-8") as f:
                    self.glossary = json.load(f)
                logger.info(f"Glossary loaded from {self.glossary_path}. ({len(self.glossary)} terms)")
            except Exception as e:
                logger.error(f"Failed to load glossary: {e}")
                self.glossary = {}
        else:
            logger.info("No glossary file found. Starting with empty glossary.")
            self.glossary = {}

    def save(self):
        try:
            # ディレクトリの存在を確認
            os.makedirs(os.path.dirname(self.glossary_path), exist_ok=True)
            with open(self.glossary_path, "w", encoding="utf-8") as f:
                json.dump(self.glossary, f, ensure_ascii=False, indent=4)
            logger.info(f"Glossary saved to {self.glossary_path}")
        except Exception as e:
            logger.error(f"Failed to save glossary: {e}")

    def register_term(self, term: str, definition: str):
        """用語と定義を登録または更新する"""
        self.glossary[term] = definition
        self.save()
        logger.info(f"Registered/Updated term: {term}")

    def get_all_hints(self) -> str:
        """SQL生成AIに渡すためのヒント文字列を生成する"""
        self.load()  # 解析の都度、最新のナレッジをディスクから読み込む
        if not self.glossary:
            return ""
        
        hints = "\n[AIが独自に学習した用語定義]\n"
        for term, definition in self.glossary.items():
            hints += f"- {term}: {definition}\n"
        return hints

# シングルトンインスタンスを提供
# 初期パスは main.py と同じ場所を想定
glossary_manager = GlossaryManager()
