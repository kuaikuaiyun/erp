@php
    $t = static fn (string $key, array $replace = []): string => __('assistant.domain_stores.'.$key, $replace);
    $quickOptionMap = is_array($quickOptions ?? null) ? $quickOptions : [];
    $normalizeOptionMap = static function (mixed $options): array {
        return is_array($options) ? $options : [];
    };
    $optionValues = static function (mixed $defaults, string $optionField) use ($quickOptionMap, $normalizeOptionMap): array {
        $defaultMap = $normalizeOptionMap($defaults);
        $customValues = $quickOptionMap[$optionField] ?? [];
        $customValues = is_array($customValues) ? $customValues : [];

        return array_values(array_unique([
            ...array_keys($defaultMap),
            ...array_values($customValues),
        ]));
    };
    $defaultOptions = [
        'site_builder' => $normalizeOptionMap($siteBuilderOptions ?? []),
        'erp' => $normalizeOptionMap($erpOptions ?? []),
        'credit_card' => $normalizeOptionMap($creditCardOptions ?? []),
        'wallet' => $normalizeOptionMap($walletOptions ?? []),
        'paypal' => $normalizeOptionMap($paypalOptions ?? []),
        'other_payment' => $normalizeOptionMap($otherPaymentOptions ?? []),
    ];
    $presetFields = ['site_builder', 'credit_card', 'wallet', 'paypal', 'other_payment'];
    $multiFields = ['team', 'erp'];
    $requiredFields = ['shop_name', 'domain', 'domain_check_status', 'email_bound', 'shop_status'];
    $fullWidthFields = ['team', 'erp', 'remark'];
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
    @foreach ($selectedFields as $selectedField)
        <input type="hidden" name="fields[]" value="{{ $selectedField }}">
    @endforeach

    <div class="assistant-batch-editor-intro">
        {{ $t('batch_update.single_field_notice') }}
        {{ $t('batch_update.selection_count', ['count' => $selectedCount]) }}
    </div>

    <div class="assistant-batch-editor-layout">
        <div class="assistant-batch-record-panels">
            <section class="box box-solid assistant-form-section assistant-batch-unified-value">
                <div class="box-header with-border">
                    <h3 class="box-title">{{ $t('batch_update.new_values') }}</h3>
                </div>
                <div class="box-body row assistant-form-grid">
                    @foreach ($selectedFields as $field)
                        @php
                            $label = $t('fields.'.$field);
                            $columnClass = in_array($field, $fullWidthFields, true) ? 'col-md-12' : 'col-md-6';
                        @endphp
                        <div class="form-group {{ $columnClass }}" data-batch-field="{{ $field }}">
                            <label for="assistant-batch-value-{{ $field }}">
                                {{ $label }}
                                @if (in_array($field, $requiredFields, true))
                                    <span class="text-red">*</span>
                                @endif
                            </label>

                            @if (array_key_exists($field, $textFields))
                                @php
                                    $config = $textFields[$field];
                                @endphp
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
                                <textarea id="assistant-batch-value-remark" name="values[remark]" class="form-control" rows="4" maxlength="2000" placeholder="{{ $t('batch_update.new_value') }}"></textarea>
                            @elseif (in_array($field, $multiFields, true))
                                @php
                                    $values = $field === 'erp'
                                        ? $optionValues($defaultOptions['erp'], 'erp')
                                        : $optionValues([], 'team');
                                    $labels = $field === 'erp' ? $defaultOptions['erp'] : [];
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
                    @endforeach
                </div>
            </section>
        </div>
    </div>
</form>
