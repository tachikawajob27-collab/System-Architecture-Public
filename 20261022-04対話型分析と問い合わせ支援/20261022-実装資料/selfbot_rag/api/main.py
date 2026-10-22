from fastapi import FastAPI, Request, status
from fastapi.staticfiles import StaticFiles
from fastapi.responses import FileResponse, JSONResponse
from fastapi.middleware.cors import CORSMiddleware
from fastapi.exceptions import RequestValidationError
from pydantic import BaseModel, Field
from starlette.concurrency import run_in_threadpool
from slowapi import Limiter, _rate_limit_exceeded_handler
from slowapi.util import get_remote_address
from slowapi.errors import RateLimitExceeded
import asyncio
import logging
import os
import re
import time

import chat_logger

logging.basicConfig(level=logging.INFO, format='%(asctime)s - %(name)s - %(levelname)s - %(message)s')
logger = logging.getLogger(__name__)

app = FastAPI(title="SELFBot RAG API")

# IP ベースのレート制限
limiter = Limiter(key_func=get_remote_address)
app.state.limiter = limiter
app.add_exception_handler(RateLimitExceeded, _rate_limit_exceeded_handler)

# グローバル例外ハンドラー：予期しないエラー時もフレンドリーな JSON を返す
@app.exception_handler(Exception)
async def global_exception_handler(request: Request, exc: Exception):
    logger.error(f"Unhandled error: {exc}", exc_info=True)
    return JSONResponse(
        status_code=status.HTTP_500_INTERNAL_SERVER_ERROR,
        content={
            "question": "",
            "answer": "申し訳ございません。現在 AI サービスに接続できないか、処理中に問題が発生しました。しばらく経ってからお試しください。",
            "sources": []
        },
        headers={"content-type": "application/json; charset=utf-8"}
    )

@app.exception_handler(RequestValidationError)
async def validation_exception_handler(request: Request, exc: RequestValidationError):
    return JSONResponse(
        status_code=status.HTTP_422_UNPROCESSABLE_ENTITY,
        content={
            "question": "",
            "answer": "入力内容に問題があります。質問は1000文字以内で入力してください。",
            "sources": []
        },
        headers={"content-type": "application/json; charset=utf-8"}
    )

# セキュリティヘッダー（CSP）を全レスポンスに付与
@app.middleware("http")
async def add_security_headers(request, call_next):
    response = await call_next(request)
    response.headers["Content-Security-Policy"] = (
        "default-src 'self'; "
        "script-src 'self'; "
        "style-src 'self' 'unsafe-inline'; "
        "img-src 'self' data: https:; "
        "connect-src 'self'; "
        "font-src 'self'; "
        "frame-ancestors 'self';"
    )
    response.headers["X-Content-Type-Options"] = "nosniff"
    response.headers["Referrer-Policy"] = "strict-origin-when-cross-origin"
    return response

# file:// から開かれるローカル HTML 用に CORS を許可
app.add_middleware(
    CORSMiddleware,
    allow_origins=["*"],
    allow_credentials=False,
    allow_methods=["*"],
    allow_headers=["*"],
)

BASE_DIR = os.path.dirname(os.path.abspath(__file__))
PROJECT_ROOT = os.path.dirname(BASE_DIR)
RESOURCES_DIR = os.getenv("RESOURCES_DIR", os.path.join(PROJECT_ROOT, "resources"))

app.mount("/static", StaticFiles(directory="static"), name="static")
app.mount("/resources", StaticFiles(directory=RESOURCES_DIR), name="resources")

@app.get("/")
async def root():
    return FileResponse("static/index.html", media_type="text/html; charset=utf-8")

# プロンプトインジェクション対策：明確な攻撃意図を持つパターンのみを検知
# 「指示」「忘れ」「無視」などの単語だけで誤検出しないよう、文脈を伴う表現に絞る
SUSPICIOUS_PATTERNS = [
    r"ignore\s+(previous|above|earlier)\s+instructions",
    r"forget\s+(previous|above|earlier)\s+instructions",
    r"ignore\s+(the\s+)?(context|above|previous)",
    r"do\s+not\s+follow\s+(the\s+)?(context|above|previous)",
    r"(show|reveal|tell\s+me|display)\s+(your\s+)?(system\s+prompt|instructions)",
    r"上記\s*を\s*無視",
    r"指示\s*を\s*無視",
    r"指示\s*を\s*上書き",
    r"プロンプト\s*を\s*(表示|教え|開示)",
    r"prompt\s+injection",
]

class QueryRequest(BaseModel):
    question: str = Field(..., max_length=1000)

    def check_suspicious(self) -> bool:
        lower = self.question.lower()
        return any(re.search(pattern, lower) for pattern in SUSPICIOUS_PATTERNS)

class QaRequest(BaseModel):
    question: str
    answer: str

