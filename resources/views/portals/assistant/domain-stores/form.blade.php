@extends(($modalForm ?? false) ? 'portal.fragment' : 'portal.layout')

@section('page_title', $formMode === 'create' ? __('assistant.domain_stores.create_title') : __('assistant.domain_stores.edit_title'))

@php
    $portalOrigin = rtrim(request()->getSchemeAndHttpHost(), '/');
    $isModalForm = (bool) ($modalForm ?? false);
    $t = static fn (string $key, array $replace = []): string => __('assistant.domain_stores.'.$key, $replace);
    $isCreate = $formMode === 'create';
    $formAction = $isCreate ? route('assistant.domain-stores.store') : route('assistant.domain-stores.update', $store);
    $value = static fn (string $field, mixed $fallback = null): mixed => old($field, $store->getAttribute($field) ?? $fallback);
    $dateValue = static fn (mixed $date): string => $date instanceof \DateTimeInterface ? $date->format('Y-m-d') : (string) ($date ?? '');
    $tagValues = static fn (string $field): array => \App\Models\AssistantDomainStore::normalizeMultiValue($value($field, []));
    $teamValues = $tagValues('team');
    $erpValues = $tagValues('erp');
    $domainCheckStoredStatus = (string) $value('domain_check_status', \App\Models\AssistantDomainStore::DOMAIN_CHECK_NOT_CHECKED);
    $domainCheckChoice = in_array($domainCheckStoredStatus, [
        \App\Models\AssistantDomainStore::DOMAIN_CHECK_ABNORMAL,
        \App\Models\AssistantDomainStore::DOMAIN_CHECK_REVIEW,
        \App\Models\AssistantDomainStore::DOMAIN_CHECK_FAILED,
    ], true)
        ? \App\Models\AssistantDomainStore::DOMAIN_CHECK_ABNORMAL
        : $domainCheckStoredStatus;
    $shopifyWebhookUrl = trim((string) config('logistics.shopify.webhook_url', ''));
    $shopifyAccessScopes = $t('form.shopify_access_scopes_value');
    $tagJavascriptTranslations = [
        'remove' => $t('javascript.remove'),
        'removeTag' => $t('javascript.remove_tag', ['name' => '__name__']),
    ];
    $quickOptionJavascriptConfig = [
        'url' => route('assistant.domain-stores.quick-options.store'),
        'deleteUrl' => route('assistant.domain-stores.quick-options.destroy'),
        'translations' => [
            'empty' => $t('javascript.quick_option_empty'),
            'added' => $t('javascript.quick_option_added'),
            'failed' => $t('javascript.quick_option_failed'),
            'delete' => $t('form.delete_quick_option'),
            'deleteFailed' => $t('javascript.quick_option_delete_failed'),
        ],
    ];
    $emailPurchaseDisplayStatus = filled($value('email'))
        ? ($emailPurchaseStatus ?? 'unsynced')
        : 'empty';
    $emailPurchaseJavascriptConfig = [
        'statusUrl' => route('assistant.domain-stores.email-purchases.status'),
        'initialStatus' => $emailPurchaseDisplayStatus,
        'empty' => $t('email_purchase_status.empty'),
        'loading' => $t('email_purchase_status.checking'),
        'failed' => $t('email_purchase_status.lookup_failed'),
    ];
    $ownerAssignmentJavascriptConfig = [
        'candidates' => $ownerCandidatesByDepartment ?? [],
        'selectedDepartmentId' => $ownerOperationDepartmentId ?? null,
        'selectedOwnerId' => $ownerUserId ?? null,
        'translations' => [
            'chooseOwner' => $t('form.owner_user_placeholder'),
            'empty' => $t('form.owner_candidates_empty'),
        ],
    ];
@endphp

@push('styles')
    <link rel="stylesheet" href="{{ $portalOrigin }}/assets/erp/assistant-domain-stores-20261009-7.css?v=20261013-1">
    <link rel="stylesheet" href="{{ $portalOrigin }}/assets/erp/assistant-domain-stores-form-20261021-1.css?v=20261021-3">
@endpush

