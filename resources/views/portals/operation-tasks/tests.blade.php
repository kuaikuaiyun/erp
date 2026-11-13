@extends('portal.layout')
@section('page_title','测品任务')
@section('content')
@include('portals.operation-tasks.partials.head')
@php
    $singleTarget = (int) ($todayProgress['single_target'] ?? 0);
    $categoryTarget = (int) ($todayProgress['category_target'] ?? 0);
    $singleDone = (int) ($todayProgress['single_done'] ?? 0);
    $categoryDone = (int) ($todayProgress['category_done'] ?? 0);
    $editableTestTasks = $tasks->getCollection()->mapWithKeys(function ($task) use ($user) {
        $requiredRectifications = $task->rectifications->where('status', 'required')->values();
        $required = $requiredRectifications->first();
        if (! $required || (int) $task->owner_user_id !== (int) $user->id) {
            return [];
        }

        $formatRejection = function ($rectification) {
            return [
                'channel' => $rectification->review_channel,
                'channel_label' => $rectification->review_channel === 'leader' ? '二级主管' : ($rectification->review_channel === 'department' ? '一级主管' : '审核人'),
                'reviewer_name' => $rectification->reviewer_name ?: '未知审核人',
                'reason' => trim((string) $rectification->rejection_opinion) ?: '审核驳回，请按要求修改。',
            ];
        };

        return [(string) $task->id => [
            'action' => route('operation-tasks.rectifications.resubmit', $required),
            'version' => (int) $task->version,
            'page_type' => $task->page_type,
            'task_type' => $task->task_type,
            'landing_url' => $task->landing_url,
            'selection_objective' => $task->selection_objective,
            'task_rejections' => $requiredRectifications
                ->filter(fn ($item) => $item->rejection_scope === 'task' || ! $item->asset_id)
                ->map($formatRejection)->values()->all(),
            'assets' => $task->assets->whereNull('retired_at')->values()->map(function ($asset) use ($requiredRectifications, $formatRejection) {
                return [
                    'id' => $asset->id,
                    'selling_point' => $asset->selling_point,
                    'source_type' => $asset->source_type,
                    'external_url' => $asset->external_url,
                    'original_name' => $asset->original_name,
                    'has_file' => filled($asset->file_path),
                    'rejections' => $requiredRectifications
                        ->filter(fn ($item) => (int) $item->asset_id === (int) $asset->id)
                        ->map($formatRejection)->values()->all(),
                ];
            })->all(),
        ]];
    })->all();
