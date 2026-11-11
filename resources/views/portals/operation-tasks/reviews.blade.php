@extends('portal.layout')

@section('page_title', '审核中心')

@section('content')
@include('portals.operation-tasks.partials.head')

@php
    $channels = ['leader' => '组长审核', 'department' => '部门审核'];
@endphp

<section class="content ot-page ot-review-page">
    <div class="ot-simple-page-head">
        <div><h1>审核中心</h1><p>二级主管处理组长审核；一级部门主管处理部门审核。仅展示当前部门范围内的数据。</p></div>
    </div>

    <form class="ot-card ot-review-filters" method="get">
        <div class="ot-card-body">
            <div class="ot-filter-grid">
                <div class="form-group"><label>开始日期</label><input class="form-control" type="date" name="from" value="{{ $reviewFilters['from'] ?? '' }}"></div>
                <div class="form-group"><label>结束日期</label><input class="form-control" type="date" name="to" value="{{ $reviewFilters['to'] ?? '' }}"></div>
                <div class="form-group"><label>二级团队</label><select class="form-control" name="department"><option value="">全部二级团队</option>@foreach($reviewTeams as $team)<option value="{{ $team->id }}" @selected((string) ($reviewFilters['department'] ?? '') === (string) $team->id)>{{ $team->parent?->name ? $team->parent->name.' / ' : '' }}{{ $team->name }}</option>@endforeach</select></div>
                <div class="form-group"><label>审核状态</label><select class="form-control" name="status"><option value="">全部</option><option value="pending" @selected(($reviewFilters['status'] ?? '') === 'pending')>待我审核</option><option value="rectification" @selected(($reviewFilters['status'] ?? '') === 'rectification')>整改处理中</option><option value="approved" @selected(($reviewFilters['status'] ?? '') === 'approved')>我负责通道已通过</option></select></div>
                <div class="ot-filter-actions"><button class="btn ot-primary"><i class="fa fa-search"></i> 查询</button><a class="btn btn-default" href="{{ route('operation-tasks.reviews.index') }}">重置</a></div>
            </div>
            <div class="ot-filter-shortcuts"><span>快捷选择</span><a href="{{ route('operation-tasks.reviews.index', ['from' => now('Asia/Shanghai')->format('Y-m-d'), 'to' => now('Asia/Shanghai')->format('Y-m-d')]) }}">今天</a><a href="{{ route('operation-tasks.reviews.index', ['from' => now('Asia/Shanghai')->subDay()->format('Y-m-d'), 'to' => now('Asia/Shanghai')->subDay()->format('Y-m-d')]) }}">昨天</a><a href="{{ route('operation-tasks.reviews.index', ['from' => now('Asia/Shanghai')->subDays(6)->format('Y-m-d'), 'to' => now('Asia/Shanghai')->format('Y-m-d')]) }}">近7天</a><a href="{{ route('operation-tasks.reviews.index') }}">全部时间</a></div>
        </div>
    </form>

    <div class="ot-card">
        <div class="ot-card-head"><span>审核队列</span><span class="ot-badge">当前 {{ $tasks->total() }} 项</span></div>
        <div class="ot-card-body ot-review-queue-list">
            @forelse($tasks as $task)
                @php
                    $taskReviews = $task->reviews->where('target_type', 'task')->where('target_version', $task->version)->keyBy('channel');
                    $rectifying = $task->rectifications->whereIn('status', ['required', 'resubmitted'])->count();
                    $taskPermissions = ['leader' => (bool) $task->can_leader_review, 'department' => (bool) $task->can_department_review];
                @endphp
                <article class="ot-review-queue-row">
                    <div class="ot-review-queue-main">
                        <div><span class="ot-badge {{ $task->page_type === 'category' ? 'orange' : '' }}">{{ $task->page_type === 'category' ? '类目页' : '单品页' }}</span><b>{{ $task->task_no }}</b><small>{{ $task->owner_name }} · {{ $task->department_name ?: '未分配团队' }}</small></div>
                        <p>{{ $task->selection_objective }}</p>
                        <small>提交于 {{ optional($task->submitted_at)->format('Y-m-d H:i') }} · 当前版本 v{{ $task->version }} · 素材 {{ $task->assets->count() }} 条 @if($rectifying) · <span class="is-rejected">{{ $rectifying }} 项整改中</span>@endif</small>
                    </div>
                    <div class="ot-review-channel-summary">
                        @foreach($channels as $channel => $label)
                            @php $review = $taskReviews->get($channel); @endphp
                            <div class="ot-channel-state"><span>{{ $label }}</span><b class="{{ $review?->result === 'rejected' ? 'is-rejected' : ($review?->result === 'approved' ? 'is-approved' : 'is-pending') }}">{{ $review?->result === 'rejected' ? '已驳回' : ($review?->result === 'approved' ? '已通过' : '待审核') }}</b></div>
                        @endforeach
                    </div>
                    <div class="ot-review-entry-actions"><a class="btn btn-default btn-sm" href="{{ route('operation-tasks.tests.show', $task) }}"><i class="fa fa-folder-open-o"></i> 查看档案</a>@if(in_array(true, $taskPermissions, true))<button class="btn ot-primary btn-sm" type="button" data-toggle="modal" data-target="#ot-audit-{{ $task->id }}"><i class="fa fa-check"></i> 开始审核</button>@endif</div>
                </article>

                <div class="modal fade ot-modal" id="ot-audit-{{ $task->id }}" tabindex="-1">
                    <div class="modal-dialog modal-lg"><div class="modal-content">
                        <div class="modal-header"><button type="button" class="close" data-dismiss="modal">×</button><h4 class="modal-title">{{ $task->task_no }} · 审核工作台</h4><p>组长审核和部门审核彼此独立，仅显示当前账号可操作的审核入口。</p></div>
                        <div class="modal-body">
                            <div class="ot-modal-summary"><b>选品理由与验证目标</b><div>{{ $task->selection_objective }}</div><a href="{{ route('operation-tasks.tests.show', $task) }}" target="_blank">在新窗口查看完整档案 <i class="fa fa-external-link"></i></a></div>
                            <h4 class="ot-modal-section-title">任务审核</h4>
                            <div class="ot-audit-grid">
                                @foreach($channels as $channel => $label)
                                    @php $review = $taskReviews->get($channel); @endphp
                                    @include('portals.operation-tasks.partials.audit-form', ['channel' => $channel, 'label' => $label, 'allowed' => $taskPermissions[$channel], 'review' => $review, 'targetType' => 'task', 'targetId' => $task->id, 'isAsset' => false])
                                @endforeach
                            </div>
                            <h4 class="ot-modal-section-title">素材审核 <span>{{ $task->assets->count() }} 条</span></h4>
                            @foreach($task->assets as $asset)
                                @php $assetReviews = $asset->reviews->where('target_version', $asset->version)->keyBy('channel'); @endphp
                                <div class="ot-audit-asset"><div><b>{{ $asset->original_name ?: '链接素材' }}</b><small>{{ $asset->selling_point }}</small></div><div class="ot-audit-grid">@foreach($channels as $channel => $label)@php $review = $assetReviews->get($channel); @endphp@include('portals.operation-tasks.partials.audit-form', ['channel' => $channel, 'label' => $label, 'allowed' => $taskPermissions[$channel], 'review' => $review, 'targetType' => 'asset', 'targetId' => $asset->id, 'isAsset' => true])@endforeach</div></div>
                            @endforeach
                        </div>
                        <div class="modal-footer"><button type="button" class="btn btn-default" data-dismiss="modal">关闭</button></div>
                    </div></div>
                </div>
            @empty
                <div class="ot-empty-state"><i class="fa fa-check-circle"></i><b>当前筛选条件下暂无审核任务</b><span>调整筛选条件后再查看，或等待本部门成员提交新任务。</span></div>
            @endforelse
        </div>
        @if($tasks->hasPages())<div class="box-footer text-center">{{ $tasks->links() }}</div>@endif
    </div>
