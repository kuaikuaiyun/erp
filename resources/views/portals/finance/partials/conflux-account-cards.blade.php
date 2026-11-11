@forelse($creditAccounts->groupBy(fn ($account) => $account->card_group_id ?? 0) as $groupId => $groupAccounts)
    <section class="finance-conflux-group" aria-labelledby="finance-conflux-group-{{ $groupId }}">
        <div class="finance-conflux-group-heading">
            <h3 id="finance-conflux-group-{{ $groupId }}">{{ $cardGroups->get($groupId, '未分组账户') }}</h3>
            @if($groupId && $canManage)
                <button type="button" class="btn btn-default btn-xs" data-add-payment-account="credit_card" data-card-platform="ye" data-credit-group="{{ $cardGroups->get($groupId) }}">添加子账户</button>
            @endif
        </div>
        <div class="paypal-account-grid">
            @foreach($groupAccounts as $creditAccount)
                <article class="paypal-account-card">
                    <header class="paypal-account-header">
                        <span class="paypal-account-logo"><i class="fa fa-shopping-bag" aria-hidden="true"></i></span>
                        <h3 title="{{ $creditAccount->account_name }}">{{ \App\Portals\Finance\FinanceCatalog::simplified($creditAccount->account_name) }}</h3>
                        <span class="paypal-connection-status" data-state="{{ $creditAccount->status === 'enabled' ? 'connected' : 'closed' }}">
                            <span class="paypal-connection-dot" aria-hidden="true"></span>
                            {{ __('finance.payment_accounts.'.$creditAccount->status) }}
                        </span>
                    </header>
                    <div class="paypal-account-values finance-conflux-card-values">
                        <p>{{ __('finance.payment_accounts.code') }} {{ $creditAccount->account_code }}</p>
                        @if($creditAccount->card_merchant_key)
                            <p>商户标识 {{ $creditAccount->card_merchant_key }}</p>
                        @endif
                        <span class="paypal-currency" title="{{ __('finance.payment_accounts.attributes.main_currency') }}">{{ $creditAccount->main_currency }}</span>
                        @if(filled($creditAccount->remark))
                            <p class="finance-conflux-remark">{{ \App\Portals\Finance\FinanceCatalog::simplified($creditAccount->remark) }}</p>
                        @endif
                    </div>
                    @if($canManage)
                        <div class="paypal-account-actions">
                            <button type="button" class="paypal-account-action" data-edit-payment-account="finance-{{ $creditAccount->id }}">
                                <i class="fa fa-pencil-square-o" aria-hidden="true"></i> {{ __('finance.payment_accounts.edit') }}
                            </button>
                        </div>
                    @endif
                </article>
            @endforeach
        </div>
    </section>
@empty
    <p>暂无 Conflux 店铺账户。</p>
@endforelse