@endphp
<section class="content ot-page ot-tests-page">
    <div class="ot-simple-page-head"><div><h1>测品任务</h1><p>提交后允许先投放，组长与审核部门当天独立审核</p></div><button type="button" class="btn ot-primary" data-toggle="modal" data-target="#ot-test-modal"><i class="fa fa-plus"></i> 新建任务</button></div>
    @if(($rejectedTestCount ?? 0) > 0)
        <div class="ot-test-rejected-alert">
            <div><b>有 {{ $rejectedTestCount }} 个测品任务被驳回，等待修改后重新提交。</b><span>只需修改审核指出的落地页或对应素材，已通过内容不会受影响。</span></div>
            <a class="btn btn-warning btn-sm" href="{{ request()->fullUrlWithQuery(['status' => 'rejected', 'page' => null]) }}">只看待修改</a>
        </div>
    @endif
    <form class="ot-test-date-filter" method="get" action="{{ url()->current() }}">
        @include('portals.operation-tasks.partials.search-filters')
        @if(request()->filled('status'))
            <input type="hidden" name="status" value="{{ request('status') }}">
        @endif
        <div class="ot-test-date-field">
            <label for="ot-submitted-from">提交开始</label>
            <input class="form-control" id="ot-submitted-from" type="date" name="submitted_from" value="{{ request('submitted_from') }}">
        </div>
        <div class="ot-test-date-field">
            <label for="ot-submitted-to">提交结束</label>
            <input class="form-control" id="ot-submitted-to" type="date" name="submitted_to" value="{{ request('submitted_to') }}">
            @error('submitted_to')<span class="help-block">结束日期不能早于开始日期</span>@enderror
        </div>
        <div class="ot-test-date-actions">
            <button class="btn ot-primary" type="submit"><i class="fa fa-search"></i> 查询</button>
            <a class="btn btn-default" href="{{ request()->fullUrlWithoutQuery(['submitted_from', 'submitted_to', 'page']) }}"><i class="fa fa-refresh"></i> 重置</a>
        </div>
    </form>
    <div class="ot-test-goals">
        <div><small>今日测品计划</small><b>{{ $todayPlan ? ($todayProgress['label'] ?? '已确认') : '—' }}</b><strong>{{ $todayPlan ? '单品 '.$singleTarget.' 个 · 类目 '.$categoryTarget.' 个' : '等待主管确认' }}</strong><span>每日单独确认，混合计划同时核算两类目标</span></div>
        <div><small>今日计划完成</small><b>{{ $todayPlan ? ($singleDone + $categoryDone) : 0 }}</b><strong>{{ $todayPlan ? '单品 '.$singleDone.'/'.$singleTarget.' · 类目 '.$categoryDone.'/'.$categoryTarget : '尚未设置计划' }}</strong><span>单品至少 2 条、类目至少 3 条新素材</span></div>
        <div><small>今日任务已双通过</small><b>{{ $todayPassed }}</b><strong>待审核不影响先投放</strong><span>审核记录绑定当前版本</span></div>
    </div>
    <div class="ot-card">
        <div class="ot-card-head"><span>任务档案</span><span class="ot-hint">共 {{ $tasks->total() }} 条；点击任务详情可追加素材、填写日报和查看整改记录。</span></div>
        <div class="table-responsive">
            <table class="table ot-table ot-test-table">
                <thead><tr><th>基础信息</th><th>任务类型</th><th>提交时间</th><th>落地页</th><th>素材信息</th><th>投放媒体</th><th>二级主管审核</th><th>一级主管审核</th><th>当前状态 / 驳回意见</th><th>操作</th></tr></thead>
                <tbody>
                @forelse($tasks as $task)
                    @php
                        $requiredRectifications = $task->rectifications->where('status', 'required');
                        $reviews = $task->reviews->where('target_version', $task->version)->keyBy('channel');
                        $reviewLabels = ['approved' => '已通过', 'rejected' => '已驳回', 'pending' => '待审核'];
                        $leaderResult = optional($reviews->get('leader'))->result ?: 'pending';
                        $departmentResult = optional($reviews->get('department'))->result ?: 'pending';
                        $leaderLabel = $reviewLabels[$leaderResult] ?? '待审核';
                        $departmentLabel = $reviewLabels[$departmentResult] ?? '待审核';
                        $rejectedReview = $reviews->filter(fn ($review) => $review->result === 'rejected')->sortByDesc('updated_at')->first();
                        if ($rejectedReview) {
                            $currentStatusLabel = '已驳回待修改';
                            $currentStatusClass = 'red';
                            $currentRejectionOpinion = trim((string) $rejectedReview->opinion);
                        } elseif ($leaderResult !== 'approved') {
                            $currentStatusLabel = '待二级主管审核';
                            $currentStatusClass = 'orange';
                            $currentRejectionOpinion = '';
                        } elseif ($departmentResult !== 'approved') {
                            $currentStatusLabel = '待一级主管审核';
                            $currentStatusClass = 'orange';
                            $currentRejectionOpinion = '';
                        } else {
                            $currentStatusLabel = '审核通过';
                            $currentStatusClass = '';
                            $currentRejectionOpinion = '';
                        }
                        $media = $task->assets->flatMap(fn ($asset) => $asset->dailyRecords->pluck('media'))->filter()->unique()->values();
                        $approvedAssets = $task->assets->filter(function ($asset) {
                            $assetReviews = $asset->reviews->where('target_version', $asset->version)->keyBy('channel');
                            return optional($assetReviews->get('leader'))->result === 'approved'
                                && optional($assetReviews->get('department'))->result === 'approved';
                        })->count();
                    @endphp
                    <tr>
                        <td class="ot-task-info"><b>{{ $task->task_no }}</b><span>运营：{{ $task->owner_name }}</span><small>团队：{{ $task->department_name ?: '未分配' }}</small></td>
                        <td><b>{{ $task->page_type === 'category' ? '类目页' : '单品页' }}</b><small>{{ $task->task_type === 'iteration' ? '已有测试迭代' : '新测试' }}</small></td>
                        <td class="ot-test-submitted-at">{{ \App\Portals\OperationTasks\Support\OperationTaskTime::format($task->submitted_at, 'Y-m-d H:i') }}</td>
                        <td><a class="btn btn-info btn-xs ot-landing-button" href="{{ $task->landing_url }}" target="_blank" rel="noopener noreferrer">落地页</a></td>
                        <td class="test-asset-info">
                            <div class="ot-list-assets">
                                @foreach($task->assets->values() as $assetIndex => $asset)
                                    @php
                                        $localAsset = (bool) $asset->file_path;
                                        $assetUrl = $localAsset ? route('operation-tasks.assets.file', $asset) : $asset->external_url;
                                        $assetVideo = $localAsset && str_starts_with((string) $asset->mime_type, 'video/');
                                        $assetImage = $localAsset && str_starts_with((string) $asset->mime_type, 'image/');
                                    @endphp
                                    <div class="ot-list-asset-row">
                                        @if($assetUrl && ($assetImage || $assetVideo))
                                            <button type="button" class="ot-list-asset-thumb" data-toggle="modal" data-target="#ot-list-preview" data-preview-src="{{ $assetUrl }}" data-preview-type="{{ $assetVideo ? 'video' : 'image' }}" data-preview-name="{{ $asset->original_name }}" aria-label="{{ __('operation_tasks.assets.preview', ['number' => $assetIndex + 1]) }}">
                                                @if($assetImage)<img src="{{ $assetUrl }}" loading="lazy" alt="{{ $asset->original_name }}">@else<i class="fa fa-play-circle" aria-hidden="true"></i>@endif
                                            </button>
                                        @elseif($assetUrl)
                                            <a class="ot-list-asset-thumb" href="{{ $assetUrl }}" target="_blank" rel="noopener noreferrer" aria-label="{{ __('operation_tasks.assets.open_link') }}"><i class="fa fa-external-link" aria-hidden="true"></i></a>
                                        @else
                                            <span class="ot-list-asset-thumb"><i class="fa fa-file-o" aria-hidden="true"></i></span>
                                        @endif
                                        <span class="ot-list-daily-state {{ $asset->dailyRecords->isNotEmpty() ? 'is-filled' : 'is-empty' }}">{{ __($asset->dailyRecords->isNotEmpty() ? 'operation_tasks.assets.daily_filled' : 'operation_tasks.assets.daily_empty') }}</span>
                                    </div>
                                @endforeach
                            </div>
                        </td>
                        <td><b>{{ $media->isNotEmpty() ? $media->join(' / ') : '暂无' }}</b><small>{{ $media->isNotEmpty() ? '来自素材投放日报' : '尚无投放日报' }}</small></td>
                        <td><span class="ot-badge {{ $leaderResult === 'rejected' ? 'red' : ($leaderResult === 'pending' ? 'orange' : '') }}">{{ $leaderLabel }}</span></td>
                        <td><span class="ot-badge {{ $departmentResult === 'rejected' ? 'red' : ($departmentResult === 'pending' ? 'orange' : '') }}">{{ $departmentLabel }}</span></td>
                        <td class="ot-task-current-status"><span class="ot-badge {{ $requiredRectifications->isNotEmpty() ? 'red' : $currentStatusClass }}">{{ $requiredRectifications->isNotEmpty() ? '已驳回待修改' : $currentStatusLabel }}</span>@if($requiredRectifications->isNotEmpty())<small>{{ $requiredRectifications->first()->rejection_opinion }}</small>@elseif($currentRejectionOpinion !== '')<small>{{ $currentRejectionOpinion }}</small>@endif</td>
                        <td class="ot-task-action">@if($requiredRectifications->isNotEmpty() && (int) $task->owner_user_id === (int) $user->id)<button class="btn btn-warning btn-sm ot-edit-test-task" type="button" data-task-id="{{ $task->id }}">修改并重新提交</button>@else<a class="btn btn-default btn-sm" href="{{ route('operation-tasks.tests.show',$task) }}">查看详情</a>@endif</td>
                    </tr>
                @empty
                    <tr class="ot-test-empty-row"><td colspan="10"><div class="ot-test-empty-state"><i class="fa fa-flask"></i><b>当前暂无测品任务</b><span>请使用页面右上角的“新建任务”创建首个测试档案。</span></div></td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        @include('portals.operation-tasks.partials.pagination', [
            'paginator' => $tasks,
            'perPageName' => 'test_per_page',
            'activeTab' => 'tests',
        ])
    </div>
