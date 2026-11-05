<div class="box-header with-border logistics-list-toolbar paypal-order-toolbar">
    <h3 class="box-title">订单列表</h3><button id="paypal-export-selected" type="button" class="btn btn-default btn-sm" disabled>导出所选</button>
    <select name="export_format" form="finance-filters" class="form-control" aria-label="{{ __('finance.records.export_format') }}">@foreach(__('finance.records.formats') as $format => $label)<option value="{{ $format }}" @selected(($filters['export_format'] ?? 'xlsx') === $format)>{{ \App\Portals\Finance\FinanceCatalog::simplified((string) ($label)) }}</option>@endforeach</select>
    <a id="finance-export-filtered" class="btn btn-default btn-sm" href="{{ route('finance.records.export', ['dataset' => $dataset, ...$filters, 'export_scope' => 'filtered', 'export_format' => 'xlsx']) }}"><i class="fa fa-download" aria-hidden="true"></i> 导出</a>
    @include('portals.finance.pagination', ['paginator' => $records])
</div>
<div class="box-body table-responsive no-padding logistics-orders-scroll">
<table class="table table-hover logistics-table logistics-orders-table paypal-order-table">
    @php
        $columnStyles = ['', 'logistics-order-identifiers', 'logistics-store-information', 'logistics-subscription-information', '', 'logistics-sale-product-details', 'logistics-payment-information', ''];
    @endphp
    <thead><tr><th scope="col" class="paypal-order-selection"><input type="checkbox" id="paypal-order-check-all" aria-label="全选当前页"></th>@foreach(['编号','订单信息','店铺信息','订阅信息','订阅复购原订单ID','出单产品明细','支付信息','金额信息','订单时间','PayPal实际退款','信用卡实际退款','交易号','账单号','买家信息','物流信息','数据状态','操作'] as $label)<th scope="col" class="{{ ($columnStyles[$loop->index] ?? '') ? $columnStyles[$loop->index].'-heading' : '' }}">{{ $label }}</th>@endforeach</tr></thead>
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
                    '金额' => $fields[7], '手续费' => $fields[8], '净额' => $fields[9]],
            ];
        @endphp
        <tr>
            <td class="paypal-order-selection"><input type="checkbox" class="paypal-order-check" value="{{ $record->id }}" aria-label="选择记录 {{ $record->id }}"></td>
            <td class="paypal-order-number" title="{{ $order ? '物流订单编号' : '财务记录编号' }}">{{ $order?->id ?? $record->id }}</td>
            @foreach($blocks as $block)
                @if($loop->iteration === 4)
                    <td class="paypal-order-origin" title="{{ $block['原订单ID'] }}">{{ $block['原订单ID'] ?: '—' }}</td>
                @elseif($loop->iteration === 5)
                    <td class="logistics-sale-product-details">
                        @php
                            $productItems = $paypalOrderItems->get($order?->id, collect());
                            if ($productItems->isEmpty()) {
                                $names = preg_split('/\r?\n/', (string) $fields[18]) ?: [];
                                $quantities = preg_split('/\r?\n/', (string) $fields[28]) ?: [];
                                $productItems = collect($names)->filter(fn ($name) => trim($name) !== '')->map(fn ($name, $index) => new \App\Models\LogisticsOrderItem([
                                    'product_title' => $name,
                                    'quantity' => is_numeric($quantities[$index] ?? null) ? $quantities[$index] : null,
                                ]));
                            }
                        @endphp
                        @include('portals.finance.partials.paypal-order-products', ['items' => $productItems])
                    </td>
                @else
                <td class="{{ $columnStyles[$loop->iteration] }}"><dl class="paypal-order-fields">
                    @forelse(array_filter($block, fn ($value) => filled($value)) as $label => $value)
                        <dt>{{ $label }}：</dt><dd @class(['is-positive' => ($label === '支付状态' && in_array($value, ['已付款', '已完成', '成功'], true)) || ($label === '订阅状态' && $value === '已订阅'), 'is-subscription' => $label === '订阅类型']) title="{{ $value }}">{{ $value }}</dd>
                    @empty
                        <dd>—</dd>
                    @endforelse
                </dl></td>
                @endif
            @endforeach
            @php
                $dateText = static fn ($value) => $value ? \Carbon\Carbon::parse($value)->timezone('Asia/Shanghai')->format('Y-m-d H:i:s') : '';
                $extraBlocks = [
                    ['实付金额' => $order?->paid_amount_usd ?? $fields[7], '运费' => $order?->total_shipping_usd],
                    ['入库时间' => $dateText($order?->created_at), '下单时间' => $dateText($order?->order_created_at), '付款时间' => $dateText($order?->paid_at)],
                ];
            @endphp
            @foreach($extraBlocks as $extra)
                <td><dl class="paypal-order-fields">@foreach($extra as $label => $value)<dt>{{ $label }}：</dt><dd title="{{ $value }}">{{ $value }}</dd>@endforeach</dl></td>
            @endforeach
            @foreach(['paypalRefunds', 'creditCardRefunds'] as $relation)
                <td>
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
            <td style="overflow-wrap:anywhere">{{ $record->reference }}</td>
            <td style="overflow-wrap:anywhere">{{ $order?->bill_number ?? $fields[13] }}</td>
            @php
                $contactBlocks = [
                    ['买家名称' => $order?->buyer_name ?? $fields[3], 'Email' => $order?->buyer_email ?? $fields[10], '国家/地区' => $order?->destination ?? $fields[36]],
                    ['物流公司' => $order?->shipping_company, '物流单号' => $order?->tracking_number, '物流状态' => $order?->logistics_status],
                ];
            @endphp
            @foreach($contactBlocks as $extra)
                <td><dl class="paypal-order-fields">@foreach($extra as $label => $value)<dt>{{ $label }}：</dt><dd title="{{ $value }}">{{ $value }}</dd>@endforeach</dl></td>
            @endforeach
            <td>{{ $order?->data_status }}</td>
            <td>
                <button type="button" class="btn btn-info btn-xs js-logistics-order-detail" data-paypal-detail="paypal-detail-{{ $record->id }}" data-order-detail-url="{{ route('finance.records', ['dataset' => $dataset, 'order_detail' => $record->id]) }}" aria-haspopup="dialog"><i class="fa fa-eye" aria-hidden="true"></i> 查看</button>
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
        <tr><td colspan="18">{{ __('finance.records.empty') }}</td></tr>
    @endforelse
    </tbody>
</table>
</div>
