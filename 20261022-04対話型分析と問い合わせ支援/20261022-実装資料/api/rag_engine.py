import os
import pathlib
import json
import logging
import time
import threading
import google.api_core.exceptions
from typing import List, Optional

from concurrent.futures import ThreadPoolExecutor

from llama_index.core import VectorStoreIndex, SimpleDirectoryReader, StorageContext, load_index_from_storage, Document
from llama_index.llms.gemini import Gemini
from llama_index.embeddings.gemini import GeminiEmbedding
from llama_index.core import Settings, Document
from llama_index.core.vector_stores import MetadataFilters, ExactMatchFilter, FilterCondition

from janome.tokenizer import Tokenizer
from llama_index.retrievers.bm25 import BM25Retriever
from llama_index.core.retrievers import QueryFusionRetriever
from llama_index.core.query_engine import RetrieverQueryEngine

from llm_utils import ModelFallbackManager, get_llama_gemini, get_gemini_model


# 環境変数から設定取得
GEMINI_API_KEY = os.getenv("GEMINI_API_KEY")
if GEMINI_API_KEY:
    os.environ["GOOGLE_API_KEY"] = GEMINI_API_KEY
# プロジェクトルートとストレージパスの設定 (Windows/Docker 両対応)
BASE_DIR = os.path.dirname(os.path.abspath(__file__))        # api/ ディレクトリ
PROJECT_ROOT = os.path.dirname(BASE_DIR)                     # ai_analysis_platform/
SRC_ROOT = os.getenv("SRC_ROOT", os.path.dirname(PROJECT_ROOT))  # c:\src or /src
PERSIST_DIR = os.path.join(BASE_DIR, "storage")
SKIP_RAG_INDEXING = os.getenv("SKIP_RAG_INDEXING", "false").lower() == "true"

# ロギング設定
logger = logging.getLogger(__name__)

# Janomeトークナイザーの準備
_janome_tokenizer = Tokenizer()

def tokenize_japanese_and_english(text: str) -> List[str]:
    # 日本語は形態素解析で単語分割、英数字・記号はそのままトークン化
    tokens = []
    try:
        for token in _janome_tokenizer.tokenize(text):
            surface = token.surface.strip()
            if surface:
                tokens.append(surface)
    except Exception as e:
        logger.warning(f"Janome tokenization failed: {e}. Falling back to space split.")
        tokens = text.split()
    return tokens


def debug_storage():
    """ストレージディレクトリの中身をログ出力するデバッグ用"""
    if os.path.exists(PERSIST_DIR):
        files = os.listdir(PERSIST_DIR)
        logger.info(f"[デバッグ] ストレージディレクトリの内容 ({PERSIST_DIR}): {files}")
    else:
        logger.info(f"[デバッグ] ストレージディレクトリが見つかりません: {PERSIST_DIR}")

def run_with_retry(func, *args, **kwargs):
    max_retries = 5
    base_delay = 10
    for attempt in range(max_retries):
        try:
            return func(*args, **kwargs)
        except (google.api_core.exceptions.ResourceExhausted, Exception) as e:
            err_msg = str(e)
            # 429に加えて、500/503/504などの一時的APIエラーや接続エラーもリトライ対象にする
            is_retryable = (
                "429" in err_msg or 
                "Quota exceeded" in err_msg or 
                "503" in err_msg or 
                "500" in err_msg or 
                "504" in err_msg or 
                isinstance(e, google.api_core.exceptions.ResourceExhausted) or
                isinstance(e, google.api_core.exceptions.ServiceUnavailable) or
                isinstance(e, google.api_core.exceptions.InternalServerError)
            )
            if is_retryable:
                delay = base_delay * (2 ** attempt)
                logger.warning(f"一時的なAPIエラーまたはレートリミットに達しました。{delay}秒後に再試行します... (試行 {attempt+1}/{max_retries}) エラー詳細: {err_msg}")
                time.sleep(delay)
            else:
                logger.error(f"処理中に予期しないエラーが発生しました: {err_msg}")
                raise e
    raise Exception("Max retries exceeded for operation.")

