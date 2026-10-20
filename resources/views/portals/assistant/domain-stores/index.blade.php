@extends('portal.layout')

@section('page_title', __('assistant.domain_stores.title'))

@php
    $portalOrigin = rtrim(request()->getSchemeAndHttpHost(), '/');
    $t = static fn (string $key, array $replace = []): string => __('assistant.domain_stores.'.$key, $replace);
    $isWebLink = static fn (?string $value): bool => str_starts_with(strtolower(trim((string) $value)), 'http://')
        || str_starts_with(strtolower(trim((string) $value)), 'https://');
    $hasFilters = collect($filters)
        ->except(['per_page'])
        ->contains(static fn (mixed $value): bool => $value !== '');
    $javascriptTranslations = [
        'clickToCopy' => $t('javascript.click_to_copy'),
        'copied' => $t('javascript.copied'),
        'copyFailed' => $t('javascript.copy_failed'),
        'select' => $t('common.select'),
        'selected' => $t('common.selected'),
        'selectPage' => $t('selection.select_page'),
        'unselectPage' => $t('selection.unselect_page'),
        'selectionCount' => $t('selection.count', ['count' => '__count__']),
        'selectedCount' => $t('export.selected_count', ['count' => '__count__']),
        'batchDeleteCount' => $t('selection.batch_delete_count', ['count' => '__count__']),
        'batchCheckCount' => $t('selection.batch_check_count', ['count' => '__count__']),
        'selectBeforeDelete' => $t('javascript.select_before_delete'),
        'batchDeleteConfirm' => $t('javascript.batch_delete_confirm', ['count' => '__count__']),
        'batchCheckConfirm' => $t('javascript.batch_check_confirm', ['count' => '__count__']),
        'batchCheckInProgress' => $t('javascript.batch_check_in_progress', ['count' => '__count__']),
        'batchAccessSelectionCount' => $t('javascript.batch_access_selection_count', ['count' => '__count__']),
        'batchCheckMaxRecords' => $t('domain_check.max_records'),
        'selectBeforeExport' => $t('javascript.select_before_export'),
        'historyModalTitle' => $t('history.modal_title', ['name' => '__name__']),
        'historyLoading' => $t('javascript.history_loading'),
        'historyEmpty' => $t('javascript.history_empty'),
        'historyLoadFailed' => $t('javascript.history_load_failed'),
        'expand' => $t('history.expand'),
        'collapse' => $t('history.collapse'),
        'deleteConfirm' => $t('javascript.delete_confirm', ['name' => '__name__']),
        'selectedFile' => $t('import.selected_file', ['name' => '__name__']),
    ];
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
    $modalJavascriptTranslations = [
        'loading' => $t('javascript.modal_loading'),
        'loadFailed' => $t('javascript.modal_load_failed'),
        'saveFailed' => $t('javascript.modal_save_failed'),
        'discardChanges' => $t('javascript.modal_discard_changes'),
        'saving' => $t('javascript.modal_saving'),
        'successTitle' => $t('messages.success_title'),
        'errorTitle' => $t('messages.error_title'),
    ];
    $batchUpdateJavascriptTranslations = [
        'selectionCount' => $t('javascript.batch_update_selection_count', ['count' => '__count__']),
        'selectBeforeUpdate' => $t('javascript.select_before_batch_update'),
        'maxRecords' => $t('batch_update.max_records', ['count' => 20]),
        'chooseFields' => $t('batch_update.choose_fields'),
        'chooseFieldsNotice' => $t('batch_update.choose_fields_notice'),
        'continueToEdit' => $t('batch_update.continue_to_edit'),
        'selectedFields' => $t('batch_update.selected_fields', ['count' => '__count__']),
        'backToFields' => $t('batch_update.back_to_fields'),
        'loading' => $t('javascript.modal_loading'),
        'loadFailed' => $t('javascript.modal_load_failed'),
        'saveFailed' => $t('javascript.modal_save_failed'),
        'saving' => $t('javascript.modal_saving'),
        'discardChanges' => $t('javascript.modal_discard_changes'),
        'saveAll' => $t('batch_update.save_all'),
        'cancel' => $t('common.cancel'),
        'successTitle' => $t('messages.success_title'),
        'errorTitle' => $t('messages.error_title'),
    ];
    $ownershipJavascriptConfig = [
        'optionsUrl' => route('assistant.domain-stores.owners.options'),
        'translations' => [
            'selectionCount' => $t('javascript.owner_selection_count', ['count' => '__count__']),
            'loading' => $t('javascript.owner_candidates_loading'),
            'loadFailed' => $t('javascript.owner_candidates_failed'),
            'empty' => $t('javascript.owner_candidates_empty'),
            'chooseOwner' => $t('javascript.owner_choose_required'),
            'chooseDepartmentFirst' => $t('javascript.owner_department_required'),
            'outsideScope' => $t('javascript.owner_outside_scope_warning', ['count' => '__count__']),
            'clearConfirm' => $t('javascript.owner_clear_confirm', ['count' => '__count__']),
            'scopeSaving' => $t('javascript.owner_scope_saving'),
        ],
    ];
    $batchUpdateFieldGroups = [
        [
            'label' => $t('sections.shop_domain'),
            'fields' => [
                ['key' => 'shop_name', 'label' => $t('fields.shop_name')],
                ['key' => 'domain', 'label' => $t('fields.domain')],
                ['key' => 'shop_login_url', 'label' => $t('fields.shop_login_url')],
                ['key' => 'email', 'label' => $t('fields.email')],
                ['key' => 'domain_expires_at', 'label' => $t('fields.domain_expires_at')],
                ['key' => 'domain_check_status', 'label' => $t('fields.domain_check_status')],
                ['key' => 'email_bound', 'label' => $t('fields.email_bound')],
            ],
        ],
        [
            'label' => $t('sections.team_usage'),
            'fields' => [
                ['key' => 'team', 'label' => $t('fields.team')],
                ['key' => 'project', 'label' => $t('fields.project')],
                ['key' => 'user_name', 'label' => $t('fields.user_name')],
            ],
        ],
        [
            'label' => $t('sections.system_payment'),
            'fields' => [
                ['key' => 'site_builder', 'label' => $t('fields.site_builder')],
                ['key' => 'erp', 'label' => $t('fields.erp')],
                ['key' => 'credit_card', 'label' => $t('fields.credit_card')],
                ['key' => 'wallet', 'label' => $t('fields.wallet')],
                ['key' => 'paypal', 'label' => $t('fields.paypal')],
                ['key' => 'other_payment', 'label' => $t('fields.other_payment')],
                ['key' => 'payment_card', 'label' => $t('fields.payment_card')],
            ],
        ],
        [
            'label' => $t('sections.shopify_app'),
            'fields' => [
                ['key' => 'store_prefix', 'label' => $t('fields.store_prefix')],
                ['key' => 'tongtu_prefix', 'label' => $t('fields.tongtu_prefix')],
                ['key' => 'shopify_client_id', 'label' => $t('fields.shopify_client_id')],
                ['key' => 'shopify_client_secret', 'label' => $t('fields.shopify_client_secret')],
            ],
        ],
        [
            'label' => $t('sections.status_expiry'),
            'fields' => [
                ['key' => 'shop_status', 'label' => $t('fields.shop_status')],
                ['key' => 'plan_expires_at', 'label' => $t('fields.plan_expires_at')],
                ['key' => 'remark', 'label' => $t('fields.remark')],
            ],
        ],
    ];
