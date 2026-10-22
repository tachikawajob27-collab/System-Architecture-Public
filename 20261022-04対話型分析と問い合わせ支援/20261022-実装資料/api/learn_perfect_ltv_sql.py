import os
import sys
import logging
from logging.handlers import RotatingFileHandler
import pandas as pd
from sqlalchemy import create_engine, text

# 1. パスとロギングの設定
base_dir = os.path.dirname(os.path.abspath(__file__))
storage_dir = os.path.join(base_dir, "storage")
log_file_path = os.path.join(storage_dir, "api.log")
os.makedirs(storage_dir, exist_ok=True)

# サーバーと同じログファイル（api.log）に出力する設定
handler = RotatingFileHandler(log_file_path, maxBytes=10*1024*1024, backupCount=5, encoding='utf-8')
logging.basicConfig(
    level=logging.INFO,
    format='%(asctime)s - %(name)s - %(levelname)s - %(message)s',
    handlers=[
        logging.StreamHandler(),
        handler
    ]
)
logger = logging.getLogger("learn_perfect_ltv_sql")

# RAGエンジンをインポートするためにパスを追加
sys.path.append(base_dir)
from rag_engine import engine

# 2. DB接続設定
DB_URL = os.getenv("DB_URL", "postgresql://postgres:password@db:5432/analysis_db")
db_engine = create_engine(DB_URL)

# 3. 完璧なSQL定義
sql_2024 = """WITH cohort_base AS (
    SELECT
        o.customer_id,
        o.create_date AS first_order_date,
        TO_CHAR(o.create_date, 'YYYY-MM') AS cohort_month,
        da.name AS asp_name,
        db.name AS baitai_name
    FROM
        dtb_order o
    INNER JOIN dtb_asp da ON o.asp_id = da.id
    INNER JOIN dtb_baitai db ON o.baitai_id = db.id
    WHERE
        o.is_first = 1
        AND o.create_date >= '2024-07-01 00:00:00'
        AND o.create_date < '2024-10-01 00:00:00'
        AND o.brand_id = 3
        AND o.del_flg = 0
        AND o.status <> 218
        AND o.order_name01 NOT LIKE '%%テスト%%'
        AND o.asp_id IS NOT NULL 
        AND o.baitai_id IS NOT NULL
        AND EXISTS (
            SELECT 1 
            FROM dtb_order_detail od 
            WHERE od.order_id = o.order_id 
              AND od.product_id = 12
        )
        AND NOT EXISTS (
            SELECT 1 
            FROM dtb_order_status dos 
            WHERE dos.order_id = o.order_id 
              AND dos.status = 601
        )
),
cohort_stats AS (
    SELECT
        cohort_month,
        asp_name,
        baitai_name,
        COUNT(DISTINCT customer_id) AS cohort_count
    FROM
        cohort_base
    GROUP BY
        1, 2, 3
    HAVING
        COUNT(DISTINCT customer_id) >= 300
),
ltv_calc AS (
    SELECT
        cb.cohort_month,
        cb.asp_name,
        cb.baitai_name,
        cb.customer_id,
        SUM(CASE WHEN o_sub.create_date < cb.first_order_date + INTERVAL '180 days' THEN o_sub.payment_total ELSE 0 END) AS ltv_180,
        SUM(CASE WHEN o_sub.create_date < cb.first_order_date + INTERVAL '365 days' THEN o_sub.payment_total ELSE 0 END) AS ltv_365,
        SUM(CASE WHEN o_sub.create_date < cb.first_order_date + INTERVAL '545 days' THEN o_sub.payment_total ELSE 0 END) AS ltv_545
    FROM
        cohort_base cb
    INNER JOIN cohort_stats cs 
        ON cb.cohort_month = cs.cohort_month 
        AND cb.asp_name = cs.asp_name 
        AND cb.baitai_name = cs.baitai_name
    LEFT JOIN dtb_order o_sub 
        ON cb.customer_id = o_sub.customer_id
        AND o_sub.del_flg = 0
        AND o_sub.status = 5
    GROUP BY
      1, 2, 3, 4
)
SELECT
    cohort_month AS "初回購入月",
    asp_name AS "ASP名",
    baitai_name AS "媒体名",
    COUNT(DISTINCT customer_id) AS "顧客数(N)",
    ROUND(SUM(ltv_180)::numeric / NULLIF(COUNT(DISTINCT customer_id), 0), 0) AS "180日LTV",
    ROUND(SUM(ltv_365)::numeric / NULLIF(COUNT(DISTINCT customer_id), 0), 0) AS "365日LTV",
    ROUND(SUM(ltv_545)::numeric / NULLIF(COUNT(DISTINCT customer_id), 0), 0) AS "545日LTV"
FROM
    ltv_calc
GROUP BY
    1, 2, 3
ORDER BY
    1, 2, 3;"""

