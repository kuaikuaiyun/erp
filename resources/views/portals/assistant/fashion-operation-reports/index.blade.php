@extends('portal.layout')
@section('page_title', $reportProject['code'] === 'danbao-general' ? '单包百货运营报表' : '单包服装运营报表')
@push('styles')
<link rel="stylesheet" href="{{ asset('vendor/laravel-admin/AdminLTE/plugins/select2/select2.min.css') }}">
<style>
.fashion-report-filter{overflow:visible!important}
.fashion-report-filter,.fashion-report-box{border:0;border-radius:12px;box-shadow:0 5px 19px rgba(28,65,92,.09);overflow:hidden}.report-filter{display:flex;align-items:flex-end;gap:11px;flex-wrap:wrap}.report-filter .form-group{margin:0}.report-filter label{display:block;margin-bottom:5px;color:#688092;font-size:12px}.report-filter .form-control{height:37px;border-radius:6px;min-width:145px}.report-download-button{height:37px;border:0;border-radius:6px;background:#19a974;color:#fff;box-shadow:0 3px 9px rgba(25,169,116,.2)}.report-download-button:hover,.report-download-button:focus{background:#138b60;color:#fff}.report-download-button[disabled]{opacity:.65}.report-summary{display:grid;grid-template-columns:repeat(7,minmax(125px,1fr));gap:11px;margin:15px 0}.report-card{padding:14px 16px;border-left:4px solid #328fc0;border-radius:9px;background:#fff;box-shadow:0 3px 13px rgba(30,65,95,.08)}.report-card span{display:block;color:#718697;font-size:12px}.report-card strong{display:block;margin-top:5px;color:#173f60;font-size:21px}.fashion-report-table{min-width:1180px;margin:0}.fashion-report-table th{padding:10px 8px!important;background:#dcecf6!important;color:#244f6d;text-align:center;white-space:nowrap}.fashion-report-table td{padding:9px 8px!important;vertical-align:middle!important}.fashion-report-table th:nth-child(7),.fashion-report-table td:nth-child(7){width:230px;max-width:230px}.fashion-report-table tbody tr.report-main-row:nth-of-type(4n+1)>td{background:#f4f9fc}.fashion-report-table tbody tr.report-main-row:nth-of-type(4n+3)>td{background:#fff8ef}.store-list,.media-list{display:grid;gap:6px}.store-list{min-width:280px}.media-list{width:100%;min-width:190px}.store-item,.media-item{padding:7px 9px;border:1px solid #dbe6ed;border-radius:6px;background:rgba(255,255,255,.7)}.media-item{display:grid;grid-template-columns:minmax(75px,1fr) auto;gap:8px}.roi{font-weight:700;color:#16805c}.report-note{padding:11px 15px;border-radius:7px;background:#fff7df;color:#765a17;margin-bottom:14px}.report-detail-row>td{padding:0!important;background:#edf5f9!important}.report-detail-card{margin:12px 18px;padding:14px 16px;border-left:4px solid #3c8dbc;border-radius:8px;background:#fff;box-shadow:0 3px 11px rgba(35,78,105,.08)}.report-detail-meta{margin-bottom:10px;color:#607d90}.report-detail-meta b{color:#274e68}.report-detail-stores{display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:9px}.report-detail-store{padding:10px 12px;border:1px solid #d5e4ec;border-radius:7px;background:#f8fbfd}.report-detail-store .label{margin-left:6px}.report-detail-store small{display:block;margin-top:4px;color:#718797}.report-detail-store strong{display:block;margin-top:7px;color:#1c668f}.detail-toggle{border:0;border-radius:5px}.empty{padding:50px!important;text-align:center;color:#8295a3}@media(max-width:1000px){.report-summary{grid-template-columns:repeat(2,1fr)}}
.submission-status{display:inline-block;padding:5px 10px;border-radius:14px;font-size:12px;font-weight:700;white-space:nowrap}.submission-status.submitted{background:#dff5e9;color:#18764d}.submission-status.pending{background:#fff0bf;color:#986b00}.submission-status.not_submitted{background:#eef1f4;color:#7a8790}
.fashion-report-table tfoot td{padding:11px 8px!important;background:#d9e9f3!important;border-color:#b9d0de!important;color:#173f60;font-weight:700;vertical-align:middle!important}.fashion-report-table tfoot .media-item{background:#f6fbfe}.fashion-report-table tfoot .total-label{text-align:center;font-size:15px}
.report-sort-button{display:inline-flex;align-items:center;justify-content:center;width:24px;height:24px;margin-left:4px;border:1px solid #9fc3da;border-radius:5px;background:#fff;color:#4e7894;text-decoration:none!important;vertical-align:middle}.report-sort-button:hover,.report-sort-button.active{border-color:#2587bd;background:#2587bd;color:#fff}
.department-multi-wrap{min-width:250px}.department-multi-wrap .select2-container{min-width:250px}.department-multi-wrap .select2-selection--multiple{min-height:37px;border-color:#d2d6de;border-radius:6px}.department-multi-wrap .select2-selection__choice{margin-top:6px!important;background:#328fc0!important;border-color:#2580af!important;color:#fff!important}.department-multi-wrap .select2-selection__choice__remove{color:#fff!important}
.roi-tier{display:inline-block;min-width:52px;padding:4px 8px;border-radius:12px;text-align:center;font-weight:800}.roi-excellent{background:#d8eee2;color:#14532d}.roi-good{background:#fff0b8;color:#8a6500}.roi-warning{background:#ffe0ad;color:#b45309}.roi-poor{background:#ffd2bd;color:#c2410c}.roi-danger{background:#f8c9c9;color:#8b1a1a}.team-position{display:inline-block;margin-left:5px;padding:2px 6px;border-radius:9px;background:#dcecf6;color:#276589;font-size:11px}.team-position.manager{background:#d8eee2;color:#14532d}
.employment-resigned{display:inline-block;margin-left:5px;padding:2px 7px;border-radius:10px;background:#f8d7da;color:#9c2430;font-size:11px;font-weight:700}
.fashion-report-table tbody tr.report-main-row.team-band-0>td{background:#f1f7fb!important}.fashion-report-table tbody tr.report-main-row.team-band-1>td{background:#fff6eb!important}.fashion-report-table tbody tr.report-main-row.team-band-0:hover>td{background:#e8f2f8!important}.fashion-report-table tbody tr.report-main-row.team-band-1:hover>td{background:#ffeedb!important}
.personal-profit{font-weight:800}.personal-profit.profit{color:#16805c}.personal-profit.loss{color:#c2410c}.personal-profit.even{color:#738596}.profit-status{display:inline-block;padding:4px 9px;border-radius:12px;font-size:12px;font-weight:700}.profit-status.profit{background:#dff5e9;color:#18764d}.profit-status.loss{background:#fde2e2;color:#b42318}.profit-status.even{background:#eef1f4;color:#6f7d87}
.report-card-button{width:100%;border-top:0;border-right:0;border-bottom:0;text-align:left;cursor:pointer}.report-card-button:hover{background:#fff7f7;box-shadow:0 5px 16px rgba(180,45,45,.12)}.report-card-button small{display:block;margin-top:5px;color:#a84a4a}
.dashboard-mode-switch{display:flex;gap:10px;align-items:stretch;margin:0 0 16px}.dashboard-mode-switch button{flex:1;min-height:66px;border:2px solid #b9d7e8;border-radius:10px;background:#f7fbfe;color:#37617a;font-size:18px;font-weight:700;transition:.16s}.dashboard-mode-switch button i{margin-right:8px}.dashboard-mode-switch button small{display:block;margin-top:4px;font-size:12px;font-weight:400;color:inherit;opacity:.82}.dashboard-mode-switch button:hover{border-color:#4198c8;background:#eef8fd}.dashboard-mode-switch button.active{border-color:#1989c5;background:linear-gradient(135deg,#278fc4,#176da5);color:#fff;box-shadow:0 5px 15px rgba(26,125,177,.25)}@media(max-width:640px){.dashboard-mode-switch{flex-direction:column}}
</style>
@endpush
@section('content')
<section class="content-header"><h1>{{ $reportProject['code'] === 'danbao-general' ? '单包百货运营报表' : '单包服装运营报表' }} <small>对应项目 XShoppy、Shopline 店铺业绩与广告花费统计</small></h1></section>
<section class="content">
@if($isDashboard)<div class="dashboard-mode-switch" role="group" aria-label="数据模式切换"><button type="button" class="{{ $isPersonalMode ? 'active' : '' }}" data-dashboard-mode="personal"><i class="fa fa-user"></i>个人模式<small>按人员查看盈亏与 ROI</small></button><button type="button" class="{{ !$isPersonalMode ? 'active' : '' }}" data-dashboard-mode="performance"><i class="fa fa-bar-chart"></i>业绩模式<small>查看运营部门业绩明细</small></button></div>@endif
<div class="box fashion-report-filter"><div class="box-body"><form class="report-filter" method="get"><input type="hidden" name="project_code" value="{{ $reportProject['code'] }}">
 <div class="form-group"><label>时间范围</label><select class="form-control" name="date_range" id="fashion-date-range"><option value="today" @selected(($filters['date_range']??'yesterday')==='today')>今天</option><option value="yesterday" @selected(($filters['date_range']??'yesterday')==='yesterday')>昨天</option><option value="last_7_days" @selected(($filters['date_range']??'yesterday')==='last_7_days')>近 7 天</option><option value="last_15_days" @selected(($filters['date_range']??'yesterday')==='last_15_days')>近 15 天</option><option value="last_30_days" @selected(($filters['date_range']??'yesterday')==='last_30_days')>近 30 天</option><option value="this_month" @selected(($filters['date_range']??'yesterday')==='this_month')>本月</option><option value="last_month" @selected(($filters['date_range']??'yesterday')==='last_month')>上月</option><option value="custom" @selected(($filters['date_range']??'yesterday')==='custom')>自定义时间</option></select></div>
 <div class="form-group"><label>开始日期</label><input class="form-control" type="date" name="date_from" value="{{ $filters['date_from'] }}"></div><div class="form-group"><label>结束日期</label><input class="form-control" type="date" name="date_to" value="{{ $filters['date_to'] }}"></div>
 <div class="form-group"><label>统计时区</label><select class="form-control" name="timezone">@foreach($timezoneOptions as $value=>$label)<option value="{{ $value }}" @selected($filters['timezone']===$value)>{{ $label }}</option>@endforeach</select></div>
 @include('portals.assistant.partials.operation-department-multi-filter',['departments'=>$departmentOptions,'selectedIds'=>$filters['department_ids'],'inputName'=>'department_ids','label'=>'运营部门'])<div class="form-group"><label>运营人员</label><input class="form-control" name="operator" value="{{ $filters['operator']??'' }}" placeholder="输入姓名"></div>
 <div class="form-group"><label>离职人员</label><select class="form-control" name="exclude_resigned"><option value="0" @selected(!($filters['exclude_resigned']??false))>包含离职人员</option><option value="1" @selected($filters['exclude_resigned']??false)>排除离职人员</option></select></div>
 @if($isDashboard)<input type="hidden" name="display_mode" id="fashion-display-mode" value="{{ $isPersonalMode ? 'personal' : 'performance' }}"><div class="form-group personal-mode-setting"><label>个人数据范围</label><select class="form-control" name="personal_range"><option value="7" @selected((int)($filters['personal_range']??7)===7)>过去 7 天（不含今天）</option><option value="14" @selected((int)($filters['personal_range']??7)===14)>过去 14 天（不含今天）</option></select></div><div class="form-group personal-mode-setting"><label>盈利系数</label><input class="form-control" type="number" name="profit_coefficient" min="0.0001" max="1000000" step="any" value="{{ $filters['profit_coefficient']??1.65 }}"></div>@else<div class="form-group"><label>数据模式</label><select class="form-control" name="display_mode" id="fashion-display-mode"><option value="performance" @selected(($filters['display_mode']??'performance')==='performance')>业绩模式</option><option value="team" @selected(($filters['display_mode']??'performance')==='team')>团队模式</option></select></div>@endif
 <button class="btn btn-primary"><i class="fa fa-search"></i> 查询</button><a class="btn btn-default" href="{{ route($fashionReportIndexRoute, ['project_code' => $reportProject['code']]) }}">重置</a>@unless($isPersonalMode)<button type="button" class="btn report-download-button" id="fashionReportImageDownload"><i class="fa fa-picture-o"></i> <span>下载列表图片</span></button>@endunless
</form></div></div>
<div class="report-summary"><div class="report-card"><span>运营人数</span><strong>{{ $summary['people'] }}</strong></div><div class="report-card"><span>店铺数量</span><strong>{{ $summary['stores'] }}</strong></div><div class="report-card"><span>订单量</span><strong>{{ $summary['orders'] }}</strong></div><div class="report-card"><span>总业绩</span><strong>${{ number_format($summary['revenue'],2) }}</strong></div><div class="report-card"><span>客单价</span><strong>{{ $summary['aov']!==null?'$'.number_format($summary['aov'],2):'—' }}</strong></div><div class="report-card"><span>广告费</span><strong>${{ number_format($summary['ad_spend'],2) }}</strong></div><div class="report-card"><span>总 ROI</span><strong>{{ $summary['roi']!==null?number_format($summary['roi'],2):'—' }}</strong></div></div>
<div class="report-note"><i class="fa fa-info-circle"></i> 订单业绩来自运营绑定店铺的所有订单，不限制付款状态；广告费统计审核通过和审核不通过的服装广告费提报，待审核数据暂不计入。各媒体仅显示实际广告花费；总 ROI＝总业绩÷全部实际广告费。</div>
<div class="report-note"><b>ROI 颜色：</b><span class="roi-tier roi-excellent">＞1.8 优秀</span> <span class="roi-tier roi-good">1.6～1.8 不错</span> <span class="roi-tier roi-warning">1.4～1.6 警戒</span> <span class="roi-tier roi-poor">1.2～1.4 较差</span> <span class="roi-tier roi-danger">＜1.2 高危</span></div>
@php
    $orderSortActive = ($filters['sort_by'] ?? '') === 'order_count';
    $revenueSortActive = ($filters['sort_by'] ?? '') === 'revenue';
    $orderSortDirection = $orderSortActive && ($filters['sort_order'] ?? 'desc') === 'desc' ? 'asc' : 'desc';
    $revenueSortDirection = $revenueSortActive && ($filters['sort_order'] ?? 'desc') === 'desc' ? 'asc' : 'desc';
    $orderSortUrl = route($fashionReportIndexRoute, array_merge(request()->except('page'), ['sort_by' => 'order_count', 'sort_order' => $orderSortDirection]));
    $revenueSortUrl = route($fashionReportIndexRoute, array_merge(request()->except('page'), ['sort_by' => 'revenue', 'sort_order' => $revenueSortDirection]));
@endphp
@if($isPersonalMode)
<div class="report-note"><i class="fa fa-user"></i> 个人模式统计 {{ $filters['date_from'] }} 至 {{ $filters['date_to'] }} 的完整自然日（不含当天），按每天计算：<b>当天营业额 ÷ {{ number_format((float)$filters['profit_coefficient'], 2) }} − 当天广告费</b>。列表按人员汇总筛选期间的数据；选择过去 7 天时，7 天均亏损才标红；选择过去 14 天时，14 天均亏损才标红。</div>
<div class="report-summary"><div class="report-card"><span>筛选期间计算结果</span><strong class="personal-profit {{ $personalSummary['profit'] > 0 ? 'profit' : ($personalSummary['profit'] < 0 ? 'loss' : 'even') }}">${{ number_format($personalSummary['profit'],2) }}</strong></div><button type="button" class="report-card report-card-button" id="continuousLossPeople"><span>连续亏损人数</span><strong class="text-red">{{ $personalSummary['continuous_loss_people'] }}</strong><small>点击查看具体人员</small></button></div>
<div class="box fashion-report-box"><div class="box-header with-border"><h3 class="box-title">个人盈亏汇总 <small>{{ $filters['date_from'] }} 至 {{ $filters['date_to'] }} · 系数 {{ number_format((float)$filters['profit_coefficient'], 2) }}</small></h3></div><div class="table-responsive"><table class="table table-bordered fashion-report-table"><thead><tr><th>运营部门</th><th>人员</th><th>订单量</th><th>筛选期间营业额($)</th><th>系数</th><th>筛选期间广告费($)</th><th>ROI</th><th>计算结果($)</th><th>详情</th></tr></thead><tbody>@forelse($personalSummaryRows as $row)<tr class="report-main-row"><td>{{ $row['department'] }}</td><td><b class="{{ $row['continuous_loss'] ? 'text-red' : '' }}" @if($row['continuous_loss']) title="当前选择范围内每天均亏损" @endif>{{ $row['operator'] }}</b></td><td class="text-right">{{ $row['order_count'] }}</td><td class="text-right">${{ number_format($row['revenue'],2) }}</td><td class="text-right">{{ number_format((float)$filters['profit_coefficient'],2) }}</td><td class="text-right">${{ number_format($row['ad_spend'],2) }}</td><td class="text-center roi"><span class="roi-tier {{ $row['roi_class'] }}">{{ $row['roi']!==null?number_format($row['roi'],2):'—' }}</span></td><td class="text-right personal-profit {{ $row['profit'] > 0 ? 'profit' : ($row['profit'] < 0 ? 'loss' : 'even') }}">${{ number_format($row['profit'],2) }}</td><td class="text-center"><button type="button" class="btn btn-info btn-xs personal-detail-trigger" data-personal-index="{{ $loop->index }}"><i class="fa fa-eye"></i> 查看详情</button></td></tr>@empty<tr><td colspan="9" class="empty"><i class="fa fa-inbox fa-2x"></i><br>当前筛选范围暂无人员数据</td></tr>@endforelse</tbody></table></div></div>
<div class="modal fade" id="personalReportDetailModal" tabindex="-1" role="dialog"><div class="modal-dialog modal-lg" role="document"><div class="modal-content"><div class="modal-header"><button type="button" class="close" data-dismiss="modal"><span>&times;</span></button><h4 class="modal-title" id="personalReportDetailTitle">个人每日数据</h4></div><div class="modal-body table-responsive" id="personalReportDetailBody"></div></div></div></div>
<div class="modal fade" id="continuousLossModal" tabindex="-1" role="dialog"><div class="modal-dialog" role="document"><div class="modal-content"><div class="modal-header"><button type="button" class="close" data-dismiss="modal"><span>&times;</span></button><h4 class="modal-title"><i class="fa fa-exclamation-triangle text-red"></i> 亏损人员</h4></div><div class="modal-body table-responsive" id="continuousLossBody"></div></div></div></div>
@else
<div class="box fashion-report-box"><div class="box-header with-border"><h3 class="box-title">运营统计明细 <small>{{ $filters['date_from'] }} 至 {{ $filters['date_to'] }} · {{ $timezoneOptions[$filters['timezone']] }}</small></h3></div><div class="table-responsive"><table class="table table-bordered fashion-report-table"><thead><tr><th>运营部门</th><th>人名</th><th>入职天数</th><th>订单量 <a class="report-sort-button {{ $orderSortActive ? 'active' : '' }}" href="{{ $orderSortUrl }}" title="按订单量{{ $orderSortDirection === 'desc' ? '从高到低' : '从低到高' }}排序"><i class="fa {{ $orderSortActive && ($filters['sort_order'] ?? 'desc') === 'asc' ? 'fa-sort-amount-asc' : 'fa-sort-amount-desc' }}"></i></a></th><th>总业绩($) <a class="report-sort-button {{ $revenueSortActive ? 'active' : '' }}" href="{{ $revenueSortUrl }}" title="按总业绩{{ $revenueSortDirection === 'desc' ? '从高到低' : '从低到高' }}排序"><i class="fa {{ $revenueSortActive && ($filters['sort_order'] ?? 'desc') === 'asc' ? 'fa-sort-amount-asc' : 'fa-sort-amount-desc' }}"></i></a></th><th>客单价($)</th><th>各媒体 / 广告费</th><th>广告费($)</th><th>总 ROI</th><th>操作</th><th>广告费提报</th></tr></thead><tbody>
@forelse($rows as $row)
<tr class="report-main-row {{ ($filters['display_mode']??'performance')==='team' ? 'team-band-'.($teamBands[$loop->index]??0) : '' }}"><td>{{ $row['department'] }}</td><td><b>{{ $row['operator'] }}</b>@if($row['is_resigned'])<span class="employment-resigned">离职</span>@endif @if(($filters['display_mode']??'performance')==='team')<span class="team-position {{ $row['team_position']==='manager'?'manager':'' }}">{{ $row['team_position']==='manager'?'负责人':($row['team_position']==='deputy'?'副主管':'组员') }}</span>@endif</td><td>{{ $row['hire_days']!==null?$row['hire_days'].' 天':'—' }}</td><td class="text-right"><b>{{ $row['order_count'] }}</b></td><td class="text-right"><b>${{ number_format($row['revenue'],2) }}</b></td><td class="text-right">{{ $row['aov']!==null?number_format($row['aov'],2):'—' }}</td><td><div class="media-list">@forelse($row['media'] as $media)<div class="media-item"><b>{{ $media['name'] }}</b><span>${{ number_format($media['spend'],2) }}</span></div>@empty<span class="text-muted">暂无已审核的广告费</span>@endforelse</div></td><td class="text-right">${{ number_format($row['ad_spend'],2) }}</td><td class="text-center roi"><span class="roi-tier {{ $row['roi_class'] }}">{{ $row['roi']!==null?number_format($row['roi'],2):'—' }}</span></td><td class="text-center"><button type="button" class="btn btn-info btn-xs detail-toggle" data-toggle="collapse" data-target="#fashion-operation-detail-{{ $loop->index }}"><i class="fa fa-eye"></i> 查看明细</button></td><td class="text-center"><span class="submission-status {{ $row['submission_status'] }}">{{ $row['submission_label'] }}</span></td></tr>
<tr class="collapse report-detail-row" id="fashion-operation-detail-{{ $loop->index }}"><td colspan="11"><div class="report-detail-card"><div class="report-detail-meta"><b>{{ $row['operator'] }}</b>　入职日期：{{ $row['hired_at']?:'—' }}　绑定店铺：{{ count($row['stores']) }} 家</div><div class="report-detail-stores">@foreach($row['stores'] as $store)<div class="report-detail-store"><b>{{ $store['name'] }}</b><span class="label label-info">{{ $store['site_builder'] }}</span><small>{{ $store['domain']?:'—' }}</small><strong>{{ $store['orders'] }} 单 · ${{ number_format($store['revenue'],2) }}</strong></div>@endforeach</div></div></td></tr>
@empty<tr><td colspan="11" class="empty"><i class="fa fa-inbox fa-2x"></i><br>当前筛选范围暂无服装运营数据</td></tr>@endforelse
</tbody><tfoot><tr><td class="total-label" colspan="3">合计</td><td class="text-right">{{ $summary['orders'] }}</td><td class="text-right">${{ number_format($summary['revenue'],2) }}</td><td class="text-right">{{ $summary['aov']!==null?'$'.number_format($summary['aov'],2):'—' }}</td><td></td><td class="text-right">${{ number_format($summary['ad_spend'],2) }}</td><td class="text-center roi"><span class="roi-tier {{ $summary['roi_class'] }}">{{ $summary['roi']!==null?number_format($summary['roi'],2):'—' }}</span></td><td></td><td></td></tr></tfoot></table></div></div>
@endif
</section>
@endsection

@push('scripts')
<script src="{{ asset('vendor/laravel-admin/AdminLTE/plugins/select2/select2.full.min.js') }}"></script>
<script>
$(function(){
    $('.js-department-multi').select2({
        width:'100%',
        closeOnSelect:false,
        allowClear:true,
        placeholder:'选择一个或多个部门',
        language:{noResults:function(){return '没有找到部门';}}
    });
    var mode=$('#fashion-display-mode');
    if(mode.length){
        var standard=$('#fashion-date-range').closest('.form-group').add($('input[name="date_from"]').closest('.form-group')).add($('input[name="date_to"]').closest('.form-group'));
        var personal=$('.personal-mode-setting');
        function refreshModeFields(){var active=mode.val()==='personal';standard.toggle(!active);personal.toggle(active);}
        mode.on('change',refreshModeFields);refreshModeFields();
        $('[data-dashboard-mode]').on('click',function(){
            var nextMode=$(this).data('dashboard-mode');
            if(!nextMode||mode.val()===nextMode)return;
            mode.val(nextMode);
            $(this).closest('section.content').find('form.report-filter').trigger('submit');
        });
    }
});
</script>
@php
    $imageReportTitle = $reportProject['code'] === 'danbao-general' ? '单包百货运营报表' : '单包服装运营报表';
    $imageSourceTitle = $fashionReportIndexRoute === 'operation-dashboard.fashion-operation-reports.index' ? '业绩看板' : '助理系统';
    $imageTitle = $imageSourceTitle.' · '.$imageReportTitle.'（列表）';
    $imageFilename = $imageReportTitle.'_列表_'.$filters['date_from'].'_'.$filters['date_to'].'.png';
    $imageTeamMode = ($filters['display_mode'] ?? 'performance') === 'team';
    $imageRows = $rows->map(function (array $row, int $index) use ($imageTeamMode, $teamBands): array {
        $row['_team_band'] = $imageTeamMode ? (int) ($teamBands[$index] ?? 0) : null;
        return $row;
    })->reject(fn (array $row): bool => abs((float) $row['revenue']) < 0.000001
        && abs((float) $row['ad_spend']) < 0.000001)->values();
    $imageRevenue = (float) $imageRows->sum('revenue');
    $imageAdSpend = (float) $imageRows->sum('ad_spend');
    $imageOrders = (int) $imageRows->sum('order_count');
    $imageAov = $imageOrders > 0 ? $imageRevenue / $imageOrders : null;
    $imageRoiRevenue = (float) $imageRows
        ->filter(fn (array $row): bool => (float) $row['ad_spend'] > 0)
        ->sum('revenue');
    $imageRoi = $imageAdSpend > 0 ? $imageRoiRevenue / $imageAdSpend : null;
    $imageFilterSummary = '日期：'.$filters['date_from'].' 至 '.$filters['date_to']
        .'　时区：'.$timezoneOptions[$filters['timezone']]
        .'　运营部门：'.($departmentOptions->whereIn('id',$filters['department_ids']??[])->pluck('name')->implode('、') ?: '全部')
        .'　运营人员：'.(($filters['operator'] ?? '') !== '' ? $filters['operator'] : '全部');
    $imageTotalSummary = '运营人数：'.$imageRows->count()
        .'　订单量：'.$imageOrders
        .'　总业绩：$'.number_format($imageRevenue, 2)
        .'　客单价：'.($imageAov !== null ? '$'.number_format($imageAov, 2) : '—')
        .'　广告费：$'.number_format($imageAdSpend, 2)
        .'　总 ROI：'.($imageRoi !== null ? number_format($imageRoi, 2) : '—');
    $imageTableTotal = [
        '合计',
        '',
        (string) $imageOrders,
        '$'.number_format($imageRevenue, 2),
        $imageAov !== null ? '$'.number_format($imageAov, 2) : '—',
        '',
        '$'.number_format($imageAdSpend, 2),
        $imageRoi !== null ? number_format($imageRoi, 2) : '—',
    ];
@endphp
<script>
(function(){
    var button=document.getElementById('fashionReportImageDownload');
    if(!button)return;
    var rows=@json($imageRows);
    var teamMode=@json($imageTeamMode);
    var filterSummary={{ Illuminate\Support\Js::from($imageFilterSummary) }};
    var totalSummary={{ Illuminate\Support\Js::from($imageTotalSummary) }};
    var imageTitle={{ Illuminate\Support\Js::from($imageTitle) }};
    var imageFilename={{ Illuminate\Support\Js::from($imageFilename) }};
    var tableTotal={{ Illuminate\Support\Js::from($imageTableTotal) }};
    var headers=['运营部门','人名','订单量','总业绩($)','客单价($)','各媒体 / 广告费','广告费($)','总 ROI'];
    var widths=[180,145,110,150,130,420,140,110],padding=34,lineHeight=24;
    function money(value){return '$'+Number(value||0).toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2});}
    function valueRows(row){
        var media=(row.media||[]).map(function(item){return (item.name||'未设置媒体')+'  '+money(item.spend);});
        return [row.department||'—',(row.operator||'—')+(row.is_resigned?'（离职）':''),String(row.order_count||0),money(row.revenue),row.aov===null||row.aov===undefined?'—':money(row.aov),media.length?media.join('\n'):'暂无已审核的广告费',money(row.ad_spend),row.roi===null||row.roi===undefined?'—':Number(row.roi).toFixed(2)];
    }
    function roiColors(value){var roi=Number(value);if(!Number.isFinite(roi))return null;if(roi>1.8)return {bg:'#d8eee2',fg:'#14532d'};if(roi>=1.6)return {bg:'#fff0b8',fg:'#8a6500'};if(roi>=1.4)return {bg:'#ffe0ad',fg:'#b45309'};if(roi>=1.2)return {bg:'#ffd2bd',fg:'#c2410c'};return {bg:'#f8c9c9',fg:'#8b1a1a'};}
    function wrap(ctx,text,maxWidth){
        var output=[];String(text==null?'':text).split('\n').forEach(function(paragraph){if(paragraph===''){output.push('');return;}var line='';Array.from(paragraph).forEach(function(char){var next=line+char;if(line&&ctx.measureText(next).width>maxWidth){output.push(line);line=char;}else line=next;});if(line)output.push(line);});return output.length?output:[''];
    }
    button.addEventListener('click',function(){
        if(button.disabled)return;
        if(!rows.length){window.alert('当前筛选范围暂无可下载的数据。');return;}
        button.disabled=true;var label=button.querySelector('span'),original=label.textContent;label.textContent='正在生成高清图片…';
        window.setTimeout(function(){
            try{
                var measure=document.createElement('canvas').getContext('2d');measure.font='500 16px "Microsoft YaHei","PingFang SC",Arial,sans-serif';
                var prepared=rows.map(function(row,rowIndex){var cells=valueRows(row).map(function(text,index){return wrap(measure,text,widths[index]-18);});return {cells:cells,height:Math.max(52,Math.max.apply(null,cells.map(function(lines){return lines.length;}))*lineHeight+18),total:false,roi:row.roi,band:teamMode?Number(row._team_band||0):(rowIndex%2)};});
                var totalCells=tableTotal.map(function(text,index){return wrap(measure,text,widths[index]-18);});prepared.push({cells:totalCells,height:Math.max(58,Math.max.apply(null,totalCells.map(function(lines){return lines.length;}))*lineHeight+18),total:true,roi:@json($imageRoi)});
                var tableWidth=widths.reduce(function(total,width){return total+width;},0),headerTop=150,tableHeaderHeight=48,totalHeight=headerTop+tableHeaderHeight+prepared.reduce(function(total,row){return total+row.height;},0)+padding;
                var ratio=Math.min(2,30000/Math.max(tableWidth+padding*2,totalHeight)),canvas=document.createElement('canvas');canvas.width=Math.ceil((tableWidth+padding*2)*ratio);canvas.height=Math.ceil(totalHeight*ratio);
                var ctx=canvas.getContext('2d');ctx.scale(ratio,ratio);ctx.fillStyle='#f4f8fb';ctx.fillRect(0,0,tableWidth+padding*2,totalHeight);
                ctx.textBaseline='top';ctx.fillStyle='#173d5f';ctx.font='700 30px "Microsoft YaHei","PingFang SC",Arial,sans-serif';ctx.fillText(imageTitle,padding,22);
                ctx.fillStyle='#526f84';ctx.font='500 17px "Microsoft YaHei","PingFang SC",Arial,sans-serif';ctx.fillText(filterSummary,padding,67);
                ctx.fillText(totalSummary,padding,98);
                ctx.fillStyle='#acd4e9';ctx.fillRect(padding,130,tableWidth,4);
                var x=padding;ctx.font='700 16px "Microsoft YaHei","PingFang SC",Arial,sans-serif';headers.forEach(function(header,index){ctx.fillStyle='#dcecf6';ctx.fillRect(x,headerTop,widths[index],tableHeaderHeight);ctx.strokeStyle='#b7ccd9';ctx.strokeRect(x+.5,headerTop+.5,widths[index]-1,tableHeaderHeight-1);ctx.fillStyle='#244f6d';ctx.textAlign='center';ctx.textBaseline='middle';ctx.fillText(header,x+widths[index]/2,headerTop+tableHeaderHeight/2);x+=widths[index];});
                var y=headerTop+tableHeaderHeight;prepared.forEach(function(row){x=padding;row.cells.forEach(function(lines,index){ctx.fillStyle=row.total?'#d9e9f3':(row.band===0?'#f1f7fb':'#fff6eb');ctx.fillRect(x,y,widths[index],row.height);ctx.strokeStyle=row.total?'#9fbccc':'#c4d5df';ctx.strokeRect(x+.5,y+.5,widths[index]-1,row.height-1);var tier=index===7?roiColors(row.roi):null;if(tier){ctx.fillStyle=tier.bg;ctx.fillRect(x+18,y+(row.height-30)/2,widths[index]-36,30);}ctx.fillStyle=tier?tier.fg:'#294b61';ctx.font=(row.total||index===1||index===2||index===3||index===7?'700 ':'500 ')+'16px "Microsoft YaHei","PingFang SC",Arial,sans-serif';ctx.textAlign=[0,1,2,3,4,6,7].indexOf(index)>=0?'center':'left';ctx.textBaseline='top';var start=y+(row.height-lines.length*lineHeight)/2,tx=ctx.textAlign==='center'?x+widths[index]/2:x+9;lines.forEach(function(line,lineIndex){ctx.fillText(line,tx,start+lineIndex*lineHeight);});x+=widths[index];});y+=row.height;});
                var link=document.createElement('a');link.download=imageFilename;link.href=canvas.toDataURL('image/png');document.body.appendChild(link);link.click();link.remove();
            }catch(error){window.alert('图片生成失败，请刷新页面后重试。');if(window.console)console.error(error);}finally{button.disabled=false;label.textContent=original;}
        },30);
    });
})();
</script>
@if($isPersonalMode)
<script>
(function($){
    var rows=@json($personalSummaryRows),modal=$('#personalReportDetailModal'),title=$('#personalReportDetailTitle'),body=$('#personalReportDetailBody'),lossModal=$('#continuousLossModal'),lossBody=$('#continuousLossBody');
    function escapeHtml(value){return $('<div>').text(value==null?'':String(value)).html();}
    function money(value){return '$'+Number(value||0).toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2});}
    function openPersonalDetail(row){
        title.text((row.operator||'')+' · {{ $filters['date_from'] }} 至 {{ $filters['date_to'] }} 每日数据');
        var html='<table class="table table-bordered table-hover"><thead><tr><th>日期</th><th>订单量</th><th>当天营业额($)</th><th>当天广告费($)</th><th>ROI</th><th>计算结果($)</th><th>状态</th></tr></thead><tbody>';
        (row.days||[]).forEach(function(day){var status=day.profit_status==='profit'?'盈利':(day.profit_status==='loss'?'亏损':'持平');html+='<tr><td>'+escapeHtml(day.date)+'</td><td class="text-right">'+Number(day.order_count||0)+'</td><td class="text-right">'+money(day.revenue)+'</td><td class="text-right">'+money(day.ad_spend)+'</td><td class="text-center">'+(day.roi==null?'—':Number(day.roi).toFixed(2))+'</td><td class="text-right '+escapeHtml(day.profit_status)+'">'+money(day.profit)+'</td><td class="text-center">'+status+'</td></tr>';});
        body.html(html+'</tbody></table>');modal.modal('show');
    }
    $(document).on('click','.personal-detail-trigger',function(){
        var row=rows[Number(this.getAttribute('data-personal-index'))];if(!row)return;
        if(this.getAttribute('data-from-loss')==='1'){
            lossModal.one('hidden.bs.modal',function(){openPersonalDetail(row);});
            lossModal.modal('hide');
            return;
        }
        openPersonalDetail(row);
    });
    $('#continuousLossPeople').on('click',function(){
        var losses=rows.filter(function(row){return !!row.continuous_loss;});
        if(!losses.length){lossBody.html('<p class="text-muted" style="margin:0">当前筛选范围没有连续亏损的人员。</p>');lossModal.modal('show');return;}
        var html='<table class="table table-bordered table-hover"><thead><tr><th>运营部门</th><th>人员</th><th>筛选期间营业额($)</th><th>筛选期间广告费($)</th><th>计算结果($)</th></tr></thead><tbody>';
        losses.forEach(function(row){var index=rows.indexOf(row);html+='<tr><td>'+escapeHtml(row.department)+'</td><td><button type="button" class="btn btn-link btn-xs text-red personal-detail-trigger" data-personal-index="'+index+'" data-from-loss="1"><i class="fa fa-eye"></i> <b>'+escapeHtml(row.operator)+'</b></button></td><td class="text-right">'+money(row.revenue)+'</td><td class="text-right">'+money(row.ad_spend)+'</td><td class="text-right text-red">'+money(row.profit)+'</td></tr>';});
        lossBody.html(html+'</tbody></table>');lossModal.modal('show');
    });
})(jQuery);
</script>
@endif
@endpush
