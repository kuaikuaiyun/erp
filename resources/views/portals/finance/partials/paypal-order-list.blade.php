@php
    $orderColumns = ['编号','订单信息','店铺信息','订阅信息','订阅复购原订单ID','出单产品明细','支付信息','金额信息','订单时间','PayPal实际退款','信用卡实际退款','交易号','账单号','买家信息','物流信息','数据状态'];
@endphp
<div class="box-header with-border logistics-list-toolbar paypal-order-toolbar {{ $dataset === 'paypal-orders' && $canManage ? 'has-order-refresh' : '' }}">
    <h3 class="box-title">订单列表</h3><button id="paypal-export-selected" type="button" class="btn btn-default btn-sm" hidden style="display:none" disabled>导出所选</button>
    @if($dataset === 'paypal-orders' && $canManage)
    <button type="button" id="finance-order-refresh-open" class="btn btn-primary btn-sm" @disabled($refreshablePayPalAccounts->isEmpty())><i class="fa fa-refresh" aria-hidden="true"></i> 刷新订单</button>
    @endif
    <input type="hidden" name="export_layout" value="page" form="finance-filters">
    @if($dataset === 'paypal-orders')
        <select class="form-control" data-active-export-template aria-label="本次使用的导出模板"><option value="">默认字段设置</option></select>
        @include('portals.finance.partials.settlement-column-settings', [
            'report' => $dataset, 'orderView' => false, 'columns' => $orderColumns,
            'exportColumns' => \App\Portals\Finance\FinancePayPalOrderExport::pageHeaders($dataset),
        ])
    @else
    <details class="finance-export-columns">
        <summary class="btn btn-default btn-sm">选择导出列</summary>
        <div class="finance-export-column-panel">
            <div class="finance-export-column-actions"><strong>选择需要导出的字段</strong><button type="button" class="btn btn-default btn-xs" data-export-columns-all>全选</button><button type="button" class="btn btn-default btn-xs" data-export-columns-none>清空</button></div>
            <input type="hidden" name="report_fields_customized" value="1" form="finance-filters">
            <div class="finance-export-column-grid">
            @foreach(\App\Portals\Finance\FinancePayPalOrderExport::pageHeaders($dataset) as $column)
                <label><input type="checkbox" name="report_columns[]" value="{{ $loop->index }}" form="finance-filters" checked> {{ \App\Portals\Finance\FinanceCatalog::simplified($column) }}</label>
            @endforeach
            </div>
            <div style="display:flex;align-items:center;gap:12px;margin-top:12px"><p class="text-muted" style="flex:1;margin:0" data-export-columns-message>至少选择一列，保存后记住本浏览器的选择。</p><button type="button" class="btn btn-primary btn-sm" data-export-columns-save>保存</button></div>
        </div>
    </details>
    @endif
    <select name="export_format" form="finance-filters" class="form-control" aria-label="{{ __('finance.records.export_format') }}">@foreach(__('finance.records.formats') as $format => $label)<option value="{{ $format }}" @selected(($filters['export_format'] ?? 'xlsx') === $format)>{{ \App\Portals\Finance\FinanceCatalog::simplified((string) ($label)) }}</option>@endforeach</select>
    <a id="finance-export-filtered" class="btn btn-default btn-sm" href="{{ route('finance.records.export', ['dataset' => $dataset, ...$filters, 'export_scope' => 'filtered', 'export_format' => 'xlsx', ...(['export_layout' => 'page'])]) }}"><i class="fa fa-download" aria-hidden="true"></i> 导出</a>
    @include('portals.finance.pagination', ['paginator' => $records])
