@php
    $t = static fn (string $key, array $replace = []): string => __('assistant.domain_stores.'.$key, $replace);
    $field = (string) ($selectedFields[0] ?? '');
    $label = $t('fields.'.$field);
    $optionValues = static function (array $defaults, string $optionField) use ($quickOptions): array {
        return array_values(array_unique([
            ...array_keys($defaults),
            ...($quickOptions[$optionField] ?? []),
        ]));
    };
    $defaultOptions = [
        'site_builder' => $siteBuilderOptions,
        'erp' => $erpOptions,
        'credit_card' => $creditCardOptions,
        'wallet' => $walletOptions,
        'paypal' => $paypalOptions,
        'other_payment' => $otherPaymentOptions,
    ];
    $presetFields = ['site_builder', 'credit_card', 'wallet', 'paypal', 'other_payment'];
    $multiFields = ['team', 'erp'];
    $textFields = [
        'shop_name' => ['type' => 'text', 'maxlength' => 100, 'required' => true],
        'domain' => ['type' => 'text', 'maxlength' => 191, 'required' => true],
        'shop_login_url' => ['type' => 'text', 'maxlength' => 2048],
        'email' => ['type' => 'email', 'maxlength' => 191],
        'project' => ['type' => 'text', 'maxlength' => 100],
        'user_name' => ['type' => 'text', 'maxlength' => 100],
        'payment_card' => ['type' => 'text', 'maxlength' => 255],
        'store_prefix' => ['type' => 'text', 'maxlength' => 100],
        'tongtu_prefix' => ['type' => 'text', 'maxlength' => 100],
        'shopify_client_id' => ['type' => 'text', 'maxlength' => 255, 'autocomplete' => 'off'],
        'shopify_client_secret' => ['type' => 'text', 'maxlength' => 255, 'autocomplete' => 'off'],
    ];
@endphp

<form method="post" action="{{ route('assistant.domain-stores.update-batch') }}" id="assistant-batch-edit-form" class="assistant-batch-edit-form" data-batch-edit-form novalidate>
    @csrf
    <input type="hidden" name="return_to" value="">
    @foreach ($selectedIds as $storeId)
        <input type="hidden" name="selected_ids[]" value="{{ $storeId }}">
    @endforeach
    <input type="hidden" name="fields[]" value="{{ $field }}">

    <div class="assistant-batch-editor-intro">
        {{ $t('batch_update.single_field_notice') }}
        {{ $t('batch_update.selection_count', ['count' => $selectedCount]) }}
    </div>

    <div class="assistant-batch-editor-layout">
        <div class="assistant-batch-record-panels">
            <section class="box box-solid assistant-form-section assistant-batch-unified-value">
                <div class="box-header with-border">
                    <h3 class="box-title">{{ $t('batch_update.new_value') }}：{{ $label }}</h3>
                </div>
                <div class="box-body row assistant-form-grid">
                    <div class="form-group col-md-12" data-batch-field="{{ $field }}">
                        <label for="assistant-batch-value-{{ $field }}">
                            {{ $label }}
                            @if (in_array($field, ['shop_name', 'domain', 'domain_check_status', 'email_bound', 'shop_status'], true))
                                <span class="text-red">*</span>
                            @endif
                        </label>

                        @if (array_key_exists($field, $textFields))
                            @php($config = $textFields[$field])
                            <input
                                id="assistant-batch-value-{{ $field }}"
                                type="{{ $config['type'] }}"
                                name="values[{{ $field }}]"
                                class="form-control"
                                maxlength="{{ $config['maxlength'] }}"
                                placeholder="{{ $t('batch_update.new_value') }}"
                                @if (!empty($config['required'])) required @endif
                                @if (!empty($config['autocomplete'])) autocomplete="{{ $config['autocomplete'] }}" @endif
                            >
                        @elseif (in_array($field, ['domain_expires_at', 'plan_expires_at'], true))
                            <input id="assistant-batch-value-{{ $field }}" type="date" name="values[{{ $field }}]" class="form-control">
                        @elseif ($field === 'domain_check_status')
                            <select id="assistant-batch-value-domain-check-status" name="values[domain_check_status]" class="form-control" required>
                                @foreach ($domainCheckLabels as $value => $optionLabel)
                                    <option value="{{ $value }}">{{ $optionLabel }}</option>
                                @endforeach
                            </select>
                        @elseif ($field === 'email_bound')
                            <select id="assistant-batch-value-email-bound" name="values[email_bound]" class="form-control" required>
                                <option value="1">{{ $t('common.yes') }}</option>
                                <option value="0">{{ $t('common.no') }}</option>
                            </select>
                        @elseif ($field === 'shop_status')
                            <select id="assistant-batch-value-shop-status" name="values[shop_status]" class="form-control" required>
                                @foreach ($shopStatusLabels as $value => $optionLabel)
                                    <option value="{{ $value }}">{{ $optionLabel }}</option>
                                @endforeach
                            </select>
                        @elseif ($field === 'remark')
                            <textarea id="assistant-batch-value-remark" name="values[remark]" class="form-control" rows="5" maxlength="2000" placeholder="{{ $t('batch_update.new_value') }}"></textarea>
                        @elseif (in_array($field, $multiFields, true))
                            @php
                                $values = $field === 'erp'
                                    ? $optionValues($erpOptions, 'erp')
                                    : $optionValues([], 'team');
                                $labels = $field === 'erp' ? $erpOptions : [];
                            @endphp
                            <input
                                id="assistant-batch-value-{{ $field }}"
                                name="values[{{ $field }}]"
                                class="form-control"
                                maxlength="2000"
                                placeholder="{{ $t('batch_update.multi_value_placeholder') }}"
                                data-batch-input="{{ $field }}"
                            >
                            @if ($values !== [])
                                <div class="assistant-batch-option-presets" data-batch-option-group="{{ $field }}">
                                    @foreach ($values as $option)
                                        <button type="button" class="btn btn-default btn-xs assistant-batch-option-choice" data-batch-multi-option="{{ $field }}" data-batch-option-value="{{ $option }}">{{ $labels[$option] ?? $option }}</button>
                                    @endforeach
                                </div>
                            @endif
                            <p class="help-block">{{ $t('batch_update.multi_value_hint') }}</p>
                        @elseif (in_array($field, $presetFields, true))
                            @php
                                $defaults = $defaultOptions[$field] ?? [];
                                $values = $optionValues($defaults, $field);
                            @endphp
                            <input
                                id="assistant-batch-value-{{ $field }}"
                                name="values[{{ $field }}]"
                                class="form-control"
                                maxlength="100"
                                placeholder="{{ $t('batch_update.new_value') }}"
                                data-batch-input="{{ $field }}"
                            >
                            @if ($values !== [])
                                <div class="assistant-batch-option-presets" data-batch-option-group="{{ $field }}">
                                    @foreach ($values as $option)
                                        <button type="button" class="btn btn-default btn-xs assistant-batch-option-choice" data-batch-single-option="{{ $field }}" data-batch-option-value="{{ $option }}">{{ $defaults[$option] ?? $option }}</button>
                                    @endforeach
                                </div>
                            @endif
                        @else
                            <input id="assistant-batch-value-{{ $field }}" name="values[{{ $field }}]" class="form-control" placeholder="{{ $t('batch_update.new_value') }}">
                        @endif
                    </div>
                </div>
            </section>
        </div>
    </div>
</form>
