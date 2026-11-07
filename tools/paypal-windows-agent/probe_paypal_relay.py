"""Read-only probe for the VPS PayPal relay.

The script talks only to the VPS relay, not to PayPal.  It validates the relay
TLS certificate SHA-1 pin and both HMAC signatures, then saves the complete
paginated transaction and dispute responses.  It never calls ERP and never
writes ERP data.
"""

from __future__ import annotations

import argparse
import base64
import hashlib
import hmac
import http.client
import json
import secrets
import ssl
import sys
import time
from datetime import datetime, timedelta, timezone
from pathlib import Path
from typing import Any
from urllib.parse import urlsplit


PATH = "/relay/v1/paypal/read"
UTC = timezone.utc
MAX_RESPONSE_BYTES = 20 * 1024 * 1024
MAX_DISPUTE_PAGES = 100
DISPUTE_SEGMENT_DAYS = 7

# 在 PyCharm 中直接填写以下四项。不要提交包含真实密钥的文件。
RELAY_URL = "https://154.83.12.101/relay/v1/paypal/read"
RELAY_CERTIFICATE_SHA1 = "8E77A10559511274F027E0412C38843CC866BC33"
RELAY_HMAC_KEY_ID = "erp-main"
RELAY_HMAC_SECRET_BASE64 = "3DFJMm8PgG93GgjOpfRUlm5bJRDoRBJQeBHZ94OB8j4="


def parse_utc(value: str) -> datetime:
    try:
        parsed = datetime.fromisoformat(value.strip().replace("Z", "+00:00"))
    except ValueError as error:
        raise argparse.ArgumentTypeError("时间须为 ISO-8601，例如 2026-10-01T00:00:00Z") from error
    return parsed.astimezone(UTC)


def iso_utc(value: datetime) -> str:
    return value.astimezone(UTC).isoformat(timespec="seconds").replace("+00:00", "Z")


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="只读请求 VPS PayPal 中转接口，不会写入 ERP。")
    parser.add_argument("--from", dest="start", type=parse_utc, help="开始时间（UTC，含）")
    parser.add_argument("--to", dest="end", type=parse_utc, help="结束时间（UTC，不含）")
    parser.add_argument("--days", type=int, default=2, help="未填写 --from/--to 时查询最近天数，默认 2")
    parser.add_argument("--page-size", type=int, default=500, help="每页条数，1 至 500，默认 500")
    parser.add_argument("--output", default="paypal_relay_probe.json", help="输出 JSON 文件")
    parser.add_argument("--raw", action="store_true", help="不脱敏输出；只可保存于受控环境")
    return parser.parse_args()


def relay_settings() -> tuple[str, str, str, bytes]:
    relay_url = RELAY_URL.strip()
    thumbprint = RELAY_CERTIFICATE_SHA1.replace(":", "").strip().lower()
    key_id = RELAY_HMAC_KEY_ID.strip()
    encoded_secret = RELAY_HMAC_SECRET_BASE64.strip()
    parts = urlsplit(relay_url)
    if parts.scheme != "https" or not parts.hostname or parts.path != PATH or parts.query or parts.fragment or parts.username or parts.password:
        raise RuntimeError(f"relay_url 必须是 HTTPS 且路径固定为 {PATH}。")
    if len(thumbprint) != 40 or any(character not in "0123456789abcdef" for character in thumbprint):
        raise RuntimeError("relay_certificate_sha1 必须是 40 位 SHA-1 十六进制指纹。")
    if not key_id or any(character not in "ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789._-" for character in key_id):
        raise RuntimeError("relay_hmac_key_id 格式不正确。")
    try:
        secret = base64.b64decode(encoded_secret, validate=True)
    except Exception as error:
        raise RuntimeError("relay_hmac_secret 必须是 Base64 文本。") from error
    if len(secret) < 32:
        raise RuntimeError("relay_hmac_secret 解码后至少需要 32 字节。")
    return relay_url, thumbprint, key_id, secret


def time_range(args: argparse.Namespace) -> tuple[datetime, datetime]:
    if (args.start is None) != (args.end is None):
        raise RuntimeError("--from 与 --to 必须同时填写。")
    if args.start is not None and args.end is not None:
        start, end = args.start, args.end
    else:
        if not 1 <= args.days <= 30:
            raise RuntimeError("--days 必须介于 1 至 30。")
        end = datetime.now(UTC) - timedelta(hours=4)
        start = end - timedelta(days=args.days)
    if start >= end or end - start > timedelta(days=30):
        raise RuntimeError("查询区间须大于 0 且不超过 30 天。")
    return start, end