</div>
<div id="finance-order-horizontal-scroll" style="overflow-x:auto;overflow-y:hidden;height:18px" aria-label="订单列表横向滚动" tabindex="0"><div style="height:1px"></div></div>
<div class="box-body table-responsive no-padding logistics-orders-scroll" id="finance-order-table-scroll">
<table class="table table-hover logistics-table logistics-orders-table paypal-order-table show-extra-columns">
    @php
        $columnStyles = ['', 'logistics-order-identifiers', 'logistics-store-information', 'logistics-subscription-information', '', 'logistics-sale-product-details', 'logistics-payment-information', ''];
    @endphp
    <thead><tr><th scope="col" class="paypal-order-selection"><input type="checkbox" id="paypal-order-check-all" aria-label="全选当前页"></th>@foreach($orderColumns as $index => $label)<th scope="col" data-settlement-column="{{ $index }}" class="{{ ($columnStyles[$index] ?? '') ? $columnStyles[$index].'-heading' : '' }}">{{ $label }}</th>@endforeach<th scope="col">操作</th></tr></thead>
    <tbody>
    @forelse($records as $record)
        @php
            $catalog = \App\Portals\Finance\FinanceCatalog::class;
            $metadata = $paypalOrderMetadata[$record->id] ?? [];
            $order = $metadata['order'] ?? null;
            $domain = ($metadata['domain'] ?? '') ?: $record->domain;
            $group = $groups->get($domain);
            $account = $catalog::simplified((string) $accountLabels->get($record->account, $record->account));
            $accounting = $accountingTransactions->get($record->id);
            $category = $accounting ? $accounting->category : $types->get($record->raw_type);
            $fields = [];
            foreach ($definition['headers'] as $index => $header) {
                $raw = $record->payload[$header] ?? '';
                $fields[$index] = $index === ($definition['type'] ?? -1)
                    ? \App\Portals\Finance\FinancePayPalEventTypes::label((string) $raw)
                    : $catalog::displayValue($dataset, $index, $raw);
            }
            if ($dataset === 'xuzong2-orders') {
                $fields = array_fill(0, 41, '');
                $fields[5] = $order?->payment_status ?? $record->status;
                $fields[6] = $record->currency;
                $fields[7] = $order?->paid_amount_usd ?? '';
                $fields[13] = $order?->bill_number ?? '';
                $fields[15] = $order?->collection_account_email ?? '';
                $fields[18] = $order?->tongtu_product_name ?? '';
                $fields[28] = $order?->sale_quantity ?? '';
            }
            $blocks = [
                ['编号' => $order?->shopify_order_number ?? null, 'ID' => $order?->shopify_order_id ?? null,
                    '通途' => $metadata['erp_order'] ?? ($record->payload['通途订单'] ?? null), '交易号' => $record->reference,
                    '账单号' => $fields[13], '关联状态' => $order ? null : '未匹配物流订单'],
                ['店铺名称' => $order?->store_name ?? null, '店铺域名' => $domain, '登录地址' => $order?->source_shop_domain ?? null,
                    '使用人' => $group?->owner, '所属团队' => $group?->team],
                ['加购' => ($upsell = ($metadata['upsell'] ?? ($record->payload['是否加购'] ?? null))) === null || $upsell === '' ? null
                    : (in_array(strtolower((string) $upsell), ['1', 'true', 'yes', '是', '加购'], true) ? '加购' : '未加购'),
                    '订阅类型' => $metadata['subscription'] ?? ($record->payload['订阅类型'] ?? null), '订阅状态' => $order?->subscription_status ?? null],
                ['原订单ID' => $order?->subscription_origin_order_id ?? null],
                ['通途SKU' => $order?->tongtu_sku ?? null, '数量' => $order?->sale_quantity ?? $fields[28],
                    '商品重量' => $order?->sale_product_weight ?? null, '商品' => $order?->tongtu_product_name ?? $fields[18]],
                ['支付状态' => $fields[5], '交易类型' => $fields[4], '支付渠道' => $order?->payment_gateway ?? ($dataset === 'paypal-orders' ? 'PayPal' : ''),
                    '收款账号' => $account, '收款账号邮箱' => $fields[15], '币种' => $fields[6],
                    '金额' => $fields[7], '手续费' => $fields[8], '净额' => $fields[9],
                    '实付金额' => $order?->paid_amount_usd, '运费' => $order?->total_shipping_usd],
            ];
        @endphp
        <tr>
            <td class="paypal-order-selection"><input type="checkbox" class="paypal-order-check" value="{{ $record->id }}" aria-label="选择记录 {{ $record->id }}"></td>
            <td data-settlement-column="0" class="paypal-order-number" title="{{ $order ? '物流订单编号' : '财务记录编号' }}">{{ $order?->id ?? $record->id }}</td>
            @foreach($blocks as $block)
                @if($loop->iteration === 4)
                    <td data-settlement-column="{{ $loop->iteration }}" class="paypal-order-origin" title="{{ $block['原订单ID'] }}">{{ $block['原订单ID'] ?: '—' }}</td>
                @elseif($loop->iteration === 5)
                    <td data-settlement-column="{{ $loop->iteration }}" class="logistics-sale-product-details">
                        @php
                            $productItems = $paypalOrderItems->get($order?->id, collect());
                            $sourceTitle = $dataset === 'paypal-orders' ? ($record->payload[$definition['headers'][18]] ?? '') : $fields[18];
                            $productItems = \App\Portals\Finance\FinancePayPalOrderExport::productItems($productItems, (string) ($sourceTitle ?: $order?->tongtu_product_name),
                                $dataset === 'paypal-orders' ? (string) ($record->payload[$definition['headers'][28]] ?? '') : (string) $fields[28]);
                        @endphp
                        @include('portals.finance.partials.paypal-order-products', ['items' => $productItems])
                    </td>
                @else
                <td data-settlement-column="{{ $loop->iteration }}" class="{{ $columnStyles[$loop->iteration] }}"><dl class="paypal-order-fields">
                    @forelse(array_filter($block, fn ($value) => filled($value)) as $label => $value)
                        <dt>{{ $label }}：</dt><dd @class(['is-positive' => ($label === '支付状态' && in_array($value, ['已付款', '已完成', '成功'], true)) || ($label === '订阅状态' && $value === '已订阅'), 'is-subscription' => $label === '订阅类型']) title="{{ $value }}">{{ $value }}</dd>
                    @empty
                        <dd>—</dd>
                    @endforelse
                </dl></td>
                @endif
            @endforeach
            @php
                $dateText = static fn ($value) => $value ? \Carbon\CarbonImmutable::parse($value, 'UTC')->setTimezone($filters['timezone'])->format('Y-m-d H:i:s') : '';
                $extraBlocks = [
                    ['实付金额' => $order?->paid_amount_usd ?? $fields[7], '运费' => $order?->total_shipping_usd],
                    ['入库时间' => $dateText($order?->created_at), '下单时间' => $dateText($order?->order_created_at), '付款时间' => $dateText($order?->paid_at)],
                ];
            @endphp
            @foreach($extraBlocks as $extra)
                <td data-settlement-column="{{ 7 + $loop->index }}"><dl class="paypal-order-fields">@foreach($extra as $label => $value)<dt>{{ $label }}：</dt><dd title="{{ $value }}">{{ $value }}</dd>@endforeach</dl></td>
            @endforeach
            @foreach(['paypalRefunds', 'creditCardRefunds'] as $relation)
                <td data-settlement-column="{{ 9 + $loop->index }}">
                    @if($order && $order->relationLoaded($relation))
                        @foreach($order->getRelation($relation) as $refund)
                            <div class="logistics-refund-item">
                                <span>{{ $refund->reason ?? $refund->refund_reason ?? '' }}</span>
                                <span>{{ $refund->refund_amount_usd ?? '' }}</span>
                            </div>
                        @endforeach
                    @endif
                </td>
            @endforeach
            <td data-settlement-column="11" style="overflow-wrap:anywhere">{{ $record->reference }}</td>
            <td data-settlement-column="12" style="overflow-wrap:anywhere">{{ $order?->bill_number ?? $fields[13] }}</td>
            @php
                $contactBlocks = [
                    ['买家名称' => $order?->buyer_name ?? $fields[3], 'Email' => $order?->buyer_email ?? $fields[10], '国家/地区' => $order?->destination ?? $fields[36]],
                    ['物流公司' => $order?->shipping_company, '物流单号' => $order?->tracking_number, '物流状态' => $order?->logistics_status],
                ];
            @endphp
            @foreach($contactBlocks as $extra)
                <td data-settlement-column="{{ 13 + $loop->index }}"><dl class="paypal-order-fields">@foreach($extra as $label => $value)<dt>{{ $label }}：</dt><dd title="{{ $value }}">{{ $value }}</dd>@endforeach</dl></td>
            @endforeach
            <td data-settlement-column="15">{{ $order?->data_status }}</td>
            <td>
                <button type="button" class="btn btn-info btn-xs js-logistics-order-detail" data-paypal-detail="paypal-detail-{{ $record->id }}" data-order-detail-url="{{ route('finance.records', ['dataset' => $dataset, 'order_detail' => $record->id, 'timezone' => $filters['timezone']]) }}" aria-haspopup="dialog"><i class="fa fa-eye" aria-hidden="true"></i> 查看</button>
                <template id="paypal-detail-{{ $record->id }}">
                    @php
                        $detailGroups = [
                            '订单信息' => [...$blocks[0], '交易时间' => $record->occurred_at->setTimezone($filters['timezone'])->format('Y-m-d H:i:s'), '查看时区' => $catalog::timezoneLabel($filters['timezone'])],
                            '店铺信息' => $blocks[1],
                            '订阅信息' => [...$blocks[2], ...$blocks[3]],
                            '支付信息' => [...$blocks[5], '交易分类' => $category, '折合人民币' => $accounting?->cny_amount],
                            '买家与收货信息' => ['姓名' => $fields[3], '付款方邮箱' => $fields[10], '联系电话' => $fields[37], '收货地址' => $fields[16]],
                        ];
                    @endphp
                    @foreach($detailGroups as $heading => $values)
                        <section class="paypal-detail-section"><h4>{{ $heading }}</h4><dl>
                            @forelse(array_filter($values, fn ($value) => filled($value)) as $label => $value)
                                <dt>{{ $label }}</dt><dd>{{ $value }}</dd>
                            @empty
                                <dd>暂无关联信息</dd>
                            @endforelse
                        </dl></section>
                    @endforeach
                    <section class="paypal-detail-section paypal-detail-wide"><h4>出单产品明细</h4>
                        @if($paypalOrderItems->has($order?->id))
                            @include('portals.finance.partials.paypal-order-products', ['items' => $paypalOrderItems->get($order->id)])
                        @else
                            <dl>@forelse(array_filter($blocks[4], fn ($value) => filled($value)) as $label => $value)<dt>{{ $label }}</dt><dd>{{ $value }}</dd>@empty<dd>暂无商品明细</dd>@endforelse</dl>
                        @endif
                    </section>
                    <details class="paypal-detail-section paypal-detail-wide"><summary>PayPal 原始交易字段</summary><dl>
                        @foreach($definition['headers'] as $index => $header)
                            @if(filled($fields[$index]))<dt>{{ $catalog::simplified($header) }}</dt><dd>{{ $fields[$index] }}</dd>@endif
                        @endforeach
                    </dl></details>
                </template>
            </td>
        </tr>
    @empty
        <tr><td data-settlement-colspan="2" colspan="{{ count($orderColumns) + 2 }}">{{ __('finance.records.empty') }}</td></tr>
    @endforelse
    </tbody>
