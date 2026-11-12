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
                <div class="form-group"><label>二级团队</label><select class="form-control" name="department"><option value="">全部二级团队</option>@foreach($reviewTeams as $team)<option value="{{ $team->id }}" @selected((string) ($reviewFilters['department'] ?? '') === (string) $team->id)>{{ optional($team->parent)->name ? optional($team->parent)->name.' / ' : '' }}{{ $team->name }}</option>@endforeach</select></div>
                <div class="form-group"><label>审核状态</label><select class="form-control" name="status"><option value="">全部</option><option value="pending" @selected(($reviewFilters['status'] ?? '') === 'pending')>待我审核</option><option value="rectification" @selected(($reviewFilters['status'] ?? '') === 'rectification')>整改处理中</option><option value="approved" @selected(($reviewFilters['status'] ?? '') === 'approved')>我负责通道已通过</option></select></div>
                <div class="ot-filter-actions"><button class="btn ot-primary"><i class="fa fa-search"></i> 查询</button><a class="btn btn-default" href="{{ route('operation-tasks.reviews.index') }}">重置</a></div>
            </div>
            <div class="ot-filter-shortcuts"><span>快捷选择</span><a href="{{ route('operation-tasks.reviews.index', ['from' => now('Asia/Shanghai')->format('Y-m-d'), 'to' => now('Asia/Shanghai')->format('Y-m-d')]) }}">今天</a><a href="{{ route('operation-tasks.reviews.index', ['from' => now('Asia/Shanghai')->subDay()->format('Y-m-d'), 'to' => now('Asia/Shanghai')->subDay()->format('Y-m-d')]) }}">昨天</a><a href="{{ route('operation-tasks.reviews.index', ['from' => now('Asia/Shanghai')->subDays(6)->format('Y-m-d'), 'to' => now('Asia/Shanghai')->format('Y-m-d')]) }}">近7天</a><a href="{{ route('operation-tasks.reviews.index') }}">全部时间</a></div>
        </div>
    </form>

    <div class="ot-review-tabs" role="tablist">
        <button type="button" class="is-active" data-review-tab="recaps">每日复盘审核 <em>{{ $recaps->total() }}</em></button>
        <button type="button" data-review-tab="tests">测品任务审核 <em>{{ $tasks->total() }}</em></button>
    </div>

    <div class="ot-card ot-recap-review-card" data-review-panel="recaps">
        <div class="ot-card-body ot-recap-review-table-wrap">
            <table class="ot-recap-review-table ot-daily-review-table">
                <thead><tr><th>数据日期</th><th>运营</th><th>团队</th><th>营业额</th><th>广告费</th><th>订单量</th><th>ROI</th><th>二级主管审核</th><th>一级主管审核</th><th>审核阶段</th><th>操作</th></tr></thead>
                <tbody>
                @forelse($recaps as $recap)
                    @php
                        $recapChannel = $recap->workflow_status === 'pending_leader' ? 'leader' : 'department';
                        $recapCanReview = $recapChannel === 'leader' ? $recap->can_leader_review : $recap->can_department_review;
                        $recapChannelLabel = $recapChannel === 'leader' ? '二级主管审核' : '一级主管审核';
                        $recapStatusLabel = fn ($status) => match ($status) { 'approved' => '已审核', 'rejected' => '已驳回', default => '待审核' };
                    @endphp
                    <tr>
                        <td class="recap-date"><b>{{ $recap->data_date->format('Y-m-d') }}</b></td>
                        <td class="recap-owner"><b>{{ $recap->user_name }}</b></td>
                        <td class="recap-team">{{ $recap->department_name ?: '未分配二级部门' }}</td>
                        <td class="recap-revenue"><b>${{ number_format($recap->total_revenue_usd, 2) }}</b></td>
                        <td class="recap-spend"><b>${{ number_format($recap->total_spend_usd, 2) }}</b></td>
                        <td class="recap-orders"><b>{{ $recap->total_orders }}</b></td>
                        <td class="recap-roi"><b>{{ (float) $recap->total_spend_usd > 0 ? number_format((float) $recap->total_revenue_usd / (float) $recap->total_spend_usd, 2) : '—' }}</b></td>
                        <td class="test-reviewer-cell">
                            <div class="ot-stage-status">
                                <span class="ot-status {{ $recap->leader_review_status === 'approved' ? 'approved' : ($recap->leader_review_status === 'rejected' ? 'rejected' : 'pending') }}">{{ $recap->leader_review_status === 'approved' ? '已通过' : ($recap->leader_review_status === 'rejected' ? '已驳回' : '待审核') }}</span>
                                <small>{{ $recap->leader_reviewer_names ?: '未配置' }}</small>
                            </div>
                        </td>
                        <td class="test-reviewer-cell">
                            <div class="ot-stage-status">
                                <span class="ot-status {{ $recap->department_review_status === 'approved' ? 'approved' : ($recap->department_review_status === 'rejected' ? 'rejected' : 'pending') }}">{{ $recap->department_review_status === 'approved' ? '已通过' : ($recap->department_review_status === 'rejected' ? '已驳回' : '待审核') }}</span>
                                <small>{{ $recap->department_reviewer_names ?: '未配置' }}</small>
                            </div>
                        </td>
                        <td class="recap-stage">@include('portals.operation-tasks.partials.review-flow', ['record' => $recap])</td>
                        <td class="recap-actions"><button type="button" class="btn ot-primary btn-sm" data-toggle="modal" data-target="#ot-recap-audit-current-{{ $recap->id }}" @disabled(! $recapCanReview)>审核</button></td>
                    </tr>
                @empty
                    <tr><td colspan="11"><div class="ot-empty-state"><i class="fa fa-check-circle"></i><b>当前没有待审核的每日复盘</b><span>新的复盘提交后，会出现在这里。</span></div></td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        @include('portals.operation-tasks.partials.pagination', ['paginator' => $recaps, 'perPageName' => 'recap_per_page', 'activeTab' => 'recaps'])

        @foreach($recaps as $recap)
                @php
                    $recapChannel = $recap->workflow_status === 'pending_leader' ? 'leader' : 'department';
                    $recapCanReview = $recapChannel === 'leader' ? $recap->can_leader_review : $recap->can_department_review;
                    $recapChannelLabel = $recapChannel === 'leader' ? '二级主管审核' : '一级主管审核';
                    $recapStatusLabel = fn ($status) => match ($status) { 'approved' => '已审核', 'rejected' => '已驳回', default => '待审核' };
                @endphp
                <div class="modal fade ot-modal" id="ot-recap-audit-current-{{ $recap->id }}" tabindex="-1"><div class="modal-dialog modal-lg"><div class="modal-content"><form method="post" action="{{ route('operation-tasks.recaps.reviews.store', $recap) }}">@csrf
                    <input type="hidden" name="channel" value="{{ $recapChannel }}">
                    <div class="modal-header"><button type="button" class="close" data-dismiss="modal">&times;</button><h4 class="modal-title">审核每日复盘</h4><p>{{ $recap->data_date->format('Y-m-d') }} · {{ $recap->user_name }} · {{ $recapChannelLabel }}</p></div>
                    <div class="modal-body"><div class="ot-modal-summary"><b>复盘经营汇总</b><div>营业额 ${{ number_format($recap->total_revenue_usd, 2) }} · 广告费 ${{ number_format($recap->total_spend_usd, 2) }} · 订单 {{ $recap->total_orders }}</div></div><h4 class="ot-modal-section-title">媒体经营数据</h4><div class="table-responsive"><table class="table ot-table"><thead><tr><th>媒体</th><th>花费</th><th>单量</th><th>营业额</th><th>ROI</th></tr></thead><tbody>@forelse($recap->media_items ?? [] as $item)<tr><td>{{ $item['name'] ?? '—' }}</td><td>${{ number_format((float)($item['spend'] ?? 0), 2) }}</td><td>{{ $item['orders'] ?? 0 }}</td><td>${{ number_format((float)($item['revenue'] ?? 0), 2) }}</td><td>{{ (float)($item['spend'] ?? 0) > 0 ? number_format((float)($item['revenue'] ?? 0)/(float)$item['spend'], 2) : '—' }}</td></tr>@empty<tr><td colspan="5" class="text-center text-muted">暂无媒体数据</td></tr>@endforelse</tbody></table></div><h4 class="ot-modal-section-title">复盘内容</h4><div class="ot-recap-review-answers">@foreach(collect($recap->question_answers ?? []) as $index => $answer)<div><b>问题{{ $index + 1 }}：{{ $answer['title'] ?? '复盘问题' }}</b><p>{{ $answer['answer'] ?? '—' }}</p></div>@endforeach</div><h4 class="ot-modal-section-title">审核处理</h4><div class="ot-recap-review-progress"><div><b>二级主管：{{ $recap->leader_reviewer_names }}</b><span>{{ $recapStatusLabel($recap->leader_review_status) }}</span></div><div><b>一级主管：{{ $recap->department_reviewer_names }}</b><span>{{ $recapStatusLabel($recap->department_review_status) }}</span></div></div><div class="form-group"><label>审核结果 *</label><select class="form-control" name="result"><option value="approved">通过</option><option value="rejected">驳回并要求修改</option></select></div><div class="form-group"><label>审核意见 <small>驳回时必填</small></label><textarea class="form-control" rows="4" name="opinion" placeholder="请填写具体修改意见"></textarea></div></div>
                    <div class="modal-footer"><button type="button" class="btn btn-default" data-dismiss="modal">取消</button><button class="btn ot-primary">保存审核结果</button></div>
                </form></div></div></div>
        @endforeach
    </div>

    <div class="ot-card ot-test-review-card" data-review-panel="tests">
        <div class="ot-card-body ot-recap-review-table-wrap">
            <table class="ot-recap-review-table ot-test-review-table">
                <thead><tr><th>任务编号</th><th>运营</th><th>团队</th><th>页面类型</th><th>提交时间</th><th>素材信息</th><th>二级主管审核</th><th>一级主管审核</th><th>审核阶段</th><th>操作</th></tr></thead>
                <tbody>
            @forelse($tasks as $task)
                @php
                    $taskReviews = $task->reviews->where('target_type', 'task')->where('target_version', $task->version)->keyBy('channel');
                    $taskPermissions = ['leader' => (bool) $task->can_leader_review, 'department' => (bool) $task->can_department_review];
                    $taskActiveChannel = array_key_first($channels);
                    $reviewedAssetCount = $task->assets->filter(function ($asset) use ($taskActiveChannel): bool {
                        return $asset->reviews
                            ->where('target_version', $asset->version)
                            ->where('channel', $taskActiveChannel)
                            ->whereIn('result', ['approved', 'rejected'])
                            ->isNotEmpty();
                    })->count();
                @endphp
                <tr>
                    <td class="test-task-no"><b>{{ $task->task_no }}</b></td>
                    <td class="test-owner"><b>{{ $task->owner_name }}</b></td>
                    <td class="test-team">{{ $task->department_name ?: '未分配二级部门' }}</td>
                    <td class="test-page-type"><span class="ot-badge {{ $task->page_type === 'category' ? 'orange' : '' }}">{{ $task->page_type === 'category' ? '类目页' : '单品页' }}</span></td>
                    <td class="test-submitted-at">{{ \App\Portals\OperationTasks\Support\OperationTaskTime::format($task->created_at, 'Y-m-d H:i') }}</td>
                    <td class="test-asset-info">
                        <b>{{ $task->assets->count() }} 条素材</b>
                        <small>审核进度 {{ $reviewedAssetCount }}/{{ $task->assets->count() }}</small>
                    </td>
                    <td class="test-reviewer-cell">
                        <div class="ot-stage-status">
                            <span class="ot-status {{ $task->leader_review_status === 'approved' ? 'approved' : ($task->leader_review_status === 'rejected' ? 'rejected' : 'pending') }}">{{ $task->leader_review_status === 'approved' ? '已通过' : ($task->leader_review_status === 'rejected' ? '已驳回' : '待审核') }}</span>
                        </div>
                    </td>
                    <td class="test-reviewer-cell">
                        <div class="ot-stage-status">
                            <span class="ot-status {{ $task->department_review_status === 'approved' ? 'approved' : ($task->department_review_status === 'rejected' ? 'rejected' : 'pending') }}">{{ $task->department_review_status === 'approved' ? '已通过' : ($task->department_review_status === 'rejected' ? '已驳回' : '待审核') }}</span>
                        </div>
                    </td>
                    <td class="recap-stage">@include('portals.operation-tasks.partials.review-flow', ['record' => $task])</td>
                    <td class="recap-actions"><div class="ot-review-actions"><a class="btn btn-info btn-sm" href="{{ $task->landing_url }}" target="_blank" rel="noopener">落地页</a><a class="btn btn-default btn-sm" href="{{ route('operation-tasks.tests.show', $task) }}">档案</a>@if(in_array(true, $taskPermissions, true))<button class="btn ot-primary btn-sm" type="button" data-toggle="modal" data-target="#ot-audit-{{ $task->id }}">审核</button>@endif</div></td>
                </tr>
            @empty
                <tr><td colspan="10"><div class="ot-empty-state"><i class="fa fa-check-circle"></i><b>当前筛选条件下暂无审核任务</b><span>调整筛选条件后再查看，或等待本部门成员提交新任务。</span></div></td></tr>
            @endforelse
                </tbody>
            </table>
        </div>
        @include('portals.operation-tasks.partials.pagination', ['paginator' => $tasks, 'perPageName' => 'task_per_page', 'activeTab' => 'tests'])

        @foreach($tasks as $task)
                @php
                    $taskReviews = $task->reviews->where('target_type', 'task')->where('target_version', $task->version)->keyBy('channel');
                    $taskPermissions = ['leader' => (bool) $task->can_leader_review, 'department' => (bool) $task->can_department_review];
                @endphp
                <div class="modal fade ot-modal ot-test-audit-modal" id="ot-audit-{{ $task->id }}" tabindex="-1"><div class="modal-dialog modal-lg"><div class="modal-content">
                    <div class="modal-header"><button type="button" class="close" data-dismiss="modal">&times;</button><h4 class="modal-title">{{ $task->task_no }} · 测品任务审核</h4><p>先核对任务资料，再逐条审核素材，最后确认整项任务。</p></div>
                    <div class="modal-body">
                        @php
                            $activeChannel = array_key_first($channels);
                            $activeLabel = $channels[$activeChannel] ?? '审核';
                            $leaderTaskReview = $taskReviews->get('leader');
                        @endphp
                        <div class="ot-audit-overview">
                            <div class="ot-audit-overview-meta">
                                <div><small>任务类型</small><b>{{ $task->page_type === 'category' ? '类目页' : '单品页' }} · {{ $task->task_type === 'iteration' ? '迭代测试' : '新测试' }}</b></div>
                                <div><small>提交运营</small><b>{{ $task->owner_name }} · {{ $task->department_name ?: '未分配团队' }}</b></div>
                                <div><small>当前审核</small><b>{{ $activeLabel }} · {{ $task->assets->count() }} 条素材</b></div>
                                <a class="btn btn-default btn-sm" href="{{ route('operation-tasks.tests.show', $task) }}" target="_blank"><i class="fa fa-folder-open-o"></i> 查看完整档案</a>
                            </div>
                            <div class="ot-audit-overview-objective"><small>选品理由与验证目标</small><p>{{ $task->selection_objective }}</p></div>
                        </div>
                        <form class="ot-unified-audit-form" method="post" action="{{ route('operation-tasks.reviews.batch.store') }}">
                            @csrf
                        <section class="ot-audit-section">
                            <div class="ot-audit-section-head"><div><span>01</span><h4>素材逐条审核</h4></div><small>{{ $task->assets->count() }} 条素材 · {{ $activeLabel }}</small></div>
                             <input type="hidden" name="task_id" value="{{ $task->id }}"><input type="hidden" name="channel" value="{{ $activeChannel }}">
                            @if($activeChannel === 'department' && $leaderTaskReview)
                                <div class="ot-prior-review">
                                    <div class="ot-prior-review-title">二级主管任务审核记录</div>
                                    <div class="ot-prior-review-meta">
                                        <b>{{ $leaderTaskReview->reviewer_name ?: '二级主管' }}</b>
                                        <span class="ot-badge {{ $leaderTaskReview->result === 'rejected' ? 'red' : '' }}">{{ $leaderTaskReview->result === 'rejected' ? '已驳回' : '已通过' }}</span>
                                        <span>{{ \App\Portals\OperationTasks\Support\OperationTaskTime::format($leaderTaskReview->reviewed_at) }}</span>
                                    </div>
                                    <p>{{ $leaderTaskReview->opinion ?: '未填写审核意见' }}</p>
                                </div>
                            @endif
                            <div class="table-responsive ot-audit-table-wrap"><table class="table ot-audit-assets-table"><thead><tr><th>素材信息</th><th>表达卖点 / 测试目的</th><th>当前状态</th><th>审核结果</th><th>审核意见</th></tr></thead><tbody>
                                @foreach($task->assets as $asset)
                                    @php
                                        $assetReview = $asset->reviews->where('target_version', $asset->version)->keyBy('channel')->get($activeChannel);
                                        $assetReviewResult = optional($assetReview)->result;
                                        $assetReviewOpinion = optional($assetReview)->opinion;
                                        $assetBadgeClass = $assetReviewResult === 'rejected' ? 'red' : ($assetReviewResult === 'approved' ? '' : 'orange');
                                        $assetStatusText = $assetReviewResult === 'approved' ? '已通过' : ($assetReviewResult === 'rejected' ? '已驳回' : '待审核');
                                        $assetPreviewUrl = $asset->file_path ? route('operation-tasks.assets.file', $asset) : trim((string) $asset->external_url);
                                        $assetPreviewName = $asset->original_name ?: ('链接素材 #'.$asset->id);
                                        $assetPreviewProbe = strtolower($assetPreviewName.' '.$assetPreviewUrl);
                                        $assetMime = strtolower((string) $asset->mime_type);
                                        $assetIsImage = str_starts_with($assetMime, 'image/') || preg_match('/\.(?:jpe?g|png|gif|webp|bmp|svg)(?:\?.*)?$/i', $assetPreviewProbe);
                                        $assetIsVideo = str_starts_with($assetMime, 'video/') || preg_match('/\.(?:mp4|webm|ogg|mov|m4v)(?:\?.*)?$/i', $assetPreviewProbe);
                                        $assetPreviewType = $assetIsImage ? 'image' : ($assetIsVideo ? 'video' : 'link');
                                        $leaderAssetReview = $asset->reviews->where('target_version', $asset->version)->where('channel', 'leader')->first();
                                    @endphp
                                    <tr><td><div class="ot-asset-info">@if($assetPreviewUrl)<button type="button" class="ot-asset-preview-trigger" data-asset-preview data-preview-type="{{ $assetPreviewType }}" data-preview-src="{{ $assetPreviewUrl }}" data-preview-name="{{ $assetPreviewName }}" aria-label="预览 {{ $assetPreviewName }}">@if($assetIsImage)<img src="{{ $assetPreviewUrl }}" loading="lazy" alt="">@elseif($assetIsVideo)<span class="ot-asset-thumb is-video"><i class="fa fa-play"></i></span>@else<span class="ot-asset-thumb is-link"><i class="fa fa-external-link"></i></span>@endif</button>@endif<div class="ot-asset-copy"><button type="button" class="ot-asset-name" @if($assetPreviewUrl) data-asset-preview data-preview-type="{{ $assetPreviewType }}" data-preview-src="{{ $assetPreviewUrl }}" data-preview-name="{{ $assetPreviewName }}" @else disabled @endif>{{ $assetPreviewName }}</button></div></div></td><td><span class="ot-badge">{{ $asset->source_type === 'ai_created' ? 'AI制作' : ($asset->source_type === 'peer_copied' ? '同行搬运' : '未标记') }}</span><div>{{ $asset->selling_point }}</div></td><td><span class="ot-badge {{ $assetBadgeClass }}">{{ $assetStatusText }}</span>@if($activeChannel === 'department' && $leaderAssetReview)<div class="ot-prior-asset-review"><b>二级主管：{{ $leaderAssetReview->reviewer_name ?: '未记录' }}</b><span>{{ $leaderAssetReview->result === 'rejected' ? '驳回' : '通过' }} · {{ \App\Portals\OperationTasks\Support\OperationTaskTime::format($leaderAssetReview->reviewed_at) }}</span>@if($leaderAssetReview->opinion)<p>{{ $leaderAssetReview->opinion }}</p>@endif</div>@endif</td><td><select class="form-control" name="assets[{{ $asset->id }}][result]"><option value="approved" @selected($assetReviewResult === 'approved')>通过</option><option value="rejected" @selected($assetReviewResult === 'rejected')>驳回该素材</option></select></td><td><textarea class="form-control" rows="2" name="assets[{{ $asset->id }}][opinion]" placeholder="驳回该素材时必须填写整改意见">{{ $assetReviewOpinion }}</textarea></td></tr>
                                @endforeach
                            </tbody></table></div>
                            <div class="ot-batch-audit-footer"><span><i class="fa fa-info-circle"></i> 请确认每条素材的结果；选择驳回时必须填写具体整改意见。</span></div>
                        </section>
                        @php
                            $review = $taskReviews->get($activeChannel);
                        @endphp
                        <section class="ot-audit-section ot-task-audit-section">
                            <div class="ot-audit-section-head"><div><span>02</span><h4>任务整体审核</h4></div><small>{{ $activeLabel }}</small></div>
                            <div class="ot-task-audit-grid">
                                <div class="ot-task-audit-fields">
                                    <div class="ot-task-audit-field">
                                        <label>审核结果</label>
                                        <select class="form-control" name="task_result"><option value="approved" @selected($review?->result === 'approved')>通过</option><option value="rejected" @selected($review?->result === 'rejected')>驳回并整改</option></select>
                                    </div>
                                    <div class="ot-task-audit-field">
                                        <label>驳回对象</label>
                                        <div class="ot-rejection-scopes">
                                            <label><input type="checkbox" name="rejection_scopes[]" value="task"> 落地页</label>
                                            <label><input type="checkbox" name="rejection_scopes[]" value="asset"> 素材问题</label>
                                            <small>驳回时至少选择一项；选择素材问题时还需驳回至少一条素材。</small>
                                        </div>
                                    </div>
                                </div>
                                <div class="ot-task-audit-opinion">
                                    <label>审核意见</label>
                                    <textarea class="form-control" name="task_opinion" placeholder="通过时选填；驳回时必须填写具体整改意见">{{ $review?->opinion }}</textarea>
                                </div>
                            </div>
                        </section>
                        <div class="ot-unified-audit-actions"><span><i class="fa fa-shield"></i> 素材与任务审核将作为一次操作统一保存。</span><button type="submit" class="btn ot-primary"><i class="fa fa-save"></i> 统一保存审核结果</button></div>
                        </form>
                    </div>
                    <div class="modal-footer"><button type="button" class="btn btn-default" data-dismiss="modal">关闭</button></div>
                </div></div></div>
        @endforeach
        <div class="modal fade ot-modal ot-asset-preview-modal" id="ot-asset-preview-modal" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-lg"><div class="modal-content">
                <div class="modal-header"><button type="button" class="close" data-dismiss="modal">&times;</button><h4 class="modal-title" id="ot-asset-preview-title">素材预览</h4></div>
                <div class="modal-body"><div class="ot-asset-preview-stage">
                    <img id="ot-asset-preview-image" alt="素材预览">
                    <video id="ot-asset-preview-video" controls playsinline preload="metadata"></video>
                    <div id="ot-asset-preview-link" class="ot-asset-preview-link"><i class="fa fa-external-link-square"></i><p>该素材不是可直接预览的图片或视频。</p><a class="btn ot-primary" target="_blank" rel="noopener">在新窗口打开</a></div>
                </div></div>
                <div class="modal-footer"><a id="ot-asset-preview-open" class="btn btn-default" target="_blank" rel="noopener"><i class="fa fa-external-link"></i> 新窗口打开</a><button type="button" class="btn btn-default" data-dismiss="modal">关闭</button></div>
            </div></div>
        </div>
    </div>
