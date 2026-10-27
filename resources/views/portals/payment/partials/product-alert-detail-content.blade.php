@php
    $translationPrefix = 'payment.product_alerts';
    $detailPrefix = $translationPrefix.'.detail_page';
@endphp
<div class="payment-alert-detail-content">
    <header class="payment-alert-detail-header"><div><h1>{{ __($detailPrefix.'.title_without_code', ['product' => $alert['product_name'], 'sku' => $alert['sku']]) }}</h1><p>{{ __($detailPrefix.'.subtitle') }}</p></div><div class="payment-alert-header-actions"><span class="payment-table-status payment-table-status-{{ $alert['status_key'] }}">{{ __($translationPrefix.'.statuses.'.$alert['status_key']) }}</span>@if($alert['status_key'] === 'completed')<button class="btn btn-default" type="button" data-payment-alert-status data-action="{{ route('payment.product-alerts.status.update', ['alert' => $alert['code']]) }}" data-operation="reopen" data-product="{{ $alert['product_name'] }}">{{ __($translationPrefix.'.operations.reopen') }}</button>@else<button class="btn btn-default" type="button" data-payment-alert-status data-action="{{ route('payment.product-alerts.status.update', ['alert' => $alert['code']]) }}" data-operation="process" data-product="{{ $alert['product_name'] }}">{{ __($translationPrefix.'.operations.process') }}</button><button class="btn btn-primary" type="button" data-payment-alert-status data-action="{{ route('payment.product-alerts.status.update', ['alert' => $alert['code']]) }}" data-operation="complete" data-product="{{ $alert['product_name'] }}">{{ __($translationPrefix.'.operations.complete') }}</button>@endif</div></header>
    <nav class="payment-tabs payment-alert-detail-tabs" role="tablist">
        @foreach (['overview', 'payments', 'events', 'logs'] as $tab)
            <button type="button" class="{{ $loop->first ? 'active' : '' }}" data-payment-tab="alert-{{ $tab }}" aria-selected="{{ $loop->first ? 'true' : 'false' }}">{{ __($detailPrefix.'.tabs.'.$tab) }}</button>
        @endforeach
    </nav>

    <section data-payment-tab-panel="alert-overview">
        <div class="payment-alert-detail-top"><article class="payment-panel payment-alert-evidence"><h2>{{ __($detailPrefix.'.evidence_title') }}</h2><div class="payment-alert-evidence-grid">
            <div><span>{{ __($detailPrefix.'.fields.channel') }}</span><strong>{{ __($translationPrefix.'.channels.'.$alert['channel_key']) }}</strong><small>{{ __($detailPrefix.'.notes.channel') }}</small></div>
            <div><span>{{ __($detailPrefix.'.fields.sample') }}</span><strong>{{ number_format($alert['eligible_order_count']) }} / {{ number_format($alert['dispute_order_count']) }}</strong><small>{{ __($detailPrefix.'.notes.sample') }}</small></div>
            <div><span>{{ __($detailPrefix.'.fields.threshold') }} <i class="fa fa-info-circle payment-formula-icon" tabindex="0" data-payment-formula-tooltip data-toggle="tooltip" data-container="body" title="{{ __($translationPrefix.'.formulas.'.$alert['threshold_key']) }}"></i></span><strong>{{ number_format($alert['dispute_rate'], 2) }}%</strong><small>{{ __($detailPrefix.'.notes.threshold_fixed', ['threshold' => number_format($alert['threshold'], 2)]) }}</small></div>
            <div><span>{{ __($detailPrefix.'.fields.recent') }}</span><strong>${{ number_format($alert['received_amount'], 2) }} / ${{ number_format($alert['dispute_amount'], 2) }}</strong><small>{{ __($detailPrefix.'.notes.recent_real') }}</small></div>
        </div></article><article class="payment-panel payment-alert-responsibility"><h2>{{ __($detailPrefix.'.processing_title') }}</h2><div><span>{{ __($detailPrefix.'.fields.status') }}</span><strong>{{ __($translationPrefix.'.statuses.'.$alert['status_key']) }}</strong></div><p>{{ __($detailPrefix.'.processing_note') }}</p></article></div>
    </section>

    <section class="payment-panel payment-alert-tab-panel" data-payment-tab-panel="alert-payments" hidden><h2>{{ __($detailPrefix.'.payments.title') }}</h2><p>{{ __($detailPrefix.'.payments.description') }}</p><div class="table-responsive"><table class="table payment-detail-table"><thead><tr>@foreach(['order','store','transaction','amount','payment_status','risk','time'] as $column)<th>{{ __($detailPrefix.'.payments.columns.'.$column) }}</th>@endforeach</tr></thead><tbody>
        @forelse($alert['payments'] as $row)<tr><td>{{ $row['order'] }}</td><td>{{ $row['store'] }}</td><td>{{ $row['transaction_id'] }}</td><td>${{ number_format($row['amount'], 2) }}</td><td>{{ $row['payment_status'] }}</td><td>{{ __($detailPrefix.'.payments.risk_summary', ['count' => $row['dispute_count'], 'status' => $row['dispute_statuses'], 'reason' => $row['dispute_reasons']]) }}</td><td>{{ $row['time'] }}</td></tr>@empty<tr><td colspan="7" class="text-center">{{ __($detailPrefix.'.empty') }}</td></tr>@endforelse
    </tbody></table></div></section>

    <section class="payment-panel payment-alert-tab-panel" data-payment-tab-panel="alert-events" hidden><h2>{{ __($detailPrefix.'.events.title') }}</h2><p>{{ __($detailPrefix.'.events.description') }}</p><div class="table-responsive"><table class="table payment-detail-table"><thead><tr>@foreach(['type','reference','related_reference','status','amount','reason','time'] as $column)<th>{{ __($detailPrefix.'.events.columns.'.$column) }}</th>@endforeach</tr></thead><tbody>
        @forelse($alert['payment_events'] as $row)<tr><td>{{ __($detailPrefix.'.events.types.'.$row['type']) }}</td><td>{{ $row['reference'] }}</td><td>{{ $row['related_reference'] }}</td><td>{{ $row['status'] }}</td><td>${{ number_format($row['amount'], 2) }}</td><td>{{ $row['reason'] }}</td><td>{{ $row['time'] }}</td></tr>@empty<tr><td colspan="7" class="text-center">{{ __($detailPrefix.'.empty') }}</td></tr>@endforelse
    </tbody></table></div></section>

    <section class="payment-panel payment-alert-tab-panel" data-payment-tab-panel="alert-logs" hidden><h2>{{ __($detailPrefix.'.logs.title') }}</h2><p>{{ __($detailPrefix.'.logs.description') }}</p><div class="payment-alert-log-list">
        @forelse($alert['processing_logs'] as $log)<article><div><strong>{{ __($translationPrefix.'.operations.'.$log['operation']) }} <span>{{ __($translationPrefix.'.statuses.'.$log['from_status']) }} <i class="fa fa-long-arrow-right"></i> {{ __($translationPrefix.'.statuses.'.$log['to_status']) }}</span></strong><time>{{ $log['time'] }}</time></div><p>{{ $log['remark'] }}</p><small>{{ __($detailPrefix.'.logs.operator', ['name' => $log['actor']]) }}</small></article>@empty<p class="payment-empty-filter">{{ __($detailPrefix.'.logs.empty') }}</p>@endforelse
    </div></section>
</div>
