@extends('portal.layout')
@include('portals.assistant.daily-submissions.partials.template-assets')
@section('page_title', '开户需求处理')

@push('styles')
<style>
.opening-review{border:0;border-radius:12px;box-shadow:0 5px 18px rgba(31,67,98,.09);overflow:hidden}.opening-filters{display:flex;align-items:flex-end;gap:12px;flex-wrap:wrap}.opening-filters .form-group{margin:0}.opening-filters label{display:block;font-size:12px;color:#687f92}.opening-filters .form-control,.opening-filters .btn{height:36px;border-radius:6px}.opening-review-table{min-width:1250px;margin:0}.opening-review-table th{background:#e8f2f8;text-align:center}.status-pill{display:inline-block;padding:5px 11px;border-radius:15px;font-size:12px}.status-pill.pending{background:#fff0c2;color:#986700}.status-pill.processing{background:#dceeff;color:#1d6299}.status-pill.processed{background:#dff5e9;color:#18784c}.status-pill.rejected{background:#fde2e2;color:#b42318}.decision-actions{display:flex;gap:6px;flex-wrap:wrap;margin-top:9px;padding-top:8px;border-top:1px dashed #c9dce8;min-width:360px}.mark-processing-form{margin-bottom:7px}.opening-review td textarea[name="response_text"]{width:100%;min-width:360px;min-height:108px;padding:10px 12px;border:1px solid #a9c8dd;border-radius:6px;background:#fff;color:#243849;line-height:1.6;resize:vertical;box-shadow:inset 0 1px 2px rgba(31,67,98,.05)}.opening-review td textarea[name="response_text"]:focus{border-color:#3c8dbc;box-shadow:0 0 0 2px rgba(60,141,188,.16)}
</style>
@endpush

@section('content')
<section class="content-header"><h1>开户需求处理 <small>处理运营提交的广告平台开户需求</small></h1></section>
<section class="content daily-submission-template-page">
@if(session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
@if($errors->any())<div class="alert alert-danger">{{ $errors->first() }}</div>@endif
<div class="box opening-review"><div class="box-body"><form class="opening-filters" method="get">
    <div class="form-group"><label>运营部门</label><select class="form-control" name="operation_department_id"><option value="">全部部门</option>@foreach($departments as $department)<option value="{{ $department->id }}" @selected((string)($filters['operation_department_id'] ?? '') === (string)$department->id)>{{ $department->name }}</option>@endforeach</select></div>
    <div class="form-group"><label>广告代理</label><select class="form-control" name="agency"><option value="">全部代理</option>@foreach($agencyOptions as $agency)<option value="{{ $agency }}" @selected(($filters['agency'] ?? '') === $agency)>{{ $agency }}</option>@endforeach</select></div>
    <div class="form-group"><label>社媒</label><select class="form-control" name="media"><option value="">全部社媒</option>@foreach($mediaOptions as $media)<option value="{{ $media }}" @selected(($filters['media'] ?? '') === $media)>{{ $media }}</option>@endforeach</select></div>
    <div class="form-group"><label>状态</label><select class="form-control" name="status"><option value="">全部</option><option value="pending" @selected(($filters['status'] ?? '') === 'pending')>未处理</option><option value="processing" @selected(($filters['status'] ?? '') === 'processing')>处理中</option><option value="processed" @selected(($filters['status'] ?? '') === 'processed')>已处理</option><option value="rejected" @selected(($filters['status'] ?? '') === 'rejected')>已驳回</option></select></div>
    <div class="form-group"><label>运营人员</label><input class="form-control" name="operator" value="{{ $filters['operator'] ?? '' }}"></div>
    <button class="btn btn-primary"><i class="fa fa-search"></i> 查询</button><a class="btn btn-default" href="{{ route('assistant.daily-submissions.account-openings.index') }}">重置</a>
</form></div></div>
<div class="box opening-review"><div class="box-header with-border"><h3 class="box-title">开户需求列表 <small>共 {{ $submissions->total() }} 条</small></h3></div><div class="table-responsive"><table class="table table-bordered table-hover opening-review-table"><thead><tr><th>提交时间</th><th>运营部门/人员</th><th>广告代理</th><th>社媒</th><th>数量</th><th>邮箱</th><th>个号链接</th><th>BCID</th><th>状态</th><th>回复内容</th><th>处理人/时间</th><th>操作</th></tr></thead><tbody>
@forelse($submissions as $item)<tr>
    <td>{{ $item->created_at?->copy()->timezone('Asia/Shanghai')->format('Y-m-d H:i') }}</td><td>{{ $item->operation_department_name ?: '—' }}<br><small>{{ $item->operator_name }}</small></td><td>{{ $item->agency_name ?: '—' }}</td><td><strong>{{ $item->media }}</strong></td><td>{{ $item->quantity }}</td><td>{{ $item->email ?: '—' }}</td><td>{{ $item->profile_link ?: '—' }}</td><td>{{ $item->bcid ?: '—' }}</td><td><span class="status-pill {{ $item->status }}">{{ $item->statusLabel() }}</span></td><td style="white-space:pre-wrap;min-width:220px">{{ $item->response_text ?: '—' }}</td><td>{{ $item->processed_by_name ?: '—' }}@if($item->processed_at)<br><small>{{ $item->processed_at->copy()->timezone('Asia/Shanghai')->format('Y-m-d H:i') }}</small>@endif</td>
    <td style="min-width:270px">@if($item->status === 'pending')<form class="mark-processing-form" method="post" action="{{ route('assistant.daily-submissions.account-openings.processing', $item) }}">@csrf @method('PATCH')<button type="submit" class="btn btn-xs btn-info" onclick="return confirm('确认将这条开户需求标记为处理中？')"><i class="fa fa-hourglass-half"></i> 处理中</button></form>@endif @if(in_array($item->status, ['pending', 'processing'], true))<form method="post" action="{{ route('assistant.daily-submissions.account-openings.process', $item) }}" class="daily-response-form" data-loading="off">@csrf @method('PATCH')<input type="hidden" name="decision" value=""><input type="hidden" name="response_text" value=""><div class="decision-actions"><button type="button" class="btn btn-xs btn-success daily-response-trigger" data-decision="processed"><i class="fa fa-check"></i> 回复并标记已处理</button><button type="button" class="btn btn-xs btn-danger daily-response-trigger" data-decision="rejected"><i class="fa fa-times"></i> 驳回</button></div></form>@else — @endif</td>
</tr>@empty<tr><td colspan="12" class="text-center text-muted">暂无开户需求</td></tr>@endforelse
</tbody></table></div>@include('portals.assistant.partials.advertising-template-pagination', ['paginator' => $submissions, 'unit' => '条记录'])</div>
</section>
@endsection
@include('portals.assistant.daily-submissions.partials.response-modal')
@include('portals.assistant.partials.legacy-operation-department-multi-upgrade')
