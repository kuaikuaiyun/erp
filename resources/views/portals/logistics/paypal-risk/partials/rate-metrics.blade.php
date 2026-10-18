@php
    /** @var array<string,mixed> $metrics */
    /** @var 'current'|'history' $scope */
    $isHistory = $scope === 'history';
    $rateMetrics = [
        [
            'class' => 'refund',
            'label' => __('logistics.paypal_risk.ui.'.($isHistory ? 'history_refund_count_rate' : 'filtered_refund_count_rate')),
            'formula' => __('logistics.paypal_risk.ui.refund_count_rate_formula'),
            'value' => (float) $metrics['refund_rate'],
            'summary' => __('logistics.paypal_risk.ui.refund_count_rate_summary', ['count' => number_format($metrics['refund_order_count'])]),
            'scale' => 5,
        ],
        [
            'class' => 'refund',
            'label' => __('logistics.paypal_risk.ui.'.($isHistory ? 'history_refund_amount_rate' : 'filtered_refund_amount_rate')),
            'formula' => __('logistics.paypal_risk.ui.refund_amount_rate_formula'),
            'value' => (float) $metrics['refund_amount_rate'],
            'summary' => __('logistics.paypal_risk.ui.refund_amount_rate_summary', ['amount' => number_format($metrics['refund_amount'], 2)]),
            'scale' => 5,
        ],
        [
            'class' => 'dispute',
            'label' => __('logistics.paypal_risk.ui.'.($isHistory ? 'history_risk_dispute_count_rate' : 'filtered_risk_dispute_count_rate')),
            'formula' => __('logistics.paypal_risk.ui.risk_dispute_count_rate_formula'),
            'value' => (float) $metrics['dispute_rate'],
            'summary' => __('logistics.paypal_risk.ui.risk_dispute_count_rate_summary', ['count' => number_format($metrics['dispute_order_count'])]),
            'scale' => 10,
        ],
        [
            'class' => 'dispute',
            'label' => __('logistics.paypal_risk.ui.'.($isHistory ? 'history_risk_dispute_amount_rate' : 'filtered_risk_dispute_amount_rate')),
            'formula' => __('logistics.paypal_risk.ui.risk_dispute_amount_rate_formula'),
            'value' => (float) $metrics['dispute_amount_rate'],
            'summary' => __('logistics.paypal_risk.ui.risk_dispute_amount_rate_summary', ['amount' => number_format($metrics['dispute_amount'], 2)]),
            'scale' => 10,
        ],
        [
            'class' => 'overall-dispute',
            'label' => __('logistics.paypal_risk.ui.'.($isHistory ? 'history_overall_dispute_count_rate' : 'filtered_overall_dispute_count_rate')),
            'formula' => __('logistics.paypal_risk.ui.overall_dispute_count_rate_formula'),
            'value' => (float) $metrics['overall_dispute_rate'],
            'summary' => __('logistics.paypal_risk.ui.overall_dispute_count_rate_summary', ['count' => number_format($metrics['overall_dispute_order_count'])]),
            'scale' => 10,
        ],
    ];
@endphp

@foreach($rateMetrics as $rateMetric)
    <div class="paypal-risk-rate-block {{ $rateMetric['class'] }}">
        <div>
            <span class="paypal-risk-rate-title">
                <span>{{ $rateMetric['label'] }}</span>
                <span class="paypal-risk-rate-formula" title="{{ $rateMetric['formula'] }}"><i class="fa fa-info-circle"></i> {{ __('logistics.paypal_risk.ui.calculation_formula') }}</span>
            </span>
            <strong>{{ number_format($rateMetric['value'], 2) }}%</strong>
        </div>
        <div class="paypal-risk-progress"><i style="width: {{ min(100, $rateMetric['value'] * $rateMetric['scale']) }}%"></i></div>
        <div class="paypal-risk-rate-note"><span>{{ $rateMetric['summary'] }}</span></div>
    </div>
@endforeach