</table>
</div>
@push('scripts')
<script>
(function () {
    var bar = document.getElementById('finance-order-horizontal-scroll');
    var viewport = document.getElementById('finance-order-table-scroll');
    var table = viewport.querySelector('table');
    var lastWidth = -1;
    var expectedScroll = new WeakMap();
    var scrollFrame = 0;
    var scrollSource = null;
    function sync(source) {
        var expected = expectedScroll.get(source);
        if (expected !== undefined && Math.abs(source.scrollLeft - expected) < 1) {
            expectedScroll.delete(source);
            return;
        }
        expectedScroll.delete(source);
        scrollSource = source;
        if (scrollFrame) return;
        scrollFrame = requestAnimationFrame(function () {
            scrollFrame = 0;
            var destination = scrollSource === bar ? viewport : bar;
            var position = scrollSource.scrollLeft;
            if (Math.abs(destination.scrollLeft - position) >= 1) {
                destination.scrollLeft = position;
                expectedScroll.set(destination, destination.scrollLeft);
            }
        });
    }
    function resize(force) {
        if (!force && viewport.clientWidth === lastWidth) return;
        lastWidth = viewport.clientWidth;
        // Fit the primary columns precisely before the pinned action column.
        // Keep secondary columns beyond that boundary instead of exposing a sliver.
        var headers = Array.from(table.tHead.rows[0].cells);
        var base = [38, 68, 220, 230, 160, 160, 340, 220];
        var available = Math.max(1436, viewport.clientWidth - 100);
        var used = 0;
        var widths = headers.map(function (header, index) {
            if (index < base.length) {
                var width = index === base.length - 1 ? available - used : Math.round(base[index] * available / 1436);
                used += width;
                return width;
            }
            return index === headers.length - 1 ? 100 : (index === 8 ? 180 : 210);
        });
        Array.from(table.rows).forEach(function (row) {
            if (row.cells.length !== headers.length) return;
            Array.from(row.cells).forEach(function (cell, index) {
                cell.style.width = widths[index] + 'px';
                cell.style.minWidth = widths[index] + 'px';
                cell.style.maxWidth = widths[index] + 'px';
            });
        });
        table.style.width = Math.max(viewport.clientWidth, widths.reduce(function (sum, width, index) {
            return sum + (headers[index].hidden ? 0 : width);
        }, 0)) + 'px';
        table.style.minWidth = table.style.width;
        // Both scrollbars need identical ranges, excluding the vertical scrollbar.
        bar.style.width = viewport.clientWidth + 'px';
        bar.firstElementChild.style.width = table.scrollWidth + 'px';
        bar.scrollLeft = viewport.scrollLeft;
        expectedScroll.set(bar, bar.scrollLeft);
    }
    bar.addEventListener('scroll', function () { sync(bar); }, { passive: true });
    viewport.addEventListener('scroll', function () { sync(viewport); }, { passive: true });
    new ResizeObserver(function () { requestAnimationFrame(function () { resize(); }); }).observe(viewport);
    document.addEventListener('finance:columns-changed', function () { resize(true); });
    resize();
})();
</script>
@endpush

