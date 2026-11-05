# 财务 VPS 只读中转协议

依据用户《财务系统开发方案.md》§1.3、§4、§6实现。账户地址、证书 SHA-1 指纹、HMAC Key ID 与 HMAC 密钥由账户资料维护。ERP 不保存 PayPal Client Secret。禁止自动刷新、写入 operation 和任意 URL 转发。

## 请求（沿用需求）

POST 固定 `/relay/v1/paypal/read`，HTTPS 固定使用 TLS 1.2，以兼容提供的 `paypal_relay_balance.py` 所注明的 Windows Server 2012 R2。证书 SHA-1 指纹在 TLS 握手阶段必须匹配，未通过前不能发送 HTTP 请求。允许固定指纹的自签证书；IP 地址以精确指纹认证身份，不要求旧证书包含 IP SAN，域名地址仍校验名称。禁止重定向。

头：`X-Relay-Key`、`X-Relay-Timestamp`、`X-Relay-Nonce`、`X-Relay-Signature`。
正文只有 `operation` 与 `query`。ERP 立即启用 `balances`、`transaction_search`，其余汇总操作待确认后启用。

请求签名原文是以下五项用 LF（`\n`）连接，结尾不追加换行：

```text
POST
/relay/v1/paypal/read
<timestamp>
<nonce>
<SHA-256(原始请求正文)，小写十六进制>
```

账户字段填写 VPS 配置中的 Base64 文本，先严格 Base64 解码为至少 32 字节的密钥，再计算 HMAC-SHA256，输出小写十六进制。数据库加密保存 Base64 文本，绝不能把 Base64 文本本身作为签名密钥。请求 nonce 为 UUID 去掉连字符的 32 位十六进制值。无参数时正文中的 `query` 为 `{}`。

## 响应（已按提供的客户端修正）

原需求未指定响应签名拼接顺序；后续用户提供的 `C:/Users/Administrator/Documents/开发需求/paypal_relay_balance.py` 明确了实际客户端协议，以下以该文件为准，取代先前自行补充的顺序。文件内的实际地址、密钥和指纹不复制到版本库。禁止跳过验签或猜测多种签名规则。

VPS 保留 PayPal 原始 HTTP 状态和正文原始字节，增加：

- `X-Relay-Response-Timestamp`：当前 Unix 秒时间戳。
- `X-Relay-Response-Signature`：下述原文的 HMAC-SHA256 小写十六进制。

原文以下四项用 LF 连接，结尾不追加换行：

```text
<HTTP 状态码，十进制>
<response timestamp>
<本次请求的 X-Relay-Nonce>
<SHA-256(响应正文原始字节)，小写十六进制>
```

请求 nonce 将响应绑定至本次请求；状态码与正文均受签名保护。ERP 接受时间差不超过 300 秒，使用恒定时间比较。响应错误也必须签名，但不会保存为业务数据。签名正确且业务字段有效后才落库。

Python VPS 端等价签名代码（`body_bytes` 必须是实际发出的原始正文）：

```python
import base64, hashlib, hmac, time
response_timestamp = str(int(time.time()))
message = '\n'.join([
    str(paypal_status), response_timestamp, request_nonce,
    hashlib.sha256(body_bytes).hexdigest(),
]).encode('utf-8')
response_signature = hmac.new(base64.b64decode(hmac_secret_base64, validate=True), message, hashlib.sha256).hexdigest()
```

完整流程由 `FinanceRelayClient` 与 `FinancePayPalSyncService` 实现并在 `FinancePortalTest` 中覆盖：单账户/逐账户刷新、失败隔离、时间分段、页码及分页总数校验、账户+交易号+事件去重、原始 JSON 留存和失败页不入库。

原有物流上传协议（`X-PayPal-*`）继续保留。该上传协议与本协议不是同一组签名头或同一套密钥，不自动复制其密钥到财务账户。

## 固定测试向量（只用于测试）

- Base64 密钥（仅测试）：`dGVzdC1rZXktbm90LXByb2R1Y3Rpb24td2l0aC0zMi1ieXRlcw==`
- 响应时间戳：`1700000000`
- 请求 nonce：`00000000000040008000000000000001`
- 状态码：`200`
- 正文字节：`{"balances":[]}`（无换行）
- 正文 SHA-256：`524ce15a3ba4e57e130190de01eca238ef18a4637ae106ee2dcc1584236d1a00`
- 响应签名：`c7a097e9d2187ce38d056de64f5830d104e061d60d91153fcd3099d5f407601a`

此向量的历史时间戳仅用于核对算法，不用于在线请求；线上需要当前时间戳。
