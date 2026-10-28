@extends('portal.layout')

@section('page_title', '广告代理')

@push('styles')
<style>
.agency-page{max-width:1050px}.agency-card{border:0;border-radius:10px;box-shadow:0 4px 18px rgba(31,61,84,.08);overflow:hidden}.agency-add{display:grid;grid-template-columns:minmax(260px,1fr) 220px auto;gap:10px;align-items:start}.agency-add .form-group{margin:0}.agency-add .form-control,.agency-add .btn{height:39px;border-radius:6px}.agency-table th{background:#eef5fa;color:#3c586e}.agency-table td,.agency-table th{vertical-align:middle!important}.agency-state{display:inline-block;padding:4px 11px;border-radius:13px;font-size:12px}.agency-state.on{background:#dff5e9;color:#18764d}.agency-state.off{background:#edf0f2;color:#758391}.agency-actions{display:flex;gap:6px}.agency-empty{text-align:center;color:#8796a3;padding:35px!important}@media(max-width:700px){.agency-add{grid-template-columns:1fr}}
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
            <div class="form-group"><input class="form-control" type="number" name="settlement_coefficient" min="0" max="9999" step="0.0001" value="{{ old('settlement_coefficient') }}" placeholder="结算系数" required></div>
            <button class="btn btn-primary"><i class="fa fa-plus"></i> 添加代理</button>
        </form>
    </div></div>
    <div class="box agency-card"><div class="box-header with-border"><h3 class="box-title">代理列表</h3><span class="text-muted"> 共 {{ $agencies->count() }} 条</span></div><div class="box-body no-padding table-responsive">
        <table class="table table-hover agency-table"><thead><tr><th style="width:90px">序号</th><th>广告代理名称</th><th style="width:180px">结算系数</th><th style="width:120px">状态</th><th style="width:190px">操作</th></tr></thead><tbody>
        @forelse($agencies as $item)<tr><td>{{ $loop->iteration }}</td><td><strong>{{ $item->name }}</strong></td><td>{{ rtrim(rtrim(number_format((float)$item->settlement_coefficient,4,'.',''),'0'),'.') }}</td><td><span class="agency-state {{ $item->is_enabled ? 'on' : 'off' }}">{{ $item->is_enabled ? '已启用' : '已停用' }}</span></td><td><div class="agency-actions">
            <form method="post" action="{{ route('assistant.advertising-agencies.toggle',$item) }}">@csrf @method('patch')<button class="btn btn-xs {{ $item->is_enabled ? 'btn-default' : 'btn-success' }}">{{ $item->is_enabled ? '停用' : '启用' }}</button></form>
            <form method="post" action="{{ route('assistant.advertising-agencies.destroy',$item) }}" onsubmit="return confirm('确定删除该广告代理吗？历史报表不会被删除。')">@csrf @method('delete')<button class="btn btn-xs btn-danger"><i class="fa fa-trash"></i> 删除</button></form>
        </div></td></tr>@empty<tr><td colspan="5" class="agency-empty">暂无广告代理</td></tr>@endforelse
        </tbody></table>
    </div></div>
</section>
@endsection
