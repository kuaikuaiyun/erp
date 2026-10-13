<div class="organization-permission-form" data-permission-form>
    <div class="alert alert-info organization-permission-tip"><i class="fa fa-info-circle"></i> {{ __('organization.page_permissions.scope_help') }}</div>
    <div class="organization-permission-tools">
        <input class="form-control input-sm" type="search" placeholder="{{ __('organization.page_permissions.search_placeholder') }}" data-permission-search>
        <button type="button" class="btn btn-default btn-sm" data-expand-all>{{ __('organization.page_permissions.expand_all') }}</button>
        <button type="button" class="btn btn-default btn-sm" data-collapse-all>{{ __('organization.page_permissions.collapse_all') }}</button>
        <button type="button" class="btn btn-default btn-sm" data-select-all>{{ __('organization.page_permissions.select_all') }}</button>
        <button type="button" class="btn btn-default btn-sm" data-clear-all>{{ __('organization.page_permissions.clear_all') }}</button>
    </div>
    <div class="organization-permission-tree" data-page-tree>
        @forelse($pageTree as $node)
            @include('admin.organization.permissions.partials.node', ['node' => $node, 'directPermissions' => $directPermissions, 'inheritedPermissionIds' => $inheritedPermissionIds])
        @empty
            <p class="text-center text-muted organization-form-empty">{{ __('organization.page_permissions.no_assignable_pages') }}</p>
        @endforelse
    </div>
    <p class="help-block"><i class="fa fa-info-circle"></i> {{ __('organization.page_permissions.system_permissions_hidden') }}</p>
    @error('permissions')<span class="help-block text-danger">{{ $message }}</span>@enderror
</div>