class PHPRagEngine:
    def __init__(self):
        self.lock = threading.Lock()
        self.fallback_manager = ModelFallbackManager(api_key=GEMINI_API_KEY)
        self.llm = None
        self.embed_model = None
        self.index = None
        self._llm_initialized = False
        self._retrievers_cache = {}

    def _ensure_llm_initialized(self):
        if self._llm_initialized:
            return
        with self.lock:
            if self._llm_initialized:
                return
            from llm_utils import FALLBACK_MODELS
            target_model = FALLBACK_MODELS[0]
            logger.info(f"プライマリモデルを初期化中: {target_model}")
            try:
                self.llm = run_with_retry(lambda: Gemini(model=target_model, api_key=GEMINI_API_KEY))
            except Exception as e:
                logger.error(f"Gemini（モデル: {target_model}）の初期化に失敗しました: {str(e)}")
                raise e
            try:
                self.embed_model = run_with_retry(lambda: GeminiEmbedding(model_name="models/gemini-embedding-001", api_key=GEMINI_API_KEY))
            except Exception as e:
                logger.error(f"GeminiEmbeddingの初期化に失敗しました: {str(e)}")
                raise e
            Settings.llm = self.llm
            Settings.embed_model = self.embed_model
            self._llm_initialized = True

    def initialize_index(self):
        self._ensure_llm_initialized()

        # ダブルチェックロッキング（ロック取得前のクイックチェック）
        if self.index is not None:
            return

        with self.lock:
            # ロック取得後のダブルチェック
            if self.index is not None:
                return

            debug_storage()
            if not os.path.exists(PERSIST_DIR):
                os.makedirs(PERSIST_DIR)

            indexed_log_path = os.path.join(PERSIST_DIR, "indexed_dirs.json")
            indexed_gold_path = os.path.join(PERSIST_DIR, "indexed_gold_files.json")
            
            # どのディレクトリを学習済みか記録をロード
            if os.path.exists(indexed_log_path):
                with open(indexed_log_path, "r", encoding="utf-8") as f:
                    indexed_dirs = set(json.load(f))
            else:
                indexed_dirs = set()

            # どの聖典ファイルを学習済みか記録をロード
            if os.path.exists(indexed_gold_path):
                try:
                    with open(indexed_gold_path, "r", encoding="utf-8") as f:
                        indexed_gold_files = set(json.load(f))
                except Exception:
                    indexed_gold_files = set()
            else:
                indexed_gold_files = set()

            storage_exists = os.path.exists(os.path.join(PERSIST_DIR, "docstore.json"))

            if storage_exists:
                logger.info("ストレージから既存のインデックスを読み込み中... [VER 5-clean]")
                try:
                    storage_context = StorageContext.from_defaults(persist_dir=PERSIST_DIR)
                    self.index = load_index_from_storage(storage_context)
                except Exception as e:
                    logger.error(f"既存インデックスの読み込みに失敗しました（破損している可能性があります）: {e}")
                    logger.info("インデックス破損のため、新規作成を開始します。")
                    self.index = None
                    indexed_dirs = set()
                    indexed_gold_files = set()
            else:
                logger.info("既存のインデックスが見つかりません。新規作成を開始します... [VER 5-clean]")
                self.index = None
                indexed_dirs = set()
                indexed_gold_files = set()

            valid_dirs = []
            new_indexed_dirs = set(indexed_dirs)
            new_gold_files = set(indexed_gold_files)

            # SKIP_RAG_INDEXING が True の場合は、ソースコードのスキャンを完全にスキップ
            if SKIP_RAG_INDEXING:
                logger.info("SKIP_RAG_INDEXINGがTrueに設定されています。ソースコードディレクトリのスキャンをスキップします。")
            # /src 配下の各ショップディレクトリを走査して未学習のものを探す
            elif os.path.exists(SRC_ROOT):
                for shop_dir in os.listdir(SRC_ROOT):
                    shop_path = os.path.normpath(os.path.join(SRC_ROOT, shop_dir))
                    
                    # パス全体ではなく、ディレクトリ名（ショップ名）で学習済みか判定
                    shop_name = os.path.basename(shop_path)
                    # 正規化された絶対パスまたはショップ名での判定を強化
                    is_indexed = any(
                        shop_name == os.path.basename(d.rstrip('/')) or 
                        shop_path.replace(os.sep, '/') == d.rstrip('/')
                        for d in indexed_dirs
                    )

                    if os.path.isdir(shop_path):
                        if is_indexed:
                            logger.info(f"ディレクトリ {shop_name} は既にインデックスされているか、ログに存在します。スキップします。")
                            continue
                        
                        target_subdirs = ["data/class", "data/class_extends"]
                        found = False
                        for sub in target_subdirs:
                            full_path = os.path.join(shop_path, sub)
                            if os.path.exists(full_path):
                                logger.info(f"インデックス対象の新規ソースディレクトリが見つかりました: {full_path}")
                                valid_dirs.append(full_path)
                                found = True
                        
                        if found:
                            new_indexed_dirs.add(shop_path.replace(os.sep, '/'))

            # --- Gold Standard（聖典）ファイル一覧 ---
            # 厳選した「本物の正解」のみを明示的にリスト。古いバージョンや試行錯誤の残骸は含めない。
            extra_docs = []
            # 本番コンテナ:/src/cartdev、Windows:SRC_ROOT/cartdev の両方を試す
            _cart_dev_candidates = [os.path.join(SRC_ROOT, "cartDev"), "/src/cartdev"]
            _cart_dev_base = next((p for p in _cart_dev_candidates if os.path.isdir(p)), "")
            CART_DEV_TOTAL = os.path.join(_cart_dev_base, "data", "class_extends", "page_extends", "admin", "total")
            logic_paths = [
                os.path.join(PROJECT_ROOT, "all_logic.csv"),         # SQLロジックの聖典
                os.path.join(BASE_DIR, "all_logic.csv"),             # 同上（api/配下）
                os.path.join(BASE_DIR, "dynamic_glossary.json"),     # ビジネスルール・掟
                os.path.join(BASE_DIR, "knowledge_hints.json"),      # 分析のコツ・禁じ手
                os.path.join(BASE_DIR, "eccube_reference_data.json"),# 2025年正解値
                os.path.join(BASE_DIR, "scent_reference_data.json"), # 香り別正解値
                os.path.join(BASE_DIR, "eccube_reference.py"),       # 2024年正解値
                os.path.join(BASE_DIR, "export_ltv_verified.py"),    # ID:301 検証済みロジック
                os.path.join(BASE_DIR, "scenario_20.json"),          # ASSシナリオ20のJSON定義
                os.path.join(BASE_DIR, "scenario_20_mermaid.md"),    # ASSシナリオ20のフローMermaid定義
                os.path.join(CART_DEV_TOTAL, "LC_Page_Admin_Total_Ass_Ex.php"),    # ASS解約阻止の正解ロジック
                os.path.join(CART_DEV_TOTAL, "LC_Page_Admin_Total_Detail_Ex.php"), # LTV・継続率の管理画面正解ロジック
            ]
            
            for p in logic_paths:
                if os.path.exists(p):
                    normalized_p = os.path.normpath(p).replace(os.sep, '/')
                    if normalized_p in indexed_gold_files:
                        logger.info(f"聖典ファイル {os.path.basename(p)} は既にインデックスされています。スキップします。")
                        continue

                    logger.info(f"新規聖典ファイルを発見しました: {p}。個別に読み込みます。")
                    reader = SimpleDirectoryReader(input_files=[p])
                    docs = reader.load_data()
                    for d in docs:
                        # 用語集、ヒント、CSV、正解値データは最重要ロジック（聖典）として扱う
                        d.metadata.update({"importance": "gold_standard", "type": "sql_logic"})
                    extra_docs.extend(docs)
                    new_gold_files.add(normalized_p)

            if valid_dirs or extra_docs:
                documents = list(extra_docs)
                for v_dir in valid_dirs:
                    logger.info(f"ドキュメントを読み込み中: {v_dir}")
                    reader = SimpleDirectoryReader(
                        input_dir=v_dir,
                        recursive=True,
                        required_exts=[".php", ".csv", ".py", ".sql", ".tpl", ".js"],
                        exclude=["**/vendor/*", "**/node_modules/*", "**/data/cache/*", "**/data/downloads/*", "**/data/logs/*", "**/data/tmp/*", "**/test/*", "**/tests/*"]
                    )
                    new_docs = reader.load_data()
                    for d in new_docs:
                        # 既に type が設定されていない場合のみ source_code を設定
                        if "type" not in d.metadata:
                            d.metadata["type"] = "source_code"
                    documents.extend(new_docs)
                
                if documents:
                    logger.info(f"Loaded {len(documents)} new PHP/reference documents. Indexing in batches...")
                    batch_size = 100
                    if self.index is None:
                        first_doc = documents[:1]
                        self.index = run_with_retry(VectorStoreIndex.from_documents, first_doc)
                        remaining_docs = documents[1:]
                    else:
                        remaining_docs = documents

                    for i in range(0, len(remaining_docs), batch_size):
                        batch = remaining_docs[i:i+batch_size]
                        logger.info(f"Processing batch {i//batch_size + 1}... ({i+1}/{len(remaining_docs)})")
                        for doc in batch:
                            run_with_retry(self.index.insert, doc)
                    
                    self.index.storage_context.persist(persist_dir=PERSIST_DIR)
                    with open(indexed_log_path, "w", encoding="utf-8") as f:
                        json.dump(list(new_indexed_dirs), f)
                    with open(indexed_gold_path, "w", encoding="utf-8") as f:
                        json.dump(list(new_gold_files), f)
            else:
                logger.info("No new PHP directories to index. Proceeding to Notion/Local docs.")

            # --- Part 2: Notion ---
            notion_token = os.getenv("NOTION_INTEGRATION_TOKEN")
            notion_db_ids_str = os.getenv("NOTION_DATABASE_IDS")
            notion_page_ids_str = os.getenv("NOTION_PAGE_IDS")
            
            if notion_token and (notion_db_ids_str or notion_page_ids_str):
                if "notion_hybrid_knowledge" not in new_indexed_dirs:
                    logger.info("Starting Notion Data Ingestion (Hybrid Mode)...")
                    notion_docs = []
                    try:
                        from llama_index.readers.notion import NotionPageReader
                        reader = NotionPageReader(integration_token=notion_token)
                        
                        # DBs
                        if notion_db_ids_str:
                            db_ids = [did.strip() for did in notion_db_ids_str.split(",") if did.strip()]
                            for db_id in db_ids:
                                logger.info(f"Querying Notion Database: {db_id}")
                                try:
                                    page_ids = reader.query_database(database_id=db_id)
                                    if page_ids:
                                        db_docs = reader.load_data(page_ids=page_ids)
                                        notion_docs.extend(db_docs)
                                except Exception as e:
                                    logger.error(f"Failed to load Notion DB {db_id}: {e}")
                        
                        # Pages
                        if notion_page_ids_str:
                            p_ids = [pid.strip() for pid in notion_page_ids_str.split(",") if pid.strip()]
                            if p_ids:
                                logger.info(f"Loading Notion Pages: {p_ids}")
                                try:
                                    p_docs = reader.load_data(page_ids=p_ids)
                                    notion_docs.extend(p_docs)
                                except Exception as e:
                                    logger.error(f"Failed to load Notion Pages: {e}")
                        
                        if notion_docs:
                            logger.info(f"Loaded {len(notion_docs)} Notion documents. Indexing...")
                            for d in notion_docs:
                                d.metadata["type"] = "business_doc"
                            
                            if self.index is None:
                                first_doc = notion_docs[:1]
                                self.index = run_with_retry(VectorStoreIndex.from_documents, first_doc)
                                remaining_notion = notion_docs[1:]
                            else:
                                remaining_notion = notion_docs
                            
                            for doc in remaining_notion:
                                run_with_retry(self.index.insert, doc)
                            
                            new_indexed_dirs.add("notion_hybrid_knowledge")
                            self.index.storage_context.persist(persist_dir=PERSIST_DIR)
                            with open(indexed_log_path, "w", encoding="utf-8") as f:
                                json.dump(list(new_indexed_dirs), f)
                    except Exception as e:
                        logger.error(f"Failed Notion ingestion: {e}")
                else:
                    logger.info("Notion knowledge is already indexed. Skipping.")

            logger.info("Index initialization / update completed successfully. [VER 6-pure-logic]")

    def _get_filtered_nodes(self, allowed_types: List[str]) -> List:
        if self.index is None:
            return []
        try:
            all_nodes = list(self.index.storage_context.docstore.docs.values())
            filtered = []
            for node in all_nodes:
                metadata = getattr(node, 'metadata', {})
                if metadata.get("type") in allowed_types:
                    filtered.append(node)
            return filtered
        except Exception as e:
            logger.error(f"Error filtering nodes: {e}")
            return []

    def _create_hybrid_retriever(self, allowed_types: List[str], similarity_top_k: int):
        # キャッシュ用のキーを作成 (allowed_typesをソートして結合したもの)
        cache_key = "-".join(sorted(allowed_types)) + f"-{similarity_top_k}"
        
        # すでにキャッシュされていれば、それを返す
        if cache_key in self._retrievers_cache:
            return self._retrievers_cache[cache_key]
            
        with self.lock:
            # ダブルチェック
            if cache_key in self._retrievers_cache:
                return self._retrievers_cache[cache_key]
                
            # 1. ベクトル検索用のリトリーバー (メタデータフィルタ付き)
            filters = []
            for t in allowed_types:
                filters.append(ExactMatchFilter(key="type", value=t))
                
            condition = FilterCondition.OR if len(filters) > 1 else FilterCondition.AND
            vector_filters = MetadataFilters(filters=filters, condition=condition)
            
            vector_retriever = self.index.as_retriever(
                similarity_top_k=similarity_top_k,
                filters=vector_filters
            )
            
            # ソースコード (source_code) はノード数が極めて多く、BM25インデックスの初期構築に
            # 多大な時間 (数分レベル) がかかるため、ハイブリッド検索は適用せずベクトル検索のみとする。
            # SQLの聖典やビジネス知識 (Notion等) のみ、キーワード完全一致が重要なためハイブリッド検索を適用する。
            if "source_code" in allowed_types:
                self._retrievers_cache[cache_key] = vector_retriever
                return vector_retriever
                
            # 2. BM25検索用のリトリーバー (該当ノードのみを対象)
            filtered_nodes = self._get_filtered_nodes(allowed_types)
            if not filtered_nodes:
                logger.warning(f"No nodes found for types {allowed_types} to initialize BM25. Falling back to vector search only.")
                self._retrievers_cache[cache_key] = vector_retriever
                return vector_retriever
                
            try:
                # ビジネス知識や聖典は日本語が含まれるため、Janomeによる形態素解析トークナイザーを使用
                bm25_retriever = BM25Retriever.from_defaults(
                    nodes=filtered_nodes,
                    similarity_top_k=similarity_top_k,
                    tokenizer=tokenize_japanese_and_english
                )
                
                # 3. ハイブリッドに融合する
                hybrid_retriever = QueryFusionRetriever(
                    [vector_retriever, bm25_retriever],
                    similarity_top_k=similarity_top_k,
                    num_queries=1,  # クエリ生成をスキップして高速化
                    mode="reciprocal_rerank",  # RRFマージ
                    use_async=False
                )
                
                # キャッシュに保存
                self._retrievers_cache[cache_key] = hybrid_retriever
                return hybrid_retriever
            except Exception as e:
                logger.error(f"Failed to initialize BM25Retriever or QueryFusionRetriever: {e}. Falling back to vector search.")
                self._retrievers_cache[cache_key] = vector_retriever
                return vector_retriever

    def query(self, text):
        if self.index is None:
            self.initialize_index()
        
        if self.index is None:
            return "現在、インデックスが作成されていないため回答できません。ソースコードやドキュメントを配置してください。"

        self._ensure_llm_initialized()

        # 1. SQLロジック（正解）専用ハイブリッドエンジン
        logic_retriever = self._create_hybrid_retriever(["sql_logic"], similarity_top_k=5)
        logic_engine = RetrieverQueryEngine.from_args(retriever=logic_retriever, llm=self.llm)

        # 2. PHPソースコード（実装）専用ハイブリッドエンジン
        code_retriever = self._create_hybrid_retriever(["source_code"], similarity_top_k=8)
        code_engine = RetrieverQueryEngine.from_args(retriever=code_retriever, llm=self.llm)

        # 3. ビジネス知識（Notion/教訓/定義）専用ハイブリッドエンジン
        knowledge_retriever = self._create_hybrid_retriever(
            ["business_doc", "terminology_definition", "analysis_lesson"], 
            similarity_top_k=5
        )
        knowledge_engine = RetrieverQueryEngine.from_args(retriever=knowledge_retriever, llm=self.llm)

        # 3エンジンを並列実行してコンテキストを結合
        logger.info(f"Executing parallel hybrid queries for: {text[:100]}...")
        with ThreadPoolExecutor(max_workers=3) as executor:
            logic_future = executor.submit(lambda: str(logic_engine.query(text)))
            code_future = executor.submit(lambda: str(code_engine.query(text)))
            knowledge_future = executor.submit(lambda: str(knowledge_engine.query(text)))
            logic_response = logic_future.result()
            code_response = code_future.result()
            knowledge_response = knowledge_future.result()

        return (
            f"【SQLロジック参照（all_logic.csv）】\n{logic_response}\n\n"
            f"【PHPソースコード参照】\n{code_response}\n\n"
            f"【ビジネス知識・教訓参照】\n{knowledge_response}"
        )

    def retrieve_contexts(self, text: str) -> dict:
        """指定されたテキストに関連する生のテキストコンテキストをメタデータ別に抽出する"""
        if self.index is None:
            self.initialize_index()
        
        if self.index is None:
            return {
                "sql_logic": "インデックスがありません。",
                "source_code": "インデックスがありません。",
                "business_knowledge": "インデックスがありません。"
            }

        # 1. SQLロジック（正解）専用ハイブリッドリトリーバー
        logic_retriever = self._create_hybrid_retriever(["sql_logic"], similarity_top_k=5)

        # 2. PHPソースコード（実装）専用ハイブリッドリトリーバー
        code_retriever = self._create_hybrid_retriever(["source_code"], similarity_top_k=8)

        # 3. ビジネス知識（Notion/教訓/定義）専用ハイブリッドリトリーバー
        knowledge_retriever = self._create_hybrid_retriever(
            ["business_doc", "terminology_definition", "analysis_lesson"], 
            similarity_top_k=5
        )

        def retrieve_and_process(retriever, query_text, min_score=0.0):
            try:
                nodes_with_score = retriever.retrieve(query_text)
                
                # スコアによるフィルタリングと重複排除
                seen_texts = set()
                processed_nodes = []
                for ns in nodes_with_score:
                    # スコア閾値の適用 (閾値が指定されている場合のみ)
                    if min_score > 0 and ns.score is not None and ns.score < min_score:
                        continue
                        
                    content = ns.node.get_content().strip()
                    # テキスト内容での重複排除 (完全に同じテキストの chunk はスキップ)
                    if content in seen_texts:
                        continue
                    
                    seen_texts.add(content)
                    processed_nodes.append(ns)
                
                if not processed_nodes:
                    return "関連情報が見つかりませんでした。"
                
                # フォーマットして結合
                formatted_contents = []
                for ns in processed_nodes:
                    meta = ns.node.metadata
                    header = ""
                    if "file_path" in meta:
                        header = f"--- Source: {os.path.basename(meta['file_path'])} ---\n"
                    elif "source" in meta:
                        header = f"--- Source: {meta['source']} ---\n"
                    
                    formatted_contents.append(f"{header}{ns.node.get_content().strip()}")
                
                return "\n\n=========================================\n\n".join(formatted_contents)
            except Exception as e:
                logger.error(f"Retriever error during retrieval: {e}")
                return "検索中にエラーが発生しました。"

        # 3つのリトリーバーを並列実行して高速に生テキストを抽出
        logger.info(f"Retrieving raw contexts for: {text[:100]}...")
        with ThreadPoolExecutor(max_workers=3) as executor:
            # sql_logic は聖典なのでスコア閾値なし(0.0)で確実に取る
            logic_future = executor.submit(retrieve_and_process, logic_retriever, text, 0.0)
            # source_code と business_knowledge はノイズを防ぐため、ごく低い類似度(0.15未満)のものは弾く
            code_future = executor.submit(retrieve_and_process, code_retriever, text, 0.15)
            knowledge_future = executor.submit(retrieve_and_process, knowledge_retriever, text, 0.15)
            
            logic_raw = logic_future.result()
            code_raw = code_future.result()
            knowledge_raw = knowledge_future.result()

        return {
            "sql_logic": logic_raw,
            "source_code": code_raw,
            "business_knowledge": knowledge_raw
        }

    def add_definition(self, term: str, definition: str):
        """特定の用語（ビジネス用語など）とその技術的定義をナレッジとして登録・永続化する"""
        if self.index is None:
            self.initialize_index()

        with self.lock:
            content = f"【ビジネス用語の技術定義】\n用語: {term}\n定義: {definition}\n"
            
            doc = Document(
                text=content,
                metadata={
                    "type": "terminology_definition",
                    "source": "conversational_learning",
                    "term": term,
                    "timestamp": time.time()
                }
            )
            
            logger.info(f"Adding new terminology definition to RAG index: {term}")
            run_with_retry(self.index.insert, doc)
            
            # ディスクに永続化
            run_with_retry(self.index.storage_context.persist, persist_dir=PERSIST_DIR)
            logger.info(f"RAG index persisted with new definition for {term}.")

    def add_analysis_lesson(self, query: str, sql: str, feedback: str = ""):
        """成功した解析事例や修正指示をナレッジとして動的に追加し永続化する"""
        if self.index is None:
            self.initialize_index()

        with self.lock:
            content = f"【過去の解析事例・教訓】\n質問: {query}\n生成SQL: {sql}\n"
            if feedback:
                content += f"ユーザーからの修正・フィードバック: {feedback}\n"
            
            # メタデータを付与して Document化
            doc = Document(
                text=content,
                metadata={
                    "type": "analysis_lesson",
                    "source": "conversational_learning",
                    "timestamp": time.time()
                }
            )
            
            logger.info(f"Adding new analysis lesson to RAG index: {query[:50]}...")
            run_with_retry(self.index.insert, doc)
            
            # ディスクに永続化
            run_with_retry(self.index.storage_context.persist, persist_dir=PERSIST_DIR)
            logger.info("RAG index persisted with new lesson.")


# シングルトンインスタンスを提供
engine = PHPRagEngine()