@if($dataset === 'paypal-orders' && $canManage)
<dialog id="finance-order-refresh-dialog" style="width:720px;max-width:calc(100vw - 32px);border:1px solid #dce6ef;border-radius:8px;padding:22px;color:#31516b">
    <form method="post" action="{{ route('finance.orders.paypal.refresh') }}" data-loading="off">
        @csrf
        <h3 style="font-size:18px;margin:0 0 18px">刷新 PayPal 订单</h3>
        <fieldset data-order-refresh-accounts style="border:0;padding:0;margin:0">
            <legend style="font-size:14px;border:0;margin:0 0 8px">选择更新账户（可多选）</legend>
            <label style="display:block"><input type="checkbox" data-order-refresh-all checked> 全选账户</label>
            <div style="max-height:150px;overflow:auto;border:1px solid #dce6ef;border-radius:5px;padding:10px">
                @foreach($refreshablePayPalAccounts as $refreshAccount)<label style="display:block;font-weight:400"><input type="checkbox" name="accounts[]" value="{{ $refreshAccount->id }}" checked> {{ \App\Portals\Finance\FinanceCatalog::simplified($refreshAccount->account_name) }}</label>@endforeach
            </div>
        </fieldset>
        <p class="text-muted" style="margin-top:12px">订单将在后台按顺序更新，不影响当前页面。停用或未配置接口的账户不参与刷新。</p>
        <p data-order-refresh-message role="status" aria-live="polite"></p>
        <div data-order-refresh-results hidden data-status-url="{{ route('finance.orders.paypal.status', ['batch' => '__batch__']) }}" style="max-height:340px;overflow:auto;margin-top:12px">
            <table class="table table-bordered" style="width:100%;font-size:13px"><thead><tr><th>PayPal账户</th><th>更新状态</th><th>已处理交易数</th><th>说明 / 失败原因</th></tr></thead><tbody></tbody></table>
            <p class="text-muted">条数包含新增和重新核对的交易；失败时已保存的数据保留。订单同步完成后点击“查询”更新列表。</p>
        </div>
        <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:18px"><button type="button" class="btn btn-default" data-order-refresh-close>关闭</button><button type="submit" class="btn btn-primary">加入更新队列</button></div>
    </form>
</dialog>
<script src="{{ asset('assets/erp/finance-order-refresh.js') }}?v=5"></script>
@endif
