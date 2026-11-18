@extends('portals.review-center.layout')

@section('page_title', __('review_center.dashboard_copy.text_013'))

@section('content')
<link rel="stylesheet" href="{{ asset('assets/erp/operation-tasks.css') }}?v=20261111-6">
<link rel="stylesheet" href="{{ asset('assets/erp/operation-tasks-dashboard.css') }}?v=20261113-1">
@include('portals.review-center.operation-tasks.partials.head')

<section class="content ot-page ot-dashboard-page">
    <div class="ot-simple-page-head">
        <div>
            <h1>{{ __('review_center.dashboard_copy.text_013') }}</h1>
            <p>{{ __('review_center.dashboard_copy.text_018') }}</p>
        </div>
        <?php if ($canManagePlans): ?>
        <?php endif; ?>
    </div>

    <div class="ot-card ot-dashboard-filter">
        <div class="ot-card-body">
            <form class="ot-filters ot-search-filter-form" id="ot-dashboard-form" method="get">
@include('portals.review-center.operation-tasks.partials.search-filters')

                @include('portals.review-center.operation-tasks.partials.date-filters', ['dateFrom' => $from, 'dateTo' => $to])
                <div class="ot-filter-submit"><button class="btn ot-primary" type="submit"><i class="fa fa-search"></i>{{ __('review_center.dashboard_copy.text_019') }}</button></div>
            </form>
        </div>
    </div>

    <div class="ot-stats ot-dashboard-stats">
        <div class="ot-stat"><span>{{ __('review_center.dashboard_copy.text_020') }}</span><b>{{ $teamRows->count() }}</b><small>{{ __('review_center.dashboard_copy.text_021') }}</small></div>
        <div class="ot-stat"><span>{{ __('review_center.dashboard_copy.text_022') }}</span><b>{{ $teamRows->sum('member_count') }}</b><small>{{ __('review_center.dashboard_copy.text_023') }}</small></div>
        <div class="ot-stat"><span>{{ __('review_center.dashboard_copy.text_024') }}</span><b>{{ $recaps->count() }}</b><small>{{ __('operation_tasks.dates.by_submission') }}</small></div>
        <div class="ot-stat"><span>{{ __('review_center.dashboard_copy.text_025') }}</span><b>${{ number_format($recaps->sum('total_revenue_usd'), 2) }}</b><small>{{ __('review_center.dashboard_copy.text_026') }}</small></div>
        <div class="ot-stat"><span>{{ __('review_center.dashboard_copy.text_027') }}</span><b>${{ number_format($recaps->sum('total_spend_usd'), 2) }}</b><small>{{ __('review_center.dashboard_copy.text_026') }}</small></div>
        <div class="ot-stat"><span>{{ __('review_center.dashboard_copy.text_028') }}</span><b>{{ $recaps->sum('total_spend_usd') > 0 ? number_format($recaps->sum('total_revenue_usd') / $recaps->sum('total_spend_usd'), 2) : '—' }}</b><small>{{ __('review_center.dashboard_copy.text_029') }}</small></div>
    </div>

    <div class="ot-dashboard-tabs"><button type="button" class="is-active" data-dashboard-tab="tests">{{ __('review_center.dashboard_copy.text_030') }}</button><button type="button" data-dashboard-tab="recaps">{{ __('review_center.dashboard_copy.text_031') }}</button></div>

    <div class="ot-dashboard-pane" data-dashboard-pane="recaps">
        <?php if ($teamRows->isEmpty()): ?>
            <div class="ot-card"><div class="ot-empty">{{ __('review_center.dashboard_copy.text_032') }}</div></div>
        <?php else: ?>
            <?php foreach ($teamRows as $team): ?>
                <div class="ot-card ot-team-panel">
                    <div class="ot-card-head"><strong>{{ $team->name }}</strong><span class="ot-badge">{{ $team->member_count }}{{ __('review_center.dashboard_member_unit') }}</span></div>
                    <div class="table-responsive"><table class="table ot-table ot-dashboard-table"><thead><tr><th>{{ __('review_center.dashboard_copy.text_033') }}</th><th>{{ __('review_center.dashboard_copy.text_024') }}</th><th>{{ __('review_center.dashboard_copy.text_025') }}</th><th>{{ __('review_center.dashboard_copy.text_034') }}</th><th>{{ __('review_center.dashboard_copy.text_027') }}</th><th>{{ __('review_center.roi') }}</th><th>{{ __('review_center.dashboard_copy.text_035') }}</th></tr></thead><tbody>
                        <?php foreach ($team->members as $row): ?>
                            <tr>
                                <td><b>{{ $row->name }}</b><small>{{ $row->position ?: __('review_center.dashboard_copy.text_003') }}</small></td><td>{{ $row->recap_days }} / {{ $row->expected_days }}{{ __('review_center.dashboard_day_unit') }}</td><td>${{ number_format($row->revenue, 2) }}</td><td>{{ $row->orders }}</td><td>${{ number_format($row->spend, 2) }}</td><td>{{ $row->spend > 0 ? number_format($row->revenue / $row->spend, 2) : '—' }}</td><td><button type="button" class="btn btn-default btn-sm" data-toggle="modal" data-target="#ot-dashboard-recaps-{{ $team->id }}-{{ $row->id }}">{{ __('review_center.dashboard_copy.text_036') }}</button></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody></table></div>
                    <?php foreach ($team->members as $row): ?>
                        <div class="modal fade ot-modal" id="ot-dashboard-recaps-{{ $team->id }}-{{ $row->id }}" tabindex="-1"><div class="modal-dialog modal-lg"><div class="modal-content"><div class="modal-header"><button type="button" class="close" data-dismiss="modal">×</button><h4 class="modal-title">{{ $row->name }} · {{ __('review_center.dashboard_recap_history') }}</h4><p>{{ __('operation_tasks.dates.submission_hint', ['from' => $from ?: __('operation_tasks.dates.all'), 'to' => $to ?: __('operation_tasks.dates.all')]) }}</p></div><div class="modal-body"><div class="table-responsive"><table class="table ot-table ot-dashboard-recap-history"><thead><tr><th>{{ __('operation_tasks.dates.business_date') }}</th><th>{{ __('operation_tasks.dates.submitted_at') }}</th><th>{{ __('review_center.dashboard_copy.text_037') }}</th><th>{{ __('review_center.dashboard_copy.text_038') }}</th><th>{{ __('review_center.dashboard_copy.text_039') }}</th><th>{{ __('review_center.dashboard_copy.text_040') }}</th><th>{{ __('review_center.dashboard_copy.text_041') }}</th><th>{{ __('review_center.roi') }}</th></tr></thead><tbody>@foreach($row->recap_day_records as $record)@if(!empty($record['missing']))<tr class="is-missing"><td>—</td><td>{{ $record['date'] }}</td><td><span class="ot-badge orange">{{ __('review_center.dashboard_copy.text_042') }}</span></td><td>—</td><td>—</td><td>—</td><td>—</td><td>—</td></tr>@else @php($state = $record['workflow_status'] ?? 'approved')<tr><td><b>{{ $record['date'] }}</b><small>v{{ $record['version'] }} · {{ $record['updated_at'] }}</small></td><td>{{ $record['submitted_at'] }}</td><td><span class="ot-badge">{{ __('review_center.dashboard_copy.text_043') }}</span></td><td><span class="ot-badge {{ $state === 'rejected' ? 'red' : ($state === 'approved' ? '' : 'orange') }}">{{ $state === 'approved' ? __('review_center.dashboard_copy.text_014') : ($state === 'pending_leader' ? __('review_center.dashboard_copy.text_015') : ($state === 'pending_department' ? __('review_center.dashboard_copy.text_016') : __('review_center.dashboard_copy.text_017'))) }}</span>@if(!empty($record['rejection_opinion']))<small class="ot-dashboard-rejection">{{ $record['rejection_opinion'] }}</small>@endif</td><td>${{ number_format($record['revenue'], 2) }}</td><td>{{ $record['orders'] }}</td><td>${{ number_format($record['spend'], 2) }}</td><td>{{ $record['spend'] > 0 ? number_format($record['revenue'] / $record['spend'], 2) : '—' }}</td></tr>@endif @endforeach</tbody></table></div></div><div class="modal-footer"><button type="button" class="btn btn-default" data-dismiss="modal">{{ __('review_center.dashboard_copy.text_044') }}</button></div></div></div></div>
                    <?php endforeach; ?>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <div class="ot-dashboard-pane is-active" data-dashboard-pane="tests">
        <?php if ($teamRows->isEmpty()): ?>
            <div class="ot-card"><div class="ot-empty">{{ __('review_center.dashboard_copy.text_032') }}</div></div>
        <?php else: ?>
            <?php foreach ($teamRows as $team): ?>
                <div class="ot-card ot-team-panel">
                    <div class="ot-card-head"><strong>{{ $team->name }}</strong><span class="ot-badge">{{ $team->member_count }}{{ __('review_center.dashboard_member_unit') }}</span></div>
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
