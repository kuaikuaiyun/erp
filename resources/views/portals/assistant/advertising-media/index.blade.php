@extends('portal.layout')

@section('page_title', '广告社媒')

@push('styles')
<style>
.media-page{max-width:1050px}.media-card{border:0;border-radius:10px;box-shadow:0 4px 18px rgba(31,61,84,.08);overflow:hidden}.media-add{display:flex;gap:10px;align-items:flex-start}.media-add .form-group{flex:1;margin:0}.media-add .form-control{height:39px;border-radius:6px}.media-add .btn{height:39px;border-radius:6px}.media-table th{background:#eef5fa;color:#3c586e}.media-table td,.media-table th{vertical-align:middle!important}.media-state{display:inline-block;padding:4px 11px;border-radius:13px;font-size:12px}.media-state.on{background:#dff5e9;color:#18764d}.media-state.off{background:#edf0f2;color:#758391}.media-actions{display:flex;gap:6px}.media-empty{text-align:center;color:#8796a3;padding:35px!important}
</style>
@endpush

@section('content')
<section class="content-header"><h1>广告社媒 <small>维护运营报表可选择的广告社媒</small></h1></section>
<section class="content media-page">
    @if(session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
    @if($errors->any())<div class="alert alert-danger">{{ $errors->first() }}</div>@endif
    <div class="box media-card"><div class="box-body"><form method="get" class="form-inline"><label style="margin-right:8px">当前助理部门</label><select class="form-control" name="assistant_department_id" onchange="this.form.submit()">@foreach($assistantDepartments as $department)<option value="{{ $department->id }}" @selected($selectedAssistantDepartment->id===$department->id)>{{ $department->name }}</option>@endforeach</select></form></div></div>
    <div class="box media-card"><div class="box-header with-border"><h3 class="box-title">添加广告社媒</h3></div><div class="box-body">
        <form class="media-add" method="post" action="{{ route('assistant.advertising-media.store') }}">@csrf<input type="hidden" name="assistant_department_id" value="{{ $selectedAssistantDepartment->id }}">
            <div class="form-group"><input class="form-control" name="name" maxlength="50" value="{{ old('name') }}" placeholder="例如：YouTube" required></div>
            <button class="btn btn-primary"><i class="fa fa-plus"></i> 添加媒介</button>
        </form>
    </div></div>
    <div class="box media-card"><div class="box-header with-border"><h3 class="box-title">媒介列表</h3><span class="text-muted"> 共 {{ $media->count() }} 条</span></div><div class="box-body no-padding table-responsive">
        <table class="table table-hover media-table"><thead><tr><th style="width:90px">序号</th><th>媒介名称</th><th style="width:120px">状态</th><th style="width:190px">操作</th></tr></thead><tbody>
        @forelse($media as $item)<tr><td>{{ $loop->iteration }}</td><td><strong>{{ $item->name }}</strong></td><td><span class="media-state {{ $item->is_enabled ? 'on' : 'off' }}">{{ $item->is_enabled ? '已启用' : '已停用' }}</span></td><td><div class="media-actions">
            @if($item->name !== '其他')<button type="button" class="btn btn-xs btn-primary media-edit" data-action="{{ route('assistant.advertising-media.update',$item) }}" data-name="{{ $item->name }}"><i class="fa fa-pencil"></i> 编辑</button><form method="post" action="{{ route('assistant.advertising-media.toggle',$item) }}">@csrf @method('patch')<input type="hidden" name="assistant_department_id" value="{{ $selectedAssistantDepartment->id }}"><button class="btn btn-xs {{ $item->is_enabled ? 'btn-default' : 'btn-success' }}">{{ $item->is_enabled ? '停用' : '启用' }}</button></form>
            <form method="post" action="{{ route('assistant.advertising-media.destroy',$item) }}" onsubmit="return confirm('确定删除该广告社媒吗？历史报表不会被删除。')">@csrf @method('delete')<input type="hidden" name="assistant_department_id" value="{{ $selectedAssistantDepartment->id }}"><button class="btn btn-xs btn-danger"><i class="fa fa-trash"></i> 删除</button></form>@else<span class="text-muted">系统兜底项</span>@endif
        </div></td></tr>@empty<tr><td colspan="4" class="media-empty">暂无广告社媒</td></tr>@endforelse
        </tbody></table>
    </div></div>
</section>

<div class="modal fade" id="media-edit-modal" tabindex="-1" role="dialog"><div class="modal-dialog" role="document"><div class="modal-content">
    <form method="post" id="media-edit-form">@csrf @method('patch')<input type="hidden" name="assistant_department_id" value="{{ $selectedAssistantDepartment->id }}">
        <div class="modal-header"><button type="button" class="close" data-dismiss="modal"><span>&times;</span></button><h4 class="modal-title">编辑广告社媒</h4></div>
        <div class="modal-body"><div class="form-group"><label for="media-edit-name">广告社媒名称 <span class="text-red">*</span></label><input id="media-edit-name" class="form-control" name="name" maxlength="50" required></div></div>
        <div class="modal-footer"><button type="button" class="btn btn-default" data-dismiss="modal">取消</button><button class="btn btn-primary"><i class="fa fa-save"></i> 保存修改</button></div>
    </form>
</div></div></div>
@endsection

@push('scripts')
<script>
(function(){var modal=document.getElementById('media-edit-modal'),form=document.getElementById('media-edit-form'),name=document.getElementById('media-edit-name');if(!modal)return;document.addEventListener('click',function(e){var button=e.target.closest&&e.target.closest('.media-edit');if(!button)return;form.action=button.getAttribute('data-action');name.value=button.getAttribute('data-name')||'';jQuery(modal).modal('show')})})();
</script>
@endpush
