@push('styles')
<style>#finance-payment-account-create .modal-body{max-height:calc(100vh - 190px);overflow-y:auto}.finance-account-form-grid{display:grid;grid-template-columns:1fr 1fr;gap:0 16px}#finance-paypal-relay-fields[hidden],#finance-credit-account-note[hidden]{display:none!important}@media(max-width:600px){.finance-account-form-grid{grid-template-columns:1fr}}</style>
@endpush
<div class="modal fade" id="finance-payment-account-create" tabindex="-1" role="dialog" aria-labelledby="finance-payment-account-create-title">
    <div class="modal-dialog" role="document"><div class="modal-content">
        <form method="post" action="{{ route('finance.payment-accounts.store') }}" id="finance-payment-account-form" autocomplete="off" data-store-url="{{ route('finance.payment-accounts.store') }}" data-create-title="{{ __('finance.payment_accounts.add') }}" data-edit-title="{{ __('finance.payment_accounts.edit') }}" data-secret-preserved="{{ __('finance.payment_accounts.secret_preserved') }}" data-failed="{{ $errors->paymentAccount->any() ? 'true' : 'false' }}" data-new-card-platform="{{ $newCardPlatform ?? '' }}" data-new-card-group="{{ $newCardGroup ?? '' }}">@csrf
            @if($newCardPlatform ?? false)<input type="hidden" name="from_credit_settlement" value="1">@endif
            <input type="hidden" name="account_editor_key" value="{{ is_string(old('account_editor_key')) ? old('account_editor_key') : '' }}">
            <input type="hidden" name="paypal_currency" value="{{ $paypalBalance['summary']['currency'] }}">
            <input type="hidden" name="balance_tab" value="{{ $activeBalanceTab }}" data-balance-tab-input>
            @if(request('balance_account'))<input type="hidden" name="balance_account" value="{{ request('balance_account') }}">@endif
            <div class="modal-header"><button type="button" class="close" data-dismiss="modal" aria-label="{{ __('finance.cancel') }}">&times;</button><h4 class="modal-title" id="finance-payment-account-create-title">{{ __('finance.payment_accounts.add') }}</h4></div>
            <div class="modal-body">
                @if($errors->paymentAccount->any())<div class="alert alert-danger" role="alert"><ul>@foreach($errors->paymentAccount->all() as $error)<li>{{ \App\Portals\Finance\FinanceCatalog::simplified((string) ($error)) }}</li>@endforeach</ul></div>@endif
                @php
                    $accountOldValue = static fn ($field, $default = '') => is_string(old($field)) ? old($field) : $default;
                @endphp
                <div class="form-group" id="finance-card-group-field" @if($accountOldValue('account_type', 'paypal') === 'paypal') hidden @endif>
                    <label for="finance-card-group">归属主体</label>
                    <input class="form-control" id="finance-card-group" name="card_group_name" list="finance-card-group-options" maxlength="100" value="{{ $accountOldValue('card_group_name') }}" placeholder="例如：叶总">
                    <datalist id="finance-card-group-options">@foreach($cardGroups as $groupName)<option value="{{ $groupName }}">@endforeach</datalist>
                    <p class="help-block">选择已有主体，或输入新主体名称。一个主体下可添加多个单包账户。</p>
                </div>
                <div class="form-group"><label for="finance-account-name">{{ __('finance.payment_accounts.attributes.account_name') }}</label><input class="form-control" id="finance-account-name" name="account_name" required maxlength="100" value="{{ $accountOldValue('account_name') }}"></div>
                <div class="finance-account-form-grid">
                    <div class="form-group"><label for="finance-account-type">{{ __('finance.payment_accounts.attributes.account_type') }}</label><select class="form-control" id="finance-account-type" name="account_type"><option value="paypal" @selected($accountOldValue('account_type', 'paypal') === 'paypal')>PayPal</option><option value="credit_card" @selected($accountOldValue('account_type') === 'credit_card')>{{ __('finance.workbench.credit_card') }}</option></select></div>
                    <div class="form-group"><label for="finance-account-currency">{{ __('finance.payment_accounts.attributes.main_currency') }}</label><input class="form-control" id="finance-account-currency" name="main_currency" required maxlength="3" pattern="[A-Z]{3}" value="{{ $accountOldValue('main_currency', 'USD') }}" style="text-transform:uppercase"></div>
                    <div class="form-group"><label for="finance-account-status">{{ __('finance.payment_accounts.attributes.status') }}</label><select class="form-control" id="finance-account-status" name="status">@foreach(['enabled', 'disabled'] as $status)<option value="{{ $status }}" @selected($accountOldValue('status', 'enabled') === $status)>{{ __('finance.payment_accounts.'.$status) }}</option>@endforeach</select></div>
                </div>
                <div class="form-group" id="finance-card-platform-field" @if($accountOldValue('account_type', 'paypal') === 'paypal') hidden @endif>
                    <label for="finance-card-platform">支付平台</label>
                    <select class="form-control" id="finance-card-platform" name="card_platform">
                        <option value="">未设置</option>
                        @foreach(\App\Portals\Finance\FinanceCreditSettlementLayout::CHANNELS as $key => $label)
                            <option value="{{ $key }}" @selected($accountOldValue('card_platform') === $key)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="form-group" id="finance-card-merchant-field" @if($accountOldValue('card_platform') !== 'ye') hidden @endif>
                    <label for="finance-card-merchant">ConfluxAPI 商户标识</label>
                    <input class="form-control" id="finance-card-merchant" name="card_merchant_key" maxlength="100" value="{{ $accountOldValue('card_merchant_key') }}">
                    <p class="help-block">填结算报表工作表后缀，例如 ZZdanbao。一个单包建一个商户账户。</p>
                </div>
                <p id="finance-account-type-lock" class="help-block" hidden>{{ __('finance.payment_accounts.linked_type_locked') }}</p>
                <div class="form-group"><label for="finance-account-remark">{{ __('finance.payment_accounts.attributes.remark') }}</label><textarea class="form-control" id="finance-account-remark" name="remark" rows="2" maxlength="1000">{{ \App\Portals\Finance\FinanceCatalog::simplified((string) ($accountOldValue('remark'))) }}</textarea></div>
                <div id="finance-credit-account-note" class="finance-status" hidden>{{ __('finance.payment_accounts.credit_unavailable') }}</div>
                <fieldset id="finance-paypal-relay-fields">
                    <legend>{{ __('finance.payment_accounts.relay_configuration') }}</legend>
                    <p class="help-block" id="finance-account-relay-status" role="status" data-configured="{{ __('finance.payment_accounts.relay_saved') }}" data-missing="{{ __('finance.payment_accounts.relay_missing') }}" data-new-account="{{ __('finance.payment_accounts.relay_new') }}"></p>
                    <details class="finance-relay-instructions">
                        <summary>{{ __('finance.payment_accounts.relay_instructions') }}</summary>
                        <ul>
                            <li>{{ __('finance.payment_accounts.relay_endpoint_help') }}</li>
                            <li>{{ __('finance.payment_accounts.relay_credentials_help') }}</li>
                            <li>{{ __('finance.payment_accounts.relay_security_help') }}</li>
                            <li>{{ __('finance.payment_accounts.relay_operations_help') }}</li>
                            <li>{{ __('finance.payment_accounts.relay_save_help') }}</li>
                        </ul>
                    </details>
                    <div class="form-group"><label for="finance-account-relay-url">{{ __('finance.payment_accounts.attributes.relay_url') }}</label><input class="form-control" id="finance-account-relay-url" type="url" name="relay_url" data-relay-required required maxlength="2048" placeholder="https://VPS_IP/relay/v1/paypal/read" value="{{ $accountOldValue('relay_url') }}"></div>
                    <div class="form-group"><label for="finance-account-certificate">{{ __('finance.payment_accounts.attributes.relay_certificate_sha1') }}</label><input class="form-control" id="finance-account-certificate" name="relay_certificate_sha1" data-relay-required required maxlength="59" value="{{ $accountOldValue('relay_certificate_sha1') }}" placeholder="SHA-1"></div>
                    <div class="form-group"><label for="finance-account-key-id">{{ __('finance.payment_accounts.attributes.relay_hmac_key_id') }}</label><input class="form-control" id="finance-account-key-id" name="relay_hmac_key_id" data-relay-required required maxlength="100" value="{{ $accountOldValue('relay_hmac_key_id', 'erp-main') }}"></div>
                    <div class="form-group"><label for="finance-account-secret">{{ __('finance.payment_accounts.attributes.relay_hmac_secret') }}</label><input class="form-control" id="finance-account-secret" type="password" name="relay_hmac_secret" data-relay-required required maxlength="2048" autocomplete="new-password"><p class="help-block">{{ __('finance.payment_accounts.secret_help') }}</p></div>
                    <div class="form-group"><label for="finance-account-server">{{ __('finance.payment_accounts.attributes.relay_server_remark') }}</label><input class="form-control" id="finance-account-server" name="relay_server_remark" maxlength="255" value="{{ $accountOldValue('relay_server_remark') }}"></div>
                </fieldset>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-default" data-dismiss="modal">{{ __('finance.cancel') }}</button><button type="submit" class="btn btn-primary">{{ __('finance.save') }}</button></div>
        </form>
    </div></div>
</div>
<script type="application/json" id="finance-payment-account-editors">@json($paymentAccountEditors)</script>
