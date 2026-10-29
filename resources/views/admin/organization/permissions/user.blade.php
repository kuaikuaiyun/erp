<div class="box box-primary">
    <div class="box-header with-border">
        <h3 class="box-title"><i class="fa fa-user-shield"></i> {{ $account->name }}（{{ $account->username }}）</h3>
        <a href="{{ admin_url('organization/accounts') }}" class="btn btn-default btn-sm pull-right"><i class="fa fa-list"></i> 返回用户管理</a>
    </div>
    <form method="post" action="{{ admin_url('organization/accounts/'.$account->getKey().'/permissions') }}">
        @csrf
        @method('PUT')
        <div class="box-body">
            <div class="alert alert-info"><i class="fa fa-info-circle"></i> 跟随部门表示使用所属部门权限；单独允许会额外开放页面；单独禁止的优先级最高。</div>
            <div class="form-group" style="max-width:420px"><input type="search" class="form-control" placeholder="搜索系统或页面名称" data-user-permission-search></div>
            <div class="organization-user-permission-tree">
                @foreach($pageTree as $node)
                    @include('admin.organization.permissions.partials.user-node', compact('node', 'overrides', 'departmentPermissionIds'))
                @endforeach
            </div>
        </div>
        <div class="box-footer"><button class="btn btn-primary"><i class="fa fa-save"></i> 保存成员权限</button></div>
    </form>
</div>
<style>
.user-permission-node{border:1px solid #e6edf3;border-radius:6px;margin:8px 0;background:#fff}.user-permission-row{display:flex;align-items:center;gap:12px;padding:10px 14px}.user-permission-row strong,.user-permission-title{min-width:260px}.user-permission-children{padding:0 0 8px 24px}.user-permission-children .user-permission-node{border-left:3px solid #d9edf7}.user-permission-inherited{color:#3c8dbc;font-size:12px}.user-permission-row select{width:180px}
</style>
<script>
$(function(){ $('[data-user-permission-search]').on('input', function(){ var q=$(this).val().toLowerCase(); $('.user-permission-node').each(function(){ var text=$(this).text().toLowerCase(); $(this).toggle(!q || text.indexOf(q)!==-1); }); }); });
</script>
