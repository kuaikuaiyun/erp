@php
    $permissionId = $node['permission_id'];
    $override = $permissionId ? $overrides->get($permissionId) : null;
    $inherited = $permissionId && in_array((int) $permissionId, $departmentPermissionIds, true);
@endphp
<div class="user-permission-node" data-search="{{ strtolower($node['title']) }}">
    <div class="user-permission-row">
        @if($permissionId)
            <span class="user-permission-title"><i class="fa fa-file-o text-aqua"></i> {{ $node['title'] }}</span>
            <span class="user-permission-inherited">部门权限：{{ $inherited ? '允许' : '不允许' }}</span>
            <select class="form-control input-sm" name="permissions[{{ $permissionId }}]">
                <option value="" {{ !$override ? 'selected' : '' }}>跟随部门</option>
                <option value="allow" {{ optional($override)->effect === 'allow' ? 'selected' : '' }}>单独允许</option>
                <option value="deny" {{ optional($override)->effect === 'deny' ? 'selected' : '' }}>单独禁止</option>
            </select>
        @else
            <strong><i class="fa fa-folder-open-o text-yellow"></i> {{ $node['title'] }}</strong>
        @endif
    </div>
    @if($node['children'] !== [])
        <div class="user-permission-children">
            @foreach($node['children'] as $child)
                @include('admin.organization.permissions.partials.user-node', ['node' => $child, 'overrides' => $overrides, 'departmentPermissionIds' => $departmentPermissionIds])
            @endforeach
        </div>
    @endif
</div>
