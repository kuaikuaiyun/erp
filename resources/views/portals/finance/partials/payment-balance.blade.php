@push('styles')
<style>
.payment-balance-section{padding:8px 0 12px;color:#17324d}.payment-balance-heading{display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap;margin-bottom:16px}.payment-balance-heading h2{font-size:20px;font-weight:600;margin:0 0 5px}.payment-balance-heading p{font-size:12px;color:#74869a;margin:0}.payment-balance-controls{display:flex;align-items:center;gap:10px;flex-wrap:wrap}.payment-balance-controls select{min-width:150px;border-radius:6px}.payment-balance-update{border-radius:6px;border:1px solid #d7e3ef;background:#fff;color:#4f46e5;padding:7px 12px}.payment-balance-card{border:1px solid #dfe6ee;border-radius:12px;background:#fff;box-shadow:0 2px 3px rgba(22,49,76,.06);overflow:hidden}.payment-balance-summary{padding:20px 22px 18px}.payment-balance-summary-header{display:flex;align-items:center;justify-content:space-between;gap:12px;font-size:13px;margin-bottom:16px}.payment-currency-badge{display:inline-block;padding:2px 9px;border-radius:20px;background:#eeecff;color:#7163ec;font-size:11px;font-weight:400;white-space:nowrap}.payment-balance-metrics{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:20px}.payment-balance-metric span{display:block;color:#698096;font-size:13px;margin-bottom:5px}.payment-balance-metric strong{font-size:24px;letter-spacing:.1px;font-variant-numeric:tabular-nums;color:#102d48;line-height:1.4}.payment-balance-table-wrap{overflow-x:auto}.payment-balance-table{width:100%;min-width:700px;border-collapse:collapse;font-size:13px;table-layout:fixed}.payment-balance-table th{background:#f7faff;border-top:1px solid #e4ebf3;color:#708399;font-size:12px;font-weight:400;padding:13px 16px;white-space:nowrap}.payment-balance-table td{border-top:1px solid #edf1f6;padding:16px;font-variant-numeric:tabular-nums;vertical-align:middle}.payment-balance-table th:first-child,.payment-balance-table td:first-child{width:22%;padding-left:22px;text-align:left}.payment-balance-table th,.payment-balance-table td{text-align:right}.payment-balance-table th:last-child,.payment-balance-table td:last-child{width:13%;text-align:left}.payment-balance-table .payment-currency-badge{margin-left:6px}.payment-balance-table .payment-row-total{font-weight:600;font-size:15px}.payment-balance-link{padding:0;border:0;background:none;color:#7063ef;font-size:12px;white-space:nowrap}.payment-balance-table tr[hidden]{display:none!important}.payment-balance-grand-total td{background:#f8faff;font-weight:600}.payment-balance-grand-total td:last-child{font-size:16px;text-align:right;padding-right:22px}.payment-balance-more{width:100%;padding:14px;border:0;border-top:1px solid #e7edf4;background:#fff;color:#7063ef;font-size:12px}.payment-balance-more .fa{margin-left:6px}.payment-balance-hint{font-size:12px;line-height:1.8;margin:12px 2px 0;color:#75879a}.payment-balance-rate-warning{margin:0;padding:10px 22px;font-size:12px;background:#fff8eb;color:#9c6b22;border-top:1px solid #f4e9d3}.payment-balance-empty{text-align:center!important;color:#8393a4;padding:28px 16px!important;line-height:1.8}.payment-balance-empty strong{display:block;font-weight:400;color:#62758a}.payment-balance-detail-cell{padding:0 18px 16px!important;background:#fafbfe;text-align:left!important}.payment-balance-detail-table{width:100%;font-size:12px}.payment-balance-detail-table th{background:none}.payment-balance-detail-table th,.payment-balance-detail-table td{font-size:12px!important;padding:10px!important;width:auto!important;text-align:right!important;white-space:nowrap}.payment-balance-detail-table th:first-child,.payment-balance-detail-table td:first-child{text-align:left!important}.payment-balance-form-grid{display:grid;grid-template-columns:1fr 1fr;gap:0 18px}.payment-balance-form-grid .form-group{min-width:0}.payment-balance-form-grid .form-control{border-radius:5px}.payment-balance-form-note{font-size:12px;line-height:1.8;color:#75879a;margin-bottom:18px}@media(max-width:767px){.payment-balance-heading h2{font-size:18px}.payment-balance-metrics{grid-template-columns:repeat(2,minmax(0,1fr));gap:18px}.payment-balance-metric strong{font-size:22px}.payment-balance-summary{padding:18px}.payment-balance-controls{width:100%}.payment-balance-controls form{flex:1}.payment-balance-controls select{width:100%}.payment-balance-form-grid{grid-template-columns:1fr}}
</style>
@endpush

<section class="payment-balance-section" id="payment2-balances" aria-labelledby="payment-balance-title">
    <div class="payment-balance-heading">
        <div><h2 id="payment-balance-title">{{ __('finance.balances.xuzong2') }}</h2><p>{{ __('finance.payment_balance.title') }}@if($paymentBalance['as_of']) · {{ __('finance.payment_balance.as_of') }} @if(!$paymentBalance['oldest_as_of']->equalTo($paymentBalance['as_of'])){{ $paymentBalance['oldest_as_of']->setTimezone('Asia/Shanghai')->format('Y-m-d H:i') }} ~ @endif{{ $paymentBalance['as_of']->setTimezone('Asia/Shanghai')->format('Y-m-d H:i') }} · {{ __('finance.payment_balance.source') }}@endif</p></div>
        <div class="payment-balance-controls">
            @if($canManage)@include('portals.finance.partials.sync-log-button')@endif
            <form method="get" action="{{ route('finance.home') }}"><input type="hidden" name="balance_tab" value="credit-card" data-balance-tab-input><input type="hidden" name="paypal_currency" value="{{ $paypalBalance['summary']['currency'] }}"><select class="form-control" name="balance_account" aria-label="{{ __('finance.payment_balance.account') }}" onchange="this.form.submit()">
                <option value="">{{ __('finance.payment_balance.all_accounts') }}</option>
                @foreach($paymentBalance['accounts'] as $accountName)<option value="{{ $accountName }}" @selected($paymentBalance['account'] === $accountName)>{{ \App\Portals\Finance\FinanceCatalog::simplified((string) ($accountName)) }}</option>@endforeach
            </select></form>
            @if($canManage)<button type="button" class="btn btn-primary btn-sm" data-add-payment-account="credit_card"><i class="fa fa-plus" aria-hidden="true"></i> {{ __('finance.payment_accounts.add') }}</button><button type="button" class="payment-balance-update" id="payment-balance-add" data-toggle="modal" data-target="#payment-balance-modal"><i class="fa fa-pencil-square-o" aria-hidden="true"></i> {{ __('finance.payment_balance.update') }}</button>@endif
        </div>
    </div>
    @if($creditAccounts->isNotEmpty())
    @php
        $settlementAuthorization = app(\App\Portal\Services\PortalMenuAuthorizationService::class);
        $settlementMenu = $settlementAuthorization->menuForRequest($portal, '/finance/reports/credit-settlement');
        $canViewCreditSettlement = $settlementMenu && $settlementAuthorization->canAccess($user, $settlementMenu);
    @endphp
    <div class="box finance-credit-accounts"><div class="box-header with-border"><h3 class="box-title">{{ __('finance.payment_accounts.credit_accounts') }}</h3></div><div class="box-body">
        <p class="finance-note">{{ __('finance.payment_accounts.credit_unavailable') }}</p>
        <div class="finance-table-wrap"><table class="table finance-table"><thead><tr><th>{{ __('finance.payment_accounts.attributes.account_name') }}</th><th>支付平台</th>@foreach(['account_code', 'main_currency', 'status', 'remark'] as $field)<th>{{ __('finance.payment_accounts.attributes.'.$field) }}</th>@endforeach @if($canManage || $canViewCreditSettlement)<th>{{ __('finance.fields.actions') }}</th>@endif</tr></thead><tbody>
            @foreach($creditAccounts->groupBy(fn ($account) => $account->card_group_id ?? 0) as $groupId => $groupAccounts)
                <tr class="active"><th scope="rowgroup" colspan="{{ $canManage || $canViewCreditSettlement ? 7 : 6 }}">
                    {{ $cardGroups->get($groupId, '未分组账户') }}
                    @if($groupId && $canManage)
                        <button type="button" class="btn btn-default btn-xs" data-add-payment-account="credit_card" data-credit-group="{{ $cardGroups->get($groupId) }}">添加子账户</button>
                    @endif
                </th></tr>
            @foreach($groupAccounts as $creditAccount)
                @php($creditPlatform = \App\Portals\Finance\FinanceCreditSettlementLayout::accountPlatform($creditAccount))
                <tr>
                    <td>{{ \App\Portals\Finance\FinanceCatalog::simplified($creditAccount->account_name) }}@if($creditAccount->card_merchant_key)<br><small>{{ $creditAccount->card_merchant_key }}</small>@endif</td>
                    <td>{{ \App\Portals\Finance\FinanceCreditSettlementLayout::CHANNELS[$creditAccount->card_platform] ?? '未设置' }}</td>
                    <td>{{ $creditAccount->account_code }}</td><td>{{ $creditAccount->main_currency }}</td>
                    <td>{{ __('finance.payment_accounts.'.$creditAccount->status) }}</td>
                    <td>{{ \App\Portals\Finance\FinanceCatalog::simplified($creditAccount->remark) }}</td>
                    @if($canManage || $canViewCreditSettlement)
                    <td>
                        @if($canViewCreditSettlement)<a class="btn btn-default btn-sm" href="{{ route('finance.report', ['report' => 'credit-settlement', 'card_channel' => $creditPlatform, 'card_group' => $creditAccount->card_group_id, 'account' => $creditAccount->activity_account]) }}">结算明细</a>@endif
                        @if($canManage)
                        <button type="button" class="btn btn-default btn-sm" data-edit-payment-account="finance-{{ $creditAccount->id }}">{{ __('finance.settings.edit') }}</button>
                        <form method="post" action="{{ route('finance.payment-accounts.delete', 'finance-'.$creditAccount->id) }}" data-loading="off" data-delete-account style="display:inline">@csrf @method('DELETE')<button type="submit" class="btn btn-default btn-sm">{{ __('finance.payment_accounts.delete') }}</button></form>
                        @endif
                    </td>
                    @endif
                </tr>
            @endforeach
            @endforeach
        </tbody></table></div>
    </div></div>
    @endif
    <div class="payment-balance-card">
        @if($paymentBalance['missingRates']->isNotEmpty())<p class="payment-balance-rate-warning" role="status">{{ __('finance.payment_balance.missing_rates', ['currencies' => $paymentBalance['missingRates']->implode('、')]) }}</p>@endif
        <div class="payment-balance-table-wrap"><table class="payment-balance-table" aria-label="{{ __('finance.payment_balance.title') }}">
            <thead><tr>@foreach(['currency', 'withdrawable', 'pending', 'reserve', 'total', 'actions'] as $field)<th scope="col">{{ __('finance.payment_balance.'.$field) }}</th>@endforeach</tr></thead>
            <tbody>
            @forelse($paymentBalance['rows'] as $index => $row)
                <tr data-currency-row="{{ $index }}" @if($index > 0) data-extra-currency hidden @endif>
                    <td>{{ $row['currency'] }}@if($row['currency'] === 'USD')<span class="payment-currency-badge">{{ __('finance.payment_balance.settlement_account') }}</span>@endif</td>
                    @foreach(['withdrawable', 'pending', 'reserve', 'total'] as $field)<td @class(['payment-row-total' => $field === 'total'])>{{ \App\Portals\Finance\FinanceCatalog::simplified((string) ($balanceFormatter->format($row[$field]))) }}</td>@endforeach
                    <td><button type="button" class="payment-balance-link" data-balance-detail="{{ $index }}" aria-expanded="false" aria-controls="payment-detail-{{ $index }}">{{ __('finance.payment_balance.details') }}</button></td>
                </tr>
                <tr id="payment-detail-{{ $index }}" data-detail-row="{{ $index }}" hidden><td colspan="6" class="payment-balance-detail-cell"><table class="payment-balance-detail-table"><thead><tr>
                    @foreach(['account', 'withdrawable', 'pending', 'reserve', 'total', 'usd_rate', 'as_of'] as $field)<th>{{ __('finance.payment_balance.'.$field) }}</th>@endforeach @if($canManage)<th>{{ __('finance.payment_balance.actions') }}</th>@endif
                </tr></thead><tbody>@foreach($row['accounts'] as $entry)<tr>
                    <td>{{ \App\Portals\Finance\FinanceCatalog::simplified((string) ($entry->account)) }}</td><td>{{ \App\Portals\Finance\FinanceCatalog::simplified((string) ($balanceFormatter->format($entry->withdrawable))) }}</td><td>{{ \App\Portals\Finance\FinanceCatalog::simplified((string) ($balanceFormatter->format($entry->pending))) }}</td><td>{{ \App\Portals\Finance\FinanceCatalog::simplified((string) ($balanceFormatter->format($entry->reserve))) }}</td><td>{{ \App\Portals\Finance\FinanceCatalog::simplified((string) ($balanceFormatter->format($balanceFormatter->total($entry->toArray())))) }}</td><td>{{ \App\Portals\Finance\FinanceCatalog::simplified((string) ($entry->currency === 'USD' ? '1' : ($entry->usd_rate ?? '—'))) }}</td><td>{{ \App\Portals\Finance\FinanceCatalog::simplified((string) ($entry->as_of->setTimezone('Asia/Shanghai')->format('Y-m-d H:i'))) }}</td>
                    @if($canManage)<td><button type="button" class="payment-balance-link" data-edit-balance="{{ json_encode(['account' => $entry->account, 'currency' => $entry->currency, 'withdrawable' => $entry->withdrawable, 'pending' => $entry->pending, 'reserve' => $entry->reserve, 'usd_rate' => $entry->usd_rate, 'as_of' => $entry->as_of->setTimezone('Asia/Shanghai')->format('Y-m-d\TH:i')], JSON_UNESCAPED_UNICODE) }}">{{ __('finance.payment_balance.edit') }}</button></td>@endif
                </tr>@endforeach</tbody></table></td></tr>
            @empty
                <tr><td colspan="6" class="payment-balance-empty"><strong>{{ __('finance.payment_balance.empty') }}</strong>{{ __('finance.payment_balance.empty_help') }}</td></tr>
            @endforelse
            <tr class="payment-balance-grand-total"><td colspan="4">{{ __('finance.payment_balance.all_total') }}</td><td colspan="2" data-grand-total>{{ \App\Portals\Finance\FinanceCatalog::simplified((string) ($balanceFormatter->format($paymentBalance['summary']['total']))) }}</td></tr>
            </tbody>
        </table></div>
        @if($paymentBalance['rows']->count() > 1)<button type="button" class="payment-balance-more" id="payment-balance-more" aria-expanded="false"><span>{{ __('finance.payment_balance.more') }}</span><i class="fa fa-angle-down" aria-hidden="true"></i></button>@endif
    </div>
    <p class="payment-balance-hint">{{ __('finance.payment_balance.hint') }}<br>{{ __('finance.payment_balance.conversion_hint') }}</p>
</section>

@if($canManage)
<div class="modal fade" id="payment-balance-modal" tabindex="-1" role="dialog" aria-labelledby="payment-balance-modal-title">
    <div class="modal-dialog" role="document"><div class="modal-content">
        <form method="post" action="{{ route('finance.balances.xuzong2.save') }}" id="payment-balance-form">@csrf
            <input type="hidden" name="balance_tab" value="credit-card" data-balance-tab-input>
            <input type="hidden" name="paypal_currency" value="{{ $paypalBalance['summary']['currency'] }}">
            <div class="modal-header"><button type="button" class="close" data-dismiss="modal" aria-label="{{ __('finance.cancel') }}">&times;</button><h4 class="modal-title" id="payment-balance-modal-title">{{ __('finance.payment_balance.save_title') }}</h4></div>
            <div class="modal-body"><p class="payment-balance-form-note">{{ __('finance.payment_balance.save_help') }}</p>
                <div class="payment-balance-form-grid">
                    <div class="form-group"><label for="payment-account">{{ __('finance.payment_balance.account') }}</label><input id="payment-account" class="form-control" name="account" list="payment-account-options" required maxlength="100" value="{{ old('account') }}"><datalist id="payment-account-options">@foreach($paymentBalance['accounts'] as $accountName)<option value="{{ $accountName }}">@endforeach</datalist></div>
                    <div class="form-group"><label for="payment-currency">{{ __('finance.payment_balance.currency') }}</label><input id="payment-currency" class="form-control" name="currency" pattern="[A-Za-z]{3}" required maxlength="3" value="{{ old('currency', 'USD') }}" style="text-transform:uppercase"></div>
                    @foreach(['withdrawable', 'pending', 'reserve'] as $field)<div class="form-group"><label for="payment-{{ $field }}">{{ __('finance.payment_balance.'.$field) }}</label><input type="number" step="0.0001" id="payment-{{ $field }}" class="form-control" name="{{ $field }}" required value="{{ old($field) }}"></div>@endforeach
                    <div class="form-group"><label for="payment-usd-rate">{{ __('finance.payment_balance.usd_rate') }}</label><input type="number" step="0.00000001" min="0.00000001" id="payment-usd-rate" class="form-control" name="usd_rate" placeholder="{{ __('finance.payment_balance.rate_placeholder') }}" value="{{ old('usd_rate') }}"></div>
                </div>
                <div class="form-group"><label for="payment-as-of">{{ __('finance.payment_balance.time_label') }}</label><input type="datetime-local" id="payment-as-of" class="form-control" name="as_of" required value="{{ old('as_of', now('Asia/Shanghai')->format('Y-m-d\TH:i')) }}"></div>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-default" data-dismiss="modal">{{ __('finance.cancel') }}</button><button type="submit" class="btn btn-primary">{{ __('finance.save') }}</button></div>
        </form>
    </div></div>
</div>
@endif

@push('scripts')
<script>
(function(){
    var section=document.getElementById('payment2-balances'),more=document.getElementById('payment-balance-more');
    var copy={more:@json(__('finance.payment_balance.more')),less:@json(__('finance.payment_balance.less')),details:@json(__('finance.payment_balance.details')),close:@json(__('finance.payment_balance.close_details'))};
    if(more){more.addEventListener('click',function(){var expanded=more.getAttribute('aria-expanded')!=='true';more.setAttribute('aria-expanded',String(expanded));more.querySelector('span').textContent=expanded?copy.less:copy.more;more.querySelector('i').className=expanded?'fa fa-angle-up':'fa fa-angle-down';section.querySelectorAll('[data-extra-currency]').forEach(function(row){row.hidden=!expanded;if(!expanded){var button=row.querySelector('[data-balance-detail]');document.getElementById('payment-detail-'+button.dataset.balanceDetail).hidden=true;button.setAttribute('aria-expanded','false');button.textContent=copy.details;}});});}
    section.querySelectorAll('[data-balance-detail]').forEach(function(button){button.addEventListener('click',function(){var row=document.getElementById('payment-detail-'+button.dataset.balanceDetail),expanded=button.getAttribute('aria-expanded')!=='true';row.hidden=!expanded;button.setAttribute('aria-expanded',String(expanded));button.textContent=expanded?copy.close:copy.details;});});
    var form=document.getElementById('payment-balance-form');
    if(form){var currency=form.elements.currency,rate=form.elements.usd_rate;function updateRate(){var usd=currency.value.trim().toUpperCase()==='USD';rate.readOnly=usd;if(usd){rate.value='1';}else if(rate.value==='1'&&rate.dataset.auto==='true'){rate.value='';}rate.dataset.auto=String(usd);}currency.addEventListener('input',updateRate);updateRate();
        document.getElementById('payment-balance-add').addEventListener('click',function(){form.reset();updateRate();});
        section.querySelectorAll('[data-edit-balance]').forEach(function(button){button.addEventListener('click',function(){var values=JSON.parse(button.dataset.editBalance);Object.keys(values).forEach(function(key){form.elements[key].value=values[key]===null?'':values[key];});updateRate();$('#payment-balance-modal').modal('show');});});
        @if($errors->hasAny(['account', 'currency', 'withdrawable', 'pending', 'reserve', 'usd_rate', 'as_of']))$('#payment-balance-modal').modal('show');@endif
    }
})();
</script>
@endpush
