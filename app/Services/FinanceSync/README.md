# 财务只读同步接口 v1

ERP 按已确认的字段白名单提供数据。调用方使用现有 HTTPS 域名，发送 `Authorization: Bearer <token>`。令牌授权本版本全部开放资源，额度按财务客户端计算，出口 IP 变化后继续使用同一额度。PayPal 余额和交易沿用现有中转接口。Worldpay 暂缓接入。

## 路由

| 方法和路径 | 用途 | 查询参数 |
| --- | --- | --- |
| `GET /api/v1/finance-sync/snapshot/{resource}` | 首次同步或重建本地数据，按来源主键升序分页 | `limit`、`cursor` |
| `GET /api/v1/finance-sync/changes/{resource}` | 按发布版本升序同步最近变更 | `cursor` 必填，`limit` 可选 |
| `GET /api/v1/finance-sync/orders/{id}/logistics` | 获取指定订单的最新物流详情 | 无 |
| `GET /api/v1/finance-sync/orders/{id}/logistics-logs` | 获取指定订单的脱敏物流日志，按日志主键倒序 | `limit`、`cursor` |

默认每页 100 条，最多 200 条。参数名区分大小写。游标由服务端签发，分别绑定到快照资源、增量资源或指定订单的日志，调用方应原样保存。

### 全量与增量

1. 分别对所需资源调用快照。保存首个响应中的 `meta.changes_cursor`，再用 `meta.next_cursor` 拉取快照剩余页，直到 `meta.has_more` 为 `false`。
2. 快照分页期间发生变更的记录可能出现在后续增量中。快照读完后用第一步保存的增量游标拉取，完整基线由快照与从该游标开始的增量共同组成。
3. 按响应顺序应用每页变更。对 `created`、`updated`、`disabled` 按来源主键写入当前记录，对 `deleted` 删除对应主键。整页处理成功后保存 `meta.next_cursor`。重试时可重复应用同一来源主键和版本。
4. `meta.has_more` 为 `true` 时继续分页。无变化时返回空数组和原游标。接口提供每个主键最新一次发布状态，同一主键的中间状态可能合并。每个资源单独保存游标。
5. 接口切换可选字段、源时间时区、应用密钥或重建发布表后，旧游标可能失效。收到 `INVALID_CURSOR` 时先核对资源、路径和游标，确认失效后重新从快照开始。

`changed_at` 是 ERP 的发布时刻，`change_version` 是严格递增的发布版本。删除响应含这两个字段、`id` 和 `change_type`。PayPal 账户由启用转停用、店铺归属状态转弃用或停用、PCL 从可跑变为不可跑时，增量返回 `disabled`，并保留相应状态字段。Webhook、批量 SQL 更新和物理删除在各自的业务事务内登记变更，事务回滚时登记也回滚。每分钟的发布计划及其批次容量决定数据延迟。

## 返回规范

- 顶层固定是 `code`、`message`、`data`、`meta`。成功使用 `OK` 和 `message: null`。列表/增量的 `data` 是数组，物流详情是对象。错误时 `data` 为 `null`。
- `meta.request_id` 用于排查。分页有 `next_cursor` 和 `has_more`，快照另有 `changes_cursor`。快照和日志最后一页的 `next_cursor` 为 `null`。增量每次返回可保存的 `next_cursor`。
- 来源主键、关联主键、匹配编号均为字符串，便于保留前导零和大整数。重量单位是克。金额、数量、重量均为十进制字符串，支持原始小数精度。美元、人民币金额字段的后缀分别是 `_usd`、`_cny`，其他金额的币种按同记录币种字段读取。布尔字段返回 JSON 布尔值。未知或缺失数据用 `null`。
- 时间以 UTC 带偏移格式返回，例如 `2025-01-02T03:04:05+00:00`。订单时间与 Shopify 原始时间对应时使用其偏移。其他无偏移时间依 `FINANCE_SYNC_SOURCE_TIMEZONE` 解释，默认与应用时区一致。
- 状态码使用小写下划线格式。已知 ERP 别名按下表转换。渠道新增的可识别代码统一为小写下划线格式，无法规范化的状态返回 `unknown`，缺失状态返回 `null`。名称、团队、款项匹配编号、商品标题和平台编号以数据字段方式提供。
- 调用程序以 HTTP 状态和 `code` 判断结果。显示文案由 ERP 当前语言的翻译文件生成。物流日志的 `message` 是按状态生成的翻译摘要，无法归类时为 `null`。

