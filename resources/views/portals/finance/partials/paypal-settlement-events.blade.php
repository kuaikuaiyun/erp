<div class="settlement-events-panel">
    <section class="settlement-detail-section">
        <h4>结算概况</h4>
        <dl class="settlement-detail-summary">
            <div><dt>当前订单状态</dt><dd>{{ $row['order_status'] }}</dd></div>
            @foreach(['payable' => '应结算（USD）', 'open_hold' => '未解除冻结（USD）'] as $field => $label)
                <div><dt>{{ $label }}</dt><dd>{{ ($row[$field] ?? null) === null ? '待核对' : \App\Portals\Finance\FinanceCatalog::reportAmount($row[$field]) }}</dd></div>
            @endforeach
        </dl>
        <dl class="settlement-detail-identifiers">
            @foreach(['shopify_order_number' => 'Shopify订单号', 'reference' => '交易 ID', 'account' => '支付账户'] as $field => $label)
                <div><dt>{{ $label }}</dt><dd>{{ $row[$field] ?? '' }}</dd></div>
            @endforeach
        </dl>
        @if(! empty($row['review']))<p class="settlement-detail-review"><i class="fa fa-info-circle" aria-hidden="true"></i> {{ $row['review'] }}</p>@endif
    </section>
    <section class="settlement-detail-section" aria-labelledby="settlement-detail-events-title">
        <h4 id="settlement-detail-events-title">交易流水 <small>{{ count($row['events']) }} 笔 · 按交易时间排列</small></h4>
        <ol class="settlement-event-list">
            @forelse($row['events'] as $event)
                @php
                    $amount = static fn ($value) => $value === null ? '—' : \App\Portals\Finance\FinanceCatalog::reportAmount($value);
                    $rate = (string) ($event['rate'] ?? '');
                    $metadata = array_filter($event['metadata'] ?? [], static fn ($value) => is_scalar($value) && filled($value));
                @endphp
                <li class="settlement-event">
                    <div class="settlement-event-heading">
                        <div class="settlement-event-title">
                            <strong>{{ $event['label'] }}</strong>
                            @if($event['code'] !== '')<span class="settlement-event-code">{{ $event['code'] }}</span>@endif
                            <span class="settlement-event-category">{{ $event['category'] }}</span>
                            <span @class(['settlement-detail-status', 'is-complete' => $event['status'] === 'S', 'is-pending' => $event['status'] === 'P', 'is-closed' => in_array($event['status'], ['D', 'V'], true)])>{{ $event['status_label'] ?: '—' }}</span>
                        </div>
                        <div class="settlement-event-context">
                            <span>{{ $event['time'] ?: '时间未记录' }} <small>{{ $event['timezone'] }}</small></span>
                            <span>支付账户 {{ $event['account'] ?: '—' }}</span>
                        </div>
                    </div>
                    <div class="settlement-event-grid">
                        <dl class="settlement-event-fields" aria-label="交易编号">
                            @foreach(['reference' => '原交易号', 'related_id' => '关联交易号', 'related_type' => '关联号类型', 'invoice' => '账单号'] as $field => $label)
                                <div><dt>{{ $label }}</dt><dd>{{ ($event[$field] ?? '') ?: '—' }}</dd></div>
                            @endforeach
                        </dl>
                        <dl class="settlement-event-fields settlement-event-money" aria-label="原币信息">
                            <div><dt>原币</dt><dd>{{ $event['currency'] ?: '—' }}</dd></div>
                            <div><dt>原币金额</dt><dd>{{ $amount($event['amount']) }}</dd></div>
                            <div><dt>原币手续费</dt><dd>{{ $amount($event['fee']) }}</dd></div>
                            <div><dt>原币净额</dt><dd><strong>{{ $amount($event['net']) }}</strong></dd></div>
                        </dl>
                        <dl class="settlement-event-fields settlement-event-money settlement-event-usd" aria-label="美元信息">
                            <div><dt>{{ $event['usd_label'] ?? '美元金额' }}</dt><dd @class(['settlement-event-usd-value', 'is-negative' => str_starts_with((string) $event['usd'], '-')])>{{ $amount($event['usd']) }}</dd></div>
                            <div><dt>美元汇率</dt><dd>{{ str_contains($rate, '.') ? rtrim(rtrim($rate, '0'), '.') : ($rate === '' ? '—' : $rate) }}</dd></div>
                            <div><dt>美元来源</dt><dd>{{ $event['source'] }}</dd></div>
                        </dl>
                    </div>
                    <details class="settlement-event-more">
                        <summary>查看全部流水字段（{{ array_sum(array_map('count', $event['fields'])) }} 项）</summary>
                        @foreach($event['fields'] + ($metadata === [] ? [] : ['补充信息' => $metadata]) as $group => $fields)
                            <section class="settlement-event-field-group">
                                <h5>{{ $group }}</h5>
                                <dl>
                                    @foreach($fields as $label => $value)
                                        <div><dt>{{ $label }}</dt><dd>{{ $value }}</dd></div>
                                    @endforeach
                                </dl>
                            </section>
                        @endforeach
                    </details>
                </li>
            @empty
                <li class="settlement-event-empty">暂无关联交易流水</li>
            @endforelse
        </ol>
    </section>
    @if($canManage && count($row['payments']) > 1)
        <section class="settlement-detail-section">
            <h4>付款记录</h4>
            <div class="settlement-detail-payment-list">
            @foreach($row['payments'] as $editable)
                <span>{{ $editable['reference'] }}
                @if($editable['locked'])（已锁定）@else @include('portals.finance.partials.settlement-edit-button') @endif
                </span>
            @endforeach
            </div>
        </section>
    @endif
</div>
