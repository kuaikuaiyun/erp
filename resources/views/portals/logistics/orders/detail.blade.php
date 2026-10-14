@php
    $display = static fn ($value): string => $value === null || $value === '' ? '—' : (string) $value;
    $money = static fn ($value): string => $value === null || $value === '' ? '—' : '$'.number_format((float) $value, 2);
    $rate = static fn ($value): string => $value === null || $value === '' ? '—' : number_format((float) $value, 2).'%';
    $date = static function ($value): string {
        if (!$value) return '—';
        try { return \Carbon\Carbon::parse($value)->timezone('Asia/Shanghai')->format('Y-m-d H:i:s'); } catch (\Throwable) { return '—'; }
    };
    $pretty = static function ($value): string {
        if ($value === null || $value === '' || $value === []) return '暂无原始数据';
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            if (json_last_error() !== JSON_ERROR_NONE) return $value;
            $value = $decoded;
        }
        return json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '暂无原始数据';
    };
    $shopifyAttributes = collect(is_array($shopifyPayload) ? data_get($shopifyPayload, 'customAttributes', []) : [])
        ->filter(fn ($item) => is_array($item) && filled($item['key'] ?? null))
        ->mapWithKeys(fn ($item) => [(string) $item['key'] => (string) ($item['value'] ?? '')])
        ->all();
    $manualRefundTotal = (float) $order->activeRefundDetails->sum('refund_amount_usd');
    $paypalRefundTotal = (float) $order->paypalRefunds->sum('refund_amount_usd');
    $productSubtotal = (float) $order->paid_amount_usd - (float) $order->total_shipping_usd;
    $statusTone = $order->hasFailureRow() ? 'is-danger' : 'is-success';
    $logSourceLabels = [
        'tongtu' => '通途',
        'shopify' => 'Shopify',
        'paypal' => 'PayPal',
        'manual' => '人工操作',
        'system' => '系统',
    ];
    $logActionLabels = [
        'match' => '通途订单匹配',
        'auto_match_paid_24h' => '付款满24小时自动匹配',
        'auto_match_fulfillment' => '首次发货自动匹配',
        'sync' => '订单同步',
        'token' => '访问令牌获取',
        'webhook_subscribe' => 'Webhook登记',
    ];
    $logStatusLabels = [
        'success' => '成功',
        'failed' => '失败',
        'skipped' => '已跳过',
        'processing' => '处理中',
        'pending' => '待处理',
    ];
    $logSourceLabel = static fn ($source): string => $logSourceLabels[strtolower(trim((string) $source))] ?? $display($source);
    $logActionLabel = static fn ($action): string => $logActionLabels[strtolower(trim((string) $action))] ?? $display($action);
    $logStatusLabel = static fn ($status): string => $logStatusLabels[strtolower(trim((string) $status))] ?? $display($status);
    $logStatusClass = static fn ($status): string => match (strtolower(trim((string) $status))) {
        'success' => 'is-success',
        'failed' => 'is-danger',
        'skipped' => 'is-skipped',
        'processing', 'pending' => 'is-pending',
        default => 'is-neutral',
    };
@endphp

