@php
    $permissionId = $node['permission_id'];
    $depth = $depth ?? 1;
    $direct = $permissionId ? $directPermissions->get($permissionId) : null;
    $inherited = $permissionId ? in_array((int) $permissionId, $inheritedPermissionIds, true) : false;
@endphp
<div class="organization-native-tree-node tree-level-{{ min($depth, 3) }} {{ $node['children'] !== [] ? 'has-children' : 'is-leaf' }}" data-tree-node data-tree-level="{{ $depth }}" data-search-name="{{ strtolower($node['title']) }}">
    <div class="organization-native-tree-row">
        @if($node['children'] !== [])
            <button type="button" class="btn btn-link btn-xs organization-native-tree-toggle" data-tree-toggle aria-expanded="false"><i class="fa fa-angle-right"></i></button>
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
        <span class="organization-tree-level-badge">{{ $depth === 1 ? __('organization.page_permissions.level_one') : __('organization.page_permissions.level_two') }}</span>
        @if($node['children'] !== [])
            <span class="organization-tree-group-actions">
                <button type="button" class="btn btn-xs btn-info" data-select-group>{{ __('organization.page_permissions.select_group') }}</button>
                <button type="button" class="btn btn-xs btn-default" data-clear-group>{{ __('organization.page_permissions.clear_group') }}</button>
            </span>
        @endif
    </div>
    @if($node['children'] !== [])
        <div class="organization-native-tree-children is-collapsed" data-tree-children>
            @foreach($node['children'] as $child)
                @include('admin.organization.permissions.partials.node', ['node' => $child, 'directPermissions' => $directPermissions, 'inheritedPermissionIds' => $inheritedPermissionIds, 'depth' => $depth + 1])
            @endforeach
        </div>
    @endif
</div>