# 回答にシステム情報や指示の開示が含まれていないか検証
FORBIDDEN_ANSWER_PATTERNS = [
    r"system\s+prompt",
    r"my\s+instructions",
    r"the\s+instructions\s+(given|provided)\s+to\s+me",
    r"システム\s*プロンプト",
    r"内部\s*指示",
    r"私に与えられた指示",
    r"\[SYSTEM\]",
    r"\[REFERENCE_INFORMATION\]",
]

SAFE_ANSWER_FALLBACK = (
    "申し訳ございませんが、その質問には安全上の理由から回答できません。"
    "別の言い方でお尋ねください。"
)


def validate_answer(answer: str) -> bool:
    """回答にシステムプロンプトや内部指示の開示が含まれていないか検証する"""
    if not answer:
        return False
    lower = answer.lower()
    return not any(re.search(pattern, lower) for pattern in FORBIDDEN_ANSWER_PATTERNS)


def get_client_ip(request: Request) -> str:
    """X-Forwarded-For ヘッダを考慮してクライアント IP を取得する"""
    forwarded = request.headers.get("x-forwarded-for")
    if forwarded:
        return forwarded.split(",")[0].strip()
    return request.client.host if request.client else "unknown"


# ログ転記・確認待ちQA投入・索引への反映を回す時刻（HH:MM）
DAILY_SYNC_TIME = os.getenv("DAILY_SYNC_TIME", "18:00")
DAILY_SYNC_ENABLED = os.getenv("DAILY_SYNC_ENABLED", "true").lower() == "true"
# 上の時刻に、運用シートの内容を索引へ反映し直すか（再起動なしで反映するため）
DAILY_REINDEX_ENABLED = os.getenv("DAILY_REINDEX_ENABLED", "true").lower() == "true"

scheduler = None


def _run_daily_sync() -> dict:
    """ログ転記 → 索引への反映（1日1回）。

    「AI が答えられなかった質問」（会話ログの × 行）が
    正規QAリストに足すべき Q&A の入口になる。
    戻り値: {"会話ログ": 転記行数, "索引": "反映済み" / "スキップ" / "エラー: ...", "エラー": [...]}
    「エラー」には転記・削除で失敗した内容が入る（空なら失敗なし）。
    """
    import log_sync

    result = {"会話ログ": 0, "索引": "スキップ", "エラー": []}
    try:
        sync_result = log_sync.run_sync() or {}
        result["会話ログ"] = sync_result.get("会話ログ", 0)
        result["エラー"].extend(sync_result.get("エラー", []))
    except Exception as e:
        logger.error(f"ログ転記でエラー: {e}")
        result["エラー"].append(f"ログ転記でエラー: {e}")

    if not DAILY_REINDEX_ENABLED:
        return result
    try:
        from rag_engine import engine
        engine.refresh_from_sheet()
        result["索引"] = "反映済み"
    except Exception as e:
        logger.error(f"索引への定期反映でエラー: {e}")
        result["索引"] = f"エラー: {e}"
        result["エラー"].append(f"索引への定期反映でエラー: {e}")
    if result["エラー"]:
        logger.warning(f"DAILY_SYNC 失敗あり: {result['エラー']}")
    return result


# 管理用の呼び出し口。定期実行の時刻を待たずに同じ処理を今すぐ流す（動作確認・急ぎの反映用）。
# 認証は付けていない（第三者に使われる心配が出てきたら、ここで合言葉チェックを足す）。


@app.post("/admin/daily-sync")
async def admin_daily_sync(request: Request):
    """毎日 18:00 の処理（ログ転記 → 索引反映）を今すぐ実行する。

    使い方: curl -X POST http://localhost:8001/admin/daily-sync
    終わるまで待って結果を返す（1分前後かかる）。
    """
    logger.info(f"ADMIN_DAILY_SYNC ip={get_client_ip(request)}")
    return await run_in_threadpool(_run_daily_sync)


def _parse_hhmm(value: str, fallback: str) -> tuple:
    """"18:00" を (18, 0) にする。読めなければ fallback を使う。"""
    for candidate in (value, fallback):
        try:
            hour, minute = (str(candidate).split(":") + ["0"])[:2]
            hour, minute = int(hour), int(minute)
        except (ValueError, AttributeError):
            hour = minute = -1
        if 0 <= hour <= 23 and 0 <= minute <= 59:
            if candidate is not value:
                logger.error(f"時刻の指定が読めません: {value}（{fallback} を使います）")
            return hour, minute
    return 0, 0


