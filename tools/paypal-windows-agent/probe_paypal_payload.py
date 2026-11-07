"""Read-only PayPal payload probe.

This script deliberately reuses the current Windows collector's PayPal request
and pagination logic, but never uploads to ERP, never writes state.json, and
never updates an ERP cursor.  It is intended to capture a sanitised sample
payload for validating a future VPS relay response contract.
"""

from __future__ import annotations

import argparse
import hashlib
import json
import sys
import uuid
from datetime import datetime, timedelta
from pathlib import Path
from typing import Any

import paypal_agent as agent


def parse_utc(value: str) -> datetime:
    parsed = agent.parse_datetime(value.strip())
    if parsed is None:
        raise argparse.ArgumentTypeError("时间须为 ISO-8601，例如 2026-10-01T00:00:00Z")
    return parsed


def arguments() -> argparse.Namespace:
    parser = argparse.ArgumentParser(
        description="只读采集 PayPal 明细样本；不会上传 ERP 或修改同步状态。"
    )
    parser.add_argument("--config", default=str(agent.DEFAULT_CONFIG_FILE), help="现有 paypal_agent 的 config.json 路径")
    parser.add_argument("--from", dest="start", type=parse_utc, help="开始时间（UTC，含）")
    parser.add_argument("--to", dest="end", type=parse_utc, help="结束时间（UTC，不含）")
    parser.add_argument("--days", type=int, default=2, help="未填写 --from/--to 时，采集最近多少天；默认 2")
    parser.add_argument("--output", default="paypal_payload_probe.json", help="输出 JSON 文件路径")
    parser.add_argument("--raw", action="store_true", help="不脱敏输出；仅限不可对外传播的安全环境")
    return parser.parse_args()


SENSITIVE_KEYS = {
    "email",
    "email_address",
    "payer_email",
    "receiver_email",
    "phone",
    "phone_number",
    "address",
    "line1",
    "line2",
    "given_name",
    "surname",
    "full_name",
    "name",
}


def redact(value: Any, key: str | None = None) -> Any:
    """Keep structure and money/transaction fields, while removing PII."""
    if key is not None and key.lower() in SENSITIVE_KEYS:
        digest = hashlib.sha256(str(value).encode("utf-8")).hexdigest()[:12]
        return f"[REDACTED:{digest}]"
    if isinstance(value, dict):
        return {str(item_key): redact(item_value, str(item_key)) for item_key, item_value in value.items()}
    if isinstance(value, list):
        return [redact(item) for item in value]
    return value


def range_from_args(args: argparse.Namespace) -> tuple[datetime, datetime]:
    if (args.start is None) != (args.end is None):
        raise RuntimeError("--from 与 --to 必须同时填写。")
    if args.start is not None and args.end is not None:
        start, end = args.start, args.end
    else:
        if args.days < 1 or args.days > 30:
            raise RuntimeError("--days 必须介于 1 至 30。")
        end = agent.utc_now() - timedelta(hours=agent.REPORTING_DELAY_HOURS)
        start = end - timedelta(days=args.days)
    if start >= end:
        raise RuntimeError("开始时间必须早于结束时间。")
    if end - start > timedelta(days=30):
        raise RuntimeError("单次样本最长 30 天；请缩短范围。")
    return start, end


def validate_probe_config() -> None:
    missing = [
        name
        for name, value in {
            "paypal_client_id": agent.PAYPAL_CLIENT_ID,
            "paypal_client_secret": agent.PAYPAL_CLIENT_SECRET,
            "paypal_merchant_id": agent.PAYPAL_MERCHANT_ID,
        }.items()
        if not str(value).strip()
    ]
    if missing:
        raise RuntimeError("请填写配置：" + "、".join(missing))
    if agent.PAYPAL_ENVIRONMENT not in {"live", "sandbox"}:
        raise RuntimeError("paypal_environment 只能填写 live 或 sandbox。")
    if not 10 <= agent.REQUEST_TIMEOUT_SECONDS <= 600:
        raise RuntimeError("request_timeout_seconds 必须在 10 至 600。")


def main() -> int:
    args = arguments()
    config = Path(args.config).expanduser().resolve()
    agent.load_config(config)
    validate_probe_config()
    start, end = range_from_args(args)

    client = agent.PayPalClient()
    client.authenticate()
    transaction_segments, transaction_count, transaction_errors = agent.collect_transaction_responses(client, start, end)
    dispute_responses, dispute_count, dispute_errors = agent.collect_dispute_responses(client, start, end)

    payload: dict[str, Any] = {
        "schema_version": 2,
        "probe_only": True,
        "upload_id": str(uuid.uuid4()),
        "merchant_id": agent.PAYPAL_MERCHANT_ID,
        "environment": agent.PAYPAL_ENVIRONMENT,
        "generated_at": agent.iso_utc(agent.utc_now()),
        "period": {"from": agent.iso_utc(start), "to": agent.iso_utc(end)},
        "source_responses": {
            "transaction_search": {"segments": transaction_segments},
            "dispute_search": dispute_responses,
        },
        "collection_summary": {
            "transactions": transaction_count,
            "disputes": dispute_count,
            "errors": len(transaction_errors) + len(dispute_errors),
        },
        "errors": [*transaction_errors, *dispute_errors],
        "collector": {"name": "KKY PayPal payload probe", "version": "1.0"},
    }

    output = Path(args.output).expanduser().resolve()
    output.parent.mkdir(parents=True, exist_ok=True)
    result = payload if args.raw else redact(payload)
    output.write_text(json.dumps(result, ensure_ascii=False, indent=2), encoding="utf-8")
    print(f"已生成只读样本：{output}")
    print(json.dumps(payload["collection_summary"], ensure_ascii=False))
    print("未上传 ERP、未写入 state.json、未修改任何同步游标。")
    return 0 if payload["collection_summary"]["errors"] == 0 else 2


if __name__ == "__main__":
    try:
        raise SystemExit(main())
    except Exception as error:
        print(f"采集失败：{error}", file=sys.stderr)
        raise SystemExit(1)
