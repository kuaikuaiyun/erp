@php
    $t = static fn (string $key, array $replace = []): string => __('assistant.domain_stores.'.$key, $replace);
    $optionValues = static function (array $defaults, string $field) use ($quickOptions): array {
        return array_values(array_unique([
            ...array_keys($defaults),
            ...($quickOptions[$field] ?? []),
        ]));
    };
    $multiValue = static fn (mixed $value): string => implode(',', \App\Models\AssistantDomainStore::normalizeMultiValue($value));
    $dateValue = static fn (mixed $value): string => $value instanceof \Carbon\CarbonInterface ? $value->format('Y-m-d') : (string) ($value ?? '');
    $siteBuilderValues = $optionValues($siteBuilderOptions, 'site_builder');
    $teamValues = $optionValues([], 'team');
    $projectValues = $optionValues([], 'project');
    $erpValues = $optionValues($erpOptions, 'erp');
    $creditCardValues = $optionValues($creditCardOptions, 'credit_card');
    $walletValues = $optionValues($walletOptions, 'wallet');
    $paypalValues = $optionValues($paypalOptions, 'paypal');
    $otherPaymentValues = $optionValues($otherPaymentOptions, 'other_payment');
    $fieldSelected = static fn (string $field): bool => in_array($field, $selectedFields, true);
    $sectionSelected = static fn (array $fields): bool => array_intersect($fields, $selectedFields) !== [];
@endphp