</section>
@endsection

@push('styles')
<style>
.ot-review-queue-list{padding-top:4px}.ot-review-queue-row{display:grid;grid-template-columns:minmax(300px,1.35fr) minmax(260px,.8fr) auto;gap:18px;align-items:center;padding:18px 0;border-bottom:1px solid #e6edf0}.ot-review-queue-row:last-child{border-bottom:0}.ot-review-queue-main b{margin-left:8px;color:#1c4054}.ot-review-queue-main small{margin-left:8px;color:#748995;font-size:12px}.ot-review-queue-main p{margin:9px 0 5px;color:#4e6876}.ot-review-entry-actions{display:flex;gap:8px;white-space:nowrap}.ot-audit-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px}.ot-audit-form-card{padding:14px;border:1px solid #dfebee;border-radius:9px;background:#f9fcfc}.ot-audit-form-card>b,.ot-audit-form-card>small{display:block}.ot-audit-form-card>small{margin:4px 0 10px;color:#738a94}.ot-audit-form-card .form-control{margin-bottom:8px}.ot-audit-form-card.is-readonly{background:#fbfcfc;border-style:dashed}.ot-audit-asset{margin-top:12px;padding:13px;border:1px solid #e2ecef;border-radius:9px}.ot-audit-asset>div:first-child{margin-bottom:10px}.ot-audit-asset small{display:block;color:#718590;margin-top:3px}@media(max-width:1000px){.ot-review-queue-row{grid-template-columns:1fr}.ot-review-entry-actions{justify-content:flex-start}}@media(max-width:650px){.ot-audit-grid{grid-template-columns:1fr}}
</style>
@endpush
