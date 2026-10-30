@php
    $report = $report ?? [];
    $money = static fn ($value) => '$'.number_format((float) $value, 2);
    $rate = static fn ($value) => number_format((float) $value, 2).'%';
    $reasonLabel = static function (array $row): string {
        if (($row['is_other'] ?? false) || ($row['reason'] ?? '') === '__other__') return __('payment.product_risk_profit.refund_report.other');
        if (($row['reason'] ?? '') === '__empty__') return __('payment.product_risk_profit.refund_report.empty_reason');
        return (string) ($row['reason'] ?? '-');
    };
@endphp
<section class="payment-refund-reason-report is-collapsed" data-payment-refund-report-panel>
    <header><div><h3><i class="fa fa-pie-chart"></i> {{ __('payment.product_risk_profit.refund_report.title') }}</h3><p>{{ __('payment.product_risk_profit.refund_report.hint') }}</p></div><div class="payment-refund-report-actions"><div class="payment-refund-report-total">@if(array_key_exists('order_count', $report))<span>{{ __('payment.product_risk_profit.refund_report.orders', ['count' => number_format((int)$report['order_count'])]) }}</span>@endif<span>{{ __('payment.product_risk_profit.refund_report.records', ['count' => number_format((int)($report['record_count'] ?? 0))]) }}</span><strong>{{ $money($report['amount'] ?? 0) }}</strong></div><button type="button" class="btn btn-default btn-sm" data-payment-refund-report-toggle data-expand-text="{{ __('payment.product_risk_profit.refund_report.expand') }}" data-collapse-text="{{ __('payment.product_risk_profit.refund_report.collapse') }}"><i class="fa fa-angle-down"></i> <span>{{ __('payment.product_risk_profit.refund_report.expand') }}</span></button></div></header>
    <div class="payment-refund-reason-columns">
        @foreach(['source_reason' => 'source', 'manual_reason' => 'manual'] as $key => $title)
            <article><h4>{{ __('payment.product_risk_profit.refund_report.'.$title) }}</h4>
                @forelse(($report[$key] ?? []) as $row)
                    <div class="payment-refund-reason-row"><div><strong title="{{ $reasonLabel($row) }}">{{ $reasonLabel($row) }}</strong><small>{{ number_format((int)$row['record_count']) }}{{ __('payment.product_risk_profit.record_unit') }} · {{ $money($row['amount']) }}</small></div><span>{{ $rate($row['count_ratio']) }}<small>{{ __('payment.product_risk_profit.refund_report.amount_ratio') }} {{ $rate($row['amount_ratio']) }}</small></span></div>
                @empty <p class="payment-refund-report-empty">{{ __('payment.product_risk_profit.refund_report.empty') }}</p>
                @endforelse
            </article>
        @endforeach
    </div>
</section>
