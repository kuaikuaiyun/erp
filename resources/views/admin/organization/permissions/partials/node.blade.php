@php
    $permissionId = $node['permission_id'];
    $direct = $permissionId ? $directPermissions->get($permissionId) : null;
    $inherited = $permissionId ? in_array((int) $permissionId, $inheritedPermissionIds, true) : false;
    $open = $node['children'] !== [];
@endphp
<div class="organization-native-tree-node {{ $node['children'] !== [] ? 'has-children' : 'is-leaf' }}" data-tree-node data-search-name="{{ strtolower($node['title']) }}">
    <div class="organization-native-tree-row">
        @if($node['children'] !== [])
            <button type="button" class="btn btn-link btn-xs organization-native-tree-toggle" data-tree-toggle aria-expanded="{{ $open ? 'true' : 'false' }}"><i class="fa fa-angle-down"></i></button>
        @else
            <span class="organization-native-tree-toggle-placeholder"></span>
        @endif
        @if($permissionId)
            <label class="organization-native-page-choice" data-permission-row>
                <input type="checkbox" name="permissions[{{ $permissionId }}][enabled]" value="1" {{ $direct ? 'checked' : '' }} {{ $inherited && !$direct ? 'checked disabled' : '' }} data-permission-checkbox>
                <span>{{ $node['title'] }}</span>
            </label>
            @if($direct)<span class="label label-info">{{ __('organization.page_permissions.direct_badge') }}</span>@elseif($inherited)<span class="label label-default">{{ __('organization.page_permissions.inherited_badge') }}</span>@endif
            @if($inherited && !$direct)<span class="help-block organization-native-inherited-help">{{ __('organization.page_permissions.inherited_help') }}</span>@endif
            <select class="form-control input-sm organization-native-scope-select" name="permissions[{{ $permissionId }}][scope]" data-scope-select {{ $direct ? '' : 'disabled' }}>
                <option value="self" {{ optional($direct)->scope === 'self' ? 'selected' : '' }}>{{ __('organization.page_permissions.scope_self') }}</option>
                <option value="descendants" {{ optional($direct)->scope === 'descendants' ? 'selected' : '' }}>{{ __('organization.page_permissions.scope_descendants') }}</option>
            </select>
        @else
            <strong>{{ $node['title'] }}</strong>
        @endif
    </div>
    @if($node['children'] !== [])
        <div class="organization-native-tree-children" data-tree-children>
            @foreach($node['children'] as $child)
                @include('admin.organization.permissions.partials.node', ['node' => $child, 'directPermissions' => $directPermissions, 'inheritedPermissionIds' => $inheritedPermissionIds])
            @endforeach
        </div>
    @endif
</div>


