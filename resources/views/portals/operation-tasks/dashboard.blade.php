@extends('portal.layout')

@section('page_title', '组长看板')

@section('content')
<link rel="stylesheet" href="{{ asset('assets/erp/operation-tasks.css') }}?v=20261111-6">
<link rel="stylesheet" href="{{ asset('assets/erp/operation-tasks-dashboard.css') }}?v=20261111-1">
@include('portals.operation-tasks.partials.head')

<section class="content ot-page ot-dashboard-page">
    <div class="ot-simple-page-head">
        <div>
            <h1>组长看板</h1>
            <p>按二级部门查看成员的每日复盘、测品任务和素材完成情况。</p>
        </div>
        <?php if ($canManagePlans): ?>
            <button type="button" class="btn ot-primary" data-toggle="modal" data-target="#ot-daily-plan-modal"><i class="fa fa-calendar-plus-o"></i> 设置每日计划</button>
        <?php endif; ?>
    </div>

    <div class="ot-card ot-dashboard-filter">
        <div class="ot-card-body">
            <form class="ot-filters" id="ot-dashboard-form" method="get">
                <div><label>开始日期</label><input class="form-control" type="date" name="from" value="{{ $from }}"></div>
                <div><label>结束日期</label><input class="form-control" type="date" name="to" value="{{ $to }}"></div>
                <div>
                    <label>团队</label>
                    <select class="form-control" name="department">
                        <option value="">全部团队</option>
                        <?php foreach ($teams as $team): ?>
                            <option value="{{ $team->id }}" <?php echo (string) $dashboardFilters['department'] === (string) $team->id ? 'selected' : ''; ?>>{{ $team->name }}</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label>运营</label>
                    <select class="form-control" name="owner">
                        <option value="">全部运营</option>
                        <?php foreach ($operators as $operator): ?>
                            <option value="{{ $operator }}" <?php echo $dashboardFilters['owner'] === $operator ? 'selected' : ''; ?>>{{ $operator }}</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="ot-dashboard-actions"><label>快捷日期</label><span><button class="btn btn-default" type="button" data-range="today">今天</button><button class="btn btn-default" type="button" data-range="yesterday">昨天</button><button class="btn btn-default" type="button" data-range="week">近7天</button><button class="btn btn-default" type="button" data-range="all">全部时间</button></span></div>
                <div class="ot-filter-submit"><button class="btn ot-primary" type="submit"><i class="fa fa-search"></i> 查询</button></div>
            </form>
        </div>
    </div>

    <div class="ot-stats ot-dashboard-stats">
        <div class="ot-stat"><span>二级部门</span><b>{{ $teamRows->count() }}</b><small>当前筛选范围</small></div>
        <div class="ot-stat"><span>成员人数</span><b>{{ $teamRows->sum('member_count') }}</b><small>按二级部门归属统计</small></div>
        <div class="ot-stat"><span>复盘提交</span><b>{{ $recaps->count() }}</b><small>按业务数据日期</small></div>
        <div class="ot-stat"><span>总营业额</span><b>${{ number_format($recaps->sum('total_revenue_usd'), 2) }}</b><small>统一 USD</small></div>
        <div class="ot-stat"><span>总广告费</span><b>${{ number_format($recaps->sum('total_spend_usd'), 2) }}</b><small>统一 USD</small></div>
        <div class="ot-stat"><span>整体 ROI</span><b>{{ $recaps->sum('total_spend_usd') > 0 ? number_format($recaps->sum('total_revenue_usd') / $recaps->sum('total_spend_usd'), 2) : '—' }}</b><small>营业额 / 广告费</small></div>
    </div>

    <div class="ot-dashboard-tabs"><button type="button" class="is-active" data-dashboard-tab="recaps">每日复盘</button><button type="button" data-dashboard-tab="tests">测品任务</button></div>

    <div class="ot-dashboard-pane is-active" data-dashboard-pane="recaps">
        <?php if ($teamRows->isEmpty()): ?>
            <div class="ot-card"><div class="ot-empty">当前权限范围内没有可查看的二级部门。</div></div>
        <?php else: ?>
            <?php foreach ($teamRows as $team): ?>
                <div class="ot-card ot-team-panel">
                    <div class="ot-card-head"><strong>{{ $team->name }}</strong><span class="ot-badge">{{ $team->member_count }} 名成员</span></div>
                    <div class="table-responsive"><table class="table ot-table ot-dashboard-table"><thead><tr><th>成员 / 角色</th><th>复盘提交</th><th>总营业额</th><th>总单量</th><th>总广告费</th><th>ROI</th><th>操作</th></tr></thead><tbody>
                        <?php foreach ($team->members as $row): ?>
                            <tr>
                                <td><b>{{ $row->name }}</b><small>{{ $row->position ?: '成员' }}</small></td><td>{{ $row->recap_days }} / {{ $row->expected_days }} 天</td><td>${{ number_format($row->revenue, 2) }}</td><td>{{ $row->orders }}</td><td>${{ number_format($row->spend, 2) }}</td><td>{{ $row->spend > 0 ? number_format($row->revenue / $row->spend, 2) : '—' }}</td><td><a class="btn btn-default btn-sm" href="{{ route('operation-tasks.recaps.index', ['operator' => $row->name, 'from' => $from, 'to' => $to]) }}">查看复盘</a></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody></table></div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <div class="ot-dashboard-pane" data-dashboard-pane="tests">
        <?php if ($teamRows->isEmpty()): ?>
            <div class="ot-card"><div class="ot-empty">当前权限范围内没有可查看的二级部门。</div></div>
        <?php else: ?>
            <?php foreach ($teamRows as $team): ?>
                <div class="ot-card ot-team-panel">
                    <div class="ot-card-head"><strong>{{ $team->name }}</strong><span class="ot-badge">{{ $team->member_count }} 名成员</span></div>
                    <div class="table-responsive"><table class="table ot-table ot-dashboard-table"><thead><tr><th>成员 / 角色</th><th>每日计划完成</th><th>区间单品</th><th>区间类目</th><th>新增素材</th><th>任务档案</th></tr></thead><tbody>
                        <?php foreach ($team->members as $row): ?>
                            <tr>
                                <td><b>{{ $row->name }}</b><small>{{ $row->position ?: '成员' }}</small></td><td>{{ $row->completed_days }} / {{ $row->planned_days }} 天</td><td>{{ $row->single_count }} 个<small>素材 {{ $row->single_assets }} 条</small></td><td>{{ $row->category_count }} 个<small>素材 {{ $row->category_assets }} 条</small></td><td>{{ $row->single_assets + $row->category_assets }} 条</td>
                                <td><?php foreach ($row->task_records as $taskRecord): ?><a class="ot-task-link" href="{{ $taskRecord['url'] }}">{{ $taskRecord['task_no'] }}</a><?php endforeach; ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody></table></div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</section>

