<div class="box">

    <div class="box-body">
        <p>平台流水号 {{ $order->reference }}　商户账户 {{ $order->account }}　累计应结算 {{ $order->payload['应结算'] ?? '—' }}</p>
        @if(isset($statement['legacy']) || isset($statement['sha256']))
            <p>该订单含历史汇总结算。重新导入原结算文件后可编辑对应的逐笔明细。</p>
        @endif
        @if($canManage && isset($statement['notice']))
            @include('portals.finance.partials.xuzong-statement-handled', ['noticeOrder' => $order])
        @endif
        @if($canManage)
            <details class="xuzong-statement-section" open>
                <summary>编辑订单</summary>
                <form id="xuzong-order-edit-form" method="post" data-loading="off" action="{{ route('finance.reports.xuzong-credit.statement.update', $order->id) }}">
                    @csrf
                    <input type="hidden" name="action" value="edit_order">
                    <div class="row">
                        @foreach(\App\Portals\Finance\FinanceXuzongOrderImport::EDITABLE_ORDER_FIELDS as $field)
                            @php($value = match ($field) {
                                '商户名称' => $order->payload[$field] ?? $order->account,
                                '平台订单号' => $order->payload['_edited_platform_order_id'] ?? (($order->payload['_platform_missing'] ?? false) ? ($order->payload['_linked_platform_order_id'] ?? '') : $order->reference),
                                '创建时间' => $order->occurred_at?->utc()->toDateTimeString(),
                                '交易币种' => $order->currency,
                                '支付状态' => $order->status,
                                default => $order->payload[$field] ?? '',
                            })
                            <label class="col-sm-4">{{ match($field) {
                                '交易流水号' => '交易 ID', '交易币种' => '货币', '交易金额' => '总额',
                                '支付状态' => '状态', '创建时间', '完成时间' => $field.'（UTC +0）',
                                default => $field,
                            } }}
                                <input class="form-control" name="order_fields[{{ $field }}]" value="{{ $value }}">
                            </label>
                        @endforeach
                        @foreach(\App\Portals\Finance\FinanceXuzongCreditReport::additionalEditableHeaders() as $field)
                            <label class="col-sm-4">{{ $field }}
                                <input class="form-control" name="report_fields[{{ $field }}]" value="{{ $reportValues[$field] ?? '' }}">
                            </label>
                        @endforeach
                    </div>
                    @unless(request()->ajax())<button class="btn btn-primary btn-sm" type="submit">保存订单</button>@endunless
                </form>
            </details>
        @endif
        @php($events = $statement['events'] ?? [])
        @forelse($events as $key => $event)
            <details class="xuzong-statement-section">
                <summary>{{ $event['time'] }}　应结算 {{ $event['amounts']['应结算'] }} USD　{{ $canManage ? '编辑' : '查看字段' }}</summary>
                @include('portals.finance.partials.xuzong-statement-event', ['editing' => true])
            </details>
        @empty
            <p>暂无逐笔结算明细。</p>
        @endforelse
        @if($canManage)
            <details class="xuzong-statement-section">
                <summary>新增结算明细</summary>
                @include('portals.finance.partials.xuzong-statement-event', ['editing' => false, 'event' => [], 'key' => ''])
            </details>
        @endif
    </div>
</div>