| HTTP | `code` | 含义 |
| --- | --- | --- |
| 200 | `OK` | 请求成功 |
| 401 | `UNAUTHORIZED` | 令牌缺失、无效、到期或撤销 |
| 403 | `HTTPS_REQUIRED` | 请求未通过 HTTPS |
| 404 | `NOT_FOUND` | 接口关闭、资源未知或指定订单不存在 |
| 405 | `METHOD_NOT_ALLOWED` | 请求方法不受支持 |
| 422 | `INVALID_REQUEST`、`INVALID_CURSOR` | 参数或游标有误 |
| 429 | `RATE_LIMITED` | 调用额度已满，按 `Retry-After` 等待 |
| 503 | `SYNC_NOT_READY`、`SERVICE_UNAVAILABLE` | 发布数据准备中或服务暂不可用 |
| 500 | `INTERNAL_ERROR` | 服务处理异常，提供 `meta.request_id` 以便排查 |

### 资源与业务字段

| 资源名 | 字段 |
| --- | --- |
| `paypal-accounts` | `id`、`account_name`、`merchant_id`、`environment`、`is_enabled`。配置开启后增加 `account_email` |
| `orders` | 匹配字段 `id`、`assistant_domain_store_id`、`source_shop_domain`、`shopify_order_id`、`shopify_order_number`、`tongtu_order_number`、`transaction_id`、`bill_number`、`payment_gateway_order_id`、`paypal_account_id`、`collection_account`。订单字段 `store_name`、`payment_gateway`、`payment_method`、`payment_status`、`order_created_at`、`paid_at`、`paid_amount_usd`、`total_shipping_usd`、`subscription_type`、`subscription_status`、`subscription_origin_order_id`、`is_add_on`。归属字段 `assigned_owner_user_id`、`assigned_owner_name`、`owner_assignment_resolved_at`、`group_names`、`operator_name`。派生字段 `shop_order_currency`、`shop_order_amount`、`payment_original_currency`、`payment_original_amount`、`merchant_order_number` |
| `order-payment-references` | `id`、`logistics_order_id`、`reference_number`、`reference_type`、`gateway_order_id`，以及交易粒度的 `payment_original_currency`、`payment_original_amount` |
| `order-items` | `id`、`logistics_order_id`、`product_title`、`shopify_sku`、`tongtu_sku_mapping_id`、`tongtu_sku`、`tongtu_product_code`、`tongtu_product_name`、`tongtu_product_type`、`quantity`、`unit_price_usd`、`line_revenue_usd`、`sale_weight_gram`、`tongtu_goods_weight_gram`、`tongtu_package_weight_gram` |
| `stores` | `id`、`domain`、`shop_name`、`owner_user_id`、`user_name`、`owner_operation_department_id`、`team`、`project`、`site_builder`、`shop_status` |
| `store-owner-histories` | `id`、`assistant_domain_store_id`、`owner_user_id`、`owner_name_snapshot`、`operation_department_id`、`operation_department_name_snapshot`、`event`、`effective_from`、`effective_to` |
| `tongtu-sku-mappings` | `id`、`shopify_sku`、`tongtu_sku`、`tongtu_product_code`、`tongtu_product_name`、`tongtu_product_type`、`status`。配置开启后增加 `goods_current_cost_cny`、`goods_average_cost_cny`、`package_cost_cny`、`cost_currency` |
| `pcl-skus` | `id`、`pcl_product_no`、`is_runnable`。用 `pcl_product_no` 对应商品行的 `tongtu_product_code` |
| `paypal-refunds` | `id`、`logistics_paypal_account_id`、`logistics_order_id`、`refund_transaction_id`、`original_transaction_id`、`event_code`、`status`、`refund_amount_usd`、`original_amount`、`currency_code`、`refund_reason`、`manual_reason`、`refunded_at` |
| `credit-card-refunds` | `id`、`logistics_order_id`、`bill_number`、`refund_reference`、`refund_amount_usd`、`refund_reason`、`occurred_at` |
| `paypal-disputes` | `id`、`logistics_paypal_account_id`、`logistics_order_id`、`dispute_id`、`disputed_transaction_id`、`reason`、`status`、`dispute_state`、`life_cycle_stage`、`channel`、`dispute_amount_usd`、`original_amount`、`currency_code`、`dispute_created_at`、`dispute_updated_at`、`outcome_code` |
| 订单物流详情 | `shipping_company`、`tracking_number`、`logistics_status`、`tracking_latest_event`、`tracking_latest_checkpoint_at` |
| 订单物流日志 | `id`、`logistics_order_id`、`source`、`action`、`status`、`message`、`created_at` |

### 状态字典