@endphp

@push('styles')
    <link rel="stylesheet" href="{{ $portalOrigin }}/assets/erp/assistant-domain-stores-20261008-5.css">
    <link rel="stylesheet" href="{{ $portalOrigin }}/assets/erp/assistant-domain-stores-index-20261009-1.css">
    <link rel="stylesheet" href="{{ $portalOrigin }}/assets/erp/assistant-domain-stores-20261010-2.css?v=20261011-1">
    <link rel="stylesheet" href="{{ $portalOrigin }}/assets/erp/assistant-domain-stores-batch-editor-20261010-1.css">
    <link rel="stylesheet" href="{{ $portalOrigin }}/assets/erp/assistant-domain-stores-batch-field-select-20261010-1.css">
    <link rel="stylesheet" href="{{ $portalOrigin }}/assets/erp/assistant-domain-stores-batch-expanded-20261010-1.css?v=20261010-2">
    <link rel="stylesheet" href="{{ $portalOrigin }}/assets/erp/assistant-domain-stores-modal-20261009-1.css">
    <link rel="stylesheet" href="{{ $portalOrigin }}/assets/erp/assistant-domain-stores-ownership-20261020-1.css?v=20261020-2">
@endpush

@section('content')
    <section class="content-header">
        <h1>{{ $t('title') }} <small>{{ $t('subtitle') }}</small></h1>
    </section>

    <section class="content assistant-domain-stores-page">
        @if ($errors->has('import_file') || $errors->has('scope') || $errors->has('department_ids') || $errors->has('selected_ids') || $errors->has('owner_department_id') || $errors->has('owner_user_id') || $errors->has('scopes') || $errors->batchUpdate->any())
            <div class="alert alert-danger {{ $errors->has('import_file') ? 'assistant-import-errors' : ($errors->has('department_ids') ? 'assistant-batch-access-errors' : ($errors->batchUpdate->any() ? 'assistant-batch-update-errors' : 'assistant-export-errors')) }}" role="alert">
                {{ $errors->first('import_file') ?: $errors->first('department_ids') ?: $errors->first('selected_ids') ?: $errors->first('owner_department_id') ?: $errors->first('owner_user_id') ?: $errors->first('scopes') ?: $errors->batchUpdate->first() ?: $errors->first('scope') }}
            </div>
        @endif

        @if (session('import_errors'))
            @php
                $importErrors = collect(session('import_errors'));
            @endphp
            <div class="alert alert-warning" role="alert">
                <strong>{{ $t('import.errors_title', ['count' => $importErrors->count()]) }}</strong>
                <div class="assistant-import-error-list" role="list">
                    @foreach ($importErrors->take(20) as $error)
                        <div class="assistant-import-error-card" role="listitem">
                            <div class="assistant-import-error-row">{{ $t('import.row', ['row' => $error['row']]) }}</div>
                            @foreach (($error['issues'] ?? [['field' => $t('import.data_field'), 'value' => $t('common.empty'), 'reason' => $error['message'] ?? $t('validation.invalid'), 'hint' => null]]) as $issue)
                                <div class="assistant-import-error-issue">
                                    <strong>{{ $issue['field'] }}</strong>
                                    <span class="assistant-import-error-value">{{ $t('import.value', ['value' => $issue['value']]) }}</span>
                                    <span class="assistant-import-error-reason">{{ $t('import.reason', ['reason' => $issue['reason']]) }}</span>
                                    @if (!empty($issue['hint']))
                                        <span class="assistant-import-error-hint">{{ $issue['hint'] }}</span>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    @endforeach
                </div>
                @if ($importErrors->count() > 20)
                    <span>{{ $t('import.remaining_errors', ['count' => $importErrors->count() - 20]) }}</span>
                @endif
            </div>
        @endif

        <div class="box box-solid assistant-domain-stores-filter">
            <div class="box-body">
                <form method="get" action="{{ route('assistant.domain-stores.index') }}" class="form-inline" data-loading="region" data-loading-target=".assistant-domain-stores-list">
                    <input type="hidden" name="per_page" value="{{ $filters['per_page'] }}">
                    <div class="form-group">
                        <label class="sr-only" for="assistant-store-keyword">{{ $t('common.search') }}</label>
                        <input id="assistant-store-keyword" class="form-control" type="search" name="keyword" value="{{ $filters['keyword'] }}" placeholder="{{ $t('filters.keyword_placeholder') }}">
                    </div>
                    <button type="submit" class="btn btn-default"><i class="fa fa-search"></i> {{ $t('common.search') }}</button>
                    <input id="assistant-advanced-filters-toggle" class="assistant-advanced-filters-toggle" type="checkbox" checked>
                    <label for="assistant-advanced-filters-toggle" class="btn btn-default assistant-advanced-filters-toggle-button"><i class="fa fa-sliders"></i> {{ $t('common.more_filters') }}</label>
                    @if ($hasFilters)
                        <a href="{{ route('assistant.domain-stores.index', ['per_page' => $filters['per_page']]) }}" class="btn btn-link" data-loading="region" data-loading-target=".assistant-domain-stores-list">{{ $t('common.clear_filters') }}</a>
                    @endif
                    @if ($canManage)
                        <div class="assistant-batch-actions pull-right">
                            @if ($canSyncEmailPurchases)
                                <button type="submit" form="assistant-email-purchase-sync-form" class="btn btn-info"><i class="fa fa-envelope"></i> {{ $t('common.sync_email_purchases') }}</button>
                            @endif
                            <button type="button" class="btn btn-default" data-toggle="modal" data-target="#assistant-export-modal"><i class="fa fa-download"></i> {{ $t('common.export') }}</button>
                            <button type="button" class="btn btn-default" data-toggle="modal" data-target="#assistant-import-modal"><i class="fa fa-upload"></i> {{ $t('common.import') }}</button>
                            <a href="{{ route('assistant.domain-stores.create') }}" class="btn btn-success assistant-editor-trigger" data-loading="off" data-editor-title="{{ $t('create_title') }}"><i class="fa fa-plus"></i> {{ $t('common.add') }}</a>
                        </div>
                    @endif
                    <div class="assistant-advanced-filters">
                        <div class="assistant-advanced-filter-grid">
                            <div class="assistant-advanced-filter-field assistant-advanced-filter-date-range">
                                <label>{{ $t('fields.date') }}</label>
                                <div><input type="date" class="form-control" name="created_from" value="{{ $filters['created_from'] }}" aria-label="{{ $t('filters.range_start', ['field' => $t('fields.date')]) }}"><span>{{ $t('common.from') }}</span><input type="date" class="form-control" name="created_to" value="{{ $filters['created_to'] }}" aria-label="{{ $t('filters.range_end', ['field' => $t('fields.date')]) }}"></div>
                            </div>
                            <div class="assistant-advanced-filter-field"><label for="filter-shop-status">{{ $t('fields.shop_status') }}</label><select id="filter-shop-status" class="form-control" name="shop_status"><option value="">{{ $t('common.all') }}</option>@foreach ($shopStatusLabels as $value => $label)<option value="{{ $value }}" @selected($filters['shop_status'] === $value)>{{ $label }}</option>@endforeach</select></div>
                            <div class="assistant-advanced-filter-field"><label for="filter-domain-check">{{ $t('fields.domain_check') }}</label><select id="filter-domain-check" class="form-control" name="domain_check_status"><option value="">{{ $t('common.all') }}</option>@foreach ($domainCheckLabels as $value => $label)<option value="{{ $value }}" @selected($filters['domain_check_status'] === $value)>{{ $label }}</option>@endforeach</select></div>
                            @if ($canManageDepartmentAccess)
                                <div class="assistant-advanced-filter-field"><label for="filter-department">{{ $t('filters.department') }}</label><select id="filter-department" class="form-control" name="department_id"><option value="">{{ $t('filters.all_departments') }}</option>@foreach ($assistantChildDepartments as $department)<option value="{{ $department->getKey() }}" @selected($filters['department_id'] === (string) $department->getKey())>{{ $department->name }}</option>@endforeach</select></div>
                            @endif
                            <div class="assistant-advanced-filter-field"><label for="filter-site-builder">{{ $t('fields.site_builder') }}</label><input id="filter-site-builder" class="form-control" name="site_builder" value="{{ $filters['site_builder'] }}" list="filter-site-builder-options"><datalist id="filter-site-builder-options">@foreach ($quickOptions['site_builder'] ?? [] as $value)<option value="{{ $value }}">{{ $siteBuilderOptions[$value] ?? $value }}</option>@endforeach</datalist></div>
                            <div class="assistant-advanced-filter-field"><label for="filter-shop-name">{{ $t('fields.shop_name') }}</label><input id="filter-shop-name" class="form-control" name="shop_name" value="{{ $filters['shop_name'] }}"></div>
                            <div class="assistant-advanced-filter-field"><label for="filter-domain">{{ $t('fields.domain') }}</label><input id="filter-domain" class="form-control" name="domain" value="{{ $filters['domain'] }}"></div>
                            <div class="assistant-advanced-filter-field assistant-advanced-filter-date-range">
                                <label>{{ $t('fields.domain_expires_at') }}</label>
                                <div><input type="date" class="form-control" name="domain_expires_from" value="{{ $filters['domain_expires_from'] }}" aria-label="{{ $t('filters.range_start', ['field' => $t('fields.domain_expires_at')]) }}"><span>{{ $t('common.from') }}</span><input type="date" class="form-control" name="domain_expires_to" value="{{ $filters['domain_expires_to'] }}" aria-label="{{ $t('filters.range_end', ['field' => $t('fields.domain_expires_at')]) }}"></div>
                            </div>
                            <div class="assistant-advanced-filter-field"><label for="filter-email">{{ $t('fields.email') }}</label><input id="filter-email" class="form-control" name="email" value="{{ $filters['email'] }}"></div>
                            <div class="assistant-advanced-filter-field"><label for="filter-email-bound">{{ $t('fields.email_binding') }}</label><select id="filter-email-bound" class="form-control" name="email_bound"><option value="">{{ $t('common.all') }}</option><option value="1" @selected($filters['email_bound'] === '1')>{{ $t('common.yes') }}</option><option value="0" @selected($filters['email_bound'] === '0')>{{ $t('common.no') }}</option></select></div>
                            <div class="assistant-advanced-filter-field"><label for="filter-email-purchased">{{ $t('fields.email_purchase') }}</label><select id="filter-email-purchased" class="form-control" name="email_purchased"><option value="">{{ $t('common.all') }}</option><option value="1" @selected($filters['email_purchased'] === '1')>{{ $t('common.yes') }}</option><option value="0" @selected($filters['email_purchased'] === '0')>{{ $t('common.no') }}</option></select></div>
                            <div class="assistant-advanced-filter-field"><label for="filter-team">{{ $t('fields.team') }}</label><input id="filter-team" class="form-control" name="team" value="{{ $filters['team'] }}"></div>
                            <div class="assistant-advanced-filter-field"><label for="filter-project">{{ $t('fields.project') }}</label><input id="filter-project" class="form-control" name="project" value="{{ $filters['project'] }}"></div>
                            <div class="assistant-advanced-filter-field"><label for="filter-user-name">{{ $t('fields.user_short') }}</label><input id="filter-user-name" class="form-control" name="user_name" value="{{ $filters['user_name'] }}"></div>
                            <div class="assistant-advanced-filter-field"><label for="filter-owner-user">{{ $t('fields.owner_user') }}</label><input id="filter-owner-user" class="form-control" name="owner_user" value="{{ $filters['owner_user'] }}"></div>
                            <div class="assistant-advanced-filter-field"><label for="filter-erp">{{ $t('fields.erp') }}</label><input id="filter-erp" class="form-control" name="erp" value="{{ $filters['erp'] }}"></div>
                            <div class="assistant-advanced-filter-field"><label for="filter-credit-card">{{ $t('fields.credit_card') }}</label><input id="filter-credit-card" class="form-control" name="credit_card" value="{{ $filters['credit_card'] }}" list="filter-credit-card-options"><datalist id="filter-credit-card-options">@foreach ($quickOptions['credit_card'] ?? [] as $value)<option value="{{ $value }}">{{ $creditCardOptions[$value] ?? $value }}</option>@endforeach</datalist></div>
                            <div class="assistant-advanced-filter-field"><label for="filter-wallet">{{ $t('fields.wallet') }}</label><input id="filter-wallet" class="form-control" name="wallet" value="{{ $filters['wallet'] }}" list="filter-wallet-options"><datalist id="filter-wallet-options">@foreach ($quickOptions['wallet'] ?? [] as $value)<option value="{{ $value }}">{{ $walletOptions[$value] ?? $value }}</option>@endforeach</datalist></div>
                            <div class="assistant-advanced-filter-field"><label for="filter-paypal">{{ $t('fields.paypal') }}</label><input id="filter-paypal" class="form-control" name="paypal" value="{{ $filters['paypal'] }}" list="filter-paypal-options"><datalist id="filter-paypal-options">@foreach ($quickOptions['paypal'] ?? [] as $value)<option value="{{ $value }}">{{ $paypalOptions[$value] ?? $value }}</option>@endforeach</datalist></div>
                            <div class="assistant-advanced-filter-field"><label for="filter-other-payment">{{ $t('fields.other_payment') }}</label><input id="filter-other-payment" class="form-control" name="other_payment" value="{{ $filters['other_payment'] }}" list="filter-other-payment-options"><datalist id="filter-other-payment-options">@foreach ($quickOptions['other_payment'] ?? [] as $value)<option value="{{ $value }}">{{ $otherPaymentOptions[$value] ?? $value }}</option>@endforeach</datalist></div>
                            <div class="assistant-advanced-filter-field"><label for="filter-login-url">{{ $t('fields.shop_login_url') }}</label><input id="filter-login-url" class="form-control" name="shop_login_url" value="{{ $filters['shop_login_url'] }}"></div>
                            <div class="assistant-advanced-filter-field assistant-advanced-filter-date-range">
                                <label>{{ $t('fields.plan_expires_at') }}</label>
                                <div><input type="date" class="form-control" name="plan_expires_from" value="{{ $filters['plan_expires_from'] }}" aria-label="{{ $t('filters.range_start', ['field' => $t('fields.plan_expires_at')]) }}"><span>{{ $t('common.from') }}</span><input type="date" class="form-control" name="plan_expires_to" value="{{ $filters['plan_expires_to'] }}" aria-label="{{ $t('filters.range_end', ['field' => $t('fields.plan_expires_at')]) }}"></div>
                            </div>
                            <div class="assistant-advanced-filter-field"><label for="filter-payment-card">{{ $t('fields.payment_card_short') }}</label><input id="filter-payment-card" class="form-control" name="payment_card" value="{{ $filters['payment_card'] }}"></div>
                            <div class="assistant-advanced-filter-field"><label for="filter-last-operator">{{ $t('fields.last_operator') }}</label><input id="filter-last-operator" class="form-control" name="last_operator_name" value="{{ $filters['last_operator_name'] }}"></div>
                            <div class="assistant-advanced-filter-field assistant-advanced-filter-date-range">
                                <label>{{ $t('fields.operation_time') }}</label>
                                <div><input type="date" class="form-control" name="updated_from" value="{{ $filters['updated_from'] }}" aria-label="{{ $t('filters.range_start', ['field' => $t('fields.operation_time')]) }}"><span>{{ $t('common.from') }}</span><input type="date" class="form-control" name="updated_to" value="{{ $filters['updated_to'] }}" aria-label="{{ $t('filters.range_end', ['field' => $t('fields.operation_time')]) }}"></div>
                            </div>
                            <div class="assistant-advanced-filter-field assistant-advanced-filter-wide"><label for="filter-remark">{{ $t('fields.remark') }}</label><input id="filter-remark" class="form-control" name="remark" value="{{ $filters['remark'] }}"></div>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        @if ($canSyncEmailPurchases)
            <form id="assistant-email-purchase-sync-form" method="post" action="{{ route('assistant.domain-stores.email-purchases.sync') }}" data-loading="button">
                @csrf
                <input type="hidden" name="return_to" value="{{ request()->fullUrl() }}">
            </form>
        @endif

        <div class="assistant-list-utility-bar">
            <div class="assistant-selection-summary">
                @if ($canManage)
                    <span id="assistant-current-selection-count" data-count-template="{{ $t('selection.count', ['count' => '__count__']) }}">{{ $t('selection.count', ['count' => 0]) }}</span>
                    <button type="button" class="btn btn-primary btn-sm" id="assistant-batch-update-trigger" data-toggle="modal" data-target="#assistant-batch-update-modal" data-batch-editor-url="{{ route('assistant.domain-stores.batch-edit') }}" hidden><i class="fa fa-pencil-square-o"></i> {{ $t('selection.batch_update') }}</button>
                    <form method="post" action="{{ route('assistant.domain-stores.check-batch') }}" class="assistant-batch-check-form" id="assistant-batch-check-form" hidden>
                        @csrf
                        <span id="assistant-batch-check-inputs"></span>
                        <button type="submit" class="btn btn-info btn-sm" id="assistant-batch-check-button"><i class="fa fa-refresh"></i> {{ $t('selection.batch_check') }}</button>
                    </form>
                    <form method="post" action="{{ route('assistant.domain-stores.destroy-batch') }}" class="assistant-batch-delete-form" id="assistant-batch-delete-form" hidden>
                        @csrf
                        @method('DELETE')
                        <span id="assistant-batch-delete-inputs"></span>
                        <button type="submit" class="btn btn-danger btn-sm" id="assistant-batch-delete-button"><i class="fa fa-trash"></i> {{ $t('selection.batch_delete') }}</button>
                    </form>
                    @if ($canManageDepartmentAccess)
                        <button type="button" class="btn btn-primary btn-sm assistant-batch-access-trigger" id="assistant-batch-access-trigger" data-toggle="modal" data-target="#assistant-batch-access-modal" hidden><i class="fa fa-share-alt"></i> {{ $t('selection.batch_manage_access') }}</button>
                    @endif
                    @if ($canManageStoreOwnership)
                        <button type="button" class="btn btn-success btn-sm" id="assistant-batch-owner-trigger" data-toggle="modal" data-target="#assistant-batch-owner-modal" hidden><i class="fa fa-user"></i> {{ $t('selection.batch_manage_owner') }}</button>
                        <button type="button" class="btn btn-default btn-sm" data-toggle="modal" data-target="#assistant-owner-scope-modal"><i class="fa fa-sitemap"></i> {{ $t('selection.configure_owner_scopes') }}</button>
                    @endif
                @else
                    <span class="text-muted"><i class="fa fa-eye"></i> {{ $t('selection.readonly') }}</span>
                @endif
            </div>
            <div class="assistant-list-top-pagination">
                @include('portals.assistant.domain-stores.partials.pagination', ['stores' => $stores, 'filters' => $filters])
            </div>
        </div>

        <div class="box box-solid assistant-domain-stores-list">
            <div class="box-body no-padding table-responsive">
                <table class="table table-hover assistant-domain-stores-table">
                    <colgroup>
                        <col class="assistant-selection-column" data-column-key="selection">
                        <col class="assistant-date-column" data-column-key="created_at">
                        <col class="assistant-site-builder-column" data-column-key="site_builder">
                        <col class="assistant-shop-column" data-column-key="shop_name">
                        <col class="assistant-domain-column" data-column-key="domain">
                        <col class="assistant-date-column" data-column-key="domain_expires_at">
                        <col class="assistant-domain-check-column" data-column-key="domain_check_status">
                        <col class="assistant-email-column" data-column-key="email">
                        <col class="assistant-boolean-column" data-column-key="email_bound">
                        <col class="assistant-boolean-column" data-column-key="email_purchased">
                        <col class="assistant-team-column" data-column-key="team">
                        <col class="assistant-project-column" data-column-key="project">
                        <col class="assistant-user-column" data-column-key="user_name">
                        <col class="assistant-owner-user-column" data-column-key="owner_user">
                        <col class="assistant-erp-column" data-column-key="erp">
                        <col class="assistant-payment-column" data-column-key="credit_card">
                        <col class="assistant-payment-column" data-column-key="wallet">
                        <col class="assistant-payment-column" data-column-key="paypal">
                        <col class="assistant-payment-column" data-column-key="other_payment">
                        <col class="assistant-login-url-column" data-column-key="shop_login_url">
                        <col class="assistant-shop-status-column" data-column-key="shop_status">
                        <col class="assistant-date-column" data-column-key="plan_expires_at">
                        <col class="assistant-payment-card-column" data-column-key="payment_card">
                        <col class="assistant-operator-column" data-column-key="last_operator_name">
                        <col class="assistant-operation-time-column" data-column-key="updated_at">
                        <col class="assistant-action-column" data-column-key="actions">
                    </colgroup>
                    <thead>
                    <tr>
                        <th class="assistant-selection-column" data-column-key="selection" data-column-resizable="false">
                            @if ($canManage)
                                <button type="button" class="btn btn-default btn-xs assistant-select-all assistant-table-select-all" aria-pressed="false" aria-label="{{ $t('selection.select_page') }}">{{ $t('selection.select_page') }}</button>
                            @endif
                        </th>
                        <th class="assistant-date-column" data-column-key="created_at">{{ $t('fields.date') }}</th>
                        <th class="assistant-site-builder-column" data-column-key="site_builder">{{ $t('fields.site_builder') }}</th>
                        <th class="assistant-shop-column" data-column-key="shop_name">{{ $t('fields.shop_name') }}</th>
                        <th class="assistant-domain-column" data-column-key="domain">{{ $t('fields.domain') }}</th>
                        <th class="assistant-date-column" data-column-key="domain_expires_at">{{ $t('fields.domain_expires_at') }}</th>
                        <th class="assistant-domain-check-column" data-column-key="domain_check_status">{{ $t('fields.domain_check') }}</th>
                        <th class="assistant-email-column" data-column-key="email">{{ $t('fields.email') }}</th>
                        <th class="assistant-boolean-column" data-column-key="email_bound">{{ $t('fields.email_binding') }}</th>
                        <th class="assistant-boolean-column" data-column-key="email_purchased">{{ $t('fields.email_purchase') }}</th>
                        <th class="assistant-team-column" data-column-key="team">{{ $t('fields.team') }}</th>
                        <th class="assistant-project-column" data-column-key="project">{{ $t('fields.project') }}</th>
                        <th class="assistant-user-column" data-column-key="user_name">{{ $t('fields.user_short') }}</th>
                        <th class="assistant-owner-user-column" data-column-key="owner_user">{{ $t('fields.owner_user') }}</th>
                        <th class="assistant-erp-column" data-column-key="erp">{{ $t('fields.erp') }}</th>
                        <th class="assistant-payment-column" data-column-key="credit_card">{{ $t('fields.credit_card') }}</th>
                        <th class="assistant-payment-column" data-column-key="wallet">{{ $t('fields.wallet') }}</th>
                        <th class="assistant-payment-column" data-column-key="paypal">{{ $t('fields.paypal') }}</th>
                        <th class="assistant-payment-column" data-column-key="other_payment">{{ $t('fields.other_payment') }}</th>
                        <th class="assistant-login-url-column" data-column-key="shop_login_url">{{ $t('fields.login_url_short') }}</th>
                        <th class="assistant-shop-status-column" data-column-key="shop_status">{{ $t('fields.shop_status') }}</th>
                        <th class="assistant-date-column" data-column-key="plan_expires_at">{{ $t('fields.plan_expires_short') }}</th>
                        <th class="assistant-payment-card-column" data-column-key="payment_card">{{ $t('fields.payment_card_short') }}</th>
                        <th class="assistant-operator-column" data-column-key="last_operator_name">{{ $t('fields.last_operator') }}</th>
                        <th class="assistant-operation-time-column" data-column-key="updated_at">{{ $t('fields.operation_time') }}</th>
                        <th class="assistant-action-column" data-column-key="actions" data-column-resizable="false">{{ $t('fields.action') }}</th>
                    </tr>
                    </thead>
                    <tbody>
                    @forelse ($stores as $store)
                        <tr class="assistant-store-row">
                            <td class="assistant-selection-column assistant-record-selection-cell" rowspan="2">
                                @if ($canManage)
                                    <button type="button" class="btn btn-default btn-xs assistant-row-selector" data-store-id="{{ $store->getKey() }}" aria-pressed="false" aria-label="{{ $t('selection.select_record', ['name' => $store->shop_name]) }}">{{ $t('common.select') }}</button>
                                @endif
                            </td>
                            <td><span class="assistant-field-value">{{ $store->created_at?->timezone('Asia/Shanghai')->format('Y-m-d') }}</span></td>
                            <td><span class="assistant-field-value">{{ $store->site_builder ? ($siteBuilderOptions[$store->site_builder] ?? $store->site_builder) : $t('common.empty') }}</span></td>
                            <td><span class="assistant-field-value assistant-field-strong">{{ $store->shop_name }}</span></td>
                            <td><span class="assistant-field-value assistant-truncate-value">{{ $store->domain ?: $t('common.empty') }}</span></td>
                            <td><span class="assistant-field-value">{{ $store->domain_expires_at?->format('Y-m-d') ?: $t('common.empty') }}</span></td>
                            <td><span class="assistant-field-value"><span class="label {{ \App\Models\AssistantDomainStore::domainCheckStatusClass($store->domain_check_status) }}">{{ $domainCheckLabels[$store->domain_check_status] ?? $store->domain_check_status }}</span></span></td>
                            <td><span class="assistant-field-value">{{ $store->email ?: $t('common.empty') }}</span></td>
                            <td><span class="assistant-field-value"><span class="label {{ $store->email_bound ? 'label-success' : 'label-default' }}">{{ $store->email_bound ? $t('common.yes') : $t('common.no') }}</span></span></td>
                            @php($emailPurchaseStatus = $store->email_purchase_sync_status ?? ($store->email_purchased ? 'current' : 'never'))
                            <td><span class="assistant-field-value"><span class="label {{ $emailPurchaseStatus === 'current' ? 'label-info' : ($emailPurchaseStatus === 'missing' ? 'label-warning' : 'label-default') }}">{{ $t('email_purchase_status.'.$emailPurchaseStatus) }}</span></span></td>
                            <td><span class="assistant-field-value">{{ \App\Models\AssistantDomainStore::displayMultiValue($store->team) ?: $t('common.empty') }}</span></td>
                            <td><span class="assistant-field-value">{{ $store->project ?: $t('common.empty') }}</span></td>
                            <td><span class="assistant-field-value">{{ $store->user_name ?: $t('common.empty') }}</span></td>
                            <td>
                                <span class="assistant-field-value">
                                    {{ $store->ownerUser?->name ?: $store->ownerUser?->username ?: $t('common.empty') }}
                                    @if ($store->ownerUser && $store->ownerDepartment)
                                        <small class="assistant-owner-department">{{ $store->ownerDepartment->name }}</small>
                                    @endif
                                    @if (in_array((int) $store->getKey(), $ownerScopeWarningIds, true))
                                        <small class="assistant-owner-scope-warning" title="{{ $t('form.owner_outside_scope') }}"><i class="fa fa-exclamation-triangle"></i> {{ $t('form.owner_outside_scope') }}</small>
                                    @endif
                                </span>
                            </td>
                            <td><span class="assistant-field-value">{{ \App\Models\AssistantDomainStore::displayMultiValue($store->erp) ?: $t('common.empty') }}</span></td>
                            <td><span class="assistant-field-value">{{ $store->credit_card ? ($creditCardOptions[$store->credit_card] ?? $store->credit_card) : $t('common.empty') }}</span></td>
                            <td><span class="assistant-field-value">{{ $store->wallet ? ($walletOptions[$store->wallet] ?? $store->wallet) : $t('common.empty') }}</span></td>
                            <td><span class="assistant-field-value">{{ $store->paypal ? ($paypalOptions[$store->paypal] ?? $store->paypal) : $t('common.empty') }}</span></td>
                            <td><span class="assistant-field-value">{{ $store->other_payment ? ($otherPaymentOptions[$store->other_payment] ?? $store->other_payment) : $t('common.empty') }}</span></td>
                            <td>
                                <span class="assistant-field-value assistant-url-value assistant-truncate-value">
                                    @if ($store->shop_login_url)
                                        @if ($isWebLink($store->shop_login_url))
                                            <a href="{{ $store->shop_login_url }}" target="_blank" rel="noopener noreferrer">{{ $store->shop_login_url }}</a>
                                        @else
                                            {{ $store->shop_login_url }}
                                        @endif
                                    @else
                                        {{ $t('common.empty') }}
                                    @endif
                                </span>
                            </td>
                            <td><span class="assistant-field-value"><span class="label {{ $store->shop_status === 'in_use' ? 'label-success' : ($store->shop_status === 'stopped' ? 'label-danger' : 'label-default') }}">{{ $shopStatusLabels[$store->shop_status] ?? $store->shop_status }}</span></span></td>
                            <td><span class="assistant-field-value">{{ $store->plan_expires_at?->format('Y-m-d') ?: $t('common.empty') }}</span></td>
                            <td><span class="assistant-field-value">{{ $store->payment_card ?: $t('common.empty') }}</span></td>
                            <td><span class="assistant-field-value">{{ $store->last_operator_name ?: $t('common.empty') }}</span></td>
                            <td><span class="assistant-field-value">{{ $store->updated_at?->timezone('Asia/Shanghai')->format('Y-m-d H:i') }}</span></td>
                            <td class="assistant-actions">
                                <button type="button" class="btn btn-default btn-xs assistant-history-trigger" data-toggle="modal" data-target="#assistant-history-modal" data-history-url="{{ route('assistant.domain-stores.history', $store) }}" data-shop-name="{{ $store->shop_name }}"><i class="fa fa-history"></i> {{ $t('common.history') }}</button>
                                @if ($canManage)
                                    <a href="{{ route('assistant.domain-stores.edit', $store) }}" class="btn btn-primary btn-xs assistant-editor-trigger" data-loading="off" data-editor-title="{{ $t('edit_title') }} · {{ $store->shop_name }}"><i class="fa fa-pencil"></i> {{ $t('common.edit') }}</a>
                                    <form method="post" action="{{ route('assistant.domain-stores.destroy', $store) }}" class="assistant-delete-form">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="btn btn-danger btn-xs" data-shop-name="{{ $store->shop_name }}"><i class="fa fa-trash"></i> {{ $t('common.delete') }}</button>
                                    </form>
                                @endif
                            </td>
                        </tr>
                        <tr class="assistant-store-note-row">
                            <td colspan="25"><span class="assistant-note-label">{{ $t('fields.remark') }}</span><span class="assistant-note-value">{{ $store->remark ?: $t('common.empty') }}</span></td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="26" class="assistant-empty-state">{{ $canManage ? $t('common.no_records') : $t('empty.readonly') }}</td>
                        </tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
            <div class="box-footer clearfix">
                @include('portals.assistant.domain-stores.partials.pagination', ['stores' => $stores, 'filters' => $filters])
            </div>
        </div>
    </section>

    <div class="modal fade" id="assistant-history-modal" tabindex="-1" role="dialog" aria-labelledby="assistant-history-title">
        <div class="modal-dialog modal-lg" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <button type="button" class="close" data-dismiss="modal" aria-label="{{ $t('common.close') }}"><span aria-hidden="true">&times;</span></button>
                    <h4 class="modal-title" id="assistant-history-title">{{ $t('history.title') }}</h4>
                </div>
                <div class="modal-body" id="assistant-history-content"><p class="text-muted">{{ $t('common.loading') }}</p></div>
            </div>
        </div>
    </div>

    @if ($canManage)
        <div class="modal fade assistant-domain-store-workbench-modal" id="assistant-domain-store-editor-modal" tabindex="-1" role="dialog" aria-labelledby="assistant-domain-store-editor-title" data-backdrop="static" data-keyboard="false">
            <div class="modal-dialog assistant-workbench-modal-dialog" role="document">
                <div class="modal-content">
                    <div class="modal-header">
                        <button type="button" class="close assistant-editor-close" aria-label="{{ $t('common.close') }}"><span aria-hidden="true">&times;</span></button>
                        <h4 class="modal-title" id="assistant-domain-store-editor-title"></h4>
                    </div>
                    <div class="modal-body" id="assistant-domain-store-editor-body" aria-live="polite"></div>
                </div>
            </div>
        </div>
    @endif

    @if ($canManage)
        <div class="modal fade" id="assistant-export-modal" tabindex="-1" role="dialog" aria-labelledby="assistant-export-title">
            <div class="modal-dialog" role="document">
                <form method="post" action="{{ route('assistant.domain-stores.export') }}" class="modal-content" id="assistant-export-form" data-loading="download">
                    @csrf
                    @foreach ($filters as $filterName => $filterValue)
                        <input type="hidden" name="{{ $filterName }}" value="{{ $filterValue }}">
                    @endforeach
                    <div class="modal-header">
                        <button type="button" class="close" data-dismiss="modal" aria-label="{{ $t('common.close') }}"><span aria-hidden="true">&times;</span></button>
                        <h4 class="modal-title" id="assistant-export-title"><i class="fa fa-download"></i> {{ $t('export.title') }}</h4>
                    </div>
                    <div class="modal-body">
                        <div class="alert alert-info assistant-export-notice"><i class="fa fa-info-circle"></i> {{ $t('export.notice') }}</div>
                        <p class="assistant-modal-section-title">{{ $t('export.scope') }}</p>
                        <label class="assistant-export-scope">
                            <input type="radio" name="scope" value="selected" checked>
                            <span><strong>{{ $t('export.selected_title') }}</strong><small id="assistant-selected-count">{{ $t('export.selected_count', ['count' => 0]) }}</small></span>
                        </label>
                        <label class="assistant-export-scope">
                            <input type="radio" name="scope" value="filtered">
                            <span><strong>{{ $t('export.filtered_title') }}</strong><small>{{ $t('export.filtered_count', ['count' => $stores->total()]) }}</small></span>
                        </label>
                        <div id="assistant-export-selected-inputs"></div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-default" data-dismiss="modal">{{ $t('common.cancel') }}</button>
                        <button type="submit" class="btn btn-primary" id="assistant-export-submit"><i class="fa fa-download"></i> {{ $t('export.confirm') }}</button>
                    </div>
                </form>
            </div>
        </div>

        <div class="modal fade" id="assistant-import-modal" tabindex="-1" role="dialog" aria-labelledby="assistant-import-title">
            <div class="modal-dialog" role="document">
                <form method="post" action="{{ route('assistant.domain-stores.import') }}" enctype="multipart/form-data" class="modal-content" id="assistant-import-form" data-loading="button">
                    @csrf
                    <div class="modal-header">
                        <button type="button" class="close" data-dismiss="modal" aria-label="{{ $t('common.close') }}"><span aria-hidden="true">&times;</span></button>
                        <h4 class="modal-title" id="assistant-import-title"><i class="fa fa-upload"></i> {{ $t('import.title') }}</h4>
                    </div>
                    <div class="modal-body">
                        <p class="text-muted">{{ $t('import.notice') }}</p>
                        @if ($canManageDepartmentAccess)
                            <p class="text-muted">{{ $t('import.root_manager_access_hint') }}</p>
                        @endif
                        <label class="assistant-import-dropzone" for="assistant-import-file">
                            <input id="assistant-import-file" type="file" name="import_file" accept=".csv,.xls,.xlsx" required>
                            <i class="fa fa-cloud-upload"></i>
                            <strong>{{ $t('import.dropzone_title') }}</strong>
                            <span>{{ $t('import.dropzone_hint') }}</span>
                            <em id="assistant-import-file-name"></em>
                        </label>
                        <p class="text-muted assistant-import-tag-hint">{{ $t('import.multi_value_hint') }}</p>
                        <a href="{{ route('assistant.domain-stores.import-template') }}" class="assistant-template-download" data-loading="download"><i class="fa fa-file-excel-o"></i> {{ $t('import.template') }}</a>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-default" data-dismiss="modal">{{ $t('common.cancel') }}</button>
                        <button type="submit" class="btn btn-success"><i class="fa fa-upload"></i> {{ $t('import.confirm') }}</button>
                    </div>
                </form>
            </div>
        </div>

        @include('portals.assistant.domain-stores.partials.batch-update-modal')

        @if ($canManageDepartmentAccess)
            <div class="modal fade" id="assistant-batch-access-modal" tabindex="-1" role="dialog" aria-labelledby="assistant-batch-access-title">
                <div class="modal-dialog" role="document">
                    <form method="post" action="{{ route('assistant.domain-stores.access.grant-batch') }}" class="modal-content assistant-batch-access-form" data-grant-url="{{ route('assistant.domain-stores.access.grant-batch') }}" data-revoke-url="{{ route('assistant.domain-stores.access.revoke-batch') }}" data-grant-label="{{ $t('selection.confirm_grant_access') }}" data-revoke-label="{{ $t('selection.confirm_revoke_access') }}">
                        @csrf
                        <input type="hidden" name="_method" value="DELETE" data-access-method disabled>
                        <div class="modal-header">
                            <button type="button" class="close" data-dismiss="modal" aria-label="{{ $t('common.close') }}"><span aria-hidden="true">&times;</span></button>
                            <h4 class="modal-title" id="assistant-batch-access-title"><i class="fa fa-share-alt"></i> {{ $t('selection.batch_manage_access') }}</h4>
                        </div>
                        <div class="modal-body">
                            <div class="btn-group assistant-batch-action-switch" role="group" aria-label="{{ $t('selection.batch_manage_access') }}">
                                <button type="button" class="btn btn-primary is-selected" data-access-mode-choice="grant" aria-pressed="true"><i class="fa fa-share"></i> {{ $t('selection.grant_access_action') }}</button>
                                <button type="button" class="btn btn-default" data-access-mode-choice="revoke" aria-pressed="false"><i class="fa fa-ban"></i> {{ $t('selection.revoke_access_action') }}</button>
                            </div>
                            <p class="text-muted assistant-batch-access-summary" data-selection-summary></p>
                            <p class="assistant-modal-section-title">{{ $t('selection.choose_departments') }}</p>
                            <div class="assistant-batch-access-options" data-batch-access-options>
                                @forelse ($assistantChildDepartments as $department)
                                    <button type="button" class="btn btn-default btn-sm assistant-batch-access-choice" data-department-id="{{ $department->getKey() }}" aria-pressed="false">{{ $department->name }}</button>
                                @empty
                                    <span class="text-muted">{{ $t('form.no_child_departments') }}</span>
                                @endforelse
                            </div>
                            <span data-selected-record-inputs></span>
                            <span data-selected-department-inputs></span>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-default" data-dismiss="modal">{{ $t('common.cancel') }}</button>
                            <button type="submit" class="btn btn-primary" data-submit-button><i class="fa fa-share" data-submit-icon></i> <span data-submit-text>{{ $t('selection.confirm_grant_access') }}</span></button>
                        </div>
                    </form>
                </div>
            </div>
        @endif

        @if ($canManageStoreOwnership)
            <div class="modal fade" id="assistant-batch-owner-modal" tabindex="-1" role="dialog" aria-labelledby="assistant-batch-owner-title">
                <div class="modal-dialog" role="document">
                    <form method="post" action="{{ route('assistant.domain-stores.owners.assign') }}" class="modal-content" id="assistant-batch-owner-form" data-assign-url="{{ route('assistant.domain-stores.owners.assign') }}" data-clear-url="{{ route('assistant.domain-stores.owners.clear') }}" data-assign-label="{{ $t('selection.confirm_assign_owner') }}" data-clear-label="{{ $t('selection.confirm_clear_owner') }}">
                        @csrf
                        <input type="hidden" name="_method" value="DELETE" data-owner-method disabled>
                        <input type="hidden" name="return_to" value="{{ request()->fullUrl() }}">
                        <div class="modal-header">
                            <button type="button" class="close" data-dismiss="modal" aria-label="{{ $t('common.close') }}"><span aria-hidden="true">&times;</span></button>
                            <h4 class="modal-title" id="assistant-batch-owner-title"><i class="fa fa-user"></i> {{ $t('selection.batch_manage_owner') }}</h4>
                        </div>
                        <div class="modal-body">
                            <div class="btn-group assistant-batch-action-switch" role="group" aria-label="{{ $t('selection.batch_manage_owner') }}">
                                <button type="button" class="btn btn-success is-selected" data-owner-mode-choice="assign" aria-pressed="true"><i class="fa fa-user-plus"></i> {{ $t('selection.assign_owner_action') }}</button>
                                <button type="button" class="btn btn-default" data-owner-mode-choice="clear" aria-pressed="false"><i class="fa fa-user-times"></i> {{ $t('selection.clear_owner_action') }}</button>
                            </div>
                            <p class="text-muted" data-owner-selection-summary></p>
                            <div class="alert alert-warning assistant-owner-warning" data-owner-warning hidden></div>
                            <div data-owner-assign-fields>
                                <div class="form-group">
                                    <label for="assistant-owner-department-select">{{ $t('selection.choose_owner_department') }}</label>
                                    <select class="form-control" id="assistant-owner-department-select" name="owner_department_id">
                                        <option value="">{{ $t('selection.keep_owner_department') }}</option>
                                        @foreach ($assistantChildDepartments as $department)
                                            <option value="{{ $department->getKey() }}">{{ $department->name }}</option>
                                        @endforeach
                                    </select>
                                    <span class="help-block">{{ $t('selection.owner_department_assignment_notice') }}</span>
                                </div>
                                <div class="form-group">
                                    <label for="assistant-owner-user-select">{{ $t('selection.choose_owner') }}</label>
                                    <select class="form-control" id="assistant-owner-user-select" name="owner_user_id" required disabled>
                                        <option value="">{{ $t('javascript.owner_candidates_loading') }}</option>
                                    </select>
                                </div>
                            </div>
                            <p class="text-muted assistant-owner-help">{{ $t('selection.owner_assignment_only_notice') }}</p>
                            <span data-owner-selected-inputs></span>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-default" data-dismiss="modal">{{ $t('common.cancel') }}</button>
                            <button type="submit" class="btn btn-success" data-owner-submit disabled><i class="fa fa-check" data-owner-submit-icon></i> <span data-owner-submit-text>{{ $t('selection.confirm_assign_owner') }}</span></button>
                        </div>
                    </form>
                </div>
            </div>

            <div class="modal fade" id="assistant-owner-scope-modal" tabindex="-1" role="dialog" aria-labelledby="assistant-owner-scope-title">
                <div class="modal-dialog modal-lg" role="document">
                    <form method="post" action="{{ route('assistant.domain-stores.owner-scopes.update') }}" class="modal-content" data-owner-scope-form>
                        @csrf
                        @method('PUT')
                        <input type="hidden" name="return_to" value="{{ request()->fullUrl() }}">
                        <div class="modal-header">
                            <button type="button" class="close" data-dismiss="modal" aria-label="{{ $t('common.close') }}"><span aria-hidden="true">&times;</span></button>
                            <h4 class="modal-title" id="assistant-owner-scope-title"><i class="fa fa-sitemap"></i> {{ $t('selection.owner_scope_title') }}</h4>
                        </div>
                        <div class="modal-body">
                            <div class="alert alert-info"><i class="fa fa-info-circle"></i> {{ $t('selection.owner_scope_help') }}</div>
                            <div class="assistant-owner-scope-grid">
                                @forelse ($assistantChildDepartments as $assistantDepartment)
                                    <section class="assistant-owner-scope-card">
                                        <h5>{{ $assistantDepartment->name }}</h5>
                                        <p class="text-muted">{{ $t('selection.choose_operation_departments') }}</p>
                                        <div class="assistant-owner-scope-options">
                                            @forelse ($operationDepartments as $operationDepartment)
                                                <label style="--owner-scope-depth: {{ (int) $operationDepartment->tree_depth }}">
                                                    <input type="checkbox" name="scopes[{{ $assistantDepartment->getKey() }}][]" value="{{ $operationDepartment->getKey() }}" data-owner-scope-checkbox data-department-id="{{ $operationDepartment->getKey() }}" data-parent-id="{{ $operationDepartment->parent_id ?: '' }}" @checked(in_array((int) $operationDepartment->getKey(), $operationScopeMap[$assistantDepartment->getKey()] ?? [], true))>
                                                    <span>{{ $operationDepartment->name }}</span>
                                                </label>
                                            @empty
                                                <span class="text-muted">{{ $t('selection.no_operation_departments') }}</span>
                                            @endforelse
                                        </div>
                                    </section>
                                @empty
                                    <p class="text-muted">{{ $t('form.no_child_departments') }}</p>
                                @endforelse
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-default" data-dismiss="modal">{{ $t('common.cancel') }}</button>
                            <button type="submit" class="btn btn-primary" data-owner-scope-submit><i class="fa fa-save"></i> <span data-owner-scope-submit-text>{{ $t('common.save') }}</span></button>
                        </div>
                    </form>
                </div>
            </div>
        @endif
    @endif
