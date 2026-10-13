from __future__ import annotations

import argparse
import gzip
import hashlib
import hmac
import json
import logging
import os
import shutil
import sys
import time
import uuid
from contextlib import contextmanager
from datetime import datetime, timedelta, timezone
from pathlib import Path
from typing import Any, Iterator
from urllib.parse import quote

import requests


# 配置在 EXE 同目录的 config.json 中填写，不再修改源码。
PAYPAL_CLIENT_ID = ""
PAYPAL_CLIENT_SECRET = ""
PAYPAL_MERCHANT_ID = ""
PAYPAL_ENVIRONMENT = "live"

ERP_IMPORT_URL = "https://wuliu.digilabreview.com/api/paypal/import"
ERP_IMPORT_SECRET = ""

INITIAL_SYNC_DAYS = 30
OVERLAP_HOURS = 4
REQUEST_TIMEOUT_SECONDS = 60
VERIFY_TLS = True

SCRIPT_DIR = Path(sys.executable).resolve().parent if getattr(sys, "frozen", False) else Path(__file__).resolve().parent
DEFAULT_CONFIG_FILE = SCRIPT_DIR / "config.json"
PENDING_DIR = SCRIPT_DIR / "pending"
ARCHIVE_DIR = SCRIPT_DIR / "archive"
FAILED_DIR = SCRIPT_DIR / "failed"
LOG_DIR = SCRIPT_DIR / "logs"
STATE_FILE = SCRIPT_DIR / "state.json"
LOCK_FILE = SCRIPT_DIR / "paypal_agent.lock"

REFUND_EVENT_CODES = {"T1107", "T1115", "T1120"}
UTC = timezone.utc


def parse_arguments() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="KKY PayPal Windows 单账号采集代理")
    parser.add_argument(
        "--config",
        default=str(DEFAULT_CONFIG_FILE),
        help="配置文件地址，默认读取 EXE 同目录的 config.json",
    )
    parser.add_argument(
        "--test-import",
        action="store_true",
        help="只测试 ERP 接收链路，不访问 PayPal，也不推进采集时间游标",
    )
    return parser.parse_args()


def load_config(config_file: Path) -> None:
    global PAYPAL_CLIENT_ID
    global PAYPAL_CLIENT_SECRET
    global PAYPAL_MERCHANT_ID
    global PAYPAL_ENVIRONMENT
    global ERP_IMPORT_URL
    global ERP_IMPORT_SECRET
    global INITIAL_SYNC_DAYS
    global OVERLAP_HOURS
    global REQUEST_TIMEOUT_SECONDS
    global VERIFY_TLS

    if not config_file.is_file():
        raise RuntimeError(
            f"未找到配置文件：{config_file}。请把 config.example.json 复制为 config.json 后填写。"
        )

    try:
        config = json.loads(config_file.read_text(encoding="utf-8-sig"))
    except json.JSONDecodeError as exception:
        raise RuntimeError(
            f"配置文件不是有效 JSON：第 {exception.lineno} 行，第 {exception.colno} 列；{exception.msg}"
        ) from exception
    except OSError as exception:
        raise RuntimeError(f"配置文件无法读取：{exception}") from exception

    if not isinstance(config, dict):
        raise RuntimeError("config.json 顶层必须是 JSON 对象。")

    PAYPAL_CLIENT_ID = str(config.get("paypal_client_id") or "").strip()
    PAYPAL_CLIENT_SECRET = str(config.get("paypal_client_secret") or "").strip()
    PAYPAL_MERCHANT_ID = str(config.get("paypal_merchant_id") or "").strip().upper()
    PAYPAL_ENVIRONMENT = str(config.get("paypal_environment") or "live").strip().lower()
    ERP_IMPORT_URL = str(config.get("erp_import_url") or "").strip()
    ERP_IMPORT_SECRET = str(config.get("erp_import_secret") or "").strip()

    try:
        INITIAL_SYNC_DAYS = int(config.get("initial_sync_days", 30))
        OVERLAP_HOURS = int(config.get("overlap_hours", 4))
        REQUEST_TIMEOUT_SECONDS = int(config.get("request_timeout_seconds", 60))
    except (TypeError, ValueError) as exception:
        raise RuntimeError("initial_sync_days、overlap_hours、request_timeout_seconds 必须填写整数。") from exception

    verify_tls = config.get("verify_tls", True)
    if not isinstance(verify_tls, bool):
        raise RuntimeError("verify_tls 必须填写 true 或 false，不能使用字符串。")
    VERIFY_TLS = verify_tls