| 字段 | 常用 API 值 |
| --- | --- |
| `change_type` | `created`、`updated`、`disabled`、`deleted` |
| `environment` | `live`、`sandbox` |
| `reference_type` | `primary` 主付款、`upsell` 加购付款 |
| `payment_status` | `paid`、`pending`、`partially_paid`、`refunded`、`partially_refunded`、`authorized`、`voided` |
| `subscription_type` | `none`、`first_purchase`、`subscription`、`recurring_N`，例如第二次复购是 `recurring_2` |
| `subscription_status` | `not_subscribed`、`subscribed`、`cancelled` |
| `shop_status` | `in_use`、`abandoned`、`stopped` |
| 店铺归属 `event` | `baseline`、`assigned`、`transferred`、`cleared` |
| 通途 `status` | `unmatched`、`queued`、`matched`、`not_found`、`conflict`、`failed` |
| PayPal 退款 `status` | `completed`、`pending`、`denied`、`reversed`，分别对应渠道 `S`、`P`、`D`、`V` |
| PayPal 纠纷字段 | 保留渠道业务状态与阶段，规范为小写下划线格式，例如 `waiting_for_seller_response`、`resolved`、`chargeback` |
| `logistics_status` | `pending_shipment`、`shipped`、`delivered`、`exception`、`pending_lookup`、`courier_unmatched`、`courier_unsupported`、`information_received`、`in_transit`、`no_information`、`lookup_expired` |

### 金额和隐私

`shop_order_*` 取可信 Shopify 店铺币金额。仅有会写入默认美元币种的历史 Webhook 回退数据时，派生店铺币金额返回空值。商户订单号取明确的 `yx_order_id`。实际付款只从精确关联的 PayPal 成功交易或 Shopify 成功交易的原币金额获取。多笔付款时，逐笔使用付款关联行的原币字段。只有一笔可确认的付款时，订单也携带该对原币字段。汇总时选定一个交易粒度，避免重复计入订单和付款关联的同一笔金额。旧订单没有持久化付款关联时保留订单 `bill_number`。缺少原币数据时保留空值。

`paid_amount_usd` 取可确认的成功交易美元金额。存在多笔付款时，只有可确认全部匹配金额均为美元口径才汇总。ERP 历史列可能按订单总额写入，证据不足时接口返回空值。`total_shipping_usd` 使用 ERP 运费列，已知店铺币种非 USD 时返回空值。结算金额沿用渠道现有数据来源。

响应使用字段白名单。账户密钥、完整卡号、客户完整联系方式、原始响应和日志上下文均排除。自由文本中可识别的邮箱、链接和长数字会脱敏，物流日志使用固定摘要。HTTP 响应使用 `Cache-Control: no-store`。审计记录调用方、路由名、请求 ID、结果码、条数和耗时。

## 脱敏样例

同一订单的主付款与加购付款查询快照。游标在真实响应中由服务端签发。

```json
{
  "code": "OK",
  "message": null,
  "data": [
    {
      "id": "19",
      "logistics_order_id": "14",
      "reference_number": "MAIN-1",
      "reference_type": "primary",
      "gateway_order_id": "GW-MAIN-1",
      "payment_original_currency": "EUR",
      "payment_original_amount": "90.5"
    },
    {
      "id": "20",
      "logistics_order_id": "14",
      "reference_number": "UP-1",
      "reference_type": "upsell",
      "gateway_order_id": "GW-UP-1",
      "payment_original_currency": "USD",
      "payment_original_amount": "11.25"
    }
  ],
  "meta": {
    "request_id": "3874f779-730f-4f4c-bce5-2baaf4b41a0c",
    "next_cursor": null,
    "has_more": false,
    "changes_cursor": "<orders-payment-changes-cursor>"
  }
}
```

历史订单归属被修正后，从 `changes/orders` 拉取。

```json
{
  "code": "OK",
  "message": null,
  "data": [
    {
      "change_type": "updated",
      "change_version": "48201",
      "changed_at": "2025-02-03T04:05:06+00:00",
      "id": "14",
      "assistant_domain_store_id": "3",
      "source_shop_domain": "shop.example.test",
      "shopify_order_id": "590000000001",
      "shopify_order_number": "#1014",
      "tongtu_order_number": null,
      "transaction_id": "MERCHANT-14",
      "bill_number": "MAIN-1",
      "payment_gateway_order_id": "GW-MAIN-1",
      "paypal_account_id": "5",
      "collection_account": "PayPal EUR",
      "store_name": "Demo Store",
      "payment_gateway": "paypal",
      "payment_method": "PayPal",
      "payment_status": "paid",
      "order_created_at": "2025-01-02T03:04:05+00:00",
      "paid_at": "2025-01-02T03:04:10+00:00",
      "paid_amount_usd": null,
      "total_shipping_usd": null,
      "subscription_type": "none",
      "subscription_status": "not_subscribed",
      "subscription_origin_order_id": null,
      "is_add_on": true,
      "assigned_owner_user_id": "7",
      "assigned_owner_name": "示例员工",
      "owner_assignment_resolved_at": "2025-02-03T04:04:00+00:00",
      "group_names": "示例团队",
      "operator_name": null,
      "shop_order_currency": "EUR",
      "shop_order_amount": "91.5",
      "merchant_order_number": "MERCHANT-14",
      "payment_original_currency": null,
      "payment_original_amount": null
    }
  ],
  "meta": {
    "request_id": "84804439-93f7-4a72-aa7e-c4de56c4ccb3",
    "next_cursor": "<next-orders-changes-cursor>",
    "has_more": false
  }
}
```