</section>
<div class="modal fade ot-modal" id="ot-test-modal" tabindex="-1"><div class="modal-dialog modal-lg"><div class="modal-content"><form class="ot-form ot-task-asset-upload-form" method="post" id="ot-create-test-form" action="{{ route('operation-tasks.tests.store') }}">@csrf<input type="hidden" name="submission_token" value="{{ old('submission_token', (string) \Illuminate\Support\Str::uuid()) }}"><div class="modal-header"><button type="button" class="close" data-dismiss="modal">×</button><h4 class="modal-title">新建测品任务</h4><p>明确选品理由、验证目标和对应素材后提交。</p></div><div class="modal-body"><div class="ot-grid"><div class="form-group"><label>落地页类型 *</label><select class="form-control" name="page_type" id="ot-page-type"><option value="single" @selected(old('page_type', 'single') === 'single')>单品页</option><option value="category" @selected(old('page_type') === 'category')>类目页</option></select></div><div class="form-group"><label>任务类型 *</label><select class="form-control" name="task_type"><option value="new" @selected(old('task_type', 'new') === 'new')>新测试</option><option value="iteration" @selected(old('task_type') === 'iteration')>已有测试迭代</option></select></div><div class="ot-full form-group"><label>落地页链接 *</label><input class="form-control" type="url" name="landing_url" value="{{ old('landing_url') }}" placeholder="https://..." required></div><div class="ot-full form-group"><label>选品理由与验证目标 *</label><textarea class="form-control" rows="4" name="selection_objective" required placeholder="卖给谁？为什么选这个品或类目？本次最想验证什么？">{{ old('selection_objective') }}</textarea></div></div><div class="form-group"><label>对应素材 *</label><div id="ot-assets"></div><button class="btn btn-default btn-sm" id="ot-add-asset" type="button"><i class="fa fa-plus"></i> 添加素材</button><p class="ot-hint" id="ot-asset-hint">{{ __('operation_tasks.upload.single_hint') }}</p></div></div><div class="modal-footer"><button type="button" class="btn btn-default" data-dismiss="modal">取消</button><button class="btn ot-primary"><i class="fa fa-send"></i> 提交任务</button></div></form></div></div></div>
<div class="modal fade ot-modal" id="ot-test-edit-modal" tabindex="-1">
    <div class="modal-dialog modal-lg ot-test-edit-dialog">
        <div class="modal-content">
            <form class="ot-form ot-task-asset-upload-form" id="ot-test-edit-form" method="post">
                @csrf
                <input type="hidden" name="form_mode" value="edit"><input type="hidden" name="task_id" value=""><input type="hidden" name="task_version" value="">
                <div class="modal-header">
                    <button type="button" class="close" data-dismiss="modal">×</button>
                    <h4 class="modal-title">修改测品任务并重新提交</h4>
                    <p>{{ __('operation_tasks.upload.edit_description') }}</p>
                </div>
                <div class="modal-body">
                    <div class="ot-edit-rejection-summary" id="ot-edit-rejection-summary"></div>
                    <div class="ot-grid">
                        <div class="form-group"><label>落地页类型 *</label><select class="form-control" name="page_type" id="ot-edit-page-type"><option value="single">单品页</option><option value="category">类目页</option></select></div>
                        <div class="form-group"><label>任务类型 *</label><select class="form-control" name="task_type"><option value="new">新测试</option><option value="iteration">已有测试迭代</option></select></div>
                        <div class="ot-full form-group"><label>落地页链接 *</label><input class="form-control" type="url" name="landing_url" placeholder="https://..." required></div>
                        <div class="ot-full form-group"><label>选品理由与验证目标 *</label><textarea class="form-control" rows="4" name="selection_objective" required></textarea></div>
                    </div>
                    <div class="form-group">
                        <div class="ot-edit-assets-head"><label>对应素材 *</label><span>{{ __('operation_tasks.upload.retained_hint') }}</span></div>
                        <div id="ot-edit-assets"></div>
                        <button class="btn btn-default btn-sm" id="ot-edit-add-asset" type="button"><i class="fa fa-plus"></i> 添加素材</button>
                        <p class="ot-hint" id="ot-edit-asset-hint"></p>
                    </div>
                </div>
                <div class="modal-footer"><button type="button" class="btn btn-default" data-dismiss="modal">取消</button><button class="btn ot-primary" type="submit"><i class="fa fa-send"></i> 重新提交审核</button></div>
            </form>
        </div>
    </div>
