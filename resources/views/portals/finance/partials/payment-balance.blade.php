@php($isConflux = $isConflux ?? false)
@once
@push('styles')
<style>
.payment-balance-section{padding:8px 0 12px;color:#17324d}
.payment-balance-heading{display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap;margin-bottom:16px}
.payment-balance-heading h2{font-size:20px;font-weight:600;margin:0 0 5px}
.payment-balance-heading p{font-size:12px;color:#74869a;margin:0}
.payment-balance-card{border:1px solid #dfe6ee;border-radius:12px;background:#fff;box-shadow:0 2px 3px rgba(22,49,76,.06);overflow:hidden}
.payment-balance-summary{padding:20px 22px 18px}
.payment-balance-summary-header{display:flex;align-items:center;justify-content:space-between;gap:12px;font-size:13px;margin-bottom:16px}
.payment-currency-badge{display:inline-block;padding:2px 9px;border-radius:20px;background:#eeecff;color:#7163ec;font-size:11px;font-weight:400;white-space:nowrap}
.payment-balance-metrics{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:20px}
.payment-balance-metric span{display:block;color:#698096;font-size:13px;margin-bottom:5px}
.payment-balance-metric strong{font-size:24px;letter-spacing:.1px;font-variant-numeric:tabular-nums;color:#102d48;line-height:1.4}
@media(max-width:767px){.payment-balance-metrics{grid-template-columns:repeat(2,minmax(0,1fr));gap:18px}}
</style>
@endpush
@endonce

<section class="payment-balance-section" id="{{ $isConflux ? 'conflux-accounts' : 'payment2-balances' }}" aria-labelledby="{{ $isConflux ? 'conflux-accounts-title' : 'payment-balance-title' }}">
    <div class="payment-balance-heading">
        <div>
            <h2 id="{{ $isConflux ? 'conflux-accounts-title' : 'payment-balance-title' }}">{{ $isConflux ? 'Conflux 店铺账户' : __('finance.balances.xuzong2') }}</h2>
            @unless($isConflux)<p>{{ __('finance.payment_accounts.credit_unavailable') }}</p>@endunless
        </div>
        @if($canManage)
            <button type="button" class="btn btn-primary btn-sm" data-add-payment-account="credit_card" @if($isConflux) data-card-platform="ye" @endif><i class="fa fa-plus" aria-hidden="true"></i> {{ $isConflux ? '添加店铺' : __('finance.payment_accounts.add') }}</button>
        @endif
    </div>
    @if($isConflux)
        @include('portals.finance.partials.conflux-account-cards')
    @elseif($creditAccounts->isNotEmpty())
        <div class="box finance-credit-accounts">
            <div class="box-header with-border"><h3 class="box-title">{{ __('finance.payment_accounts.credit_accounts') }}</h3></div>
            <div class="box-body finance-table-wrap">
                <table class="table finance-table">
                    <thead>
                        <tr>
                            <th>{{ __('finance.payment_accounts.attributes.account_name') }}</th>
                            <th>支付平台</th>
                            @foreach(['account_code', 'main_currency', 'status', 'remark'] as $field)
                                <th>{{ __('finance.payment_accounts.attributes.'.$field) }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($creditAccounts->groupBy(fn ($account) => $account->card_group_id ?? 0) as $groupId => $groupAccounts)
                            <tr class="active"><th scope="rowgroup" colspan="6">
                                {{ $cardGroups->get($groupId, '未分组账户') }}
                                @if($groupId && $canManage)
                                    <button type="button" class="btn btn-default btn-xs" data-add-payment-account="credit_card" data-credit-group="{{ $cardGroups->get($groupId) }}">添加子账户</button>
                                @endif
                            </th></tr>
                            @foreach($groupAccounts as $creditAccount)
                                <tr>
                                    <td>{{ \App\Portals\Finance\FinanceCatalog::simplified($creditAccount->account_name) }}@if($creditAccount->card_merchant_key)<br><small>{{ $creditAccount->card_merchant_key }}</small>@endif</td>
                                    <td>{{ \App\Portals\Finance\FinanceCreditSettlementLayout::CHANNELS[$creditAccount->card_platform] ?? '未设置' }}</td>
                                    <td>{{ $creditAccount->account_code }}</td>
                                    <td>{{ $creditAccount->main_currency }}</td>
                                    <td>{{ __('finance.payment_accounts.'.$creditAccount->status) }}</td>
                                    <td>{{ \App\Portals\Finance\FinanceCatalog::simplified($creditAccount->remark) }}</td>
                                </tr>
                            @endforeach
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif
</section>