def utc_now() -> datetime:
    return datetime.now(UTC)


def iso_utc(value: datetime) -> str:
    return value.astimezone(UTC).isoformat(timespec="seconds").replace("+00:00", "Z")


def parse_datetime(value: str | None) -> datetime | None:
    if not value:
        return None
    try:
        return datetime.fromisoformat(value.replace("Z", "+00:00")).astimezone(UTC)
    except (TypeError, ValueError):
        return None


def ensure_directories() -> None:
    for directory in (PENDING_DIR, ARCHIVE_DIR, FAILED_DIR, LOG_DIR):
        directory.mkdir(parents=True, exist_ok=True)


def configure_logging() -> None:
    log_file = LOG_DIR / f"paypal_agent_{utc_now().strftime('%Y%m%d')}.log"
    formatter = logging.Formatter("%(asctime)s [%(levelname)s] %(message)s")
    root = logging.getLogger()
    root.setLevel(logging.INFO)
    root.handlers.clear()

    file_handler = logging.FileHandler(log_file, encoding="utf-8")
    file_handler.setFormatter(formatter)
    root.addHandler(file_handler)

    console_handler = logging.StreamHandler(sys.stdout)
    console_handler.setFormatter(formatter)
    root.addHandler(console_handler)


@contextmanager
def single_instance() -> Iterator[None]:
    if os.name != "nt":
        raise RuntimeError("该采集脚本仅用于 Windows VPS。")

    import msvcrt

    handle = LOCK_FILE.open("a+b")
    try:
        handle.seek(0, os.SEEK_END)
        if handle.tell() == 0:
            handle.write(b"0")
            handle.flush()
        handle.seek(0)
        try:
            msvcrt.locking(handle.fileno(), msvcrt.LK_NBLCK, 1)
        except OSError as exception:
            raise RuntimeError("另一个 PayPal 采集任务仍在运行，本次退出。") from exception
        yield
    finally:
        try:
            handle.seek(0)
            msvcrt.locking(handle.fileno(), msvcrt.LK_UNLCK, 1)
        except OSError:
            pass
        handle.close()


def validate_config(require_paypal_credentials: bool = True) -> None:
    required = {
        "paypal_merchant_id": PAYPAL_MERCHANT_ID,
        "erp_import_url": ERP_IMPORT_URL,
        "erp_import_secret": ERP_IMPORT_SECRET,
    }
    if require_paypal_credentials:
        required.update(
            {
                "paypal_client_id": PAYPAL_CLIENT_ID,
                "paypal_client_secret": PAYPAL_CLIENT_SECRET,
            }
        )
    missing = [name for name, value in required.items() if not str(value).strip()]
    if missing:
        raise RuntimeError("请先填写配置：" + "、".join(missing))
    if PAYPAL_ENVIRONMENT not in {"live", "sandbox"}:
        raise RuntimeError("paypal_environment 只能填写 live 或 sandbox。")
    if not 1 <= INITIAL_SYNC_DAYS <= 730:
        raise RuntimeError("initial_sync_days 必须在 1 至 730 之间。")
    if not 0 <= OVERLAP_HOURS <= 168:
        raise RuntimeError("overlap_hours 必须在 0 至 168 之间。")
    if not 10 <= REQUEST_TIMEOUT_SECONDS <= 600:
        raise RuntimeError("request_timeout_seconds 必须在 10 至 600 之间。")


def load_state() -> dict[str, Any]:
    if not STATE_FILE.exists():
        return {}
    try:
        value = json.loads(STATE_FILE.read_text(encoding="utf-8"))
        return value if isinstance(value, dict) else {}
    except (OSError, json.JSONDecodeError):
        logging.warning("状态文件无法读取，将按首次采集范围执行。")
        return {}


