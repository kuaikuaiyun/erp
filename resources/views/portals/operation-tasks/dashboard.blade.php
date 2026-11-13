@extends('portal.layout')

@section('page_title', '组长看板')

@section('content')
<link rel="stylesheet" href="{{ asset('assets/erp/operation-tasks.css') }}?v=20261111-6">
<link rel="stylesheet" href="{{ asset('assets/erp/operation-tasks-dashboard.css') }}?v=20261113-1">
@include('portals.operation-tasks.partials.head')

<section class="content ot-page ot-dashboard-page">
    <div class="ot-simple-page-head">
        <div>
            <h1>组长看板</h1>
            <p>按二级部门查看成员的每日复盘、测品任务和素材完成情况。</p>
        </div>
        <?php if ($canManagePlans): ?>
        <?php endif; ?>
    </div>

    <div class="ot-card ot-dashboard-filter">
        <div class="ot-card-body">
            <form class="ot-filters ot-search-filter-form" id="ot-dashboard-form" method="get">
@include('portals.operation-tasks.partials.search-filters')

                @include('portals.operation-tasks.partials.date-filters', ['dateFrom' => $from, 'dateTo' => $to])
                <div class="ot-filter-submit"><button class="btn ot-primary" type="submit"><i class="fa fa-search"></i> 查询</button></div>
            </form>
        </div>
    </div>

    <div class="ot-stats ot-dashboard-stats">
        <div class="ot-stat"><span>二级部门</span><b>{{ $teamRows->count() }}</b><small>当前筛选范围</small></div>
        <div class="ot-stat"><span>成员人数</span><b>{{ $teamRows->sum('member_count') }}</b><small>按二级部门归属统计</small></div>
        <div class="ot-stat"><span>复盘提交</span><b>{{ $recaps->count() }}</b><small>{{ __('operation_tasks.dates.by_submission') }}</small></div>
        <div class="ot-stat"><span>总营业额</span><b>${{ number_format($recaps->sum('total_revenue_usd'), 2) }}</b><small>统一 USD</small></div>
        <div class="ot-stat"><span>总广告费</span><b>${{ number_format($recaps->sum('total_spend_usd'), 2) }}</b><small>统一 USD</small></div>
        <div class="ot-stat"><span>整体 ROI</span><b>{{ $recaps->sum('total_spend_usd') > 0 ? number_format($recaps->sum('total_revenue_usd') / $recaps->sum('total_spend_usd'), 2) : '—' }}</b><small>营业额 / 广告费</small></div>
    </div>

    <div class="ot-dashboard-tabs"><button type="button" class="is-active" data-dashboard-tab="tests">测品任务</button><button type="button" data-dashboard-tab="recaps">每日复盘</button></div>

    <div class="ot-dashboard-pane" data-dashboard-pane="recaps">
        <?php if ($teamRows->isEmpty()): ?>
            <div class="ot-card"><div class="ot-empty">当前权限范围内没有可查看的二级部门。</div></div>
        <?php else: ?>
            <?php foreach ($teamRows as $team): ?>
                <div class="ot-card ot-team-panel">
                    <div class="ot-card-head"><strong>{{ $team->name }}</strong><span class="ot-badge">{{ $team->member_count }} 名成员</span></div>
                    <div class="table-responsive"><table class="table ot-table ot-dashboard-table"><thead><tr><th>成员 / 角色</th><th>复盘提交</th><th>总营业额</th><th>总单量</th><th>总广告费</th><th>ROI</th><th>操作</th></tr></thead><tbody>
                        <?php foreach ($team->members as $row): ?>
                            <tr>
                                <td><b>{{ $row->name }}</b><small>{{ $row->position ?: '成员' }}</small></td><td>{{ $row->recap_days }} / {{ $row->expected_days }} 天</td><td>${{ number_format($row->revenue, 2) }}</td><td>{{ $row->orders }}</td><td>${{ number_format($row->spend, 2) }}</td><td>{{ $row->spend > 0 ? number_format($row->revenue / $row->spend, 2) : '—' }}</td><td><button type="button" class="btn btn-default btn-sm" data-toggle="modal" data-target="#ot-dashboard-recaps-{{ $team->id }}-{{ $row->id }}">查看复盘</button></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody></table></div>
                    <?php foreach ($team->members as $row): ?>
                        <div class="modal fade ot-modal" id="ot-dashboard-recaps-{{ $team->id }}-{{ $row->id }}" tabindex="-1"><div class="modal-dialog modal-lg"><div class="modal-content"><div class="modal-header"><button type="button" class="close" data-dismiss="modal">×</button><h4 class="modal-title">{{ $row->name }} · 每日复盘记录</h4><p>{{ __('operation_tasks.dates.submission_hint', ['from' => $from ?: __('operation_tasks.dates.all'), 'to' => $to ?: __('operation_tasks.dates.all')]) }}</p></div><div class="modal-body"><div class="table-responsive"><table class="table ot-table ot-dashboard-recap-history"><thead><tr><th>{{ __('operation_tasks.dates.business_date') }}</th><th>{{ __('operation_tasks.dates.submitted_at') }}</th><th>提交状态</th><th>审核进度</th><th>营业额</th><th>单量</th><th>广告费</th><th>ROI</th></tr></thead><tbody>@foreach($row->recap_day_records as $record)@if(!empty($record['missing']))<tr class="is-missing"><td>—</td><td>{{ $record['date'] }}</td><td><span class="ot-badge orange">未提交</span></td><td>—</td><td>—</td><td>—</td><td>—</td><td>—</td></tr>@else @php($state = $record['workflow_status'] ?? 'approved')<tr><td><b>{{ $record['date'] }}</b><small>v{{ $record['version'] }} · {{ $record['updated_at'] }}</small></td><td>{{ $record['submitted_at'] }}</td><td><span class="ot-badge">已提交</span></td><td><span class="ot-badge {{ $state === 'rejected' ? 'red' : ($state === 'approved' ? '' : 'orange') }}">{{ $state === 'approved' ? '双级已通过' : ($state === 'pending_leader' ? '待二级主管审核' : ($state === 'pending_department' ? '待一级主管审核' : '已驳回待修改')) }}</span>@if(!empty($record['rejection_opinion']))<small class="ot-dashboard-rejection">{{ $record['rejection_opinion'] }}</small>@endif</td><td>${{ number_format($record['revenue'], 2) }}</td><td>{{ $record['orders'] }}</td><td>${{ number_format($record['spend'], 2) }}</td><td>{{ $record['spend'] > 0 ? number_format($record['revenue'] / $record['spend'], 2) : '—' }}</td></tr>@endif @endforeach</tbody></table></div></div><div class="modal-footer"><button type="button" class="btn btn-default" data-dismiss="modal">关闭</button></div></div></div></div>
                    <?php endforeach; ?>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <div class="ot-dashboard-pane is-active" data-dashboard-pane="tests">
        <?php if ($teamRows->isEmpty()): ?>
            <div class="ot-card"><div class="ot-empty">当前权限范围内没有可查看的二级部门。</div></div>
        <?php else: ?>
            <?php foreach ($teamRows as $team): ?>
                <div class="ot-card ot-team-panel">
                    <div class="ot-card-head"><strong>{{ $team->name }}</strong><span class="ot-badge">{{ $team->member_count }} 名成员</span></div>
                    <div class="table-responsive"><table class="table ot-table ot-dashboard-table ot-dashboard-activity-table">
                        <colgroup><col class="ot-col-member"><col class="ot-col-plan"><col class="ot-col-count"><col class="ot-col-count"><col class="ot-col-assets"><col class="ot-col-tasks"></colgroup>
                        <thead><tr>
                            <th>{{ __('operation_tasks.activity.member_role') }}</th>
                            <th>{{ __('operation_tasks.activity.plan_completion') }}</th>
                            <th>{{ __('operation_tasks.activity.new_single') }}</th>
                            <th>{{ __('operation_tasks.activity.new_category') }}</th>
                            <th>{{ __('operation_tasks.activity.assets') }}</th>
                            <th>{{ __('operation_tasks.activity.archive') }}</th>
                        </tr></thead>
                        <tbody>
                        @foreach($team->members as $row)
                            <tr>
                                <td class="ot-member-cell"><b>{{ $row->name }}</b><small>{{ $row->position ?: __('operation_tasks.activity.member') }}</small></td>
                                <td class="ot-activity-number {{ $row->planned_days ? '' : 'ot-is-zero' }}"><span class="{{ $row->completed_days ? 'ot-count-positive' : '' }}">{{ $row->completed_days }}</span><span class="ot-count-divider"> / </span>{{ $row->planned_days }} <span class="ot-count-unit">{{ __('operation_tasks.activity.unit_day') }}</span></td>
                                <td class="ot-activity-number {{ $row->single_count ? 'ot-count-positive' : 'ot-is-zero' }}">{{ $row->single_count }} <span class="ot-count-unit">{{ __('operation_tasks.activity.unit_task') }}</span></td>
                                <td class="ot-activity-number {{ $row->category_count ? 'ot-count-positive' : 'ot-is-zero' }}">{{ $row->category_count }} <span class="ot-count-unit">{{ __('operation_tasks.activity.unit_task') }}</span></td>
                                <td><div class="ot-asset-metrics">
                                    <span class="ot-asset-metric {{ $row->initial_assets ? 'is-new' : 'ot-is-zero' }}"><span>{{ __('operation_tasks.activity.metric_initial') }}</span><b>{{ $row->initial_assets }}</b><span>{{ __('operation_tasks.activity.unit_asset') }}</span></span>
                                    <span class="ot-asset-metric {{ $row->supplement_assets ? 'is-supplement' : 'ot-is-zero' }}"><span>{{ __('operation_tasks.activity.metric_supplement') }}</span><b>{{ $row->supplement_assets }}</b><span>{{ __('operation_tasks.activity.unit_asset') }}</span></span>
                                </div></td>
                                <td class="ot-activity-tasks">
                                    <div class="ot-activity-task-list">
                                    @forelse(array_slice($row->task_records, 0, 3) as $taskRecord)
                                        <div class="ot-activity-task">
                                            <a class="ot-task-link" href="{{ $taskRecord['url'] }}">{{ $taskRecord['task_no'] }}</a>
                                            @if($taskRecord['new_task'])<span class="ot-task-activity-badge is-new">{{ __('operation_tasks.activity.new_task') }}</span>@endif
                                            @if($taskRecord['supplement_count'])<span class="ot-task-activity-badge is-supplement">{{ __('operation_tasks.activity.supplement_tag', ['count' => $taskRecord['supplement_count']]) }}</span>@elseif(! $taskRecord['new_task'])<span class="ot-task-activity-badge">{{ __('operation_tasks.activity.resubmitted') }}</span>@endif
                                        </div>
                                    @empty
                                        <span class="ot-activity-empty" title="{{ __('operation_tasks.activity.no_records') }}">{{ __('operation_tasks.activity.empty') }}</span>
                                    @endforelse
                                    </div>
                                    @if(count($row->task_records) > 3)<button type="button" class="btn btn-link btn-xs ot-activity-view-all" data-toggle="modal" data-target="#ot-task-activity-{{ $team->id }}-{{ $row->id }}">{{ __('operation_tasks.activity.view_all', ['count' => count($row->task_records)]) }}</button>@endif
                                </td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table></div>
                    @foreach($team->members as $activityMember)
                    <div class="modal fade ot-modal" id="ot-task-activity-{{ $team->id }}-{{ $activityMember->id }}" tabindex="-1"><div class="modal-dialog modal-lg"><div class="modal-content">
                        <div class="modal-header"><button type="button" class="close" data-dismiss="modal">&times;</button><h4 class="modal-title">{{ $activityMember->name }} · {{ __('operation_tasks.activity.task_records') }}</h4></div>
                        <div class="modal-body"><table class="table ot-table"><thead><tr><th>{{ __('operation_tasks.activity.number') }}</th><th>{{ __('operation_tasks.activity.new_count') }}</th><th>{{ __('operation_tasks.activity.assets') }}</th><th>{{ __('operation_tasks.activity.latest_at') }}</th></tr></thead><tbody>
                            @foreach($activityMember->task_records as $activityRecord)<tr><td><a href="{{ $activityRecord['url'] }}">{{ $activityRecord['task_no'] }}</a></td><td>{{ $activityRecord['new_task'] ? 1 : 0 }}</td><td>{{ __('operation_tasks.activity.supplement', ['count' => $activityRecord['supplement_count']]) }}</td><td>{{ $activityRecord['submitted_at'] }}</td></tr>@endforeach
                        </tbody></table></div>
                        <div class="modal-footer"><button type="button" class="btn btn-default" data-dismiss="modal">{{ __('operation_tasks.management.cancel') }}</button></div>
                    </div></div></div>
                    @endforeach

                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</section>

<script src="{{ asset('assets/erp/operation-tasks-dashboard.js') }}" defer></script>
@endsection