</div>
@push('styles')
<style>
    .ot-task-asset-upload-form .ot-upload-box{display:block!important;min-height:106px;overflow:hidden;padding:13px 15px;cursor:default;isolation:isolate;background:#f8fcff;transition:border-color .16s ease,box-shadow .16s ease}
    .ot-task-asset-upload-form .ot-upload-progress{position:absolute;inset:0 auto 0 0;width:0;z-index:-1;pointer-events:none;background:#d3ebdf;transition:width .15s linear,background-color .16s ease}
    .ot-task-asset-upload-form .ot-upload-content{display:flex;align-items:center;gap:13px;min-width:0}
    .ot-task-asset-upload-form .ot-upload-copy{flex:1;min-width:0}
    .ot-task-asset-upload-form .ot-upload-copy .ot-file-drop-copy{margin:0;cursor:pointer}
    .ot-task-asset-upload-form .ot-upload-copy strong{font-weight:600}
    .ot-task-asset-upload-form .ot-upload-message{display:block;margin-top:4px;color:#28745f;font-size:12px;line-height:1.5;overflow-wrap:anywhere}
    .ot-task-asset-upload-form .ot-upload-message:empty{display:none}
    .ot-task-asset-upload-form .ot-upload-actions{display:flex;flex:0 0 auto;align-items:flex-end;flex-direction:column;gap:6px}
    .ot-task-asset-upload-form .ot-upload-actions .btn{margin:0;cursor:pointer;white-space:nowrap}
    .ot-task-asset-upload-form .ot-upload-percentage{color:#23704f;font-size:17px;font-variant-numeric:tabular-nums;line-height:1.2}
    .ot-task-asset-upload-form .ot-upload-box[data-upload-status="queued"],.ot-task-asset-upload-form .ot-upload-box[data-upload-status="checking"]{border-color:#91b3cc;background:#f3f7fa}
    .ot-task-asset-upload-form .ot-upload-box[data-upload-status="verifying"] .ot-upload-progress{background:#d7e9f4}
    .ot-task-asset-upload-form .ot-upload-box[data-upload-status="failed"]{border-color:#d87c7c;background:#fff6f5}
    .ot-task-asset-upload-form .ot-upload-box[data-upload-status="failed"] .ot-upload-progress{background:#f5d9d7}
    .ot-task-asset-upload-form .ot-upload-box[data-upload-status="failed"] .ot-upload-message,.ot-task-asset-upload-form .ot-upload-box[data-upload-status="failed"] .ot-upload-percentage{color:#b13b36}
    .ot-task-asset-upload-form .ot-upload-box [hidden]{display:none!important}
    @media(max-width:600px){.ot-task-asset-upload-form .ot-upload-content{gap:9px}.ot-task-asset-upload-form .ot-upload-content>.ot-file-drop-icon{width:32px;height:32px;flex-basis:32px;font-size:15px}.ot-task-asset-upload-form .ot-upload-box{padding:11px}.ot-task-asset-upload-form .ot-upload-actions .btn{font-size:11px;padding:4px 7px}}
    .ot-test-empty-row td{height:220px!important;padding:0!important;text-align:center!important;vertical-align:middle!important}
    .ot-test-empty-state{display:flex;min-height:220px;align-items:center;justify-content:center;flex-direction:column;gap:8px;color:#738793}
    .ot-test-empty-state i{display:grid;width:34px;height:34px;place-items:center;border-radius:50%;background:#e8f5f0;color:#24947d;font-size:17px}
    .ot-test-empty-state b{color:#294b5d;font-size:15px;font-weight:700}
    .ot-test-empty-state span{font-size:12px;line-height:1.6}
    .ot-test-rejected-alert{display:flex;align-items:center;justify-content:space-between;gap:18px;margin-bottom:14px;padding:13px 16px;border:1px solid #f0c987;border-left:4px solid #e69b25;border-radius:8px;background:#fff9ed;color:#80550d}.ot-test-rejected-alert b,.ot-test-rejected-alert span{display:block}.ot-test-rejected-alert span{margin-top:3px;font-size:12px;color:#99733a}
</style>
@endpush
@push('styles')
<style>
.ot-test-date-filter{display:flex;align-items:flex-end;gap:12px;margin:0 0 16px;padding:15px 18px;border:1px solid #d9e6eb;border-radius:10px;background:#fff;box-shadow:0 4px 16px rgba(25,57,73,.035)}
.ot-test-date-field{width:220px}.ot-test-date-field label{display:block;margin-bottom:6px;color:#31566a;font-size:12px;font-weight:700}.ot-test-date-field .form-control{height:36px;border-color:#cfdee5;border-radius:4px}.ot-test-date-field .help-block{display:block;margin:5px 0 0;color:#d9534f;font-size:12px}
.ot-test-date-actions{display:flex;align-items:center;gap:8px}.ot-test-date-actions .btn{height:36px;min-width:76px;display:inline-flex;align-items:center;justify-content:center;gap:5px}
@media(max-width:700px){.ot-test-date-filter{align-items:stretch;flex-direction:column}.ot-test-date-field{width:100%}.ot-test-date-actions{justify-content:flex-end}}
</style>
@endpush

<div class="modal fade ot-modal" id="ot-list-preview" tabindex="-1" role="dialog" aria-labelledby="ot-list-preview-title"><div class="modal-dialog modal-lg"><div class="modal-content">
<div class="modal-header"><button type="button" class="close" data-dismiss="modal" aria-label="{{ __('operation_tasks.video.close') }}">&times;</button><h4 class="modal-title" id="ot-list-preview-title">{{ __('operation_tasks.assets.preview_title') }}</h4><p id="ot-list-preview-name"></p></div>
<div class="modal-body"><img id="ot-list-preview-image" hidden alt=""><video id="ot-list-preview-video" hidden controls playsinline preload="none">{{ __('operation_tasks.video.unsupported') }}</video></div>
<div class="modal-footer"><button type="button" class="btn btn-default" data-dismiss="modal">{{ __('operation_tasks.video.close') }}</button></div>
</div></div></div>
@endsection
@push('styles')<style>
.ot-tests-page{max-width:1540px;margin:0 auto}.ot-tests-page .ot-head{margin-bottom:14px}.ot-test-goals{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:14px;margin-bottom:16px}.ot-test-goals>div{position:relative;min-height:110px;padding:17px 20px;border:1px solid #dce8ed;border-radius:10px;background:#fff;box-shadow:0 4px 16px rgba(25,57,73,.035)}.ot-test-goals>div:before{content:'';position:absolute;left:0;top:15px;bottom:15px;width:3px;border-radius:3px;background:#258b78}.ot-test-goals small{display:block;color:#708796;font-size:12px}.ot-test-goals b{display:inline-block;margin:8px 12px 0 0;color:#163d54;font-size:28px;line-height:1}.ot-test-goals strong{display:inline-block;color:#2f657b;font-size:12px;font-weight:700}.ot-test-goals span{display:block;margin-top:8px;color:#81929a;font-size:11px}.ot-tests-page .ot-card-head{min-height:54px;padding:14px 20px}.ot-tests-page .ot-hint{margin:0;font-size:12px}@media(max-width:800px){.ot-test-goals{grid-template-columns:1fr}.ot-test-goals>div{min-height:92px}}
</style>@endpush
@push('styles')<style>
.ot-form-label-row{display:flex;align-items:center;justify-content:space-between;gap:10px}.ot-form-label-row label{margin:0}.ot-url-example-image{display:block;width:100%;height:auto;border:1px solid #dce7ec;border-radius:6px}
</style>@endpush
@push('scripts')<script>
(function(){
    var input=document.querySelector('#ot-test-modal input[name="landing_url"]');
    if(!input||!window.jQuery)return;
    var label=input.parentElement.querySelector('label');
    if(label){
        var row=document.createElement('div'); row.className='ot-form-label-row';
        label.parentNode.insertBefore(row,label); row.appendChild(label);
        var button=document.createElement('button'); button.type='button'; button.className='btn btn-default btn-xs'; button.setAttribute('data-toggle','modal'); button.setAttribute('data-target','#ot-landing-url-example'); button.innerHTML='<i class="fa fa-picture-o"></i> 查看示例'; row.appendChild(button);
    }
    var modal=document.createElement('div'); modal.className='modal fade ot-modal'; modal.id='ot-landing-url-example'; modal.tabIndex=-1;
    modal.innerHTML='<div class="modal-dialog modal-lg"><div class="modal-content"><div class="modal-header"><button type="button" class="close" data-dismiss="modal">&times;</button><h4 class="modal-title">落地页链接填写示例</h4><p>如果链接带有 random_key，请完整保留参数。</p></div><div class="modal-body"><img class="ot-url-example-image" src="{{ asset('assets/erp/operation-task-landing-url-guide.png') }}" alt="落地页链接填写示例"></div><div class="modal-footer"><button type="button" class="btn btn-default" data-dismiss="modal">关闭</button></div></div></div>';
    document.body.appendChild(modal);
})();
</script>@endpush
@push('scripts')
@php
    $operationTaskUploadConfig = [
        'beginUrl' => route('operation-tasks.tests.uploads.store'),
        'statusUrl' => route('operation-tasks.tests.uploads.show', ['token' => '__TOKEN__']),
        'partUrl' => route('operation-tasks.tests.uploads.parts.store', ['token' => '__TOKEN__', 'index' => '__INDEX__']),
        'completeUrl' => route('operation-tasks.tests.uploads.complete', ['token' => '__TOKEN__']),
        'labels' => __('operation_tasks.upload'),
        'validation' => __('operation_tasks.validation'),
        'initialAssets' => old('form_mode') === 'edit' ? [] : old('assets', []),
    ];
@endphp
<script>window.OperationTaskAssetUploads = @json($operationTaskUploadConfig);</script>
<script src="{{ asset('assets/erp/operation-task-asset-upload.js') }}?v=20261113-1"></script>
@if(($errors->any() && old('form_mode') !== 'edit') || request()->boolean('new'))
<script>jQuery(function () { jQuery('#ot-test-modal').modal('show'); });</script>
@endif
@endpush
@push('styles')
<style>
    .ot-test-edit-dialog{width:min(1120px,calc(100vw - 32px))}
    .ot-edit-rejection-summary{display:none;margin-bottom:16px;padding:14px 16px;border:1px solid #f1caca;border-left:4px solid #dc5c5c;border-radius:8px;background:#fff7f7}.ot-edit-rejection-summary.is-visible{display:block}.ot-edit-rejection-summary h5{margin:0 0 4px;color:#9b2f2f;font-size:15px;font-weight:700}.ot-edit-rejection-summary>p{margin:0 0 10px;color:#806060;font-size:12px}.ot-edit-rejection-list{display:grid;gap:8px}.ot-edit-rejection-item{padding:10px 12px;border:1px solid #f0dddd;border-radius:6px;background:#fff}.ot-edit-rejection-meta{display:flex;align-items:center;gap:7px;margin-bottom:4px}.ot-edit-rejection-meta b{color:#7f3030}.ot-edit-rejection-meta span{padding:2px 7px;border-radius:10px;background:#fbe8e8;color:#b14343;font-size:11px}.ot-edit-rejection-item p{margin:0;color:#4f5e66;line-height:1.6;white-space:pre-wrap}
    .ot-edit-assets-head{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:10px}.ot-edit-assets-head label{margin:0}.ot-edit-assets-head span{color:#718895;font-size:12px}
    .ot-edit-asset-row{margin-bottom:14px;padding:16px;border:1px solid #dce8ed;border-radius:9px;background:#f9fcfd}.ot-edit-asset-title{display:flex;align-items:center;justify-content:space-between;margin-bottom:12px}.ot-edit-asset-title b{color:#17445a}.ot-edit-retained-file{display:flex;align-items:center;gap:8px;min-height:34px;margin-top:8px;padding:8px 10px;border:1px solid #cfe5db;border-radius:5px;background:#f0faf6;color:#28745f;font-size:12px}.ot-edit-retained-file i{color:#269b7c}.ot-edit-retained-file small{margin-left:auto;color:#718895}.ot-edit-new-file{border-color:#dce8ed;background:#f5f8fa;color:#718895}
    .ot-edit-asset-rejections{margin:0 0 13px;padding:11px 12px;border:1px solid #f1cece;border-radius:6px;background:#fff6f6}.ot-edit-asset-rejections>strong{display:block;margin-bottom:7px;color:#a33b3b}.ot-edit-asset-rejections .ot-edit-rejection-item{padding:7px 9px}.ot-edit-asset-rejections .ot-edit-rejection-item+.ot-edit-rejection-item{margin-top:6px}
    @media(max-width:767px){.ot-test-edit-dialog{width:auto}.ot-edit-assets-head{align-items:flex-start;flex-direction:column}}
</style>
@endpush
@push('scripts')
<script>
(function () {
    var tasks = @json($editableTestTasks);
    var previousEdit = @json(old('form_mode') === 'edit' ? old() : null);
    var modal = document.getElementById('ot-test-edit-modal');
    var form = document.getElementById('ot-test-edit-form');
    var list = document.getElementById('ot-edit-assets');
    var addButton = document.getElementById('ot-edit-add-asset');
    var pageType = document.getElementById('ot-edit-page-type');
    var rejectionSummary = document.getElementById('ot-edit-rejection-summary');

    if (!modal || !form || !list || !window.jQuery) return;

    function escapeHtml(value) {
        return String(value == null ? '' : value).replace(/[&<>'"]/g, function (character) {
            return {'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#039;','"':'&quot;'}[character];
        });
    }

    function rejectionItems(items) {
        return (items || []).map(function (item) {
            return '<div class="ot-edit-rejection-item">'+
                '<div class="ot-edit-rejection-meta"><span>'+escapeHtml(item.channel_label || '审核')+'</span><b>'+escapeHtml(item.reviewer_name || '未知审核人')+'</b></div>'+
                '<p>'+escapeHtml(item.reason || '审核驳回，请按要求修改。')+'</p></div>';
        }).join('');
    }

    function renderTaskRejections(task) {
        var items = task.task_rejections || [];
        if (!rejectionSummary) return;
        if (!items.length) {
            rejectionSummary.classList.remove('is-visible');
            rejectionSummary.innerHTML = '';
            return;
        }
        rejectionSummary.innerHTML = '<h5>整体任务驳回原因</h5><p>请按以下审核意见修改任务资料；各素材的单独驳回原因会显示在对应素材中。</p><div class="ot-edit-rejection-list">'+rejectionItems(items)+'</div>';
        rejectionSummary.classList.add('is-visible');
    }

    function uploadText(key, values) {
        var text = (window.OperationTaskAssetUploads.labels || {})[key] || '';
        Object.keys(values || {}).forEach(function (name) { text = text.split(':' + name).join(String(values[name])); });
        return text;
    }
    var editUploads = window.OperationTaskAssetUploader({
        form: form, list: list, type: pageType, edit: true,
        hint: document.getElementById('ot-edit-asset-hint'),
        renderHeader: function (asset, index) {
            var rejections = (asset.rejections || []).length
                ? '<div class="ot-edit-asset-rejections"><strong>'+escapeHtml(uploadText('asset_rejections'))+'</strong>'+rejectionItems(asset.rejections)+'</div>' : '';
            return '<div class="ot-edit-asset-title"><b>'+escapeHtml(uploadText('asset_number', {number:index + 1}))+'</b><span class="ot-badge">'+escapeHtml(uploadText(asset.id ? 'existing_asset' : 'new_asset'))+'</span></div>'+rejections;
        }
    });
    function addAsset(asset) { editUploads.addRow(asset); }

    function openEdit(task, id, previous) {
        var values = previous || task;
        form.action = task.action;
        form.elements.task_id.value = id;
        form.elements.task_version.value = values.task_version || task.version;
        form.elements.page_type.value = values.page_type;
        form.elements.task_type.value = values.task_type;
        form.elements.landing_url.value = values.landing_url || '';
        form.elements.selection_objective.value = values.selection_objective || '';
        renderTaskRejections(task);
        editUploads.reset();
        var assets = task.assets || [];
        if (previous) assets = Object.keys(previous.assets || {}).map(function (key) {
            var saved = previous.assets[key]; saved = saved && typeof saved === 'object' ? saved : {};
            var original = (task.assets || []).filter(function (asset) { return String(asset.id) === String(saved.id); })[0] || {};
            return Object.assign({},original,{selling_point:saved.selling_point || '',source_type:saved.source_type || '',external_url:saved.url || '',upload_token:saved.upload_token || ''});
        });
        assets.forEach(addAsset);
        window.jQuery(modal).modal('show');
    }

    Array.prototype.forEach.call(document.querySelectorAll('.ot-edit-test-task'), function (button) {
        button.addEventListener('click', function () {
            var task = tasks[String(button.getAttribute('data-task-id'))];
            if (!task) return;
            openEdit(task,button.getAttribute('data-task-id'));
        });
    });

    if (previousEdit && tasks[String(previousEdit.task_id)]) openEdit(tasks[String(previousEdit.task_id)],previousEdit.task_id,previousEdit);
    addButton.addEventListener('click', function () { addAsset({}); });
    window.jQuery(modal).on('hide.bs.modal', function () { editUploads.pause(); });

})();
</script>
@endpush

@push('styles')
<style>
.ot-tests-page .ot-test-table{min-width:1440px}.ot-tests-page .table-responsive{overflow-x:auto}
.ot-tests-page .ot-test-table thead th:nth-child(1){width:220px}.ot-tests-page .ot-test-table thead th:nth-child(2){width:100px}.ot-tests-page .ot-test-table thead th:nth-child(3){width:140px}.ot-tests-page .ot-test-table thead th:nth-child(4){width:80px}.ot-tests-page .ot-test-table thead th:nth-child(5){width:220px}.ot-tests-page .ot-test-table thead th:nth-child(6){width:120px}.ot-tests-page .ot-test-table thead th:nth-child(7),.ot-tests-page .ot-test-table thead th:nth-child(8){width:115px}.ot-tests-page .ot-test-table thead th:nth-child(9){width:180px}.ot-tests-page .ot-test-table thead th:nth-child(10){width:110px}
.ot-tests-page .ot-test-table thead th,.ot-tests-page .ot-test-table tbody td{padding:10px;border-right:1px solid #e0e8ed;border-bottom:1px solid #e0e8ed;vertical-align:middle}.ot-tests-page .ot-test-table thead th{border-top:1px solid #e0e8ed}.ot-tests-page .ot-test-table thead th:last-child,.ot-tests-page .ot-test-table tbody td:last-child{border-right:0}.ot-tests-page .ot-test-table tbody td.test-asset-info{padding:6px 10px;text-align:left}
.ot-list-assets{display:grid;gap:0;margin:0}.ot-list-asset-row{display:flex;align-items:center;gap:8px;padding:6px 0;text-align:left}.ot-list-asset-row+.ot-list-asset-row{border-top:1px solid #e0e8ed}.ot-list-asset-thumb{display:flex;flex:0 0 64px;width:64px;height:48px;align-items:center;justify-content:center;padding:0;border:1px solid #d6e3e9;border-radius:5px;background:#edf4f7;color:#246c98;overflow:hidden}.ot-list-asset-thumb img{width:100%;height:100%;object-fit:contain}.ot-list-asset-thumb i{font-size:24px}.ot-list-daily-state{font-size:11px;white-space:nowrap}.ot-list-daily-state.is-filled{color:#218873}.ot-list-daily-state.is-empty{color:#b8751a}
#ot-list-preview .modal-body{text-align:center}#ot-list-preview-image,#ot-list-preview-video{max-width:100%;max-height:65vh}#ot-list-preview-video{width:100%;background:#111}#ot-list-preview [hidden]{display:none!important}
</style>
@endpush
@push('scripts')
<script>
(function () {
    var modal = window.jQuery('#ot-list-preview');
    var video = document.getElementById('ot-list-preview-video');
    var image = document.getElementById('ot-list-preview-image');
    function release() {
        video.pause();
        video.removeAttribute('src');
        video.load();
        video.hidden = true;
        image.removeAttribute('src');
        image.hidden = true;
    }
    modal.on('show.bs.modal', function (event) {
        var trigger = event.relatedTarget;
        if (!trigger) return;
        release();
        var name = trigger.getAttribute('data-preview-name') || '';
        document.getElementById('ot-list-preview-name').textContent = name;
        if (trigger.getAttribute('data-preview-type') === 'video') {
            video.hidden = false;
            video.src = trigger.getAttribute('data-preview-src');
            video.preload = 'metadata';
            video.load();
        } else {
            image.alt = name;
            image.hidden = false;
            image.src = trigger.getAttribute('data-preview-src');
        }
    });
    modal.on('hide.bs.modal', release);
    window.addEventListener('pagehide', release);
}());
</script>
@endpush
