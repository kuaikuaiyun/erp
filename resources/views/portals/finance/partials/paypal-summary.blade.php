<div class="paypal-summary" data-paypal-summary="{{ $paypalBalance['summary']['currency'] }}">
    <div class="paypal-summary-heading"><span>{{ __('finance.paypal.summary') }}</span><div class="paypal-summary-controls">
        <form method="get" action="{{ route('finance.home') }}">
            <input type="hidden" name="balance_tab" value="{{ $activeBalanceTab }}" data-balance-tab-input>
            @if(request('balance_account'))<input type="hidden" name="balance_account" value="{{ request('balance_account') }}">@endif
            <select name="paypal_currency" aria-label="{{ __('finance.paypal.currency_filter') }}" onchange="this.form.submit()" @disabled($paypalBalance['availableCurrencies']->isEmpty())>
                @forelse($paypalBalance['availableCurrencies'] as $currency)<option value="{{ $currency }}" @selected($currency === $paypalBalance['summary']['currency'])>{{ \App\Portals\Finance\FinanceCatalog::simplified((string) ($currency)) }}</option>@empty<option value="">{{ __('finance.paypal.no_currencies') }}</option>@endforelse
            </select>
        </form>
    </div></div>
    <div class="paypal-summary-metrics">
        @foreach(['available' => 'available_total', 'withheld' => 'withheld_total', 'total' => 'grand_total'] as $field => $label)<div class="paypal-summary-metric"><span>{{ __('finance.paypal.'.$label) }}</span><strong data-paypal-total="{{ $field }}">{{ \App\Portals\Finance\FinanceCatalog::simplified((string) ($balanceFormatter->format($paypalBalance['summary'][$field]))) }}</strong></div>@endforeach
    </div>
    @if(!$paypalBalance['summary']['has_balance'])<p class="paypal-summary-message">{{ __($paypalBalance['availableCurrencies']->isEmpty() ? 'finance.paypal.empty_summary' : 'finance.paypal.no_enabled_currency', ['currency' => $paypalBalance['summary']['currency']]) }}</p>@endif
    @if($paypalBalance['monthlyCny'])<p class="paypal-summary-message">按 {{ $paypalBalance['monthlyCny']['month'] }} 月度固定汇率折合人民币：{{ $paypalBalance['monthlyCny']['total'] === null ? '待配置' : $balanceFormatter->format($paypalBalance['monthlyCny']['total']).' CNY' }}</p>@endif
</div>