</section>
@endsection

@push('styles')
<style>
.ot-review-tabs{display:inline-flex;gap:0!important;margin:0 0 14px;border:1px solid #cbdce8;border-radius:3px;background:#fff;overflow:hidden}.ot-review-tabs button{display:flex;align-items:center;justify-content:center;gap:7px;min-width:190px;height:38px;padding:0 20px;border:0!important;border-right:1px solid #cbdce8!important;border-bottom:0!important;background:#fff!important;color:#49667c!important;font-size:13px;font-weight:400!important;text-align:center;cursor:pointer;transition:background .16s ease,color .16s ease}.ot-review-tabs button:last-child{border-right:0!important}.ot-review-tabs button:hover{background:#eef7fc!important;color:#247fae!important}.ot-review-tabs button.is-active{background:#3292c5!important;color:#fff!important}.ot-review-tabs button em{display:inline;color:inherit;font-size:12px;font-style:normal;font-weight:400}.ot-review-pagination{padding:10px 0 18px;text-align:center}.ot-review-pagination .pagination{margin:0}
.ot-test-audit-modal .modal-dialog{width:min(1080px,calc(100% - 36px))!important}.ot-test-audit-modal .modal-header{padding:18px 24px}.ot-test-audit-modal .modal-body{padding:18px 22px 24px;background:#f4f7f9!important}.ot-test-audit-modal .modal-footer{padding:11px 22px}.ot-audit-overview,.ot-audit-section{border:1px solid #dbe6eb;border-radius:8px;background:#fff;box-shadow:0 2px 8px rgba(35,67,83,.035)}.ot-audit-overview{margin-bottom:16px;overflow:hidden}.ot-audit-overview-meta{display:grid;grid-template-columns:1fr 1.25fr 1fr auto;gap:0;align-items:center;border-bottom:1px solid #e4ecef}.ot-audit-overview-meta>div{min-height:68px;padding:13px 16px;border-right:1px solid #e4ecef}.ot-audit-overview-meta small,.ot-audit-overview-meta b{display:block}.ot-audit-overview-meta small,.ot-audit-overview-objective small{margin-bottom:5px;color:#7a8d98;font-size:11px}.ot-audit-overview-meta b{color:#27495b;font-size:13px;line-height:1.45}.ot-audit-overview-meta>a{margin:0 16px;white-space:nowrap}.ot-audit-overview-objective{padding:14px 16px}.ot-audit-overview-objective p{margin:0;color:#405f70;line-height:1.65;white-space:pre-wrap}.ot-audit-section{margin-top:16px;overflow:hidden}.ot-audit-section-head{display:flex;align-items:center;justify-content:space-between;min-height:48px;padding:0 16px;border-bottom:1px solid #dfe9ed;background:#f7fafb}.ot-audit-section-head>div{display:flex;align-items:center;gap:9px}.ot-audit-section-head span{display:inline-flex;width:25px;height:25px;align-items:center;justify-content:center;border-radius:5px;background:#e5f4ef;color:#21806e;font-size:11px;font-weight:700}.ot-audit-section-head h4{margin:0;color:#244657;font-size:15px;font-weight:700}.ot-audit-section-head small{color:#718690}.ot-audit-table-wrap{margin:0;overflow-x:auto}.ot-test-audit-modal .ot-audit-assets-table{margin:0}.ot-test-audit-modal .ot-audit-assets-table thead th{padding:10px 12px;border:0;border-bottom:1px solid #dfe8ec;background:#edf4f7;color:#49697a;font-size:12px;vertical-align:middle}.ot-test-audit-modal .ot-audit-assets-table td{padding:12px;border:0;border-bottom:1px solid #e6edef;vertical-align:middle}.ot-test-audit-modal .ot-audit-assets-table tbody tr:last-child td{border-bottom:0}.ot-test-audit-modal .ot-audit-assets-table tbody tr:nth-child(even){background:#fafcfd}.ot-test-audit-modal .ot-audit-assets-table td:first-child b,.ot-test-audit-modal .ot-audit-assets-table td:first-child small{display:block}.ot-test-audit-modal .ot-audit-assets-table td:first-child b{color:#244657;line-height:1.45}.ot-test-audit-modal .ot-audit-assets-table td:first-child small{margin-top:5px;color:#7c8e98}.ot-test-audit-modal .ot-audit-assets-table .form-control{border-color:#cad9e1;border-radius:4px;box-shadow:none}.ot-test-audit-modal .ot-audit-assets-table select.form-control{height:36px}.ot-test-audit-modal .ot-audit-assets-table textarea.form-control{min-height:58px;resize:vertical}.ot-test-audit-modal .ot-batch-audit-footer{display:flex;align-items:center;justify-content:space-between;gap:18px;padding:13px 16px!important;border-top:1px solid #dfe9ed!important;background:#f8fbfc}.ot-test-audit-modal .ot-batch-audit-footer span{max-width:none!important;color:#677f8c;font-size:12px}.ot-test-audit-modal .ot-batch-audit-footer i{margin-right:5px;color:#2d8b79}.ot-task-audit-section{padding-bottom:0}.ot-task-audit-section .ot-audit-form-card{margin:0;padding:16px;border:0;border-radius:0;background:#fff}.ot-task-audit-section .ot-audit-form-card>b,.ot-task-audit-section .ot-audit-form-card>small{display:none}.ot-task-audit-section .ot-audit-form-card form{display:grid;grid-template-columns:180px 220px minmax(260px,1fr) auto;gap:10px;align-items:end}.ot-task-audit-section .ot-audit-form-card textarea{min-height:70px;resize:vertical}.ot-task-audit-section .ot-audit-form-card .btn{height:34px;white-space:nowrap}.ot-test-audit-modal .modal-footer .btn{min-width:72px}
.ot-task-audit-grid{display:grid;grid-template-columns:360px minmax(0,1fr);gap:18px;align-items:stretch;padding:16px}.ot-task-audit-grid label{display:block;margin:0 0 6px;color:#496575;font-size:12px;font-weight:700;line-height:18px}.ot-task-audit-fields{display:grid;grid-template-columns:1fr;gap:12px}.ot-task-audit-field{min-width:0}.ot-task-audit-fields .form-control{width:100%;height:38px}.ot-task-audit-opinion{display:flex;min-width:0;flex-direction:column}.ot-task-audit-opinion textarea{width:100%;height:auto;min-height:0;flex:1;resize:vertical}.ot-task-audit-grid .form-control{border-color:#cad9e1;border-radius:4px;box-shadow:none}.ot-unified-audit-actions{display:flex;align-items:center;justify-content:space-between;gap:18px;margin-top:16px;padding:14px 16px;border:1px solid #dbe6eb;border-radius:8px;background:#fff}.ot-unified-audit-actions span{color:#687f8c;font-size:12px}.ot-unified-audit-actions span i{margin-right:5px;color:#258573}.ot-unified-audit-actions .btn{min-width:180px;height:38px}
.ot-review-target{display:flex;min-height:38px;align-items:center;gap:10px;padding:7px 11px;border:1px solid #cad9e1;border-radius:4px;background:#f7fafb}.ot-review-target b{color:#244657}.ot-review-target span{color:#718690;font-size:11px}.ot-prior-review{margin:14px 16px 0;padding:12px 14px;border:1px solid #cfe1ea;border-left:3px solid #3292c5;border-radius:5px;background:#f5fafc}.ot-prior-review-title{margin-bottom:7px;color:#244657;font-weight:700}.ot-prior-review-meta{display:flex;align-items:center;gap:9px;color:#728691;font-size:12px}.ot-prior-review p{margin:7px 0 0;color:#496575;white-space:pre-wrap}.ot-prior-asset-review{margin-top:8px;padding-top:7px;border-top:1px dashed #d5e2e7;color:#647c88;font-size:11px;line-height:1.5}.ot-prior-asset-review b,.ot-prior-asset-review span{display:block}.ot-prior-asset-review p{margin:4px 0 0;color:#8a5b45;white-space:pre-wrap}
.ot-test-audit-modal .modal-dialog{width:min(1280px,calc(100% - 36px))!important}.ot-test-audit-modal .ot-audit-assets-table{min-width:1080px;table-layout:fixed}.ot-test-audit-modal .ot-audit-assets-table th:nth-child(1){width:27%}.ot-test-audit-modal .ot-audit-assets-table th:nth-child(2){width:20%}.ot-test-audit-modal .ot-audit-assets-table th:nth-child(3){width:10%}.ot-test-audit-modal .ot-audit-assets-table th:nth-child(4){width:15%}.ot-test-audit-modal .ot-audit-assets-table th:nth-child(5){width:28%}.ot-asset-info{display:flex;align-items:center;gap:12px;min-width:0}.ot-asset-preview-trigger{display:flex;flex:0 0 76px;width:76px;height:58px;align-items:center;justify-content:center;padding:0;overflow:hidden;border:1px solid #d4e1e7;border-radius:6px;background:#edf4f7;cursor:pointer}.ot-asset-preview-trigger img{width:100%;height:100%;object-fit:cover;transition:transform .18s ease}.ot-asset-preview-trigger:hover img{transform:scale(1.06)}.ot-asset-thumb{display:flex;width:100%;height:100%;align-items:center;justify-content:center;color:#fff;font-size:18px}.ot-asset-thumb.is-video{background:linear-gradient(135deg,#224c61,#29947e)}.ot-asset-thumb.is-link{background:linear-gradient(135deg,#617889,#87a0ae)}.ot-asset-copy{min-width:0}.ot-asset-copy small{display:block;margin-top:4px;color:#7c8e98}.ot-asset-name{display:block;max-width:100%;padding:0;border:0;background:transparent;color:#244657;font-weight:700;line-height:1.45;text-align:left;word-break:break-word;cursor:pointer}.ot-asset-name:hover{color:#2587b5;text-decoration:underline}.ot-asset-name:disabled{color:#244657;cursor:default;text-decoration:none}.ot-asset-copy .ot-asset-preview-hint{color:#2587b5;font-size:11px}.ot-asset-preview-modal{z-index:1070}.ot-asset-preview-modal .modal-dialog{width:min(1120px,calc(100% - 36px))!important}.ot-asset-preview-modal .modal-body{padding:16px;background:#18232a!important}.ot-asset-preview-stage{display:flex;min-height:420px;max-height:72vh;align-items:center;justify-content:center;overflow:auto;border-radius:4px;background:#111a1f}.ot-asset-preview-stage img,.ot-asset-preview-stage video{display:none;max-width:100%;max-height:70vh}.ot-asset-preview-stage img.is-active,.ot-asset-preview-stage video.is-active{display:block}.ot-asset-preview-link{display:none;color:#d8e3e8;text-align:center}.ot-asset-preview-link.is-active{display:block}.ot-asset-preview-link i{display:block;margin-bottom:12px;font-size:44px}.ot-asset-preview-link p{margin-bottom:16px}.ot-asset-preview-modal .modal-footer{display:flex;justify-content:flex-end;gap:8px}
@media(max-width:900px){.ot-audit-overview-meta{grid-template-columns:1fr 1fr}.ot-audit-overview-meta>div{border-bottom:1px solid #e4ecef}.ot-audit-overview-meta>a{margin:12px 16px}.ot-task-audit-grid{grid-template-columns:1fr}.ot-task-audit-opinion textarea{min-height:112px}}@media(max-width:767px){.ot-review-tabs{display:flex;width:100%}.ot-review-tabs button{flex:1;min-width:0;padding:0 10px}.ot-test-audit-modal .modal-dialog{width:auto!important;margin:10px!important}.ot-test-audit-modal .modal-body{padding:12px}.ot-audit-overview-meta{grid-template-columns:1fr}.ot-audit-overview-meta>div{border-right:0}.ot-task-audit-fields{grid-template-columns:1fr}.ot-unified-audit-actions{align-items:flex-start;flex-direction:column}.ot-unified-audit-actions .btn{width:100%}}
.ot-asset-name,.ot-asset-copy{display:none!important}.ot-asset-info{justify-content:center}.ot-test-audit-modal .ot-audit-assets-table th:nth-child(1){width:13%}.ot-test-audit-modal .ot-audit-assets-table th:nth-child(2){width:27%}.ot-test-audit-modal .ot-audit-assets-table th:nth-child(3){width:10%}.ot-test-audit-modal .ot-audit-assets-table th:nth-child(4){width:16%}.ot-test-audit-modal .ot-audit-assets-table th:nth-child(5){width:34%}
.ot-rejection-scopes{display:flex;min-height:90px;align-content:flex-start;align-items:center;flex-wrap:wrap;gap:10px 18px;padding:12px 14px;border:1px solid #d2dfe5;border-radius:5px;background:#f8fbfc}.ot-rejection-scopes label{display:inline-flex;align-items:center;gap:6px;margin:0;color:#264b5e;font-weight:600;cursor:pointer}.ot-rejection-scopes input{margin:0}.ot-rejection-scopes small{flex-basis:100%;color:#788d98;line-height:1.5}.ot-stage-status{display:flex;flex-direction:column;align-items:center;justify-content:center;gap:5px;white-space:nowrap}.ot-current-review-state{text-align:center!important;vertical-align:middle!important}.ot-workflow-state{display:inline-flex;align-items:center;justify-content:center;min-height:24px;padding:3px 9px;border-radius:12px;font-weight:600;line-height:1.35;white-space:nowrap}.ot-workflow-state.is-pending,.ot-workflow-state.is-warning{background:#fff3df;color:#b36b13}.ot-workflow-state.is-rejected{background:#fdeaea;color:#c64a4a}.ot-workflow-state.is-approved{background:#e7f5ef;color:#168262}.ot-rejection-note{width:100%;max-width:230px;margin:7px auto 0;padding-top:7px;border-top:1px dashed #d7e1e6;text-align:left}.ot-rejection-note b,.ot-rejection-note span,.ot-rejection-note small{display:block}.ot-rejection-note b{margin-bottom:3px;color:#a74444;font-size:11px}.ot-rejection-note span{color:#6f4545;font-size:12px;line-height:1.45;white-space:normal;word-break:break-word}.ot-rejection-note small{margin-top:4px;color:#8a9aa2;font-size:10px;line-height:1.35}
</style>
@endpush

@push('scripts')
<script>
(function () {
    function notify(message, type, title) {
        if (window.ErpAdminNotifier && typeof window.ErpAdminNotifier.notify === 'function') {
            window.ErpAdminNotifier.notify({type: type || 'warning', title: title || '提交前请检查', message: message});
        } else {
            window.alert(message);
        }
    }
    var previewModal = document.getElementById('ot-asset-preview-modal');
    var previewTitle = document.getElementById('ot-asset-preview-title');
    var previewImage = document.getElementById('ot-asset-preview-image');
    var previewVideo = document.getElementById('ot-asset-preview-video');
    var previewLink = document.getElementById('ot-asset-preview-link');
    var previewLinkButton = previewLink ? previewLink.querySelector('a') : null;
    var previewOpenButton = document.getElementById('ot-asset-preview-open');
    function resetAssetPreview() {
        [previewImage, previewVideo, previewLink].forEach(function (element) {
            if (element) element.classList.remove('is-active');
        });
        if (previewImage) previewImage.removeAttribute('src');
        if (previewVideo) {
            previewVideo.pause();
            previewVideo.removeAttribute('src');
            previewVideo.load();
        }
    }
    document.addEventListener('click', function (event) {
        var trigger = event.target.closest('[data-asset-preview]');
        if (!trigger || !previewModal || !window.jQuery) return;
        var source = trigger.getAttribute('data-preview-src') || '';
        var type = trigger.getAttribute('data-preview-type') || 'link';
        var name = trigger.getAttribute('data-preview-name') || '素材预览';
        if (!source) return;
        resetAssetPreview();
        if (previewTitle) previewTitle.textContent = name;
        if (previewOpenButton) previewOpenButton.href = source;
        if (previewLinkButton) previewLinkButton.href = source;
        if (type === 'image' && previewImage) {
            previewImage.src = source;
            previewImage.classList.add('is-active');
        } else if (type === 'video' && previewVideo) {
            previewVideo.src = source;
            previewVideo.classList.add('is-active');
            previewVideo.load();
        } else if (previewLink) {
            previewLink.classList.add('is-active');
        }
        window.jQuery(previewModal).modal('show');
    });
    if (previewModal && window.jQuery) {
        window.jQuery(previewModal).on('hidden.bs.modal', function () {
            resetAssetPreview();
            if (document.querySelector('.modal.in')) document.body.classList.add('modal-open');
        });
    }
    document.addEventListener('submit', function (event) {
        var form = event.target;
        if (form && form.matches('.ot-unified-audit-form')) {
            event.preventDefault();
            var invalidMessage = '';
            form.querySelectorAll('select[name$="[result]"]').forEach(function (select) {
                if (select.value !== 'rejected') return;
                var row = select.closest('tr');
                var opinion = row ? row.querySelector('textarea[name$="[opinion]"]') : null;
                if (opinion && !opinion.value.trim()) {
                    opinion.classList.add('has-error');
                    if (!invalidMessage) opinion.focus();
                    invalidMessage = '驳回素材时必须填写具体整改意见。';
                }
            });
            var taskResult = form.querySelector('[name="task_result"]');
            var taskOpinion = form.querySelector('[name="task_opinion"]');
            if (taskResult && taskResult.value === 'rejected' && taskOpinion && !taskOpinion.value.trim()) {
                taskOpinion.classList.add('has-error');
                if (!invalidMessage) taskOpinion.focus();
                invalidMessage = '驳回任务时必须填写具体整改意见。';
            }
            if (taskResult && taskResult.value === 'rejected') {
                var checkedScopes = form.querySelectorAll('input[name="rejection_scopes[]"]:checked');
                if (!checkedScopes.length) {
                    invalidMessage = invalidMessage || '驳回任务时请至少选择一个驳回对象。';
                }
                var assetScope = form.querySelector('input[name="rejection_scopes[]"][value="asset"]:checked');
                var hasRejectedAsset = Array.prototype.some.call(
                    form.querySelectorAll('select[name$="[result]"]'),
                    function (select) { return select.value === 'rejected'; }
                );
                if (assetScope && !hasRejectedAsset) {
                    invalidMessage = invalidMessage || '选择“素材问题”后，请至少驳回一条素材。';
                }
            }
            if (invalidMessage) {
                notify(invalidMessage);
                return;
            }
            var submitButton = form.querySelector('[type="submit"]');
            var originalHtml = submitButton ? submitButton.innerHTML : '';
            if (submitButton) {
                submitButton.disabled = true;
                submitButton.innerHTML = '<i class="fa fa-spinner fa-spin"></i> 正在保存';
            }
            fetch(form.action, {
                method: 'POST',
                body: new FormData(form),
                headers: {'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest'},
                credentials: 'same-origin'
            }).then(function (response) {
                return response.json().catch(function () { return {}; }).then(function (body) {
                    if (!response.ok) throw body;
                    return body;
                });
            }).then(function (body) {
                var modal = form.closest('.modal');
                var modalId = modal ? modal.id : '';
                var trigger = modalId ? document.querySelector('[data-target="#' + modalId + '"]') : null;
                var row = trigger ? trigger.closest('tr') : null;
                if (modal && window.jQuery) window.jQuery(modal).modal('hide');
                if (row) row.parentNode.removeChild(row);
                var count = document.querySelector('[data-review-tab="tests"] em');
                if (count) count.textContent = String(Math.max(0, (parseInt(count.textContent, 10) || 0) - 1));
                notify(body.message || '审核结果已保存。', 'success', '保存成功');
            }).catch(function (error) {
                var errors = error && error.errors ? error.errors : null;
                var firstKey = errors ? Object.keys(errors)[0] : null;
                var message = firstKey && errors[firstKey] ? errors[firstKey][0] : (error.message || '保存失败，请稍后重试。');
                notify(message, 'error', '保存失败');
            }).then(function () {
                if (submitButton) {
                    submitButton.disabled = false;
                    submitButton.innerHTML = originalHtml;
                }
            });
            return;
        }
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
    var tabButtons = document.querySelectorAll('[data-review-tab]');
    var tabPanels = document.querySelectorAll('[data-review-panel]');
    function activateReviewTab(name) {
        tabButtons.forEach(function (button) {
            button.classList.toggle('is-active', button.getAttribute('data-review-tab') === name);
        });
        tabPanels.forEach(function (panel) {
            panel.style.display = panel.getAttribute('data-review-panel') === name ? '' : 'none';
        });
        var url = new URL(window.location.href);
        url.searchParams.set('review_tab', name);
        window.history.replaceState({}, '', url.toString());
    }
    tabButtons.forEach(function (button) {
        button.addEventListener('click', function () { activateReviewTab(button.getAttribute('data-review-tab')); });
    });
    activateReviewTab(@json(request('review_tab') === 'tests' ? 'tests' : 'recaps'));
    @if($errors->any())
    notify(@json($errors->first()));
    @endif
})();
</script>
@endpush