<form method="post" action="{{ route('assistant.domain-stores.update-batch') }}" id="assistant-batch-edit-form" class="assistant-batch-edit-form" data-batch-edit-form novalidate>
    @csrf
    <input type="hidden" name="return_to" value="">
    @foreach ($stores as $store)
        <input type="hidden" name="selected_ids[]" value="{{ $store->getKey() }}">
    @endforeach
    @foreach ($selectedFields as $field)
        <input type="hidden" name="fields[]" value="{{ $field }}">
    @endforeach

    <div class="assistant-batch-editor-intro">
        {{ $t('batch_update.independent_notice', ['count' => $stores->count()]) }}
    </div>

    <div class="assistant-batch-editor-layout">
        <div class="assistant-batch-record-panels assistant-batch-record-panels-expanded">
            @foreach ($stores as $store)
                @php
                    $storeId = (int) $store->getKey();
                    $prefix = 'records['.$storeId.']';
                    $idPrefix = 'batch-'.$storeId.'-';
                    $teamSelectedValues = \App\Models\AssistantDomainStore::normalizeMultiValue($store->team);
                    $erpSelectedValues = \App\Models\AssistantDomainStore::normalizeMultiValue($store->erp);
                @endphp
                <article class="assistant-batch-record-panel is-active" data-batch-record-panel="{{ $storeId }}">
                    <header class="assistant-batch-record-heading">
                        <h5>{{ $store->shop_name }}</h5>
                        <span>{{ $store->domain }}</span>
                    </header>

                    @if ($sectionSelected(['shop_name', 'domain', 'shop_login_url', 'email', 'domain_expires_at', 'domain_check_status', 'email_bound']))
                    <section class="box box-solid assistant-form-section">
                        <div class="box-header with-border"><h3 class="box-title">{{ $t('sections.shop_domain') }}</h3></div>
                        <div class="box-body row assistant-form-grid">
                            @if ($fieldSelected('shop_name'))
                            <div class="form-group col-md-6" data-batch-field="shop_name">
                                <label for="{{ $idPrefix }}shop-name">{{ $t('fields.shop_name') }} <span class="text-red">*</span></label>
                                <input id="{{ $idPrefix }}shop-name" name="{{ $prefix }}[shop_name]" class="form-control" value="{{ $store->shop_name }}" maxlength="100" required>
                            </div>
                            @endif
                            @if ($fieldSelected('domain'))
                            <div class="form-group col-md-6" data-batch-field="domain">
                                <label for="{{ $idPrefix }}domain">{{ $t('fields.domain') }} <span class="text-red">*</span></label>
                                <input id="{{ $idPrefix }}domain" name="{{ $prefix }}[domain]" class="form-control" value="{{ $store->domain }}" maxlength="191" required>
                            </div>
                            @endif
                            @if ($fieldSelected('shop_login_url'))
                            <div class="form-group col-md-6" data-batch-field="shop_login_url">
                                <label for="{{ $idPrefix }}shop-login-url">{{ $t('fields.shop_login_url') }}</label>
                                <input id="{{ $idPrefix }}shop-login-url" name="{{ $prefix }}[shop_login_url]" class="form-control" value="{{ $store->shop_login_url }}" maxlength="2048">
                            </div>
                            @endif
                            @if ($fieldSelected('email'))
                            <div class="form-group col-md-6" data-batch-field="email">
                                <label for="{{ $idPrefix }}email">{{ $t('fields.email') }}</label>
                                <input id="{{ $idPrefix }}email" type="email" name="{{ $prefix }}[email]" class="form-control" value="{{ $store->email }}" maxlength="191">
                            </div>
                            @endif
                            @if ($fieldSelected('domain_expires_at'))
                            <div class="form-group col-md-4" data-batch-field="domain_expires_at">
                                <label for="{{ $idPrefix }}domain-expires">{{ $t('fields.domain_expires_at') }}</label>
                                <input id="{{ $idPrefix }}domain-expires" type="date" name="{{ $prefix }}[domain_expires_at]" class="form-control" value="{{ $dateValue($store->domain_expires_at) }}">
                            </div>
                            @endif
                            @if ($fieldSelected('domain_check_status'))
                            <div class="form-group col-md-4" data-batch-field="domain_check_status">
                                <label for="{{ $idPrefix }}domain-check">{{ $t('fields.domain_check_status') }} <span class="text-red">*</span></label>
                                <select id="{{ $idPrefix }}domain-check" name="{{ $prefix }}[domain_check_status]" class="form-control">@foreach ($domainCheckLabels as $value => $label)<option value="{{ $value }}" @selected($store->domain_check_status === $value)>{{ $label }}</option>@endforeach</select>
                            </div>
                            @endif
                            @if ($fieldSelected('email_bound'))
                            <div class="form-group col-md-2" data-batch-field="email_bound">
                                <label for="{{ $idPrefix }}email-bound">{{ $t('fields.email_bound') }} <span class="text-red">*</span></label>
                                <select id="{{ $idPrefix }}email-bound" name="{{ $prefix }}[email_bound]" class="form-control"><option value="1" @selected($store->email_bound)>{{ $t('common.yes') }}</option><option value="0" @selected(!$store->email_bound)>{{ $t('common.no') }}</option></select>
                            </div>
                            @endif
                        </div>
                    </section>
                    @endif

                    @if ($sectionSelected(['team', 'project', 'user_name']))
                    <section class="box box-solid assistant-form-section">
                        <div class="box-header with-border"><h3 class="box-title">{{ $t('sections.team_usage') }}</h3></div>
                        <div class="box-body row assistant-form-grid">
                            @if ($fieldSelected('team'))
                            <div class="form-group col-md-4" data-batch-field="team">
                                <label for="{{ $idPrefix }}team">{{ $t('fields.team') }}</label>
                                <input id="{{ $idPrefix }}team" name="{{ $prefix }}[team]" class="form-control" value="{{ $multiValue($store->team) }}" maxlength="2000" list="{{ $idPrefix }}team-options" placeholder="{{ $t('batch_update.multi_value_placeholder') }}" data-batch-input="team">
                                <datalist id="{{ $idPrefix }}team-options">@foreach ($teamValues as $value)<option value="{{ $value }}">{{ $value }}</option>@endforeach</datalist>
                                @if ($teamValues !== [])
                                    <div class="assistant-batch-option-presets" data-batch-option-group="team">
                                        @foreach ($teamValues as $option)
                                            <button type="button" class="btn btn-default btn-xs assistant-batch-option-choice @if (in_array($option, $teamSelectedValues, true)) is-selected @endif" data-batch-multi-option="team" data-batch-option-value="{{ $option }}">{{ $option }}</button>
                                        @endforeach
                                    </div>
                                @endif
                            </div>
                            @endif
                            @if ($fieldSelected('project'))
                            <div class="form-group col-md-4" data-batch-field="project">
                                <label for="{{ $idPrefix }}project">{{ $t('fields.project') }}</label>
                                <input id="{{ $idPrefix }}project" name="{{ $prefix }}[project]" class="form-control" value="{{ $store->project }}" maxlength="100" list="{{ $idPrefix }}project-options">
                                <datalist id="{{ $idPrefix }}project-options">@foreach ($projectValues as $value)<option value="{{ $value }}">{{ $value }}</option>@endforeach</datalist>
                            </div>
                            @endif
                            @if ($fieldSelected('user_name'))
                            <div class="form-group col-md-4" data-batch-field="user_name">
                                <label for="{{ $idPrefix }}user-name">{{ $t('fields.user_name') }}</label>
                                <input id="{{ $idPrefix }}user-name" name="{{ $prefix }}[user_name]" class="form-control" value="{{ $store->user_name }}" maxlength="100">
                            </div>
                            @endif
                        </div>
                    </section>
                    @endif

                    @if ($sectionSelected(['site_builder', 'erp', 'credit_card', 'wallet', 'paypal', 'other_payment', 'payment_card']))
                    <section class="box box-solid assistant-form-section">
                        <div class="box-header with-border"><h3 class="box-title">{{ $t('sections.system_payment') }}</h3></div>
                        <div class="box-body row assistant-form-grid">
                            @if ($fieldSelected('site_builder'))
                            <div class="form-group col-md-4" data-batch-field="site_builder">
                                <label for="{{ $idPrefix }}site-builder">{{ $t('fields.site_builder') }}</label>
                                <input id="{{ $idPrefix }}site-builder" name="{{ $prefix }}[site_builder]" class="form-control" value="{{ $store->site_builder }}" maxlength="100" list="{{ $idPrefix }}site-builder-options" data-batch-input="site_builder">
                                <datalist id="{{ $idPrefix }}site-builder-options">@foreach ($siteBuilderValues as $value)<option value="{{ $value }}">{{ $siteBuilderOptions[$value] ?? $value }}</option>@endforeach</datalist>
                                <div class="assistant-batch-option-presets" data-batch-option-group="site_builder">
                                    @foreach ($siteBuilderValues as $option)
                                        <button type="button" class="btn btn-default btn-xs assistant-batch-option-choice @if ((string) $store->site_builder === (string) $option) is-selected @endif" data-batch-single-option="site_builder" data-batch-option-value="{{ $option }}">{{ $siteBuilderOptions[$option] ?? $option }}</button>
                                    @endforeach
                                </div>
                            </div>
                            @endif
                            @if ($fieldSelected('erp'))
                            <div class="form-group col-md-4" data-batch-field="erp">
                                <label for="{{ $idPrefix }}erp">{{ $t('fields.erp') }}</label>
                                <input id="{{ $idPrefix }}erp" name="{{ $prefix }}[erp]" class="form-control" value="{{ $multiValue($store->erp) }}" maxlength="2000" list="{{ $idPrefix }}erp-options" placeholder="{{ $t('batch_update.multi_value_placeholder') }}" data-batch-input="erp">
                                <datalist id="{{ $idPrefix }}erp-options">@foreach ($erpValues as $value)<option value="{{ $value }}">{{ $erpOptions[$value] ?? $value }}</option>@endforeach</datalist>
                                <div class="assistant-batch-option-presets" data-batch-option-group="erp">
                                    @foreach ($erpValues as $option)
                                        <button type="button" class="btn btn-default btn-xs assistant-batch-option-choice @if (in_array($option, $erpSelectedValues, true)) is-selected @endif" data-batch-multi-option="erp" data-batch-option-value="{{ $option }}">{{ $erpOptions[$option] ?? $option }}</button>
                                    @endforeach
                                </div>
                            </div>
                            @endif
                            @if ($fieldSelected('credit_card'))
                            <div class="form-group col-md-4" data-batch-field="credit_card">
                                <label for="{{ $idPrefix }}credit-card">{{ $t('fields.credit_card') }}</label>
                                <input id="{{ $idPrefix }}credit-card" name="{{ $prefix }}[credit_card]" class="form-control" value="{{ $store->credit_card }}" maxlength="100" list="{{ $idPrefix }}credit-card-options" data-batch-input="credit_card">
                                <datalist id="{{ $idPrefix }}credit-card-options">@foreach ($creditCardValues as $value)<option value="{{ $value }}">{{ $creditCardOptions[$value] ?? $value }}</option>@endforeach</datalist>
                                <div class="assistant-batch-option-presets" data-batch-option-group="credit_card">
                                    @foreach ($creditCardValues as $option)
                                        <button type="button" class="btn btn-default btn-xs assistant-batch-option-choice @if ((string) $store->credit_card === (string) $option) is-selected @endif" data-batch-single-option="credit_card" data-batch-option-value="{{ $option }}">{{ $creditCardOptions[$option] ?? $option }}</button>
                                    @endforeach
                                </div>
                            </div>
                            @endif
                            @if ($fieldSelected('wallet'))
                            <div class="form-group col-md-4" data-batch-field="wallet">
                                <label for="{{ $idPrefix }}wallet">{{ $t('fields.wallet') }}</label>
                                <input id="{{ $idPrefix }}wallet" name="{{ $prefix }}[wallet]" class="form-control" value="{{ $store->wallet }}" maxlength="100" list="{{ $idPrefix }}wallet-options" data-batch-input="wallet">
                                <datalist id="{{ $idPrefix }}wallet-options">@foreach ($walletValues as $value)<option value="{{ $value }}">{{ $walletOptions[$value] ?? $value }}</option>@endforeach</datalist>
                                <div class="assistant-batch-option-presets" data-batch-option-group="wallet">
                                    @foreach ($walletValues as $option)
                                        <button type="button" class="btn btn-default btn-xs assistant-batch-option-choice @if ((string) $store->wallet === (string) $option) is-selected @endif" data-batch-single-option="wallet" data-batch-option-value="{{ $option }}">{{ $walletOptions[$option] ?? $option }}</button>
                                    @endforeach
                                </div>
                            </div>
                            @endif
                            @if ($fieldSelected('paypal'))
                            <div class="form-group col-md-4" data-batch-field="paypal">
                                <label for="{{ $idPrefix }}paypal">{{ $t('fields.paypal') }}</label>
                                <input id="{{ $idPrefix }}paypal" name="{{ $prefix }}[paypal]" class="form-control" value="{{ $store->paypal }}" maxlength="100" list="{{ $idPrefix }}paypal-options" data-batch-input="paypal">
                                <datalist id="{{ $idPrefix }}paypal-options">@foreach ($paypalValues as $value)<option value="{{ $value }}">{{ $paypalOptions[$value] ?? $value }}</option>@endforeach</datalist>
                                <div class="assistant-batch-option-presets" data-batch-option-group="paypal">
                                    @foreach ($paypalValues as $option)
                                        <button type="button" class="btn btn-default btn-xs assistant-batch-option-choice @if ((string) $store->paypal === (string) $option) is-selected @endif" data-batch-single-option="paypal" data-batch-option-value="{{ $option }}">{{ $paypalOptions[$option] ?? $option }}</button>
                                    @endforeach
                                </div>
                            </div>
                            @endif
                            @if ($fieldSelected('other_payment'))
                            <div class="form-group col-md-4" data-batch-field="other_payment">
                                <label for="{{ $idPrefix }}other-payment">{{ $t('fields.other_payment') }}</label>
                                <input id="{{ $idPrefix }}other-payment" name="{{ $prefix }}[other_payment]" class="form-control" value="{{ $store->other_payment }}" maxlength="100" list="{{ $idPrefix }}other-payment-options" data-batch-input="other_payment">
                                <datalist id="{{ $idPrefix }}other-payment-options">@foreach ($otherPaymentValues as $value)<option value="{{ $value }}">{{ $otherPaymentOptions[$value] ?? $value }}</option>@endforeach</datalist>
                                <div class="assistant-batch-option-presets" data-batch-option-group="other_payment">
                                    @foreach ($otherPaymentValues as $option)
                                        <button type="button" class="btn btn-default btn-xs assistant-batch-option-choice @if ((string) $store->other_payment === (string) $option) is-selected @endif" data-batch-single-option="other_payment" data-batch-option-value="{{ $option }}">{{ $otherPaymentOptions[$option] ?? $option }}</button>
                                    @endforeach
                                </div>
                            </div>
                            @endif
                            @if ($fieldSelected('payment_card'))
                            <div class="form-group col-md-12" data-batch-field="payment_card">
                                <label for="{{ $idPrefix }}payment-card">{{ $t('fields.payment_card') }}</label>
                                <input id="{{ $idPrefix }}payment-card" name="{{ $prefix }}[payment_card]" class="form-control" value="{{ $store->payment_card }}" maxlength="255">
                            </div>
                            @endif
                        </div>
                    </section>
                    @endif

                    @if ($sectionSelected(['store_prefix', 'shopify_client_id', 'shopify_client_secret']))
                    <section class="box box-solid assistant-form-section assistant-batch-shopify-section">
                        <div class="box-header with-border"><h3 class="box-title">{{ $t('sections.shopify_app') }}</h3></div>
                        <div class="box-body row assistant-form-grid">
                            @if ($fieldSelected('store_prefix'))
                            <div class="form-group col-md-4" data-batch-field="store_prefix">
                                <label for="{{ $idPrefix }}store-prefix">{{ $t('fields.store_prefix') }}</label>
                                <input id="{{ $idPrefix }}store-prefix" name="{{ $prefix }}[store_prefix]" class="form-control" value="{{ $store->store_prefix }}" maxlength="100">
                            </div>
                            @endif
                            @if ($fieldSelected('shopify_client_id'))
                            <div class="form-group col-md-4" data-batch-field="shopify_client_id">
                                <label for="{{ $idPrefix }}shopify-client-id">{{ $t('fields.shopify_client_id') }}</label>
                                <input id="{{ $idPrefix }}shopify-client-id" name="{{ $prefix }}[shopify_client_id]" class="form-control" value="{{ $store->shopify_client_id }}" maxlength="255" autocomplete="off">
                            </div>
                            @endif
                            @if ($fieldSelected('shopify_client_secret'))
                            <div class="form-group col-md-4" data-batch-field="shopify_client_secret">
                                <label for="{{ $idPrefix }}shopify-client-secret">{{ $t('fields.shopify_client_secret') }}</label>
                                <input id="{{ $idPrefix }}shopify-client-secret" name="{{ $prefix }}[shopify_client_secret]" class="form-control" value="{{ $store->shopify_client_secret }}" maxlength="255" autocomplete="off">
                            </div>
                            @endif
                        </div>
                    </section>
                    @endif

                    @if ($sectionSelected(['shop_status', 'plan_expires_at', 'remark']))
                    <section class="box box-solid assistant-form-section">
                        <div class="box-header with-border"><h3 class="box-title">{{ $t('sections.status_expiry') }}</h3></div>
                        <div class="box-body row assistant-form-grid">
                            @if ($fieldSelected('shop_status'))
                            <div class="form-group col-md-4" data-batch-field="shop_status">
                                <label for="{{ $idPrefix }}shop-status">{{ $t('fields.shop_status') }} <span class="text-red">*</span></label>
                                <select id="{{ $idPrefix }}shop-status" name="{{ $prefix }}[shop_status]" class="form-control">@foreach ($shopStatusLabels as $value => $label)<option value="{{ $value }}" @selected($store->shop_status === $value)>{{ $label }}</option>@endforeach</select>
                            </div>
                            @endif
                            @if ($fieldSelected('plan_expires_at'))
                            <div class="form-group col-md-4" data-batch-field="plan_expires_at">
                                <label for="{{ $idPrefix }}plan-expires">{{ $t('fields.plan_expires_at') }}</label>
                                <input id="{{ $idPrefix }}plan-expires" type="date" name="{{ $prefix }}[plan_expires_at]" class="form-control" value="{{ $dateValue($store->plan_expires_at) }}">
                            </div>
                            @endif
                            @if ($fieldSelected('remark'))
                            <div class="form-group col-md-12" data-batch-field="remark">
                                <label for="{{ $idPrefix }}remark">{{ $t('fields.remark') }}</label>
                                <textarea id="{{ $idPrefix }}remark" name="{{ $prefix }}[remark]" class="form-control" rows="3" maxlength="2000">{{ $store->remark }}</textarea>
                            </div>
                            @endif
                        </div>
                    </section>
                    @endif
                </article>
            @endforeach
        </div>
    </div>
</form>
