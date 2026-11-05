<div class="payment-balance-card" data-credit-summary="USD">
    <div class="payment-balance-summary">
        <div class="payment-balance-summary-header"><span>{{ __('finance.workbench.credit_summary') }}</span><span class="payment-currency-badge">{{ __('finance.payment_balance.settlement_currency') }}</span></div>
        <div class="payment-balance-metrics">
            @foreach(['withdrawable', 'pending', 'reserve', 'total'] as $field)<div class="payment-balance-metric"><span>{{ __('finance.payment_balance.'.$field) }}</span><strong data-summary="{{ $field }}">{{ \App\Portals\Finance\FinanceCatalog::simplified((string) ($balanceFormatter->format($paymentOverview['summary'][$field]))) }}</strong></div>@endforeach
        </div>
    </div>
    <p class="finance-credit-overview-note">{{ __('finance.workbench.all_credit_accounts') }}</p>
    @if($paymentOverview['missingRates']->isNotEmpty())<p class="payment-balance-rate-warning" role="status">{{ __('finance.payment_balance.missing_rates', ['currencies' => $paymentOverview['missingRates']->implode('、')]) }}</p>@endif
</div>