@section('content')
    @unless ($isModalForm)
        <section class="content-header">
            <h1>{{ $isCreate ? $t('create_title') : $t('edit_title') }} <small>{{ $t('subtitle') }}</small></h1>
        </section>
    @endunless

    <section class="{{ $isModalForm ? 'assistant-modal-form-content' : 'content' }} assistant-domain-stores-page">
        <form method="post" action="{{ $formAction }}" class="assistant-domain-store-form" @if ($isModalForm) data-modal-form novalidate @endif>
            @csrf
            @if ($canAssignStoreOwner)
                <script type="application/json" data-owner-form-config>@json($ownerAssignmentJavascriptConfig)</script>
            @endif
            @unless ($isCreate)
                @method('PUT')
            @endunless
            @if ($isModalForm)
                <input type="hidden" name="modal" value="1">
            @endif

            <div class="box box-solid assistant-form-section assistant-form-shop-section">
                <div class="box-header with-border"><h3 class="box-title">{{ $t('sections.shop_domain') }}</h3></div>
                <div class="box-body row assistant-form-grid">
                    <div class="form-group col-md-6 @error('shop_name') has-error @enderror">
                        <label for="shop_name">{{ $t('fields.shop_name') }} <span class="text-red">*</span></label>
                        <input id="shop_name" name="shop_name" class="form-control" value="{{ $value('shop_name') }}" maxlength="100" required>
                        @error('shop_name')<span class="help-block">{{ $message }}</span>@enderror
                    </div>
                    <div class="form-group col-md-6 @error('domain') has-error @enderror">
                        <label for="domain">{{ $t('fields.domain') }} <span class="text-red">*</span></label>
                        <input id="domain" name="domain" class="form-control" value="{{ $value('domain') }}" maxlength="191" required>
                        @error('domain')<span class="help-block">{{ $message }}</span>@enderror
                    </div>
                    <div class="form-group col-md-6 @error('shop_login_url') has-error @enderror">
                        <label for="shop_login_url">{{ $t('fields.shop_login_url') }}</label>
                        <input id="shop_login_url" type="text" name="shop_login_url" class="form-control" value="{{ $value('shop_login_url') }}" maxlength="2048" placeholder="{{ $t('form.login_url_placeholder') }}">
                        @error('shop_login_url')<span class="help-block">{{ $message }}</span>@enderror
                    </div>
                    <div class="form-group col-md-6 @error('email') has-error @enderror">
                        <label for="email">{{ $t('fields.email') }}</label>
                        <input id="email" type="email" name="email" class="form-control" value="{{ $value('email') }}" maxlength="191">
                        @error('email')<span class="help-block">{{ $message }}</span>@enderror
                    </div>
                    <div class="form-group col-md-4 @error('domain_expires_at') has-error @enderror">
                        <label for="domain_expires_at">{{ $t('fields.domain_expires_at') }}</label>
                        <input id="domain_expires_at" type="date" name="domain_expires_at" class="form-control" value="{{ $dateValue($value('domain_expires_at')) }}">
                        @error('domain_expires_at')<span class="help-block">{{ $message }}</span>@enderror
                    </div>
                    <div class="form-group col-md-4 @error('domain_check_status') has-error @enderror">
                        <label for="domain_check_status">{{ $t('fields.domain_check_status') }}</label>
                        <div class="assistant-option-group assistant-domain-check-option-group" data-domain-check-option-group data-display-status="{{ $domainCheckChoice }}">
                            <input id="domain_check_status" type="hidden" name="domain_check_status" value="{{ $domainCheckStoredStatus }}">
                            @foreach ([\App\Models\AssistantDomainStore::DOMAIN_CHECK_NORMAL, \App\Models\AssistantDomainStore::DOMAIN_CHECK_NOT_CHECKED, \App\Models\AssistantDomainStore::DOMAIN_CHECK_ABNORMAL] as $option)
                                <button type="button" class="btn btn-default btn-sm assistant-option-choice @if ($domainCheckChoice === $option) is-selected @endif" data-option-value="{{ $option }}">{{ $domainCheckLabels[$option] }}</button>
                            @endforeach
                        </div>
                        @error('domain_check_status')<span class="help-block">{{ $message }}</span>@enderror
                    </div>
                    <div class="form-group col-md-4 @error('email_bound') has-error @enderror">
                        <label for="email_bound">{{ $t('fields.email_bound') }} <span class="text-red">*</span></label>
                        <div class="assistant-option-group" data-option-group data-required="true">
                            <input type="hidden" name="email_bound" value="{{ (bool) $value('email_bound') ? '1' : '0' }}">
                            <button type="button" class="btn btn-default btn-sm assistant-option-choice @if ((bool) $value('email_bound')) is-selected @endif" data-option-value="1">{{ $t('common.yes') }}</button>
                            <button type="button" class="btn btn-default btn-sm assistant-option-choice @if (!(bool) $value('email_bound')) is-selected @endif" data-option-value="0">{{ $t('common.no') }}</button>
                        </div>
                        @error('email_bound')<span class="help-block">{{ $message }}</span>@enderror
                    </div>
                    <div class="form-group col-md-4">
                        <label>{{ $t('fields.email_purchased') }}</label>
                        <div class="assistant-email-purchase-status" data-email-purchase-status>
                            <span class="label label-default" data-email-purchase-label>{{ $t('email_purchase_status.'.$emailPurchaseDisplayStatus) }}</span>
                            <span class="text-muted assistant-email-purchase-hint">{{ $t('form.email_purchase_auto_hint') }}</span>
                        </div>
                    </div>
                </div>
            </div>

            <div class="box box-solid assistant-form-section assistant-form-team-section">
                <div class="box-header with-border"><h3 class="box-title">{{ $t('sections.team_usage') }}</h3></div>
                <div class="box-body row assistant-form-grid">
                    <div class="form-group col-md-4 @error('team') has-error @enderror">
                        <label for="team">{{ $t('fields.team') }}</label>
                        <div class="assistant-quick-option-group assistant-tag-input-group" data-tag-input-group data-team-quick-option-group data-quick-option-field="team">
                            <div class="assistant-quick-option-input-row assistant-tag-editor-row">
                                <div class="assistant-tag-editor" data-tag-editor data-field-name="team[]">
                                    @foreach ($teamValues as $tag)
                                        <span class="assistant-tag-chip" data-tag-value="{{ $tag }}">
                                            <span class="assistant-tag-chip-label">{{ $tag }}</span>
                                            <input type="hidden" name="team[]" value="{{ $tag }}">
                                            <button type="button" class="assistant-tag-remove" aria-label="{{ $t('javascript.remove_tag', ['name' => $tag]) }}" title="{{ $t('javascript.remove') }}"><i class="fa fa-times"></i></button>
                                        </span>
                                    @endforeach
                                    <input id="team" type="text" class="assistant-tag-entry" maxlength="100" placeholder="{{ $t('form.team_placeholder') }}">
                                </div>
                                @if ($canManageQuickOptions)
                                    <button type="button" class="btn btn-default assistant-add-team-project-quick-option"><i class="fa fa-plus"></i> {{ $t('form.add_quick_option') }}</button>
                                @endif
                            </div>
                            <div class="assistant-option-group assistant-tag-presets">
                                @foreach ($quickOptions['team'] ?? [] as $option)
                                    <span class="assistant-custom-quick-option-wrap">
                                        <button type="button" class="btn btn-default btn-sm assistant-tag-preset" data-tag-value="{{ $option }}">{{ $option }}</button>
                                        @if ($canManageQuickOptions)
                                            <button type="button" class="assistant-remove-team-project-quick-option" data-option-value="{{ $option }}" aria-label="{{ $t('form.delete_quick_option') }}" title="{{ $t('form.delete_quick_option') }}"><i class="fa fa-times"></i></button>
                                        @endif
                                    </span>
                                @endforeach
                            </div>
                        </div>
                        @error('team')<span class="help-block">{{ $message }}</span>@enderror
                        @error('team.*')<span class="help-block">{{ $message }}</span>@enderror
                    </div>
                    <div class="form-group col-md-4 @error('project') has-error @enderror">
                        <label for="project">{{ $t('fields.project') }}</label>
                        <div class="assistant-quick-option-group assistant-project-option-group" data-project-quick-option-group data-quick-option-field="project">
                            <div class="assistant-quick-option-input-row">
                                <input id="project" name="project" class="form-control" value="{{ $value('project') }}" maxlength="100">
                                @if ($canManageQuickOptions)
                                    <button type="button" class="btn btn-default assistant-add-team-project-quick-option"><i class="fa fa-plus"></i> {{ $t('form.add_quick_option') }}</button>
                                @endif
                            </div>
                            <div class="assistant-option-group assistant-project-option-presets">
                                @foreach ($quickOptions['project'] ?? [] as $option)
                                    <span class="assistant-custom-quick-option-wrap">
                                        <button type="button" class="btn btn-default btn-sm assistant-project-option-choice" data-option-value="{{ $option }}">{{ $option }}</button>
                                        @if ($canManageQuickOptions)
                                            <button type="button" class="assistant-remove-team-project-quick-option" data-option-value="{{ $option }}" aria-label="{{ $t('form.delete_quick_option') }}" title="{{ $t('form.delete_quick_option') }}"><i class="fa fa-times"></i></button>
                                        @endif
                                    </span>
                                @endforeach
                            </div>
                        </div>
                        @error('project')<span class="help-block">{{ $message }}</span>@enderror
                    </div>
                    <div class="form-group col-md-4 @error('user_name') has-error @enderror">
                        <label for="user_name">{{ $t('fields.user_name') }}</label>
                        <input id="user_name" name="user_name" class="form-control" value="{{ $value('user_name') }}" maxlength="100">
                        @error('user_name')<span class="help-block">{{ $message }}</span>@enderror
                    </div>
                </div>
            </div>

            <div class="box box-solid assistant-form-section assistant-form-system-section">
                <div class="box-header with-border"><h3 class="box-title">{{ $t('sections.system_payment') }}</h3></div>
                <div class="box-body row assistant-form-grid">
                    <div class="form-group col-md-4 assistant-system-builder @error('site_builder') has-error @enderror">
                        <label for="site_builder">{{ $t('fields.site_builder') }}</label>
                        <div class="assistant-single-option-input" data-single-option-group data-quick-option-field="site_builder">
                            <div class="assistant-quick-option-input-row">
                                <input id="site_builder" name="site_builder" class="form-control" value="{{ $value('site_builder') }}" maxlength="100" placeholder="{{ $t('form.custom_option_placeholder') }}">
                                @if ($canManageQuickOptions)
                                    <button type="button" class="btn btn-default assistant-add-quick-option"><i class="fa fa-plus"></i> {{ $t('form.add_quick_option') }}</button>
                                @endif
                            </div>
                            <div class="assistant-option-group assistant-single-option-presets">
                                @foreach ($siteBuilderOptions as $option => $label)
                                    @if (in_array($option, $quickOptions['site_builder'] ?? [], true))
                                        <span class="assistant-custom-quick-option-wrap">
                                            <button type="button" class="btn btn-default btn-sm assistant-single-option-choice" data-option-value="{{ $option }}">{{ $label }}</button>
                                            @if ($canManageQuickOptions)
                                                <button type="button" class="assistant-remove-quick-option" data-option-value="{{ $option }}" aria-label="{{ $t('form.delete_quick_option') }}" title="{{ $t('form.delete_quick_option') }}"><i class="fa fa-times"></i></button>
                                            @endif
                                        </span>
                                    @endif
                                @endforeach
                                @foreach ($quickOptions['site_builder'] ?? [] as $option)
                                    @if (!array_key_exists($option, $siteBuilderOptions))
                                        <span class="assistant-custom-quick-option-wrap">
                                            <button type="button" class="btn btn-default btn-sm assistant-single-option-choice assistant-custom-quick-option" data-option-value="{{ $option }}">{{ $option }}</button>
                                            @if ($canManageQuickOptions)
                                                <button type="button" class="assistant-remove-quick-option" data-option-value="{{ $option }}" aria-label="{{ $t('form.delete_quick_option') }}" title="{{ $t('form.delete_quick_option') }}"><i class="fa fa-times"></i></button>
                                            @endif
                                        </span>
                                    @endif
                                @endforeach
                            </div>
                        </div>
                        @error('site_builder')<span class="help-block">{{ $message }}</span>@enderror
                    </div>
                    <div class="form-group col-md-4 assistant-system-erp @error('erp') has-error @enderror">
                        <label for="erp">{{ $t('fields.erp') }}</label>
                        <div class="assistant-tag-input-group" data-tag-input-group>
                            <div class="assistant-tag-editor" data-tag-editor data-field-name="erp[]">
                                @foreach ($erpValues as $tag)
                                    <span class="assistant-tag-chip" data-tag-value="{{ $tag }}">
                                        <span class="assistant-tag-chip-label">{{ $tag }}</span>
                                        <input type="hidden" name="erp[]" value="{{ $tag }}">
                                        <button type="button" class="assistant-tag-remove" aria-label="{{ $t('javascript.remove_tag', ['name' => $tag]) }}" title="{{ $t('javascript.remove') }}"><i class="fa fa-times"></i></button>
                                    </span>
                                @endforeach
                                <input id="erp" type="text" class="assistant-tag-entry" maxlength="100" placeholder="{{ $t('form.erp_placeholder') }}">
                            </div>
                            <div class="assistant-tag-presets" aria-label="{{ $t('form.erp_presets') }}">
                            @foreach ($erpOptions as $option => $label)
                                <button type="button" class="btn btn-default btn-xs assistant-tag-preset @if (in_array($option, $erpValues, true)) is-selected @endif" data-tag-value="{{ $option }}">{{ $label }}</button>
                            @endforeach
                            </div>
                        </div>
                        @error('erp')<span class="help-block">{{ $message }}</span>@enderror
                        @error('erp.*')<span class="help-block">{{ $message }}</span>@enderror
                    </div>
                    <div class="form-group col-md-4 assistant-payment-field assistant-credit-card-field @error('credit_card') has-error @enderror">
                        <label for="credit_card">{{ $t('fields.credit_card') }}</label>
                        <div class="assistant-single-option-input" data-single-option-group data-quick-option-field="credit_card">
                            <div class="assistant-quick-option-input-row">
                                <input id="credit_card" name="credit_card" class="form-control" value="{{ $value('credit_card') }}" maxlength="100" placeholder="{{ $t('form.custom_option_placeholder') }}">
                                @if ($canManageQuickOptions)
                                    <button type="button" class="btn btn-default assistant-add-quick-option"><i class="fa fa-plus"></i> {{ $t('form.add_quick_option') }}</button>
                                @endif
                            </div>
                            <div class="assistant-option-group assistant-single-option-presets">
                                @foreach ($creditCardOptions as $option => $label)
                                    @if (in_array($option, $quickOptions['credit_card'] ?? [], true))
                                        <span class="assistant-custom-quick-option-wrap">
                                            <button type="button" class="btn btn-default btn-sm assistant-single-option-choice" data-option-value="{{ $option }}">{{ $label }}</button>
                                            @if ($canManageQuickOptions)
                                                <button type="button" class="assistant-remove-quick-option" data-option-value="{{ $option }}" aria-label="{{ $t('form.delete_quick_option') }}" title="{{ $t('form.delete_quick_option') }}"><i class="fa fa-times"></i></button>
                                            @endif
                                        </span>
                                    @endif
                                @endforeach
                                @foreach ($quickOptions['credit_card'] ?? [] as $option)
                                    @if (!array_key_exists($option, $creditCardOptions))
                                        <span class="assistant-custom-quick-option-wrap">
                                            <button type="button" class="btn btn-default btn-sm assistant-single-option-choice assistant-custom-quick-option" data-option-value="{{ $option }}">{{ $option }}</button>
                                            @if ($canManageQuickOptions)
                                                <button type="button" class="assistant-remove-quick-option" data-option-value="{{ $option }}" aria-label="{{ $t('form.delete_quick_option') }}" title="{{ $t('form.delete_quick_option') }}"><i class="fa fa-times"></i></button>
                                            @endif
                                        </span>
                                    @endif
                                @endforeach
                            </div>
                        </div>
                        @error('credit_card')<span class="help-block">{{ $message }}</span>@enderror
                    </div>
                    <div class="form-group col-md-4 assistant-payment-field @error('wallet') has-error @enderror">
                        <label for="wallet">{{ $t('fields.wallet') }}</label>
                        <div class="assistant-single-option-input" data-single-option-group data-quick-option-field="wallet">
                            <div class="assistant-quick-option-input-row">
                                <input id="wallet" name="wallet" class="form-control" value="{{ $value('wallet') }}" maxlength="100" placeholder="{{ $t('form.custom_option_placeholder') }}">
                                @if ($canManageQuickOptions)
                                    <button type="button" class="btn btn-default assistant-add-quick-option"><i class="fa fa-plus"></i> {{ $t('form.add_quick_option') }}</button>
                                @endif
                            </div>
                            <div class="assistant-option-group assistant-single-option-presets">
                                @foreach ($walletOptions as $option => $label)
                                    @if (in_array($option, $quickOptions['wallet'] ?? [], true))
                                        <span class="assistant-custom-quick-option-wrap">
                                            <button type="button" class="btn btn-default btn-sm assistant-single-option-choice" data-option-value="{{ $option }}">{{ $label }}</button>
                                            @if ($canManageQuickOptions)
                                                <button type="button" class="assistant-remove-quick-option" data-option-value="{{ $option }}" aria-label="{{ $t('form.delete_quick_option') }}" title="{{ $t('form.delete_quick_option') }}"><i class="fa fa-times"></i></button>
                                            @endif
                                        </span>
                                    @endif
                                @endforeach
                                @foreach ($quickOptions['wallet'] ?? [] as $option)
                                    @if (!array_key_exists($option, $walletOptions))
                                        <span class="assistant-custom-quick-option-wrap">
                                            <button type="button" class="btn btn-default btn-sm assistant-single-option-choice assistant-custom-quick-option" data-option-value="{{ $option }}">{{ $option }}</button>
                                            @if ($canManageQuickOptions)
                                                <button type="button" class="assistant-remove-quick-option" data-option-value="{{ $option }}" aria-label="{{ $t('form.delete_quick_option') }}" title="{{ $t('form.delete_quick_option') }}"><i class="fa fa-times"></i></button>
                                            @endif
                                        </span>
                                    @endif
                                @endforeach
                            </div>
                        </div>
                        @error('wallet')<span class="help-block">{{ $message }}</span>@enderror
                    </div>
                    <div class="form-group col-md-4 assistant-payment-field @error('paypal') has-error @enderror">
                        <label for="paypal">{{ $t('fields.paypal') }}</label>
                        <div class="assistant-single-option-input" data-single-option-group data-quick-option-field="paypal">
                            <div class="assistant-quick-option-input-row">
                                <input id="paypal" name="paypal" class="form-control" value="{{ $value('paypal') }}" maxlength="100" placeholder="{{ $t('form.custom_option_placeholder') }}">
                                @if ($canManageQuickOptions)
                                    <button type="button" class="btn btn-default assistant-add-quick-option"><i class="fa fa-plus"></i> {{ $t('form.add_quick_option') }}</button>
                                @endif
                            </div>
                            <div class="assistant-option-group assistant-single-option-presets">
                                @foreach ($paypalOptions as $option => $label)
                                    @if (in_array($option, $quickOptions['paypal'] ?? [], true))
                                        <span class="assistant-custom-quick-option-wrap">
                                            <button type="button" class="btn btn-default btn-sm assistant-single-option-choice" data-option-value="{{ $option }}">{{ $label }}</button>
                                            @if ($canManageQuickOptions)
                                                <button type="button" class="assistant-remove-quick-option" data-option-value="{{ $option }}" aria-label="{{ $t('form.delete_quick_option') }}" title="{{ $t('form.delete_quick_option') }}"><i class="fa fa-times"></i></button>
                                            @endif
                                        </span>
                                    @endif
                                @endforeach
                                @foreach ($quickOptions['paypal'] ?? [] as $option)
                                    @if (!array_key_exists($option, $paypalOptions))
                                        <span class="assistant-custom-quick-option-wrap">
                                            <button type="button" class="btn btn-default btn-sm assistant-single-option-choice assistant-custom-quick-option" data-option-value="{{ $option }}">{{ $option }}</button>
                                            @if ($canManageQuickOptions)
                                                <button type="button" class="assistant-remove-quick-option" data-option-value="{{ $option }}" aria-label="{{ $t('form.delete_quick_option') }}" title="{{ $t('form.delete_quick_option') }}"><i class="fa fa-times"></i></button>
                                            @endif
                                        </span>
                                    @endif
                                @endforeach
                            </div>
                        </div>
                        @error('paypal')<span class="help-block">{{ $message }}</span>@enderror
                    </div>
                    <div class="form-group col-md-4 assistant-payment-field @error('other_payment') has-error @enderror">
                        <label for="other_payment">{{ $t('fields.other_payment') }}</label>
                        <div class="assistant-single-option-input" data-single-option-group data-quick-option-field="other_payment">
                            <div class="assistant-quick-option-input-row">
                                <input id="other_payment" name="other_payment" class="form-control" value="{{ $value('other_payment') }}" maxlength="100" placeholder="{{ $t('form.custom_option_placeholder') }}">
                                @if ($canManageQuickOptions)
                                    <button type="button" class="btn btn-default assistant-add-quick-option"><i class="fa fa-plus"></i> {{ $t('form.add_quick_option') }}</button>
                                @endif
                            </div>
                            <div class="assistant-option-group assistant-single-option-presets">
                                @foreach ($otherPaymentOptions as $option => $label)
                                    @if (in_array($option, $quickOptions['other_payment'] ?? [], true))
                                        <span class="assistant-custom-quick-option-wrap">
                                            <button type="button" class="btn btn-default btn-sm assistant-single-option-choice" data-option-value="{{ $option }}">{{ $label }}</button>
                                            @if ($canManageQuickOptions)
                                                <button type="button" class="assistant-remove-quick-option" data-option-value="{{ $option }}" aria-label="{{ $t('form.delete_quick_option') }}" title="{{ $t('form.delete_quick_option') }}"><i class="fa fa-times"></i></button>
                                            @endif
                                        </span>
                                    @endif
                                @endforeach
                                @foreach ($quickOptions['other_payment'] ?? [] as $option)
                                    @if (!array_key_exists($option, $otherPaymentOptions))
                                        <span class="assistant-custom-quick-option-wrap">
                                            <button type="button" class="btn btn-default btn-sm assistant-single-option-choice assistant-custom-quick-option" data-option-value="{{ $option }}">{{ $option }}</button>
                                            @if ($canManageQuickOptions)
                                                <button type="button" class="assistant-remove-quick-option" data-option-value="{{ $option }}" aria-label="{{ $t('form.delete_quick_option') }}" title="{{ $t('form.delete_quick_option') }}"><i class="fa fa-times"></i></button>
                                            @endif
                                        </span>
                                    @endif
                                @endforeach
                            </div>
                        </div>
                        @error('other_payment')<span class="help-block">{{ $message }}</span>@enderror
                    </div>
                </div>
            </div>

            <div id="assistant-shopify-app-section" class="box box-solid assistant-form-section assistant-form-shopify-section" @if ((string) $value('site_builder') !== 'shopify') hidden @endif>
                <div class="box-header with-border"><h3 class="box-title">{{ $t('sections.shopify_app') }}</h3></div>
                <div class="box-body row assistant-form-grid">
                    <div class="form-group col-md-3 @error('store_prefix') has-error @enderror">
                        <label for="store_prefix">{{ $t('fields.store_prefix') }}</label>
                        <input id="store_prefix" name="store_prefix" class="form-control" value="{{ $value('store_prefix') }}" maxlength="100">
                        @error('store_prefix')<span class="help-block">{{ $message }}</span>@enderror
                    </div>
                    <div class="form-group col-md-3 @error('tongtu_prefix') has-error @enderror">
                        <label for="tongtu_prefix">{{ $t('fields.tongtu_prefix') }}</label>
                        <input id="tongtu_prefix" name="tongtu_prefix" class="form-control" value="{{ $value('tongtu_prefix') }}" maxlength="100">
                        @error('tongtu_prefix')<span class="help-block">{{ $message }}</span>@enderror
                    </div>
                    <div class="form-group col-md-3 @error('shopify_client_id') has-error @enderror">
                        <label for="shopify_client_id">{{ $t('fields.shopify_client_id') }}</label>
                        <input id="shopify_client_id" name="shopify_client_id" class="form-control" value="{{ $value('shopify_client_id') }}" maxlength="255" autocomplete="off">
                        @error('shopify_client_id')<span class="help-block">{{ $message }}</span>@enderror
                    </div>
                    <div class="form-group col-md-3 @error('shopify_client_secret') has-error @enderror">
                        <label for="shopify_client_secret">{{ $t('fields.shopify_client_secret') }}</label>
                        <input id="shopify_client_secret" name="shopify_client_secret" class="form-control" value="{{ $value('shopify_client_secret') }}" maxlength="255" autocomplete="off">
                        @error('shopify_client_secret')<span class="help-block">{{ $message }}</span>@enderror
                    </div>
                    @if (false)
                    <div class="col-md-12 text-muted small">
                        <i class="fa fa-bolt text-aqua"></i> 保存时，满足 Shopify、在用状态、登入网址、Client ID 和 Client Secret 条件的店铺会自动登记订单、订单更新及退款 Webhook；该动作不会同步历史订单。
                    </div>
                    @endif
                    <div class="col-md-12 assistant-shopify-config-guide">
                        <h4>{{ $t('form.shopify_config_guide') }}</h4>
                        <div class="row">
                            <div class="col-md-6 form-group">
                                <label>{{ $t('fields.shopify_app_url') }}</label>
                                <div class="assistant-shopify-copy-row">
                                    <input type="text" class="form-control" value="{{ $shopifyWebhookUrl }}" readonly>
                                    <button type="button" class="btn btn-default assistant-guide-copy" data-copy-value="{{ $shopifyWebhookUrl }}" data-copy-label="{{ $t('common.copy') }}" data-copied-label="{{ $t('javascript.copied') }}" data-copy-failed-label="{{ $t('javascript.copy_failed') }}"><i class="fa fa-copy"></i> {{ $t('common.copy') }}</button>
                                </div>
                            </div>
                            <div class="col-md-6 form-group">
                                <label>{{ $t('fields.shopify_redirect_url') }}</label>
                                <div class="assistant-shopify-copy-row">
                                    <input type="text" class="form-control" value="{{ $shopifyWebhookUrl }}" readonly>
                                    <button type="button" class="btn btn-default assistant-guide-copy" data-copy-value="{{ $shopifyWebhookUrl }}" data-copy-label="{{ $t('common.copy') }}" data-copied-label="{{ $t('javascript.copied') }}" data-copy-failed-label="{{ $t('javascript.copy_failed') }}"><i class="fa fa-copy"></i> {{ $t('common.copy') }}</button>
                                </div>
                            </div>
                            <div class="col-md-12 form-group">
                                <label>{{ $t('fields.shopify_access_scopes') }}</label>
                                <div class="assistant-shopify-copy-row">
                                    <input type="text" class="form-control" value="{{ $shopifyAccessScopes }}" readonly>
                                    <button type="button" class="btn btn-default assistant-guide-copy" data-copy-value="{{ $shopifyAccessScopes }}" data-copy-label="{{ $t('common.copy') }}" data-copied-label="{{ $t('javascript.copied') }}" data-copy-failed-label="{{ $t('javascript.copy_failed') }}"><i class="fa fa-copy"></i> {{ $t('common.copy') }}</button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="box box-solid assistant-form-section assistant-form-status-section">
                <div class="box-header with-border"><h3 class="box-title">{{ $t('sections.status_expiry') }}</h3></div>
                <div class="box-body row assistant-form-grid">
                    <div class="form-group col-md-4 assistant-status-field @error('shop_status') has-error @enderror">
                        <label for="shop_status">{{ $t('fields.shop_status') }} <span class="text-red">*</span></label>
                        <div class="assistant-option-group" data-option-group data-required="true">
                            <input type="hidden" name="shop_status" value="{{ $value('shop_status') }}">
                            @foreach ($shopStatusLabels as $option => $label)
                                <button type="button" class="btn btn-default btn-sm assistant-option-choice @if ((string) $value('shop_status') === (string) $option) is-selected @endif" data-option-value="{{ $option }}">{{ $label }}</button>
                            @endforeach
                        </div>
                        @error('shop_status')<span class="help-block">{{ $message }}</span>@enderror
                    </div>
                    <div class="form-group col-md-4 assistant-status-field @error('plan_expires_at') has-error @enderror">
                        <label for="plan_expires_at">{{ $t('fields.plan_expires_at') }}</label>
                        <input id="plan_expires_at" type="date" name="plan_expires_at" class="form-control" value="{{ $dateValue($value('plan_expires_at')) }}">
                        @error('plan_expires_at')<span class="help-block">{{ $message }}</span>@enderror
                    </div>
                    <div class="form-group col-md-4 assistant-status-field @error('payment_card') has-error @enderror">
                        <label for="payment_card">{{ $t('fields.payment_card') }}</label>
                        <input id="payment_card" name="payment_card" class="form-control" value="{{ $value('payment_card') }}" maxlength="255">
                        @error('payment_card')<span class="help-block">{{ $message }}</span>@enderror
                    </div>
                    <div class="form-group col-md-12 assistant-status-remark @error('remark') has-error @enderror">
                        <label for="remark">{{ $t('fields.remark') }}</label>
                        <input id="remark" name="remark" class="form-control" value="{{ $value('remark') }}" maxlength="2000">
                        @error('remark')<span class="help-block">{{ $message }}</span>@enderror
                    </div>
                </div>
            </div>

            @if ($canAssignStoreOwner)
                <div class="box box-solid assistant-form-section assistant-form-owner-section">
                    <div class="box-header with-border"><h3 class="box-title">{{ $t('sections.operation_owner') }}</h3></div>
                    <div class="box-body row assistant-form-grid">
                        <div class="form-group assistant-owner-form-field @error('operation_department_id') has-error @enderror">
                            <label for="operation_department_id">{{ $t('fields.owner_operation_department') }}</label>
                            <select id="operation_department_id" name="operation_department_id" class="form-control" data-owner-operation-department>
                                <option value="">{{ $t('form.owner_operation_department_placeholder') }}</option>
                                @foreach ($ownerOperationDepartments as $department)
                                    <option value="{{ $department->getKey() }}" @selected((int) $ownerOperationDepartmentId === (int) $department->getKey())>{{ $department->name }}</option>
                                @endforeach
                            </select>
                            @error('operation_department_id')<span class="help-block">{{ $message }}</span>@enderror
                        </div>
                        <div class="form-group assistant-owner-form-field @error('owner_user_id') has-error @enderror">
                            <label for="owner_user_id">{{ $t('fields.owner_user') }}</label>
                            <select id="owner_user_id" name="owner_user_id" class="form-control" data-owner-user @disabled($ownerOperationDepartmentId === null && empty($currentOwnerOutsideScope))>
                                <option value="">{{ $t('form.owner_user_placeholder') }}</option>
                                @if ($ownerOperationDepartmentId !== null)
                                    @foreach (($ownerCandidatesByDepartment[$ownerOperationDepartmentId] ?? []) as $candidate)
                                        <option value="{{ $candidate['id'] }}" @selected((int) $ownerUserId === (int) $candidate['id'])>
                                            {{ $candidate['name'] }}@if ($candidate['username']) ({{ $candidate['username'] }})@endif @if ($candidate['departments']) - {{ $candidate['departments'] }}@endif
                                        </option>
                                    @endforeach
                                @elseif (!empty($currentOwnerOutsideScope))
                                    <option value="{{ $currentOwnerOutsideScope['id'] }}" selected>
                                        {{ $currentOwnerOutsideScope['name'] }}@if ($currentOwnerOutsideScope['username']) ({{ $currentOwnerOutsideScope['username'] }})@endif - {{ $t('form.owner_outside_scope') }}
                                    </option>
                                @endif
                            </select>
                            @error('owner_user_id')<span class="help-block">{{ $message }}</span>@enderror
                        </div>
                        <div class="assistant-owner-form-note">
                            <i class="fa fa-info-circle" aria-hidden="true"></i>
                            <span>{{ $t('form.owner_assignment_hint') }}</span>
                        </div>
                    </div>
                </div>
            @endif

            @if ($canManageDepartmentAccess)
                <div class="box box-solid assistant-form-section assistant-form-access-section">
                    <div class="box-header with-border"><h3 class="box-title">{{ $t('sections.department_access') }}</h3></div>
                    <div class="box-body row assistant-form-grid">
                        <div class="form-group assistant-status-department-access @error('authorized_department_ids') has-error @enderror">
                            <label>{{ $t('fields.authorized_departments') }}</label>
                            <div class="assistant-option-group assistant-multi-option-group" data-multi-option-group>
                                @forelse ($assistantChildDepartments as $department)
                                    @php($departmentId = (int) $department->getKey())
                                    <button type="button" class="btn btn-default btn-sm assistant-option-choice assistant-multi-option-choice @if (in_array($departmentId, $authorizedDepartmentIds, true)) is-selected @endif" data-option-value="{{ $departmentId }}" aria-pressed="{{ in_array($departmentId, $authorizedDepartmentIds, true) ? 'true' : 'false' }}">{{ $department->name }}</button>
                                    <input type="hidden" class="assistant-multi-option-input" name="authorized_department_ids[]" value="{{ $departmentId }}" @disabled(!in_array($departmentId, $authorizedDepartmentIds, true))>
                                @empty
                                    <span class="text-muted">{{ $t('form.no_child_departments') }}</span>
                                @endforelse
                            </div>
                            <span class="help-block">{{ $t('form.authorized_departments_hint') }}</span>
                            @error('authorized_department_ids')<span class="help-block">{{ $message }}</span>@enderror
                            @error('authorized_department_ids.*')<span class="help-block">{{ $message }}</span>@enderror
                        </div>
                    </div>
                </div>
            @endif

            <div class="assistant-form-actions">
                @if ($isModalForm)
                    <button type="button" class="btn btn-default assistant-modal-cancel">{{ $t('common.cancel') }}</button>
                @else
                    <a href="{{ route('assistant.domain-stores.index') }}" class="btn btn-default">{{ $t('common.cancel') }}</a>
                @endif
                <button type="submit" class="btn btn-success" data-modal-submit><i class="fa fa-save"></i> {{ $isCreate ? $t('common.confirm_add') : $t('common.save_changes') }}</button>
            </div>
        </form>
    </section>