def save_state(payload: dict[str, Any]) -> None:
    period = payload.get("period") if isinstance(payload.get("period"), dict) else {}
    value = {
        "merchant_id": PAYPAL_MERCHANT_ID.strip().upper(),
        "last_success_to": period.get("to"),
        "last_upload_id": payload.get("upload_id"),
        "updated_at": iso_utc(utc_now()),
    }
    temporary = STATE_FILE.with_suffix(".tmp")
    temporary.write_text(json.dumps(value, ensure_ascii=False, indent=2), encoding="utf-8")
    temporary.replace(STATE_FILE)


def paypal_base_url() -> str:
    return "https://api-m.sandbox.paypal.com" if PAYPAL_ENVIRONMENT == "sandbox" else "https://api-m.paypal.com"


class PayPalClient:
    def __init__(self) -> None:
        self.session = requests.Session()
        self.session.headers.update({"Accept": "application/json", "User-Agent": "KKY-PayPal-Windows-Agent/1.0"})
        self.token = ""

    def authenticate(self) -> None:
        response = self.session.post(
            paypal_base_url() + "/v1/oauth2/token",
            auth=(PAYPAL_CLIENT_ID.strip(), PAYPAL_CLIENT_SECRET.strip()),
            data={"grant_type": "client_credentials"},
            timeout=REQUEST_TIMEOUT_SECONDS,
            verify=VERIFY_TLS,
        )
        self._raise_for_error(response, "获取 PayPal Access Token")
        body = response.json()
        self.token = str(body.get("access_token") or "").strip()
        if not self.token:
            raise RuntimeError("PayPal 未返回 Access Token。")
        self.session.headers.update({"Authorization": "Bearer " + self.token})

    def get(self, path: str, params: dict[str, Any], label: str) -> dict[str, Any]:
        last_error: Exception | None = None
        for attempt in range(3):
            try:
                response = self.session.get(
                    paypal_base_url() + path,
                    params=params,
                    timeout=REQUEST_TIMEOUT_SECONDS,
                    verify=VERIFY_TLS,
                )
                if response.status_code == 401 and attempt == 0:
                    self.authenticate()
                    continue
                if response.status_code == 429 or response.status_code >= 500:
                    if attempt < 2:
                        time.sleep(2 ** (attempt + 1))
                        continue
                self._raise_for_error(response, label)
                body = response.json()
                if not isinstance(body, dict):
                    raise RuntimeError(label + "：响应 JSON 顶层不是对象。")
                return body
            except (requests.RequestException, ValueError, RuntimeError) as exception:
                last_error = exception
                if isinstance(exception, RuntimeError) or attempt >= 2:
                    break
                time.sleep(2 ** (attempt + 1))
        raise RuntimeError(str(last_error or (label + "失败。")))

    @staticmethod
    def _raise_for_error(response: requests.Response, label: str) -> None:
        if response.ok:
            return
        try:
            body = response.json()
            name = str(body.get("name") or "").strip() if isinstance(body, dict) else ""
            message = str(body.get("message") or "").strip() if isinstance(body, dict) else ""
            details = body.get("details") if isinstance(body, dict) else []
            detail_messages = []
            if isinstance(details, list):
                for detail in details:
                    if isinstance(detail, dict):
                        value = str(detail.get("description") or detail.get("issue") or "").strip()
                        if value:
                            detail_messages.append(value)
            text = "；".join(part for part in (name, message, "；".join(detail_messages)) if part)
        except ValueError:
            text = response.text[:1000]
        raise RuntimeError(f"{label}（HTTP {response.status_code}）：{text or response.text[:1000]}")


def sync_range() -> tuple[datetime, datetime]:
    end = utc_now() - timedelta(minutes=1)
    previous = parse_datetime(str(load_state().get("last_success_to") or ""))
    start = previous - timedelta(hours=OVERLAP_HOURS) if previous else end - timedelta(days=max(1, INITIAL_SYNC_DAYS))
    if start >= end:
        start = end - timedelta(hours=max(1, OVERLAP_HOURS))
    return start, end


def date_segments(start: datetime, end: datetime) -> Iterator[tuple[datetime, datetime]]:
    cursor = start
    while cursor <= end:
        segment_end = min(end, cursor + timedelta(days=30) - timedelta(seconds=1))
        yield cursor, segment_end
        cursor = segment_end + timedelta(seconds=1)