def _start_scheduler():
    global scheduler

    jobs = []
    if DAILY_SYNC_ENABLED:
        jobs.append(("daily_log_sync", _run_daily_sync,
                     _parse_hhmm(DAILY_SYNC_TIME, "18:00"),
                     "ログ転記・確認待ちQA投入"))
    else:
        logger.info("DAILY_SYNC_ENABLED が false のためログ転記はしません")

    if not jobs:
        return

    try:
        from apscheduler.schedulers.background import BackgroundScheduler
        from apscheduler.triggers.cron import CronTrigger

        scheduler = BackgroundScheduler(timezone=os.getenv("TZ", "Asia/Tokyo"))
        for job_id, func, (hour, minute), label in jobs:
            scheduler.add_job(
                func,
                CronTrigger(hour=hour, minute=minute),
                id=job_id,
                replace_existing=True,
            )
            logger.info(f"{label}を毎日 {hour:02d}:{minute:02d} に実行します")
        scheduler.start()
    except Exception as e:
        logger.error(f"定期実行の設定に失敗しました: {e}")


@app.on_event("startup")
async def startup_event():
    from rag_engine import engine
    logger.info("Initializing RAG index on startup...")
    engine.initialize_index()
    _start_scheduler()


@app.on_event("shutdown")
async def shutdown_event():
    if scheduler is not None:
        scheduler.shutdown(wait=False)

@app.get("/health")
async def health():
    return {"status": "ok"}

# 回答生成の最大待機時間（秒）
CHAT_TIMEOUT = 60

# レート制限：1 IP あたり 1 分間に 10 リクエスト
CHAT_RATE_LIMIT = "10/minute"


@app.post("/chat")
@limiter.limit(CHAT_RATE_LIMIT)
async def chat(request: Request, req: QueryRequest):
    from rag_engine import engine

    client_ip = get_client_ip(request)
    start_time = time.time()
    question_preview = req.question[:200]

    logger.info(f"CHAT_INPUT ip={client_ip} question={question_preview!r}")

    # プロンプトインジェクション対策：危険な入力を検知
    if req.check_suspicious():
        logger.warning(f"CHAT_SUSPICIOUS ip={client_ip} question={question_preview!r}")
        chat_logger.log_chat(
            question=req.question,
            answer="（安全上の理由で処理しませんでした）",
            user=client_ip,
            answered="×",
        )
        return JSONResponse(
            content={
                "question": req.question,
                "answer": "申し訳ございませんが、その質問は安全上の理由から処理できません。別の言い方でお尋ねください。",
                "sources": []
            },
            headers={"content-type": "application/json; charset=utf-8"}
        )

    try:
        result = await asyncio.wait_for(
            run_in_threadpool(engine.query, req.question),
            timeout=CHAT_TIMEOUT
        )
    except asyncio.TimeoutError:
        logger.warning(
            f"CHAT_TIMEOUT ip={client_ip} duration={time.time() - start_time:.2f}s "
            f"question={question_preview!r}"
        )
        chat_logger.log_chat(
            question=req.question,
            answer="（時間内に回答を作れませんでした）",
            user=client_ip,
            answered="×",
        )
        return JSONResponse(
            content={
                "question": req.question,
                "answer": "申し訳ございません。回答の生成に時間がかかり、正常に処理を完了できませんでした。お手数ですが、システム管理者にお問い合わせください。",
                "sources": []
            },
            headers={"content-type": "application/json; charset=utf-8"}
        )

    answer = result.get("answer", "")
    answer_preview = answer[:200]

    # 回答の事後検証
    if not validate_answer(answer):
        logger.warning(
            f"CHAT_UNSAFE_OUTPUT ip={client_ip} question={question_preview!r} "
            f"answer={answer_preview!r}"
        )
        chat_logger.log_chat(
            question=req.question,
            answer=SAFE_ANSWER_FALLBACK,
            user=client_ip,
            answered="×",
        )
        return JSONResponse(
            content={
                "question": req.question,
                "answer": SAFE_ANSWER_FALLBACK,
                "sources": []
            },
            headers={"content-type": "application/json; charset=utf-8"}
        )

    logger.info(
        f"CHAT_OUTPUT ip={client_ip} duration={time.time() - start_time:.2f}s "
        f"question={question_preview!r} answer={answer_preview!r}"
    )

    chat_logger.log_chat(
        question=req.question,
        answer=answer,
        sources=result.get("sources", []),
        user=client_ip,
        similar_qa=result.get("similar_qa", ""),
    )

    return JSONResponse(
        content={
            "question": req.question,
            "answer": answer,
            "sources": result.get("sources", [])
        },
        headers={"content-type": "application/json; charset=utf-8"}
    )

@app.post("/qa")
@limiter.limit("30/minute")
async def add_qa(request: Request, req: QaRequest):
    from rag_engine import engine
    client_ip = get_client_ip(request)
    logger.info(
        f"QA_REGISTER ip={client_ip} question={req.question[:200]!r} "
        f"answer={req.answer[:200]!r}"
    )
    await run_in_threadpool(engine.add_qa, req.question, req.answer)
    return {"status": "ok", "message": "Q&A registered"}

