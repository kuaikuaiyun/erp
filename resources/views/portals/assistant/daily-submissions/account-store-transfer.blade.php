@extends('portal.layout')
@section('page_title', '账号/店铺弃用转让处理')

@push('styles')
<style>
    .review-box{border:0;border-radius:12px;box-shadow:0 5px 18px rgba(31,67,98,.09);overflow:hidden}
    .filters{display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap}.review-table{min-width:1450px;margin:0}.review-table th{background:#eaf3f9;text-align:center}
    .tag{display:inline-block;padding:4px 9px;border-radius:13px}.tag.pending{background:#fff0c2;color:#986700}.tag.processed{background:#dff5e9;color:#18784c}.tag.discard{background:#fde5e2;color:#b93a2f}.tag.transfer{background:#e3effd;color:#2468a2}
    .reason-images{display:flex;gap:8px;flex-wrap:wrap;margin-top:9px}.reason-image{width:86px;padding:5px;border:1px solid #d9e4ec;border-radius:7px;background:#fff;text-align:center}.reason-image img{display:block;width:74px;height:58px;object-fit:cover;border-radius:4px;margin-bottom:4px}.reason-image .btn{padding:2px 5px}
</style>
@endpush

@section('content')
<section class="content-header"><h1>账号/店铺弃用转让处理 <small>处理运营提交的弃用或转让需求</small></h1></section>
<section class="content">
    @if(session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
    @if($errors->any())<div class="alert alert-danger">{{ $errors->first() }}</div>@endif
    <div class="box review-box"><div class="box-body">
        <form class="filters">
            <div><label>业务部门</label><select class="form-control" name="operation_department_id"><option value="">全部</option>@foreach($departments as $d)<option value="{{ $d->id }}" @selected((string)($filters['operation_department_id'] ?? '') === (string)$d->id)>{{ $d->name }}</option>@endforeach</select></div>
            <div><label>对象</label><select class="form-control" name="target_type"><option value="">全部</option><option value="account" @selected(($filters['target_type'] ?? '') === 'account')>账号</option><option value="store" @selected(($filters['target_type'] ?? '') === 'store')>店铺</option></select></div>
            <div><label>方式</label><select class="form-control" name="action_type"><option value="">全部</option><option value="discard" @selected(($filters['action_type'] ?? '') === 'discard')>弃用</option><option value="transfer" @selected(($filters['action_type'] ?? '') === 'transfer')>转让</option></select></div>
            <div><label>状态</label><select class="form-control" name="status"><option value="">全部</option><option value="pending" @selected(($filters['status'] ?? '') === 'pending')>未处理</option><option value="processed" @selected(($filters['status'] ?? '') === 'processed')>已处理</option></select></div>
            <div><label>运营</label><input class="form-control" name="operator" value="{{ $filters['operator'] ?? '' }}"></div>
            <button class="btn btn-primary">查询</button><a class="btn btn-default" href="{{ route('assistant.daily-submissions.account-store-transfers.index') }}">重置</a>
        </form>
    </div></div>
    <div class="box review-box"><div class="table-responsive"><table class="table table-bordered table-hover review-table">
        <thead><tr><th>提交时间</th><th>部门/运营</th><th>对象</th><th>方式</th><th>账号/店铺信息</th><th>理由/图片</th><th>状态/回复</th><th>处理</th></tr></thead>
        <tbody>@forelse($submissions as $item)<tr>
            <td>{{ $item->created_at?->copy()->timezone('Asia/Shanghai')->format('Y-m-d H:i') }}</td><td>{{ $item->operation_department_name ?: '—' }}<br><small>{{ $item->operator_name }}</small></td><td>{{ $item->targetLabel() }}</td><td><span class="tag {{ $item->action_type }}">{{ $item->actionLabel() }}</span></td>
            <td>@if($item->target_type === 'account')代理：{{ $item->agency_name }} / {{ $item->media_name }}<br>账号：{{ $item->account_name }}（{{ $item->account_number }}）@else 项目：{{ $item->project_name }}<br>建站系统：{{ $item->website_system_name }}<br>域名：{{ $item->store_domain }}@endif</td>
            <td style="white-space:pre-wrap">{{ $item->reason }}@if(!empty($item->reason_images))<div class="reason-images">@foreach($item->reason_images as $imageIndex => $reasonImage)<div class="reason-image"><a href="{{ route('assistant.daily-submissions.account-store-transfers.images.view', [$item, $imageIndex]) }}" target="_blank" title="查看原图"><img src="{{ route('assistant.daily-submissions.account-store-transfers.images.view', [$item, $imageIndex]) }}" alt="理由图片 {{ $imageIndex + 1 }}"></a><a class="btn btn-xs btn-default" href="{{ route('assistant.daily-submissions.account-store-transfers.images.download', [$item, $imageIndex]) }}"><i class="fa fa-download"></i> 下载</a></div>@endforeach</div>@endif</td>
            <td><span class="tag {{ $item->status }}">{{ $item->statusLabel() }}</span><br><span style="white-space:pre-wrap">{{ $item->response_text ?: '—' }}</span></td>
            <td style="min-width:250px">@if($item->status === 'pending')<form method="post" action="{{ route('assistant.daily-submissions.account-store-transfers.process', $item) }}">@csrf @method('PATCH')<textarea class="form-control" name="response_text" rows="3" maxlength="5000" required placeholder="填写处理结果"></textarea><button class="btn btn-xs btn-success" style="margin-top:6px"><i class="fa fa-check"></i> 回复并标记已处理</button></form>@else {{ $item->processed_by_name ?: '—' }}<br><small>{{ $item->processed_at?->copy()->timezone('Asia/Shanghai')->format('Y-m-d H:i') }}</small>@endif</td>
        </tr>@empty<tr><td colspan="8" class="text-center text-muted">暂无弃用/转让需求</td></tr>@endforelse</tbody>
    </table></div><div class="box-footer clearfix"><span class="text-muted">共 {{ $submissions->total() }} 条，第 {{ $submissions->currentPage() }} / {{ $submissions->lastPage() }} 页，每页 {{ $submissions->perPage() }} 条</span>@if($submissions->hasPages())<div class="pull-right">{{ $submissions->links() }}</div>@endif</div></div>
</section>
@endsection
@include('portals.assistant.partials.legacy-operation-department-multi-upgrade')