def collect_transactions(client: PayPalClient, start: datetime, end: datetime) -> list[dict[str, Any]]:
    records: list[dict[str, Any]] = []
    for segment_start, segment_end in date_segments(start, end):
        page = 1
        while True:
            body = client.get(
                "/v1/reporting/transactions",
                {
                    "start_date": iso_utc(segment_start),
                    "end_date": iso_utc(segment_end),
                    "fields": "all",
                    "page_size": 500,
                    "page": page,
                },
                "PayPal 交易记录查询失败",
            )
            details = body.get("transaction_details")
            if isinstance(details, list):
                records.extend(row for row in details if isinstance(row, dict))
            total_pages = max(1, int(body.get("total_pages") or 1))
            if page >= total_pages:
                break
            page += 1
            if page > 500:
                raise RuntimeError("PayPal 交易记录分页超过 500 页，已停止以避免异常循环。")
    return records


def collect_disputes(client: PayPalClient, start: datetime, end: datetime) -> tuple[list[dict[str, Any]], list[str]]:
    earliest = utc_now() - timedelta(days=179)
    start = max(start, earliest)
    records: list[dict[str, Any]] = []
    detail_errors: list[str] = []
    page = 1
    while start <= end:
        body = client.get(
            "/v1/customer/disputes",
            {
                "update_time_after": iso_utc(start),
                "update_time_before": iso_utc(end),
                "page_size": 50,
                "page": page,
            },
            "PayPal 纠纷列表查询失败",
        )
        items = body.get("items")
        if not isinstance(items, list):
            items = []
        for item in items:
            if not isinstance(item, dict) or not item.get("dispute_id"):
                continue
            dispute_id = str(item["dispute_id"])
            try:
                detail = client.get(
                    "/v1/customer/disputes/" + quote(dispute_id, safe=""),
                    {},
                    "PayPal 纠纷详情查询失败",
                )
                records.append({**item, **detail})
            except RuntimeError as exception:
                records.append(item)
                detail_errors.append(f"{dispute_id}：{exception}")
        links = body.get("links")
        has_next = isinstance(links, list) and any(
            isinstance(link, dict) and link.get("rel") == "next" for link in links
        )
        if not has_next:
            break
        page += 1
        if page > 100:
            raise RuntimeError("PayPal 纠纷分页超过 100 页，已停止以避免异常循环。")
    return records, detail_errors


def collect_payload() -> dict[str, Any]:
    start, end = sync_range()
    client = PayPalClient()
    client.authenticate()
    errors: dict[str, str | None] = {"transactions": None, "disputes": None}
    transactions: list[dict[str, Any]] = []
    disputes: list[dict[str, Any]] = []

    try:
        transactions = collect_transactions(client, start, end)
    except RuntimeError as exception:
        errors["transactions"] = str(exception)

    try:
        disputes, detail_errors = collect_disputes(client, start, end)
        if detail_errors:
            errors["disputes"] = "部分纠纷详情失败：" + "；".join(detail_errors[:20])
    except RuntimeError as exception:
        errors["disputes"] = str(exception)

    upload_id = str(uuid.uuid4())
    return {
        "schema_version": 1,
        "upload_id": upload_id,
        "merchant_id": PAYPAL_MERCHANT_ID.strip().upper(),
        "environment": PAYPAL_ENVIRONMENT,
        "generated_at": iso_utc(utc_now()),
        "period": {"from": iso_utc(start), "to": iso_utc(end)},
        "transactions": transactions,
        "disputes": disputes,
        "errors": errors,
        "collector": {"name": "KKY PayPal Windows Agent", "version": "1.0"},
    }


def save_pending(payload: dict[str, Any]) -> Path:
    merchant = "".join(character if character.isalnum() or character in "-_" else "_" for character in PAYPAL_MERCHANT_ID.strip().upper())
    filename = f"paypal_{merchant}_{utc_now().strftime('%Y%m%d_%H%M%S')}_{payload['upload_id']}.json"
    path = PENDING_DIR / filename
    temporary = path.with_suffix(".tmp")
    temporary.write_text(json.dumps(payload, ensure_ascii=False, separators=(",", ":")), encoding="utf-8")
    temporary.replace(path)
    return path


