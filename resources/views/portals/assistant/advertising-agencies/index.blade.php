@extends('portal.layout')

@section('page_title', '广告代理')

@push('styles')
<style>
.agency-page{max-width:1150px}.agency-card{border:0;border-radius:10px;box-shadow:0 4px 18px rgba(31,61,84,.08);overflow:hidden}.agency-add{display:grid;grid-template-columns:minmax(240px,1fr) 220px 200px auto;gap:10px;align-items:start}.agency-add .form-group{margin:0}.agency-add .form-control,.agency-add .btn{height:39px;border-radius:6px}.agency-table th{background:#eef5fa;color:#3c586e}.agency-table td,.agency-table th{vertical-align:middle!important}.agency-state{display:inline-block;padding:4px 11px;border-radius:13px;font-size:12px}.agency-state.on{background:#dff5e9;color:#18764d}.agency-state.off{background:#edf0f2;color:#758391}.agency-actions{display:flex;gap:6px}.agency-empty{text-align:center;color:#8796a3;padding:35px!important}@media(max-width:700px){.agency-add{grid-template-columns:1fr}}
</style>
@endpush

@section('content')
<section class="content-header"><h1>广告代理 <small>维护代理名称及对应结算系数</small></h1></section>
<section class="content agency-page">
    @if(session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
    @if($errors->any())<div class="alert alert-danger">{{ $errors->first() }}</div>@endif
    <div class="box agency-card"><div class="box-header with-border"><h3 class="box-title">添加广告代理</h3></div><div class="box-body">
        <form class="agency-add" method="post" action="{{ route('assistant.advertising-agencies.store') }}">@csrf
            <div class="form-group"><input class="form-control" name="name" maxlength="100" value="{{ old('name') }}" placeholder="广告代理名称" required></div>
            <div class="form-group"><select class="form-control" name="advertising_media_id" required><option value="">请选择广告社媒</option>@foreach($media as $medium)<option value="{{ $medium->id }}" @selected((string)old('advertising_media_id')===(string)$medium->id)>{{ $medium->name }}</option>@endforeach</select></div>
            <div class="form-group"><input class="form-control" type="number" name="settlement_coefficient" min="0" max="9999" step="0.0001" value="{{ old('settlement_coefficient') }}" placeholder="结算系数" required></div>
            <button class="btn btn-primary"><i class="fa fa-plus"></i> 添加代理</button>
        </form>
    </div></div>
    <div class="box agency-card"><div class="box-header with-border"><h3 class="box-title">代理列表</h3><span class="text-muted"> 共 {{ $agencies->count() }} 条</span></div><div class="box-body no-padding table-responsive">
        <table class="table table-hover agency-table"><thead><tr><th style="width:90px">序号</th><th>广告代理名称</th><th style="width:180px">广告社媒</th><th style="width:180px">结算系数</th><th style="width:120px">状态</th><th style="width:250px">操作</th></tr></thead><tbody>
        @forelse($agencies as $item)<tr><td>{{ $loop->iteration }}</td><td><strong>{{ $item->name }}</strong></td><td>{{ $item->advertisingMedia?->name ?: '—' }}</td><td>{{ rtrim(rtrim(number_format((float)$item->settlement_coefficient,4,'.',''),'0'),'.') }}</td><td><span class="agency-state {{ $item->is_enabled ? 'on' : 'off' }}">{{ $item->is_enabled ? '已启用' : '已停用' }}</span></td><td><div class="agency-actions">
            <button type="button" class="btn btn-xs btn-primary agency-edit" data-action="{{ route('assistant.advertising-agencies.update',$item) }}" data-name="{{ $item->name }}" data-media-id="{{ $item->advertising_media_id }}" data-coefficient="{{ (float)$item->settlement_coefficient }}"><i class="fa fa-pencil"></i> 编辑</button>
            <form method="post" action="{{ route('assistant.advertising-agencies.toggle',$item) }}">@csrf @method('patch')<button class="btn btn-xs {{ $item->is_enabled ? 'btn-default' : 'btn-success' }}">{{ $item->is_enabled ? '停用' : '启用' }}</button></form>
            <form method="post" action="{{ route('assistant.advertising-agencies.destroy',$item) }}" onsubmit="return confirm('确定删除该广告代理吗？历史报表不会被删除。')">@csrf @method('delete')<button class="btn btn-xs btn-danger"><i class="fa fa-trash"></i> 删除</button></form>
        </div></td></tr>@empty<tr><td colspan="6" class="agency-empty">暂无广告代理</td></tr>@endforelse
        </tbody></table>
    </div></div>
</section>

<div class="modal fade" id="agency-edit-modal" tabindex="-1" role="dialog"><div class="modal-dialog" role="document"><div class="modal-content">
    <form method="post" id="agency-edit-form">@csrf @method('patch')
        <div class="modal-header"><button type="button" class="close" data-dismiss="modal"><span>&times;</span></button><h4 class="modal-title">编辑广告代理</h4></div>
        <div class="modal-body">
            <div class="form-group"><label for="agency-edit-name">广告代理名称 <span class="text-red">*</span></label><input id="agency-edit-name" class="form-control" name="name" maxlength="100" required></div>
            <div class="form-group"><label for="agency-edit-media">广告社媒 <span class="text-red">*</span></label><select id="agency-edit-media" class="form-control" name="advertising_media_id" required><option value="">请选择广告社媒</option>@foreach($media as $medium)<option value="{{ $medium->id }}">{{ $medium->name }}</option>@endforeach</select></div>
            <div class="form-group"><label for="agency-edit-coefficient">结算系数 <span class="text-red">*</span></label><input id="agency-edit-coefficient" class="form-control" type="number" name="settlement_coefficient" min="0" max="9999" step="0.0001" required></div>
        </div>
        <div class="modal-footer"><button type="button" class="btn btn-default" data-dismiss="modal">取消</button><button class="btn btn-primary"><i class="fa fa-save"></i> 保存修改</button></div>
    </form>
</div></div></div>
@endsection

@push('scripts')
<script>
(function(){var modal=document.getElementById('agency-edit-modal'),form=document.getElementById('agency-edit-form'),name=document.getElementById('agency-edit-name'),media=document.getElementById('agency-edit-media'),coefficient=document.getElementById('agency-edit-coefficient');if(!modal)return;document.addEventListener('click',function(e){var button=e.target.closest&&e.target.closest('.agency-edit');if(!button)return;form.action=button.getAttribute('data-action');name.value=button.getAttribute('data-name')||'';media.value=button.getAttribute('data-media-id')||'';coefficient.value=button.getAttribute('data-coefficient')||'';jQuery(modal).modal('show')})})();
</script>
@endpush