def request_relay(relay_url: str, thumbprint: str, key_id: str, secret: bytes, operation: str, query: dict[str, Any]) -> tuple[int, dict[str, str], bytes]:
    timestamp = str(int(time.time()))
    nonce = secrets.token_hex(16)
    body = json.dumps({"operation": operation, "query": query}, ensure_ascii=False, separators=(",", ":")).encode("utf-8")
    message = "\n".join(["POST", PATH, timestamp, nonce, hashlib.sha256(body).hexdigest()]).encode("utf-8")
    signature = hmac.new(secret, message, hashlib.sha256).hexdigest()
    target = urlsplit(relay_url)

    # The exact certificate pin is verified before any HTTP request is sent.
    context = ssl._create_unverified_context()
    context.minimum_version = ssl.TLSVersion.TLSv1_2
    connection = http.client.HTTPSConnection(target.hostname, target.port or 443, timeout=60, context=context)
    try:
        connection.connect()
        certificate = connection.sock.getpeercert(binary_form=True) if connection.sock else None
        actual = hashlib.sha1(certificate or b"").hexdigest()
        if not hmac.compare_digest(actual, thumbprint):
            raise RuntimeError(f"VPS 证书指纹不匹配：实际为 {actual}")

        connection.request(
            "POST",
            PATH,
            body=body,
            headers={
                "Accept": "application/json",
                "Accept-Encoding": "identity",
                "Content-Type": "application/json",
                "Content-Length": str(len(body)),
                "X-Relay-Key": key_id,
                "X-Relay-Timestamp": timestamp,
                "X-Relay-Nonce": nonce,
                "X-Relay-Signature": signature,
            },
        )
        response = connection.getresponse()
        raw = response.read(MAX_RESPONSE_BYTES + 1)
        if len(raw) > MAX_RESPONSE_BYTES:
            raise RuntimeError("VPS 响应超过 20 MB，已拒绝保存。")
        headers = {key.lower(): value for key, value in response.getheaders()}
        response_timestamp = headers.get("x-relay-response-timestamp", "")
        response_signature = headers.get("x-relay-response-signature", "")
        if not response_timestamp.isdigit() or len(response_signature) != 64:
            raise RuntimeError("VPS 响应缺少或包含无效 HMAC 签名。")
        if abs(int(time.time()) - int(response_timestamp)) > 300:
            raise RuntimeError("VPS 响应时间戳超出 5 分钟允许误差。")
        response_message = "\n".join([str(response.status), response_timestamp, nonce, hashlib.sha256(raw).hexdigest()]).encode("utf-8")
        expected = hmac.new(secret, response_message, hashlib.sha256).hexdigest()
        if not hmac.compare_digest(expected, response_signature.lower()):
            raise RuntimeError("VPS 响应 HMAC 验签失败。")
        return response.status, headers, raw
    finally:
        connection.close()


def parse_payload(status: int, raw: bytes) -> dict[str, Any]:
    try:
        payload = json.loads(raw.decode("utf-8"))
    except (UnicodeDecodeError, json.JSONDecodeError) as error:
        raise RuntimeError("VPS 响应不是有效 JSON。") from error
    if status < 200 or status >= 300:
        raise RuntimeError(f"VPS 返回 HTTP {status}：{json.dumps(payload, ensure_ascii=False)[:1000]}")
    if not isinstance(payload, dict):
        raise RuntimeError("VPS 成功响应必须是 JSON 对象。")
    return payload


def collect_disputes(
    relay_url: str,
    thumbprint: str,
    key_id: str,
    secret: bytes,
    start: datetime,
    end: datetime,
) -> tuple[dict[str, Any], int]:
    """Fetch every dispute-list page and every returned dispute detail."""
    request_bases: list[dict[str, str]] = []
    list_responses: list[dict[str, Any]] = []
    dispute_ids: dict[str, None] = {}

    cursor = start
    while cursor < end:
        segment_end = min(cursor + timedelta(days=DISPUTE_SEGMENT_DAYS), end)
        request_base = {
            "update_time_after": iso_utc(cursor),
            "update_time_before": iso_utc(segment_end),
            "page_size": "50",
        }
        request_bases.append(request_base)
        for page in range(1, MAX_DISPUTE_PAGES + 1):
            query = {**request_base, "page": str(page)}
            status, _headers, raw = request_relay(
                relay_url, thumbprint, key_id, secret, "dispute_search", query
            )
            payload = parse_payload(status, raw)
            items = payload.get("items")
            if not isinstance(items, list):
                raise RuntimeError("纠纷列表响应缺少 items 数组。")
            list_responses.append(payload)
            for item in items:
                if not isinstance(item, dict):
                    raise RuntimeError("纠纷列表 items 中包含非对象记录。")
                dispute_id = str(item.get("dispute_id") or "").strip()
                if not dispute_id:
                    raise RuntimeError("纠纷列表记录缺少 dispute_id。")
                dispute_ids[dispute_id] = None

            links = payload.get("links")
            has_next = isinstance(links, list) and any(
                isinstance(link, dict) and str(link.get("rel") or "").lower() == "next"
                for link in links
            )
            if not has_next:
                break
            if not items:
                raise RuntimeError("纠纷列表声明仍有下一页，但当前页没有 items。")
        else:
            raise RuntimeError(f"纠纷列表超过 {MAX_DISPUTE_PAGES} 页，已停止。")
        cursor = segment_end

    detail_responses: list[dict[str, Any]] = []
    for dispute_id in dispute_ids:
        status, _headers, raw = request_relay(
            relay_url,
            thumbprint,
            key_id,
            secret,
            "dispute_detail",
            {"dispute_id": dispute_id},
        )
        payload = parse_payload(status, raw)
        returned_id = str(payload.get("dispute_id") or "").strip()
        if returned_id != dispute_id:
            raise RuntimeError(f"纠纷详情 ID 不一致：请求 {dispute_id}，返回 {returned_id or '空'}。")
        if not isinstance(payload.get("disputed_transactions"), list):
            raise RuntimeError(f"纠纷 {dispute_id} 详情缺少 disputed_transactions 数组。")
        detail_responses.append({"dispute_id": dispute_id, "response": payload})

    return {
        "requests": request_bases,
        "list_responses": list_responses,
        "detail_responses": detail_responses,
    }, len(dispute_ids)


