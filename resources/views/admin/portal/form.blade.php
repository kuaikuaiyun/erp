@php
    $isEditing = $portal !== null;
    $selectedDepartmentIds = collect(old('department_ids', $grantedDepartmentIds))
        ->filter(fn ($id) => is_numeric($id))
        ->map(fn ($id) => (int) $id)
        ->all();
    $formAction = $isEditing
        ? admin_url('portal/portals/'.$portal->getKey())
        : admin_url('portal/portals');
    $selectedStatus = old('status', $portal?->status ?? \App\Models\Portal::STATUS_DISABLED);
@endphp

<form method="post" action="{{ $formAction }}" class="portal-management-form" data-portal-form data-domain-suffix="{{ $rootDomain }}">
    @csrf
    @if ($isEditing)
        @method('PUT')
    @endif

    <div class="row portal-config-layout">
        <div class="col-lg-7">
            <section class="box box-primary portal-config-primary">
                <div class="box-header with-border">
                    <h3 class="box-title"><i class="fa fa-cube"></i> {{ __('portal.management.info_title') }}</h3>
                    <div class="box-tools pull-right">
                        <a href="{{ admin_url('portal/portals') }}" class="btn btn-default btn-sm">
                            <i class="fa fa-list"></i> {{ __('portal.common.back') }}
                        </a>
                    </div>
                </div>
                <div class="box-body">
                    <div class="portal-config-intro">
                        <span class="portal-config-intro-icon"><i class="fa fa-info-circle"></i></span>
                        <div>
                            <strong>{{ __('portal.management.info_title') }}</strong>
                            <p>{{ __('portal.management.system_no_delete') }}</p>
                        </div>
                    </div>

                    <section class="portal-form-section">
                        <h4 class="portal-form-section-title">{{ __('portal.management.basic_section') }}</h4>
                        <div class="row">
                            <div class="col-sm-6">
                                <div class="portal-field {{ $errors->has('name') ? 'has-error' : '' }}">
                                    <label for="portal-name"><span class="text-red">*</span> {{ __('portal.management.name') }}</label>
                                    <input id="portal-name" name="name" type="text" class="form-control" maxlength="100"
                                           value="{{ old('name', $portal?->name) }}" required>
                                    @error('name')
                                        <span class="help-block">{{ $message }}</span>
                                    @enderror
                                </div>
                            </div>
                            <div class="col-sm-6">
                                <div class="portal-field {{ $errors->has('code') ? 'has-error' : '' }}">
                                    <label for="portal-code"><span class="text-red">*</span> {{ __('portal.management.code') }}</label>
                                    @if ($isEditing)
                                        <p id="portal-code" class="portal-static-code"><code>{{ $portal->code }}</code></p>
                                    @else
                                        <input id="portal-code" name="code" type="text" class="form-control" maxlength="63"
                                               value="{{ old('code') }}" required autocomplete="off"
                                               data-portal-code-input>
                                    @endif
                                    <p class="help-block">{{ __('portal.management.code_hint') }}</p>
                                    @error('code')
                                        <span class="help-block">{{ $message }}</span>
                                    @enderror
                                </div>
                            </div>
                        </div>

                        <div class="portal-field portal-domain-field">
                            <label for="portal-domain">{{ __('portal.management.domain') }}</label>
                            <div class="input-group">
                                <span class="input-group-addon"><i class="fa fa-globe"></i></span>
                                <input id="portal-domain" type="text" class="form-control" readonly
                                       value="{{ $domain ?? '' }}"
                                       placeholder="{{ $domainConfigured ? __('portal.management.domain_pending') : __('portal.management.domain_unavailable') }}"
                                       data-portal-domain-preview>
                            </div>
                            <p class="help-block">{{ __('portal.management.domain_hint') }}</p>
                        </div>

                        <div class="portal-field {{ $errors->has('remark') ? 'has-error' : '' }}">
                            <label for="portal-remark">{{ __('portal.management.remark') }}</label>
                            <textarea id="portal-remark" name="remark" class="form-control" rows="4" maxlength="1000">{{ old('remark', $portal?->remark) }}</textarea>
                            @error('remark')
                                <span class="help-block">{{ $message }}</span>
                            @enderror
                        </div>
                    </section>

                    <section class="portal-form-section portal-status-section">
                        <h4 class="portal-form-section-title">{{ __('portal.management.status_section') }}</h4>
                        <div class="portal-status-options">
                            @foreach ($statuses as $value => $label)
                                <label class="portal-status-option">
                                    <input type="radio" name="status" value="{{ $value }}" {{ $selectedStatus === $value ? 'checked' : '' }}>
                                    <span>{{ $label }}</span>
                                </label>
                            @endforeach
                        </div>
                        <p class="help-block">{{ __('portal.management.status_hint') }}</p>
                        @error('status')
                            <span class="help-block">{{ $message }}</span>
                        @enderror
                    </section>
                </div>
            </section>
        </div>

        <div class="col-lg-5">
            <section class="box box-default portal-side-box portal-departments-box">
                <div class="box-header with-border">
                    <h3 class="box-title"><i class="fa fa-sitemap"></i> {{ __('portal.management.authorized_departments') }}</h3>
                </div>
                <div class="box-body">
                    <div class="portal-side-note">
                        <i class="fa fa-info-circle"></i>
                        <p>{{ __('portal.management.department_hint') }}</p>
                        <p>{{ __('portal.management.authorization_scope') }}</p>
                    </div>

                    @if ($rootDepartments->isEmpty())
                        <p class="text-muted portal-department-empty">{{ __('portal.management.department_empty') }}</p>
                    @else
                        <div class="portal-department-options">
                            @foreach ($rootDepartments as $department)
                                @php
                                    $isDepartmentGranted = in_array((int) $department->getKey(), $selectedDepartmentIds, true);
                                @endphp
                                <label class="portal-department-option {{ $isDepartmentGranted ? 'is-selected' : '' }}">
                                    <span class="portal-department-option-main">
                                        <input type="checkbox" name="department_ids[]" value="{{ $department->getKey() }}" data-portal-department-input
                                               {{ $isDepartmentGranted ? 'checked' : '' }}>
                                        <span class="portal-department-option-name">{{ $department->name }}</span>
                                    </span>
                                    <small>{{ $department->slug }}</small>
                                </label>
                            @endforeach
                        </div>
                    @endif

                    @error('department_ids')
                        <span class="help-block text-red">{{ $message }}</span>
                    @enderror
                    @error('department_ids.*')
                        <span class="help-block text-red">{{ $message }}</span>
                    @enderror
                </div>
            </section>

            <section class="box box-default portal-side-box portal-history-box">
                <div class="box-header with-border">
                    <h3 class="box-title"><i class="fa fa-history"></i> {{ __('portal.history.title') }}</h3>
                </div>
                <div class="box-body {{ $isEditing ? 'no-padding' : '' }}">
                    @if ($isEditing)
                        @include('admin.portal.partials.authorization-history-compact', ['history' => $history])
                    @else
                        <p class="text-muted portal-history-empty"><i class="fa fa-history"></i> {{ __('portal.history.available_after_create') }}</p>
                    @endif
                </div>
            </section>
        </div>
    </div>

    <div class="portal-form-actions">
        <a href="{{ admin_url('portal/portals') }}" class="btn btn-default">{{ __('portal.common.cancel') }}</a>
        <button type="submit" class="btn btn-primary"><i class="fa fa-save"></i> {{ __('portal.common.save') }}</button>
    </div>
</form>


