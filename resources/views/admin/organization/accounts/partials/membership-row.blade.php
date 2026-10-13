@php
    $departmentId = is_array($membership) ? ($membership['department_id'] ?? null) : $membership?->department_id;
    $existingPosition = is_array($membership) ? ($membership['position'] ?? null) : $membership?->position;
    $selectedPosition = old('memberships.'.$rowIndex.'.position', $existingPosition ?? \App\Models\Department::POSITION_MEMBER);
@endphp
<div class="organization-membership-item" data-membership-row data-no-available-position-message="{{ __('organization.accounts.no_available_position') }}">
    <div class="organization-membership-department-field">
        <label class="organization-membership-field-label" for="membership-department-{{ $rowIndex }}">{{ __('organization.accounts.department') }}</label>
        <select id="membership-department-{{ $rowIndex }}" class="form-control" name="memberships[{{ $rowIndex }}][department_id]" data-membership-department>
            <option value="">{{ __('organization.common.not_set') }}</option>
            @foreach($departmentOptions as $option)
                <option value="{{ $option['id'] }}"
                        data-position-availability="{{ json_encode($option['position_availability'] ?? []) }}"
                        data-position-notice="{{ $option['position_notice'] ?? '' }}"
                    {{ (string) old('memberships.'.$rowIndex.'.department_id', $departmentId) === (string) $option['id'] ? 'selected' : '' }}>{{ $option['label'] }}</option>
            @endforeach
        </select>
    </div>
    <div class="organization-membership-position-field">
        <span class="organization-membership-position-title">{{ __('organization.accounts.position') }}：</span>
        @foreach($positions as $position => $label)
            @php($positionId = 'membership-position-'.$rowIndex.'-'.$position)
            <label class="organization-membership-position-choice" for="{{ $positionId }}" data-membership-position-choice>
                <input id="{{ $positionId }}" type="radio" name="memberships[{{ $rowIndex }}][position]" value="{{ $position }}" data-membership-position {{ $selectedPosition === $position ? 'checked' : '' }}>
                <span>{{ $label }}</span>
            </label>
        @endforeach
        <span class="organization-membership-position-notice" data-membership-position-notice aria-live="polite"></span>
    </div>
    <div class="organization-membership-action">
        <button type="button" class="btn btn-default btn-sm" data-remove-membership>{{ __('organization.accounts.remove_department') }}</button>
    </div>
</div>