sql_2025 = """WITH cohort_base AS (
    SELECT
        o.customer_id,
        o.create_date AS first_order_date,
        TO_CHAR(o.create_date, 'YYYY-MM') AS cohort_month,
        da.name AS asp_name,
        db.name AS baitai_name
    FROM
        dtb_order o
    INNER JOIN dtb_asp da ON o.asp_id = da.id
    INNER JOIN dtb_baitai db ON o.baitai_id = db.id
    WHERE
        o.is_first = 1
        AND o.create_date >= '2025-07-01 00:00:00'
        AND o.create_date < '2025-11-01 00:00:00'
        AND o.brand_id = 3
        AND o.del_flg = 0
        AND o.status <> 218
        AND o.order_name01 NOT LIKE '%%テスト%%'
        AND o.asp_id IS NOT NULL 
        AND o.baitai_id IS NOT NULL
        AND EXISTS (
            SELECT 1 
            FROM dtb_order_detail od 
            WHERE od.order_id = o.order_id 
              AND od.product_id = 12
        )
        AND NOT EXISTS (
            SELECT 1 
            FROM dtb_order_status dos 
            WHERE dos.order_id = o.order_id 
              AND dos.status = 601
        )
),
cohort_stats AS (
    SELECT
        cohort_month,
        asp_name,
        baitai_name,
        COUNT(DISTINCT customer_id) AS cohort_count
    FROM
        cohort_base
    GROUP BY
        1, 2, 3
    HAVING
        COUNT(DISTINCT customer_id) >= 300
),
ltv_calc AS (
    SELECT
        cb.cohort_month,
        cb.asp_name,
        cb.baitai_name,
        cb.customer_id,
        SUM(CASE WHEN o_sub.create_date < cb.first_order_date + INTERVAL '180 days' THEN o_sub.payment_total ELSE 0 END) AS ltv_180,
        SUM(CASE WHEN o_sub.create_date < cb.first_order_date + INTERVAL '365 days' THEN o_sub.payment_total ELSE 0 END) AS ltv_365,
        SUM(CASE WHEN o_sub.create_date < cb.first_order_date + INTERVAL '545 days' THEN o_sub.payment_total ELSE 0 END) AS ltv_545
    FROM
        cohort_base cb
    INNER JOIN cohort_stats cs 
        ON cb.cohort_month = cs.cohort_month 
        AND cb.asp_name = cs.asp_name 
        AND cb.baitai_name = cs.baitai_name
    LEFT JOIN dtb_order o_sub 
        ON cb.customer_id = o_sub.customer_id
        AND o_sub.del_flg = 0
        AND o_sub.status = 5
    GROUP BY
      1, 2, 3, 4
)
SELECT
    cohort_month AS "初回購入月",
    asp_name AS "ASP名",
    baitai_name AS "媒体名",
    COUNT(DISTINCT customer_id) AS "顧客数(N)",
    ROUND(SUM(ltv_180)::numeric / NULLIF(COUNT(DISTINCT customer_id), 0), 0) AS "180日LTV",
    ROUND(SUM(ltv_365)::numeric / NULLIF(COUNT(DISTINCT customer_id), 0), 0) AS "365日LTV",
    ROUND(SUM(ltv_545)::numeric / NULLIF(COUNT(DISTINCT customer_id), 0), 0) AS "545日LTV"
FROM
    ltv_calc
GROUP BY
    1, 2, 3
ORDER BY
    1, 2, 3;"""

# 4. クエリ文の定義
query_2024 = "オイルを2024年7月～9月に初回購入した顧客を対象に、ASP×媒体ごとに180日LTV→365日LTV→545日LTVを集計してください。各月ごとにASP×媒体でN300以上の媒体のみ集計して、顧客数、180日LTV、365日LTV、545日LTVの形式でまとめてください。"
query_2025 = "オイルを2025年7月～10月に初回購入した顧客を対象に、ASP×媒体ごとに180日LTV→365日LTV→545日LTVを集計してください。各月ごとにASP×媒体でN300以上の媒体のみ集計して、顧客数、180日LTV、365日LTV、545日LTVの形式でまとめてください。"

def run_sql_and_learn():
    logger.info("=== STARTING PERFECT SQL LEARNING SCRIPT ===")
    
    # 2024年のSQL実行テスト
    logger.info("Running 2024 perfect LTV SQL...")
    try:
        with db_engine.connect() as conn:
            df_2024 = pd.read_sql_query(text(sql_2024), conn)
            logger.info(f"2024 SQL Executed Successfully. Result row count: {len(df_2024)}")
            print(df_2024.head(10))
    except Exception as e:
        logger.error(f"Failed to execute 2024 SQL: {e}")
        return

    # 2025年のSQL実行テスト
    logger.info("Running 2025 perfect LTV SQL...")
    try:
        with db_engine.connect() as conn:
            df_2025 = pd.read_sql_query(text(sql_2025), conn)
            logger.info(f"2025 SQL Executed Successfully. Result row count: {len(df_2025)}")
            print(df_2025.head(10))
    except Exception as e:
        logger.error(f"Failed to execute 2025 SQL: {e}")
        return

    # RAG学習
    logger.info("Learning 2024 analysis lesson...")
    try:
        engine.add_analysis_lesson(query_2024, sql_2024)
        logger.info("Successfully learned 2024 LTV cohort analysis lesson!")
    except Exception as e:
        logger.error(f"Failed to learn 2024 lesson: {e}")
        return

    logger.info("Learning 2025 analysis lesson...")
    try:
        engine.add_analysis_lesson(query_2025, sql_2025)
        logger.info("Successfully learned 2025 LTV cohort analysis lesson!")
    except Exception as e:
        logger.error(f"Failed to learn 2025 lesson: {e}")
        return

    logger.info("=== SUCCESSFULLY COMPLETED PERFECT SQL LEARNING & EXECUTION ===")

if __name__ == "__main__":
    run_sql_and_learn()