def upload_json_bytes(json_bytes: bytes, filename: str) -> dict[str, Any]:
    compressed = gzip.compress(json_bytes, compresslevel=6)
    timestamp = str(int(time.time()))
    nonce = uuid.uuid4().hex
    body_hash = hashlib.sha256(compressed).hexdigest()
    message = f"{timestamp}\n{nonce}\n{body_hash}".encode("utf-8")
    signature = hmac.new(ERP_IMPORT_SECRET.encode("utf-8"), message, hashlib.sha256).hexdigest()
    response = requests.post(
        ERP_IMPORT_URL,
        data=compressed,
        headers={
            "Accept": "application/json",
            "Content-Type": "application/json",
            "Content-Encoding": "gzip",
            "X-PayPal-Timestamp": timestamp,
            "X-PayPal-Nonce": nonce,
            "X-PayPal-Signature": signature,
            "X-PayPal-Filename": filename,
            "User-Agent": "KKY-PayPal-Windows-Agent/1.0",
        },
        timeout=max(REQUEST_TIMEOUT_SECONDS, 120),
        verify=VERIFY_TLS,
    )
    try:
        body = response.json()
    except ValueError:
        body = {"accepted": False, "message": response.text[:1000]}
    if not response.ok or not body.get("accepted"):
        raise RuntimeError(f"ERP 接收失败（HTTP {response.status_code}）：{body.get('message') or response.text[:1000]}")
    return body


def upload(path: Path) -> dict[str, Any]:
    return upload_json_bytes(path.read_bytes(), path.name)


def test_import_connection() -> int:
    now = iso_utc(utc_now())
    payload = {
        "schema_version": 1,
        "upload_id": str(uuid.uuid4()),
        "merchant_id": PAYPAL_MERCHANT_ID.strip().upper(),
        "environment": PAYPAL_ENVIRONMENT,
        "generated_at": now,
        "period": {"from": now, "to": now},
        "transactions": [],
        "disputes": [],
        "errors": {},
        "collector": {"name": "KKY PayPal Windows Agent connection test", "version": "1.1"},
    }
    raw = json.dumps(payload, ensure_ascii=False, separators=(",", ":")).encode("utf-8")
    response = upload_json_bytes(raw, "paypal_import_connection_test.json")
    logging.info("ERP 接收链路测试成功：%s", response.get("message", "已接收"))
    return 0


def archive(path: Path, payload: dict[str, Any], response: dict[str, Any]) -> None:
    target_dir = ARCHIVE_DIR / utc_now().strftime("%Y%m")
    target_dir.mkdir(parents=True, exist_ok=True)
    shutil.move(str(path), str(target_dir / path.name))
    if response.get("status") == "success":
        save_state(payload)


def send_pending_files() -> bool:
    for path in sorted(PENDING_DIR.glob("*.json")):
        try:
            payload = json.loads(path.read_text(encoding="utf-8"))
            response = upload(path)
            archive(path, payload, response)
            logging.info("历史待发送文件已接收：%s；%s", path.name, response.get("message", ""))
        except Exception as exception:
            logging.error("历史待发送文件仍未成功，停止本轮新采集：%s；%s", path.name, exception)
            return False
    return True


def main(config_file: Path, test_import: bool = False) -> int:
    ensure_directories()
    configure_logging()
    load_config(config_file)
    validate_config(require_paypal_credentials=not test_import)
    with single_instance():
        if test_import:
            return test_import_connection()

        if not send_pending_files():
            return 2

        logging.info("开始采集 Merchant ID %s 的 PayPal 数据。", PAYPAL_MERCHANT_ID.strip().upper())
        payload = collect_payload()
        path = save_pending(payload)
        logging.info(
            "采集完成：交易 %d 条，纠纷 %d 条，文件 %s。",
            len(payload["transactions"]),
            len(payload["disputes"]),
            path.name,
        )
        try:
            response = upload(path)
            archive(path, payload, response)
            logging.info("ERP 已接收：%s", response.get("message", "完成"))
            return 0 if response.get("status") in {"success", "partial"} else 3
        except Exception as exception:
            logging.exception("文件上传失败，已保留在 pending 目录：%s", exception)
            return 2


if __name__ == "__main__":
    try:
        arguments = parse_arguments()
        raise SystemExit(main(Path(arguments.config).expanduser().resolve(), arguments.test_import))
    except Exception as error:
        ensure_directories()
        if not logging.getLogger().handlers:
            configure_logging()
        logging.exception("PayPal 采集任务失败：%s", error)
        raise SystemExit(1)