@endsection

@push('scripts')
    <script>
        window.ErpAssistantDomainStoreTranslations = @json($javascriptTranslations);
        window.ErpAssistantDomainStoreTagTranslations = @json($tagJavascriptTranslations);
        window.ErpAssistantDomainStoreQuickOptionConfig = @json($quickOptionJavascriptConfig);
        window.ErpAssistantDomainStoreModalTranslations = @json($modalJavascriptTranslations);
        window.ErpAssistantDomainStoreBatchUpdateTranslations = @json($batchUpdateJavascriptTranslations);
        window.ErpAssistantDomainStoreBatchUpdateFieldGroups = @json($batchUpdateFieldGroups);
        window.ErpAssistantDomainStoreOwnership = @json($ownershipJavascriptConfig);
    </script>
    <script src="{{ $portalOrigin }}/assets/erp/assistant-domain-stores-20261007-2.js"></script>
    <script src="{{ $portalOrigin }}/assets/erp/assistant-domain-stores-batch-access-20261020-2.js"></script>
    <script src="{{ $portalOrigin }}/assets/erp/assistant-domain-stores-batch-editor-20261010-2.js?v=20261011-2"></script>
    <script src="{{ $portalOrigin }}/assets/erp/assistant-domain-stores-check-20261007-3.js"></script>
    <script src="{{ $portalOrigin }}/assets/erp/assistant-domain-stores-resize-20261008-1.js"></script>
    <script src="{{ $portalOrigin }}/assets/erp/assistant-domain-store-access-guide-20261009-1.js"></script>
    <script src="{{ $portalOrigin }}/assets/erp/assistant-domain-store-quick-options-20261008-2.js"></script>
    <script src="{{ $portalOrigin }}/assets/erp/assistant-domain-store-team-project-options-20261009-1.js"></script>
    <script src="{{ $portalOrigin }}/assets/erp/assistant-domain-stores-modal-20261009-1.js?v=20261011-2"></script>
    <script src="{{ $portalOrigin }}/assets/erp/assistant-domain-stores-ownership-20261020-3.js?v=20261020-4"></script>
    @if (session('success'))
        <script>
            window.ErpAdminNotifier.notify({
                type: 'success',
                title: @json($t('messages.success_title')),
                message: @json(session('success'))
            });
        </script>
    @endif
    @if (session('error'))
        <script>
            window.ErpAdminNotifier.notify({
                type: 'error',
                title: @json($t('messages.error_title')),
                message: @json(session('error'))
            });
        </script>
    @endif
@endpush
