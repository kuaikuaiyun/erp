            @if($card['display']['has_balance'])
            @php($row = $card['display'])
            <div class="paypal-account-values">
                <div class="paypal-account-currency"><span class="paypal-currency">{{ \App\Portals\Finance\FinanceCatalog::simplified((string) ($row['currency'])) }}</span></div>
                <div class="paypal-account-metrics">
                    <div><span>{{ __('finance.fields.available') }}</span><strong @class(['paypal-value-empty' => $row['available'] === null])>{{ \App\Portals\Finance\FinanceCatalog::simplified((string) ($balanceFormatter->format($row['available']))) }}</strong></div>
                    <div><span>{{ __('finance.fields.withheld') }}</span><strong @class(['paypal-value-empty' => $row['withheld'] === null])>{{ \App\Portals\Finance\FinanceCatalog::simplified((string) ($balanceFormatter->format($row['withheld']))) }}</strong></div>
                </div>
                <div class="paypal-account-total"><span>{{ __('finance.paypal.account_total') }}</span><strong>{{ $balanceFormatter->format($row['total']) }} {{ $row['currency'] }}</strong></div>
            </div>
            <p class="paypal-account-footer">
                {{ __('finance.paypal.timestamp') }}
                @if($card['as_of'])
                    {{ $card['as_of']->setTimezone('Asia/Shanghai')->format('Y-m-d H:i') }}
                    @if($card['latest_as_of'] && !$card['latest_as_of']->equalTo($card['as_of']))
                        ~ {{ $card['latest_as_of']->setTimezone('Asia/Shanghai')->format('Y-m-d H:i') }}
                    @endif
                @else
                    {{ __('finance.paypal.time_unknown') }}
                @endif
            </p>
            @else
            <div class="paypal-account-values"><div class="paypal-account-currency"><span>{{ __($card['currencies']->isEmpty() ? 'finance.paypal.no_data' : 'finance.paypal.missing_currency', ['currency' => $card['display']['currency']]) }}</span><span class="paypal-currency">{{ \App\Portals\Finance\FinanceCatalog::simplified((string) ($card['display']['currency'])) }}</span></div><div class="paypal-account-metrics"><div><span>{{ __('finance.fields.available') }}</span><strong class="paypal-value-empty">—</strong></div><div><span>{{ __('finance.fields.withheld') }}</span><strong class="paypal-value-empty">—</strong></div></div><div class="paypal-account-total"><span>{{ __('finance.paypal.account_total') }}</span><strong>—</strong></div></div>
            <p class="paypal-account-footer">@if($card['as_of']){{ __('finance.paypal.timestamp') }} {{ $card['as_of']->setTimezone('Asia/Shanghai')->format('Y-m-d H:i') }}@else{{ __('finance.paypal.not_collected') }}@endif</p>
            @endif
            <p class="paypal-account-footer" data-balance-fetched-at title="本系统成功调用接口并保存这份余额的时间（北京时间）">
                {{ __('finance.paypal.fetched_at') }}
                {{ $card['fetched_at'] ? $card['fetched_at']->setTimezone('Asia/Shanghai')->format('Y-m-d H:i:s') : '尚未获取' }}
            </p>