## 本地验收

1. 运行 `php artisan test --filter=FinanceSyncApiTest`。默认使用 SQLite 内存数据库。验证 MySQL 时，建一个独立空数据库 `finance_sync_feature_test`，并为测试进程设置 `FINANCE_SYNC_TEST_MYSQL_DATABASE` 和该实例的 `DB_HOST`、`DB_PORT`、`DB_USERNAME`、`DB_PASSWORD`。测试临时创建本身所需的夹具表和触发器，并在结束时清理。该数据库必须为空。
2. 在与宝塔数据库同版本的 ERP 测试库中，先运行 `php artisan migrate --path=database/migrations/2026_11_25_230003_create_finance_sync_tables.php`。预期新增同步表和触发器。数据库账号需要创建和删除相关表、索引和触发器的权限。
3. 运行 `php artisan finance-sync:publish` 为首批全量数据预热。每次命令处理有限批次，大库可重复运行。设好测试令牌哈希并启用接口，检查快照成功返回。初始化期间返回 `503 SYNC_NOT_READY`。
4. 依次检查 11 类快照。用主付款与加购各一笔的测试订单核对付款关联，检查缺失原币返回空值，物流详情的日志是状态摘要。保留增量游标，修正一笔历史订单，运行发布命令，检查同一订单以 `updated` 返回。测试账户停用、物理删除、批量修正和事务回滚。
5. 重复带旧游标和新游标的轮询。核查 `finance_sync_records` 的快照和增量查询，使用 `EXPLAIN` 确认记录分页索引可用。在与生产相近的数据量下比较启用触发器前后的批量更新耗时和发布积压。用错误令牌、旧令牌、超限请求、异常参数、正常及变更来源 IP 验证鉴权和限流。

## 宝塔部署与回退

1. 本地验收通过后手动上传改动文件，逐项核对迁移、HTTPS、应用密钥以及源库无偏移时间的实际时区。保持 `FINANCE_SYNC_ENABLED=false` 做迁移和首次发布。实际部署使用项目对应的 PHP 8.2 可执行文件，线上迁移按需加 `--force`。
2. 为对接方生成至少 32 字节随机令牌，经安全渠道交付原始令牌。部署配置填写 `FINANCE_SYNC_TOKEN_SHA256`。按轮换计划配置 `FINANCE_SYNC_TOKEN_EXPIRES_AT` 为 Unix 秒，`0` 表示未设置到期时间。轮换时将旧令牌哈希置于 `FINANCE_SYNC_PREVIOUS_TOKEN_SHA256` 和短期 `FINANCE_SYNC_PREVIOUS_TOKEN_EXPIRES_AT`，再配置新哈希。需要立即撤销时移除对应哈希并刷新配置。
3. `FINANCE_SYNC_RATE_STORE=file` 适合单应用实例，多个应用实例使用共享 Redis 存储。默认每客户端每分钟 60 次，根据验收结果调整。HTTPS 在可信入口终止时，先验收 Laravel 能正确判断 HTTPS。宝塔访问日志和自定义调试记录应排除令牌等凭证。
4. 配好 `FINANCE_SYNC_ENABLED=true` 后刷新 Laravel 配置和路由缓存，确认常规 `schedule:run` 每分钟执行并能看到 `finance-sync:publish`。只运行财务专用调度模式的进程跳过此项发布。发布通过计划任务执行，部署不需要额外前端构建或新队列。
5. 需要停止服务时先关闭开关并刷新配置，等发布任务结束。若要移除触发器和发布表，确认这项迁移在迁移表的实际批次，然后执行 `php artisan migrate:rollback --path=database/migrations/2026_11_25_230003_create_finance_sync_tables.php --batch=<批次编号>`。回退删除同步专用表和本接口触发器，保留业务源表。再次部署时重新初始化快照及游标。