@endsection

@push('scripts')
    <script>
        window.ErpAssistantDomainStoreTagTranslations = @json($tagJavascriptTranslations);
        window.ErpAssistantDomainStoreQuickOptionConfig = @json($quickOptionJavascriptConfig);
        window.ErpAssistantEmailPurchaseConfig = @json($emailPurchaseJavascriptConfig);
        window.ErpAssistantDomainStoreOwnerForm = @json($ownerAssignmentJavascriptConfig);
    </script>
    <script src="{{ $portalOrigin }}/assets/erp/assistant-domain-store-tags-20261007-3.js"></script>
    <script src="{{ $portalOrigin }}/assets/erp/assistant-domain-store-form-20261008-1.js"></script>
    <script src="{{ $portalOrigin }}/assets/erp/assistant-domain-store-access-guide-20261009-1.js"></script>
    <script src="{{ $portalOrigin }}/assets/erp/assistant-domain-store-quick-options-20261008-2.js"></script>
    <script src="{{ $portalOrigin }}/assets/erp/assistant-domain-store-team-project-options-20261009-1.js"></script>
    <script src="{{ $portalOrigin }}/assets/erp/assistant-domain-store-domain-check-20261008-1.js"></script>
    <script src="{{ $portalOrigin }}/assets/erp/assistant-domain-store-email-purchase-20261013-1.js?v=20261013-1"></script>
    <script src="{{ $portalOrigin }}/assets/erp/assistant-domain-store-owner-form-20261020-1.js?v=20261021-2"></script>
@endpush
