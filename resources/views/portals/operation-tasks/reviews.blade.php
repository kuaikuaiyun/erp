@extends('portal.layout')

@section('page_title', '审核中心')

@include('portals.operation-tasks.partials.head')

@section('content')

@php
    $channels = $reviewChannels ?? ['leader' => '组长审核', 'department' => '部门审核'];
@endphp

<section class="content ot-page ot-review-page">
    <div class="ot-simple-page-head">
        <div>
            <h1>审核中心</h1>
            <p>二级主管处理组长审核；一级部门主管处理部门审核。仅展示当前部门范围内的数据。</p>
        </div>
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

    <div class="ot-review-section-label ot-review-section-label-recap"><i class="fa fa-calendar-check-o"></i> 每日复盘审核</div>
    <div class="ot-card ot-recap-review-card">
        <div class="ot-card-head"><span>每日复盘待审</span><span class="ot-badge ot-recap-count">{{ $recaps->count() }} 条</span></div>
        <div class="ot-card-body">
            @forelse($recaps as $recap)
                @php
                    $recapChannel = $recap->workflow_status === 'pending_leader' ? 'leader' : 'department';
                    $recapCanReview = $recapChannel === 'leader' ? $recap->can_leader_review : $recap->can_department_review;
                    $recapChannelLabel = $recapChannel === 'leader' ? '二级主管审核' : '一级主管审核';
                @endphp
                <article class="ot-recap-review-row">
                    <div class="ot-recap-review-answers">
                        @foreach(collect($recap->question_answers ?? []) as $answer)
                            <div><b>{{ $answer['title'] ?? '复盘问题' }}</b><p>{{ $answer['answer'] ?? '—' }}</p></div>
                        @endforeach
                    </div>
                    <div><b>{{ $recap->data_date->format('Y-m-d') }} · {{ $recap->user_name }}</b><small>{{ $recap->department_name ?: '未分配二级部门' }} · {{ $recapChannelLabel }}</small><p class="ot-recap-review-answer">{{ collect($recap->question_answers ?? [])->pluck('answer')->filter()->implode('；') }}</p></div>
                    <div class="ot-recap-review-metrics"><span>营业额 <b>${{ number_format($recap->total_revenue_usd, 2) }}</b></span><span>广告费 <b>${{ number_format($recap->total_spend_usd, 2) }}</b></span><span>订单 <b>{{ $recap->total_orders }}</b></span></div>
                    <div><button type="button" class="btn ot-primary btn-sm" data-toggle="modal" data-target="#ot-recap-audit-current-{{ $recap->id }}" @disabled(! $recapCanReview)><i class="fa fa-check"></i> 审核 / 驳回</button></div>
                    <div class="ot-review-detail-action"><button type="button" class="btn btn-default btn-sm" data-toggle="modal" data-target="#ot-recap-detail-review-{{ $recap->id }}"><i class="fa fa-eye"></i> 查看详情</button></div>
                </article>
                <div class="modal fade ot-modal" id="ot-recap-detail-review-{{ $recap->id }}" tabindex="-1"><div class="modal-dialog modal-lg"><div class="modal-content"><div class="modal-header"><button type="button" class="close" data-dismiss="modal">&times;</button><h4 class="modal-title">{{ $recap->data_date->format('Y-m-d') }} 复盘 · {{ $recap->user_name }}</h4></div><div class="modal-body"><table class="table ot-table"><thead><tr><th>媒体</th><th>花费</th><th>单量</th><th>营业额</th><th>ROI</th></tr></thead><tbody>@foreach($recap->media_items ?? [] as $item)<tr><td>{{ $item['name'] ?? '—' }}</td><td>${{ number_format((float)($item['spend'] ?? 0), 2) }}</td><td>{{ $item['orders'] ?? 0 }}</td><td>${{ number_format((float)($item['revenue'] ?? 0), 2) }}</td><td>{{ (float)($item['spend'] ?? 0) > 0 ? number_format((float)($item['revenue'] ?? 0)/(float)$item['spend'], 2) : '—' }}</td></tr>@endforeach</tbody></table><div class="ot-recap-review-answers">@foreach(collect($recap->question_answers ?? []) as $answer)<div><b>{{ $answer['title'] ?? '复盘问题' }}</b><p>{{ $answer['answer'] ?? '—' }}</p></div>@endforeach</div></div><div class="modal-footer"><button type="button" class="btn btn-default" data-dismiss="modal">关闭</button></div></div></div></div>
                <div class="modal fade ot-modal" id="ot-recap-audit-current-{{ $recap->id }}" tabindex="-1"><div class="modal-dialog"><div class="modal-content"><form method="post" action="{{ route('operation-tasks.recaps.reviews.store', $recap) }}">@csrf
                    <input type="hidden" name="channel" value="{{ $recapChannel }}">
                    <div class="modal-header"><button type="button" class="close" data-dismiss="modal">&times;</button><h4 class="modal-title">审核每日复盘</h4><p>{{ $recap->data_date->format('Y-m-d') }} · {{ $recap->user_name }} · {{ $recapChannelLabel }}</p></div>
                    <div class="modal-body"><div class="ot-modal-summary"><b>复盘经营汇总</b><div>营业额 ${{ number_format($recap->total_revenue_usd, 2) }} · 广告费 ${{ number_format($recap->total_spend_usd, 2) }} · 订单 {{ $recap->total_orders }}</div></div><div class="form-group"><label>审核结果 *</label><select class="form-control" name="result"><option value="approved">通过</option><option value="rejected">驳回并要求修改</option></select></div><div class="form-group"><label>审核意见 <small>驳回时必填</small></label><textarea class="form-control" rows="4" name="opinion" placeholder="请填写具体修改意见"></textarea></div></div>
                    <div class="modal-footer"><button type="button" class="btn btn-default" data-dismiss="modal">取消</button><button class="btn ot-primary">保存审核结果</button></div>
                </form></div></div></div>
            @empty
                <div class="ot-empty-state"><i class="fa fa-check-circle"></i><b>当前没有待审核的每日复盘</b><span>新的复盘提交后，会出现在这里。</span></div>
            @endforelse
        </div>
    </div>

    <div class="ot-review-section-label ot-review-section-label-test"><i class="fa fa-flask"></i> 测品任务审核</div>
    <div class="ot-card ot-test-review-card">
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
                            @php($review = $taskReviews->get($channel))
                            <div class="ot-channel-state"><span>{{ $label }}</span><b class="{{ $review?->result === 'rejected' ? 'is-rejected' : ($review?->result === 'approved' ? 'is-approved' : 'is-pending') }}">{{ $review?->result === 'rejected' ? '已驳回' : ($review?->result === 'approved' ? '已通过' : '待审核') }}</b></div>
                        @endforeach
                    </div>
                    <div class="ot-review-entry-actions"><a class="btn btn-default btn-sm" href="{{ route('operation-tasks.tests.show', $task) }}"><i class="fa fa-folder-open-o"></i> 查看档案</a>@if(in_array(true, $taskPermissions, true))<button class="btn ot-primary btn-sm" type="button" data-toggle="modal" data-target="#ot-audit-{{ $task->id }}"><i class="fa fa-check"></i> 开始审核</button>@endif</div>
                </article>
                <div class="modal fade ot-modal" id="ot-audit-{{ $task->id }}" tabindex="-1"><div class="modal-dialog modal-lg"><div class="modal-content">
                    <div class="modal-header"><button type="button" class="close" data-dismiss="modal">&times;</button><h4 class="modal-title">{{ $task->task_no }} · 审核工作台</h4><p>仅显示当前账号可操作的审核入口。</p></div>
                    <div class="modal-body">
                        <div class="ot-modal-summary"><b>选品理由与验证目标</b><div>{{ $task->selection_objective }}</div><a href="{{ route('operation-tasks.tests.show', $task) }}" target="_blank">在新窗口查看完整档案 <i class="fa fa-external-link"></i></a></div>
                        @php($activeChannel = array_key_first($channels))
                        @php($activeLabel = $channels[$activeChannel] ?? '审核')
                        <h4 class="ot-modal-section-title">素材审核 <span>{{ $task->assets->count() }} 条 · {{ $activeLabel }}</span></h4>
                        <form method="post" action="{{ route('operation-tasks.reviews.assets.store') }}">@csrf
                            <input type="hidden" name="task_id" value="{{ $task->id }}"><input type="hidden" name="channel" value="{{ $activeChannel }}">
                            <div class="table-responsive"><table class="table ot-audit-assets-table"><thead><tr><th>素材</th><th>表达卖点 / 测试目的</th><th>当前状态</th><th>审核结果</th><th>审核意见</th></tr></thead><tbody>
                                @foreach($task->assets as $asset)
                                    @php($assetReview = $asset->reviews->where('target_version', $asset->version)->keyBy('channel')->get($activeChannel))
                                    <tr><td><b>{{ $asset->original_name ?: '链接素材 #'.$asset->id }}</b><small>v{{ $asset->version }} · {{ $asset->uploader_name }}</small></td><td>{{ $asset->selling_point }}</td><td><span class="ot-badge {{ $assetReview?->result === 'rejected' ? 'red' : ($assetReview?->result === 'approved' ? '' : 'orange') }}">{{ $assetReview?->result === 'approved' ? '已通过' : ($assetReview?->result === 'rejected' ? '已驳回' : '待审核') }}</span></td><td><select class="form-control" name="assets[{{ $asset->id }}][result]"><option value="approved" @selected($assetReview?->result === 'approved')>通过</option><option value="rejected" @selected($assetReview?->result === 'rejected')>驳回并整改</option></select></td><td><textarea class="form-control" rows="2" name="assets[{{ $asset->id }}][opinion]" placeholder="驳回时必须填写整改意见">{{ $assetReview?->opinion }}</textarea></td></tr>
                                @endforeach
                            </tbody></table></div>
                            <div class="ot-batch-audit-footer"><span>请完成每条素材的审核结果后，再一次性保存。</span><button class="btn ot-primary"><i class="fa fa-save"></i> 保存全部素材审核</button></div>
                        </form>
                        <h4 class="ot-modal-section-title">任务审核 <span>{{ $activeLabel }}</span></h4>
                        @php($review = $taskReviews->get($activeChannel))
                        @include('portals.operation-tasks.partials.audit-form', ['channel' => $activeChannel, 'label' => $activeLabel, 'allowed' => $taskPermissions[$activeChannel] ?? false, 'review' => $review, 'targetType' => 'task', 'targetId' => $task->id, 'isAsset' => false])
                    </div>
                    <div class="modal-footer"><button type="button" class="btn btn-default" data-dismiss="modal">关闭</button></div>
                </div></div></div>
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
.ot-review-section-label{display:inline-flex;align-items:center;gap:7px;margin:17px 0 8px;padding:7px 12px;border-radius:16px;font-size:13px;font-weight:700}.ot-review-section-label-recap{color:#24739a;background:#e9f7ff}.ot-review-section-label-test{color:#a45d0a;background:#fff3e2}.ot-recap-review-card{border-top:3px solid #43a9d7}.ot-test-review-card{border-top:3px solid #f2a64b}.ot-review-queue-list{padding-top:4px}.ot-review-queue-row{display:grid;grid-template-columns:minmax(300px,1.35fr) minmax(260px,.8fr) auto;gap:18px;align-items:center;padding:18px 0;border-bottom:1px solid #e6edf0}.ot-review-queue-row:last-child{border-bottom:0}.ot-review-queue-main b{margin-left:8px;color:#1c4054}.ot-review-queue-main small{margin-left:8px;color:#748995;font-size:12px}.ot-review-queue-main p{margin:9px 0 5px;color:#4e6876}.ot-review-entry-actions{display:flex;gap:8px;white-space:nowrap}.ot-recap-review-row{display:grid;grid-template-columns:minmax(250px,1fr) minmax(260px,.8fr) auto;gap:18px;align-items:center;padding:15px 0;border-bottom:1px solid #e6edf0}.ot-recap-review-row:last-child{border-bottom:0}.ot-recap-review-row b,.ot-recap-review-row small{display:block}.ot-recap-review-row b{color:#1c4054}.ot-recap-review-row small{margin-top:5px;color:#718894}.ot-recap-review-metrics{display:flex;gap:16px;color:#647b86;font-size:12px}.ot-recap-review-metrics b{display:inline;margin-left:4px;color:#244b5c}.ot-audit-assets-table{min-width:960px;margin-bottom:0}.ot-audit-assets-table th{white-space:nowrap;background:#f3f8f9}.ot-audit-assets-table td{vertical-align:middle}.ot-audit-assets-table td>b,.ot-audit-assets-table td>small{display:block}.ot-audit-assets-table td>small{margin-top:4px;color:#718590;font-size:11px}.ot-audit-assets-table textarea{min-width:210px;resize:vertical}.ot-batch-audit-footer{display:flex;align-items:center;justify-content:space-between;gap:16px;padding:13px 0 4px;color:#718590;font-size:12px}.ot-batch-audit-footer .btn{white-space:nowrap}@media(max-width:1000px){.ot-review-queue-row,.ot-recap-review-row{grid-template-columns:1fr}.ot-review-entry-actions{justify-content:flex-start}}@media(max-width:650px){.ot-recap-review-metrics{flex-wrap:wrap}.ot-batch-audit-footer{align-items:flex-start;flex-direction:column}}
</style>
@endpush

@push('scripts')
<script>
(function () {
    function notify(message) {
        if (window.ErpAdminNotifier && typeof window.ErpAdminNotifier.notify === 'function') {
            window.ErpAdminNotifier.notify({type: 'warning', title: '提交前请检查', message: message});
        } else {
            window.alert(message);
        }
    }
    document.addEventListener('submit', function (event) {
        var form = event.target;
        if (!form || !form.matches('form[action*="/recaps/"][action*="/reviews"]')) return;
        var result = form.querySelector('[name="result"]');
        var opinion = form.querySelector('[name="opinion"]');
        if (result && result.value === 'rejected' && opinion && !opinion.value.trim()) {
            event.preventDefault();
            opinion.classList.add('has-error');
            opinion.focus();
            notify('选择驳回时，必须填写具体修改意见。');
        }
    });
    @if($errors->any())
    notify(@json($errors->first()));
    @endif
})();
</script>
@endpush
