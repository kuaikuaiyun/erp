<div class="organization-form-section">
    <div class="organization-form-section-divider">
        <span>{{ __('organization.departments.child_departments') }}</span>
        @if($canCreateChild)<a href="{{ admin_url('organization/departments/create?parent_id='.$department->getKey()) }}" class="btn btn-default btn-xs pull-right"><i class="fa fa-plus"></i> {{ __('organization.departments.create_child') }}</a>@endif
    </div>
    <p class="help-block organization-form-section-help">{{ $department->children->isNotEmpty() ? $department->children->pluck('name')->implode('、') : __('organization.departments.no_child_department') }}</p>
</div>


