@push('styles')
<style>#paypal-exchange-rates .modal-body{max-height:calc(100vh - 190px);overflow-y:auto}</style>
@endpush
<div class="modal fade" id="paypal-exchange-rates" tabindex="-1" role="dialog" aria-labelledby="paypal-exchange-rates-title">
    <div class="modal-dialog" role="document"><div class="modal-content">
        <form method="post" action="{{ route('finance.exchange-rates.save') }}" id="paypal-exchange-rates-form">@csrf
            <input type="hidden" name="balance_tab" value="{{ $activeBalanceTab }}" data-balance-tab-input>
            <input type="hidden" name="paypal_currency" value="{{ $paypalBalance['summary']['currency'] }}">
            @if(request('balance_account'))<input type="hidden" name="balance_account" value="{{ request('balance_account') }}">@endif
            <div class="modal-header"><button type="button" class="close" data-dismiss="modal" aria-label="{{ __('finance.cancel') }}">&times;</button><h4 class="modal-title" id="paypal-exchange-rates-title">{{ __('finance.exchange.edit') }}</h4></div>
            <div class="modal-body">
                <p class="paypal-rate-form-note">{{ __('finance.exchange.help') }}</p>
                @if($paypalBalance['exchangeRates']['updated_at'])<p class="paypal-rate-form-note">{{ __('finance.exchange.updated') }} {{ \Carbon\CarbonImmutable::parse($paypalBalance['exchangeRates']['updated_at'], 'UTC')->setTimezone('Asia/Shanghai')->format('Y-m-d H:i') }}</p>@endif
                @if($errors->exchangeRates->any())<div class="alert alert-danger paypal-rate-errors" role="alert"><ul>@foreach($errors->exchangeRates->all() as $error)<li>{{ \App\Portals\Finance\FinanceCatalog::simplified((string) ($error)) }}</li>@endforeach</ul></div>@endif
                <table class="paypal-rate-table"><thead><tr><th>{{ __('finance.fields.currency') }}</th><th>{{ __('finance.exchange.rate_heading') }}</th></tr></thead><tbody id="paypal-rate-rows">
                    @php
                        $defaultRows = $paypalBalance['exchangeRates']['currencies']->map(fn ($currency) => ['currency' => $currency, 'units_per_usd' => $paypalBalance['exchangeRates']['rates']->get($currency)]);
                        $oldRows = old('rates');
                        $rateRows = is_array($oldRows) ? collect($oldRows)->filter(fn ($row) => is_array($row))->map(fn ($row) => [
                            'currency' => is_string($row['currency'] ?? null) ? $row['currency'] : '',
                            'units_per_usd' => is_scalar($row['units_per_usd'] ?? null) ? $row['units_per_usd'] : '',
                        ])->values() : $defaultRows;
                        if ($rateRows->isEmpty()) { $rateRows = $defaultRows; }
                    @endphp
                    @foreach($rateRows as $index => $rateRow)
                    <tr><td><input class="form-control" name="rates[{{ $index }}][currency]" aria-label="{{ __('finance.fields.currency') }}" pattern="[A-Z]{3}" maxlength="3" required value="{{ $rateRow['currency'] ?? '' }}" @readonly($paypalBalance['exchangeRates']['currencies']->contains($rateRow['currency'] ?? ''))></td><td><input class="form-control" name="rates[{{ $index }}][units_per_usd]" aria-label="{{ __('finance.exchange.rate_for', ['currency' => $rateRow['currency'] ?? '']) }}" type="number" step="0.0000000001" min="0.0000000001" value="{{ ($rateRow['currency'] ?? '') === 'USD' ? '1' : ($rateRow['units_per_usd'] ?? '') }}" @readonly(($rateRow['currency'] ?? '') === 'USD') placeholder="{{ __('finance.exchange.unset') }}"></td></tr>
                    @endforeach
                </tbody></table>
                <button class="btn btn-default btn-sm paypal-rate-add" type="button" id="paypal-rate-add"><i class="fa fa-plus" aria-hidden="true"></i> {{ __('finance.exchange.add') }}</button>
                <template id="paypal-rate-row-template"><tr><td><input class="form-control" data-rate-currency aria-label="{{ __('finance.fields.currency') }}" pattern="[A-Z]{3}" maxlength="3" required placeholder="CNY" style="text-transform:uppercase"></td><td><input class="form-control" data-rate-value aria-label="{{ __('finance.exchange.rate') }}" type="number" step="0.0000000001" min="0.0000000001" placeholder="{{ __('finance.exchange.unset') }}"></td></tr></template>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-default" data-dismiss="modal">{{ __('finance.cancel') }}</button><button type="submit" class="btn btn-primary">{{ __('finance.save') }}</button></div>
        </form>
    </div></div>
</div>
@push('scripts')
<script>
(function(){
    var rows=document.getElementById('paypal-rate-rows'),button=document.getElementById('paypal-rate-add');
    button.addEventListener('click',function(){
        if(rows.children.length>=100)return;
        var index=rows.children.length,fragment=document.getElementById('paypal-rate-row-template').content.cloneNode(true),currency=fragment.querySelector('[data-rate-currency]'),value=fragment.querySelector('[data-rate-value]');
        currency.name='rates['+index+'][currency]';value.name='rates['+index+'][units_per_usd]';
        currency.addEventListener('input',function(){currency.value=currency.value.toUpperCase();});rows.appendChild(fragment);currency.focus();
    });
    @if($errors->exchangeRates->any())$('#paypal-exchange-rates').modal('show');@endif
})();
</script>
@endpush