<div class="logistics-order-detail" data-order-id="{{ $order->getKey() }}" data-edit-url="{{ route('logistics.orders.edit', $order) }}">
    <header class="logistics-order-detail-hero {{ $statusTone }}">
        <div>
            <span class="logistics-order-detail-eyebrow">{{ $display($order->store_name) }} · Shopify #{{ $display($order->shopify_order_number) }}</span>
            <h3>{{ $display($order->tongtu_order_number ?: ($order->store_name.'-'.$order->shopify_order_number)) }}</h3>
            <p><i class="fa fa-clock-o"></i> 下单时间 {{ $date($order->order_created_at) }}　·　系统编号 {{ $order->getKey() }}</p>
        </div>
        <div class="logistics-order-detail-tags">
            <span class="label {{ $order->statusClass() }}">{{ $display($order->data_status) }}</span>
            <span class="label label-primary">{{ $display($order->payment_status) }}</span>
            <span class="label {{ $order->logisticsStatusClass() }}">{{ $display($order->logistics_status) }}</span>
            <span class="label {{ $order->is_add_on === '已加购' ? 'label-warning' : 'label-default' }}">{{ $display($order->is_add_on) }}</span>
            <span class="label label-info">{{ $display($order->subscription_type) }}</span>
        </div>
    </header>

    <div class="logistics-order-detail-layout">
        <main>
            <section class="logistics-order-detail-card">
                <div class="logistics-order-detail-card-title"><h4><i class="fa fa-cubes"></i> 订单商品</h4><span>共 {{ $order->items->count() }} 个明细</span></div>
                <div class="logistics-order-detail-items">
                    @forelse($order->items as $item)
                        <article class="logistics-order-detail-item">
                            <div class="logistics-order-detail-item-image">
                                @if($item->product_image_url)<img src="{{ $item->product_image_url }}" alt="">@else<i class="fa fa-cube"></i>@endif
                            </div>
                            <div class="logistics-order-detail-item-copy">
                                <strong>{{ $display($item->product_title) }}</strong>
                                <span>{{ $display($item->variant_title) }}</span>
                                <div><code>{{ $display($item->shopify_sku) }}</code><em>通途 SKU：{{ $display($item->tongtu_sku) }}</em></div>
                                <small>配货名称：{{ $display($item->tongtu_product_name) }} @if($item->tongtu_product_type)（类型 {{ $item->tongtu_product_type }}）@endif</small>
                            </div>
                            <dl class="logistics-order-detail-item-metrics">
                                <div><dt>数量</dt><dd>{{ $display($item->quantity) }}</dd></div>
                                <div><dt>单价</dt><dd>{{ $money($item->unit_price_usd) }}</dd></div>
                                <div><dt>销售额</dt><dd>{{ $money($item->line_revenue_usd) }}</dd></div>
                                <div><dt>重量</dt><dd>{{ $item->sale_weight_gram === null ? '—' : number_format((float) $item->sale_weight_gram, 2).' g' }}</dd></div>
                            </dl>
                        </article>
                    @empty
                        <div class="logistics-order-detail-empty">暂无商品明细。</div>
                    @endforelse
                </div>
            </section>

            <section class="logistics-order-detail-card">
                <div class="logistics-order-detail-card-title"><h4><i class="fa fa-user-o"></i> 客户与收货信息</h4></div>
                <div class="logistics-order-detail-definition-grid">
                    <dl><dt>买家名称</dt><dd>{{ $display($order->buyer_name) }}</dd></dl>
                    <dl><dt>Email</dt><dd>{{ $display($order->buyer_email) }}</dd></dl>
                    <dl><dt>电话</dt><dd>{{ $display($order->buyer_phone) }}</dd></dl>
                    <dl><dt>目的地</dt><dd>{{ $display($order->destination) }}</dd></dl>
                    <dl><dt>Shopify 客户 ID</dt><dd>{{ $display($order->shopify_customer_id) }}</dd></dl>
                    <dl><dt>客户累计订单</dt><dd>{{ $display($order->shopify_customer_order_count) }}</dd></dl>
                </div>
            </section>

            <section class="logistics-order-detail-card">
                <div class="logistics-order-detail-card-title"><h4><i class="fa fa-credit-card"></i> 支付信息</h4><span>保留 Shopify 原始属性</span></div>
                <div class="logistics-order-detail-definition-grid">
                    <dl><dt>支付方式</dt><dd>{{ $display($order->payment_method) }}</dd></dl>
                    <dl><dt>支付渠道</dt><dd>{{ $display($order->payment_gateway) }}</dd></dl>
                    <dl><dt>收款账号</dt><dd>{{ $display($order->collection_account) }}</dd></dl>
                    <dl><dt>收款账号邮箱</dt><dd>{{ $display($order->collection_account_email) }}</dd></dl>
                    <dl><dt>交易号 / yx_order_id</dt><dd>{{ $display($order->transaction_id) }}</dd></dl>
                    <dl><dt>账单号</dt><dd>{{ $display($order->bill_number) }}</dd></dl>
                    <dl><dt>支付网关订单号</dt><dd>{{ $display($order->payment_gateway_order_id) }}</dd></dl>
                    <dl><dt>PayPal Merchant ID</dt><dd>{{ $display($order->paypal_merchant_id) }}</dd></dl>
                    <dl><dt>Checkout ID</dt><dd>{{ $display($order->shopify_checkout_id) }}</dd></dl>
                    <dl><dt>Cart Token</dt><dd class="is-wrap">{{ $display($order->shopify_cart_token) }}</dd></dl>
                </div>
                @if($shopifyAttributes !== [])
                    <div class="logistics-order-detail-attributes">
                        @foreach($shopifyAttributes as $key => $value)
                            <dl @class(['is-json' => $key === 'yx_page_source'])>
                                <dt>{{ $key }}</dt>
                                @if($key === 'yx_page_source')
                                    <dd><pre>{{ $pretty($value) }}</pre></dd>
                                @else
                                    <dd>{{ $display($value) }}</dd>
                                @endif
                            </dl>
                        @endforeach
                    </div>
                @endif
            </section>

            <div class="logistics-order-detail-two-column">
                <section class="logistics-order-detail-card">
                    <div class="logistics-order-detail-card-title"><h4><i class="fa fa-refresh"></i> 订阅关系</h4></div>
                    <div class="logistics-order-detail-definition-grid is-single">
                        <dl><dt>订阅类型</dt><dd>{{ $display($order->subscription_type) }}</dd></dl>
                        <dl><dt>订阅状态</dt><dd>{{ $display($order->subscription_status) }}</dd></dl>
                        <dl><dt>复购原订单 ID</dt><dd>{{ $display($order->subscription_origin_order_id) }}</dd></dl>
                        <dl><dt>关联来源 / 状态</dt><dd>{{ $display($order->subscription_link_source) }} / {{ $display($order->subscription_link_status) }}</dd></dl>
                    </div>
                </section>
                <section class="logistics-order-detail-card">
                    <div class="logistics-order-detail-card-title"><h4><i class="fa fa-truck"></i> 物流信息</h4></div>
                    <div class="logistics-order-detail-definition-grid is-single">
                        <dl><dt>物流状态</dt><dd>{{ $display($order->logistics_status) }}</dd></dl>
                        <dl><dt>物流公司</dt><dd>{{ $display($order->shipping_company) }}</dd></dl>
                        <dl><dt>跟踪单号</dt><dd>{{ $display($order->tracking_number) }}</dd></dl>
                        <dl><dt>首次发货时间</dt><dd>{{ $date($order->first_fulfilled_at) }}</dd></dl>
                    </div>
                </section>
            </div>

            <section class="logistics-order-detail-card">
                <div class="logistics-order-detail-card-title"><h4><i class="fa fa-reply"></i> 退款与纠纷</h4><span>导入退款 {{ $money($manualRefundTotal) }} · PayPal 退款 {{ $money($paypalRefundTotal) }}</span></div>
                <div class="table-responsive"><table class="table logistics-order-detail-table"><thead><tr><th>来源</th><th>编号</th><th>金额</th><th>类型 / 状态</th><th>原因 / 事件类型</th><th>时间</th></tr></thead><tbody>
                    @foreach($order->activeRefundDetails as $refund)<tr><td><span class="label label-info">导入</span></td><td>{{ $display($refund->refund_number) }}</td><td>{{ $money($refund->refund_amount_usd) }}</td><td>{{ $display($refund->refund_type) }}</td><td>{{ $display($refund->refund_reason ?: $refund->remark) }}</td><td>{{ $date($refund->created_at) }}</td></tr>@endforeach
                    @foreach($order->paypalRefunds as $refund)<tr><td><span class="label label-primary">PayPal退款</span></td><td>{{ $display($refund->refund_transaction_id) }}</td><td>{{ $money($refund->refund_amount_usd) }}</td><td>{{ $refund->statusLabel() }}</td><td title="{{ $refund->refund_reason }}">{{ $refund->eventLabel() }}</td><td>{{ $date($refund->refunded_at) }}</td></tr>@endforeach
                    @foreach($order->paypalDisputes as $dispute)<tr><td><span class="label label-danger">PayPal纠纷</span></td><td>{{ $display($dispute->dispute_id) }}</td><td>{{ $money($dispute->dispute_amount_usd ?: $dispute->original_amount) }}</td><td>{{ $display($dispute->life_cycle_stage) }} / {{ $display($dispute->status) }}</td><td>{{ $display($dispute->reason) }}</td><td>{{ $date($dispute->dispute_created_at) }}</td></tr>@endforeach
                    @if($order->activeRefundDetails->isEmpty() && $order->paypalRefunds->isEmpty() && $order->paypalDisputes->isEmpty())<tr><td colspan="6" class="text-center text-muted">暂无退款或纠纷明细。</td></tr>@endif
                </tbody></table></div>
            </section>

            <section class="logistics-order-detail-card">
                <div class="logistics-order-detail-card-title"><h4><i class="fa fa-exchange"></i> 通途匹配与同步记录</h4><span>{{ $display($order->sku_exception_reason) }}</span></div>
                <div class="logistics-order-detail-definition-grid">
                    <dl><dt>通途订单号</dt><dd>{{ $display($order->tongtu_order_number) }}</dd></dl>
                    <dl><dt>通途 SKU</dt><dd class="is-wrap">{{ $display($order->tongtu_sku) }}</dd></dl>
                    <dl><dt>通途配货名称</dt><dd class="is-wrap">{{ $display($order->tongtu_product_name) }}</dd></dl>
                    <dl><dt>出单产品重量</dt><dd class="is-wrap">{{ $display($order->sale_product_weight) }}</dd></dl>
                </div>
                @if($order->logs->isNotEmpty())
                    <div class="logistics-order-detail-timeline">
                        <div class="logistics-order-detail-timeline-head"><span>时间线</span><span>执行动作</span><span>执行结果</span></div>
                        @foreach($order->logs as $log)
                            <div class="logistics-order-detail-timeline-row {{ $logStatusClass($log->status) }}">
                                <i aria-hidden="true"></i>
                                <time>{{ $date($log->created_at) }}</time>
                                <strong>{{ $logSourceLabel($log->source) }} · {{ $logActionLabel($log->action) }}</strong>
                                <div class="logistics-order-detail-timeline-result">
                                    <span>{{ $logStatusLabel($log->status) }}</span>
                                    <p>{{ $display($log->message) }}</p>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif
            </section>

            <section class="logistics-order-detail-card logistics-order-detail-raw">
                <div class="logistics-order-detail-card-title"><h4><i class="fa fa-code"></i> 原始数据</h4><span>用于映射核查，不改写原始内容</span></div>
                <details><summary>Shopify 原始订单</summary><pre>{{ $pretty($shopifyPayload) }}</pre></details>
                <details><summary>通途原始返回</summary><pre>{{ $pretty($tongtuPayload) }}</pre></details>
                <details><summary>Shopify 发货信息</summary><pre>{{ $pretty($fulfillmentPayload) }}</pre></details>
                <details><summary>yx_page_source</summary><pre>{{ $pretty($pageSourcePayload) }}</pre></details>
            </section>
        </main>

        <aside>
            <section class="logistics-order-detail-card logistics-order-detail-settlement">
                <div class="logistics-order-detail-card-title"><h4>店铺付款信息</h4></div>
                <dl><dt>商品小计</dt><dd>{{ $money($productSubtotal) }}</dd></dl>
                <dl><dt>买家实付运费</dt><dd>{{ $money($order->total_shipping_usd) }}</dd></dl>
                <dl class="is-total"><dt>实付金额</dt><dd>{{ $money($order->paid_amount_usd) }}</dd></dl>
                <div class="logistics-order-detail-paid"><span>{{ $display($order->payment_status) }}</span><strong>{{ $money($order->paid_amount_usd) }}</strong></div>
                <dl><dt>货品成本</dt><dd>{{ $money($order->product_cost_usd) }}</dd></dl>
                <dl><dt>手续费</dt><dd>{{ $money($order->handling_fee_usd) }}</dd></dl>
                <dl><dt>Shopify 退款</dt><dd>{{ $money($order->shopify_refund_amount_usd) }}</dd></dl>
                <dl><dt>渠道退款</dt><dd>{{ $money($order->channel_refund_amount_usd) }}</dd></dl>
                <dl><dt>平台费用</dt><dd>{{ $money($order->platform_fee_usd) }}</dd></dl>
                <dl class="is-total"><dt>净额</dt><dd>{{ $money($order->net_amount_usd) }}</dd></dl>
                <div class="logistics-order-detail-profit"><span>财务利润</span><strong>{{ $money($order->financial_profit_usd) }}</strong><em>{{ $rate($order->financial_profit_rate) }}</em></div>
            </section>
            <section class="logistics-order-detail-card logistics-order-detail-source">
                <div class="logistics-order-detail-card-title"><h4>归属信息</h4></div>
                <dl><dt>来源域名</dt><dd>{{ $display($order->source_shop_domain) }}</dd></dl>
                <dl><dt>所属部门</dt><dd>{{ $display($order->department_name) }}</dd></dl>
                <dl><dt>运营人员</dt><dd>{{ $display($order->operator_name) }}</dd></dl>
                <dl><dt>所属组</dt><dd>{{ $display($order->group_names) }}</dd></dl>
                <dl><dt>创建时间</dt><dd>{{ $date($order->created_at) }}</dd></dl>
                <dl><dt>最后更新</dt><dd>{{ $date($order->updated_at) }}</dd></dl>
            </section>
        </aside>
    </div>
</div>
