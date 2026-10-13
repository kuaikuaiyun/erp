@php
    $membershipRows = old('memberships', $memberships->map(fn ($membership) => [
        'department_id' => $membership->department_id,
        'position' => $membership->position,
    ])->all());

    if ($membershipRows === []) {
        $membershipRows = [['department_id' => null, 'position' => \App\Models\Department::POSITION_MEMBER]];
    }
@endphp
<div class="organization-form-section" data-organization-account-section>
    <div class="organization-membership-panel">
        <div class="organization-membership-panel-heading">
            <i class="fa fa-sitemap" aria-hidden="true"></i>
            <span>{{ __('organization.accounts.department_memberships') }}</span>
            <button type="button" class="btn btn-default btn-xs pull-right" data-add-membership><i class="fa fa-plus"></i> {{ __('organization.accounts.add_department') }}</button>
        </div>
        <p class="organization-membership-panel-help">{{ __('organization.accounts.department_memberships_intro') }}</p>
        <p class="organization-membership-panel-help organization-membership-panel-help-secondary">{{ __('organization.accounts.position_selection_help') }}</p>
        <div data-membership-list>
            @foreach($membershipRows as $index => $membership)
                @include('admin.organization.accounts.partials.membership-row', ['rowIndex' => $index, 'membership' => $membership])
            @endforeach
        </div>
        @error('memberships')<span class="help-block text-danger">{{ $message }}</span>@enderror
    </div>
    <template data-membership-template>
        @include('admin.organization.accounts.partials.membership-row', ['rowIndex' => '__INDEX__', 'membership' => null])
    </template>
</div>