SENSITIVE_KEYS = {"email", "email_address", "payer_email", "receiver_email", "phone", "phone_number", "address", "line1", "line2", "given_name", "surname", "full_name", "alternate_full_name", "name"}


def redact(value: Any, key: str | None = None) -> Any:
    if key is not None and key.lower() in SENSITIVE_KEYS:
        return "[REDACTED:" + hashlib.sha256(str(value).encode("utf-8")).hexdigest()[:12] + "]"
    if isinstance(value, dict):
        return {str(item_key): redact(item_value, str(item_key)) for item_key, item_value in value.items()}
    if isinstance(value, list):
        return [redact(item) for item in value]
    return value


def main() -> int:
    args = parse_args()
    if not 1 <= args.page_size <= 500:
        raise RuntimeError("--page-size 必须介于 1 至 500。")
    relay_url, thumbprint, key_id, secret = relay_settings()
    start, end = time_range(args)
    base_query = {
        "start_date": iso_utc(start),
        "end_date": iso_utc(end),
        "fields": "all",
        "balance_affecting_records_only": "N",
        "page_size": str(args.page_size),
    }
    responses: list[dict[str, Any]] = []
    total_pages: int | None = None
    expected_items: int | None = None
    received_items = 0

    for page in range(1, 1001):
        query = {**base_query, "page": str(page)}
        status, _headers, raw = request_relay(relay_url, thumbprint, key_id, secret, "transaction_search", query)
        payload = parse_payload(status, raw)
        rows = payload.get("transaction_details")
        pages = payload.get("total_pages")
        items = payload.get("total_items")
        returned_page = payload.get("page")
        if not isinstance(rows, list) or not isinstance(pages, int) or not isinstance(items, int) or not isinstance(returned_page, int):
            raise RuntimeError("交易响应缺少 transaction_details、page、total_pages 或 total_items。")
        if returned_page != page or pages < 1 or items < 0 or len(rows) > args.page_size:
            raise RuntimeError("交易响应分页字段不一致。")
        if total_pages is None:
            total_pages, expected_items = pages, items
        elif total_pages != pages or expected_items != items:
            raise RuntimeError("分页过程中 total_pages 或 total_items 发生变化。")
        responses.append(payload)
        received_items += len(rows)
        if page >= pages:
            break
        if not rows:
            raise RuntimeError("未到最后一页却返回空交易列表。")
    else:
        raise RuntimeError("分页超过 1000 页，已停止。")

    if expected_items is None or received_items != expected_items:
        raise RuntimeError(f"分页交易总数不一致：返回 {received_items}，声明 {expected_items}。")

    dispute_search, dispute_count = collect_disputes(
        relay_url, thumbprint, key_id, secret, start, end
    )
    result: dict[str, Any] = {
        "probe_only": True,
        "relay_url": relay_url,
        "period": {"from": iso_utc(start), "to": iso_utc(end)},
        "source_responses": {
            "transaction_search": {
                "segments": [{"segment": 1, "request": base_query, "responses": responses}],
            },
            "dispute_search": dispute_search,
        },
        "summary": {
            "transaction_pages": len(responses),
            "transactions": received_items,
            "dispute_pages": len(dispute_search["list_responses"]),
            "disputes": dispute_count,
            "errors": 0,
        },
    }
    output = Path(args.output).expanduser().resolve()
    output.parent.mkdir(parents=True, exist_ok=True)
    output.write_text(json.dumps(result if args.raw else redact(result), ensure_ascii=False, indent=2), encoding="utf-8")
    print(f"VPS 中转交易样本已保存：{output}")
    print(json.dumps(result["summary"], ensure_ascii=False))
    print("只进行了 VPS 只读请求；未连接 ERP，也未写入任何数据。")
    return 0


if __name__ == "__main__":
    try:
        raise SystemExit(main())
    except Exception as error:
        print(f"探测失败：{error}", file=sys.stderr)
        raise SystemExit(1)