<?php if ($canManagePlans): ?>
    <div class="modal fade ot-modal" id="ot-daily-plan-modal" tabindex="-1" role="dialog"><div class="modal-dialog modal-lg"><div class="modal-content">
        <form class="ot-form" method="post" action="{{ route('operation-tasks.daily-plans.store') }}">
            @csrf
            <div class="modal-header"><button type="button" class="close" data-dismiss="modal">×</button><h4 class="modal-title">确认成员每日测品计划</h4></div>
            <div class="modal-body"><div class="ot-grid">
                <div><label>计划日期 *</label><input class="form-control" type="date" name="plan_date" value="{{ now('Asia/Shanghai')->format('Y-m-d') }}" required></div>
                <div><label>二级部门 *</label><select class="form-control" name="department_id" id="ot-plan-team" required><option value="">请选择二级部门</option><?php foreach ($teamRows as $team): ?><option value="{{ $team->id }}">{{ $team->name }}</option><?php endforeach; ?></select></div>
                <div><label>成员 *</label><select class="form-control" name="user_id" id="ot-plan-user" required><option value="">请先选择二级部门</option><?php foreach ($teamRows as $team): ?><?php foreach ($team->members as $member): ?><option value="{{ $member->id }}" data-team="{{ $team->id }}" hidden>{{ $member->name }}</option><?php endforeach; ?><?php endforeach; ?></select></div>
                <div><label>计划类型 *</label><select class="form-control" name="plan_mode" id="ot-plan-mode"><option value="single">单品</option><option value="category">类目</option><option value="mixed">混合计划</option></select></div>
                <div class="ot-plan-custom" hidden><label>单品目标 *</label><input class="form-control" type="number" min="1" max="30" name="single_target" value="1"></div><div class="ot-plan-custom" hidden><label>类目目标 *</label><input class="form-control" type="number" min="1" max="30" name="category_target" value="1"></div><div class="ot-full"><label>计划说明</label><textarea class="form-control" rows="3" name="plan_note"></textarea></div>
            </div></div>
            <div class="modal-footer"><button type="button" class="btn btn-default" data-dismiss="modal">取消</button><button class="btn ot-primary">确认计划</button></div>
        </form>
    </div></div></div>
<?php endif; ?>

<script src="{{ asset('assets/erp/operation-tasks-dashboard.js') }}" defer></script>
@endsection
