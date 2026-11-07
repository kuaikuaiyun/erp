@extends('portal.layout')

@section('page_title', '运营周报')

@push('styles')
<style>
.weekly-filter,.weekly-data{border:0;border-radius:12px;box-shadow:0 5px 18px rgba(31,67,98,.09);overflow:hidden}.weekly-filter .box-body{padding:16px 18px}.weekly-toolbar{display:flex;align-items:flex-end;gap:12px;flex-wrap:wrap}.weekly-toolbar .form-group{margin:0}.weekly-toolbar label{display:block;margin-bottom:6px;color:#61788b;font-size:12px}.weekly-toolbar .form-control,.weekly-toolbar .btn{height:38px;border-radius:6px}.weekly-toolbar .form-control{min-width:150px;border-color:#d7e3ec}.weekly-range{padding:9px 14px;border-radius:7px;background:#edf5fb;color:#315f7e;font-weight:600}.weekly-kpis{display:grid;grid-template-columns:repeat(6,minmax(145px,1fr));gap:12px;margin:15px 0}.weekly-kpi{padding:15px 17px;border-left:4px solid #3498db;border-radius:10px;background:linear-gradient(135deg,#fff,#f6fafe);box-shadow:0 4px 14px rgba(31,67,98,.08)}.weekly-kpi span{display:block;color:#74889a;font-size:12px}.weekly-kpi strong{display:block;margin-top:5px;color:#163d60;font-size:22px}.weekly-table-wrap{overflow:auto}.weekly-table{min-width:2850px;margin:0}.weekly-table.details-collapsed{min-width:1600px}.weekly-table.details-collapsed tr>th:nth-child(n+6):nth-child(-n+19),.weekly-table.details-collapsed tbody tr>td:nth-child(n+6):nth-child(-n+19),.weekly-table.details-collapsed tfoot tr>td:nth-child(n+6):nth-child(-n+19){display:none}.weekly-table th{background:#d9eaf5;color:#224e6e;text-align:center;white-space:nowrap;padding:11px 8px!important}.weekly-table td{vertical-align:middle!important;padding:10px 8px!important;border-color:#e1e9ef!important}.weekly-table tbody tr:nth-child(odd)>td{background:#f5f9fc}.weekly-table tbody tr:nth-child(even)>td{background:#fff8ef}.weekly-table .identity{font-weight:600;color:#285b7e}.weekly-table .number,.weekly-table .money{text-align:right}.weekly-table .ad-spend{background:#e2f1fa!important;color:#15618f;font-weight:700}.weekly-table .positive{color:#16815d;font-weight:700}.weekly-table .negative{color:#d9534f;font-weight:700}.weekly-table .domains{min-width:190px;white-space:normal}.weekly-table .media-summary{min-width:260px;white-space:normal;line-height:1.55}.weekly-table .media-summary span{display:inline-block;margin-right:10px}.weekly-table tfoot td{background:#dce9f2!important;font-weight:700}.weekly-data .box-header{padding:15px 18px}.weekly-data .box-title{color:#173e60;font-weight:700}.weekly-detail-toggle{float:right;border-radius:6px}.weekly-detail-toggle i{margin-right:5px}@media(max-width:1100px){.weekly-kpis{grid-template-columns:repeat(3,1fr)}}
</style>
@endpush

@section('content')
<section class="content-header"><h1>运营周报 <small>按 UTC-4 自然周（周一至周日）统计运营业绩</small></h1></section>
<section class="content">
    <div class="box weekly-filter"><div class="box-body">
        <form class="weekly-toolbar" method="get" action="{{ route('operation.weekly-reports.index') }}">
            @if($isBoss)
                <div class="form-group"><label>运营部门</label><select class="form-control input-sm" name="operation_department_id" onchange="this.form.submit()"><option value="">全部运营部门</option>@foreach($operationDepartments as $department)<option value="{{ $department->id }}" @selected($selectedDepartmentId === (int)$department->id)>{{ $department->name }}</option>@endforeach</select></div>
            @elseif($isReportDepartmentLeader && $viewerOperationDepartment)
                <div class="form-group"><label>运营部门</label><select class="form-control input-sm" disabled><option>{{ $viewerOperationDepartment->name }}</option></select></div>
            @endif
            @if($canSelectOperator)
                <div class="form-group"><label>运营人员</label><select class="form-control input-sm" name="operator_user_id"><option value="all" @selected($selectAllOperators)>全部运营人员</option>@foreach($operatorOptions as $option)<option value="{{ $option->id }}" @selected(!$selectAllOperators && $selectedOperator?->id === $option->id)>{{ $option->name ?: $option->username }}</option>@endforeach</select></div>
            @endif
            <div class="form-group"><label>开始日期</label><input type="date" class="form-control input-sm" name="date_from" value="{{ $inputDateFrom }}" required></div>
            <div class="form-group"><label>结束日期</label><input type="date" class="form-control input-sm" name="date_to" value="{{ $inputDateTo }}" required></div>
            <div class="form-group"><label>统计时区</label><select class="form-control input-sm" name="timezone">@foreach($timezoneOptions as $value=>$label)<option value="{{$value}}" @selected($timezone===$value)>{{$label}}</option>@endforeach</select></div>
            <button class="btn btn-primary"><i class="fa fa-search"></i> 查询周报</button>
            <span class="weekly-range">{{ $dateFrom }} 至 {{ $dateTo }}</span>
        </form>
    </div></div>

    <div class="weekly-kpis">
        <div class="weekly-kpi"><span>首单总量</span><strong>{{ $summary['first_orders'] }} 单</strong></div>
        <div class="weekly-kpi"><span>首单营业额</span><strong>${{ number_format($summary['first_revenue'], 2) }}</strong></div>
        <div class="weekly-kpi"><span>首单总成本</span><strong>${{ number_format($summary['first_cost'], 2) }}</strong></div>
        <div class="weekly-kpi"><span>广告费</span><strong>${{ number_format($summary['ad_spend'], 2) }}</strong></div>
        <div class="weekly-kpi"><span>首单盈利</span><strong class="{{ $summary['first_profit'] >= 0 ? 'positive' : 'negative' }}">${{ number_format($summary['first_profit'], 2) }}</strong></div>
        <div class="weekly-kpi"><span>首单 ROI</span><strong>{{ $summary['first_roi'] !== null ? number_format($summary['first_roi'], 2) : '—' }}</strong></div>
    </div>

    <div class="box weekly-data">
        <div class="box-header with-border"><h3 class="box-title">周业绩明细</h3><button type="button" class="btn btn-default btn-sm weekly-detail-toggle" data-table="operation-weekly-report-table"><i class="fa fa-columns"></i><span>显示明细列</span></button></div>
        <div class="box-body no-padding weekly-table-wrap"><table class="table table-bordered table-hover weekly-table details-collapsed" id="operation-weekly-report-table">
            <thead><tr><th>统计周期</th><th>运营部门</th><th>人名</th><th>店铺域名</th><th>广告费($)</th><th>首单订阅单量</th><th>首单订阅营业额($)</th><th>首单普通单量</th><th>首单普通营业额($)</th><th>首单合计单量</th><th>首单合计营业额($)</th><th>首单总采购费($)</th><th>首单总运费($)</th><th>首单交易退货费($)</th><th>首单总成本($)</th><th>首单盈利($)</th><th>首单亏损率</th><th>续费单量</th><th>续费营业额($)</th><th>首单总量</th><th>首单订阅率</th><th>首单总营业额($)</th><th>首单总成本($)</th><th>首单总亏损率</th><th>媒体 / 单量 / ROI</th><th>首单总 ROI</th><th>续费单总单量</th><th>续费单总营业额($)</th></tr></thead>
            <tbody>
            @forelse($weeklyRows as $row)
                <tr><td class="identity">{{ $row['period_from'] }} 至 {{ $row['period_to'] }}</td><td class="identity">{{ $row['department_name'] }}</td><td class="identity">{{ $row['operator_name'] }}</td><td class="domains">{{ $row['shop_domains'] ? implode('、', $row['shop_domains']) : '—' }}</td><td class="money ad-spend">{{ number_format($row['ad_spend'],2) }}</td><td class="number">{{ $row['subscription_orders'] }}</td><td class="money">{{ number_format($row['subscription_revenue'],2) }}</td><td class="number">{{ $row['normal_orders'] }}</td><td class="money">{{ number_format($row['normal_revenue'],2) }}</td><td class="number">{{ $row['first_orders'] }}</td><td class="money">{{ number_format($row['first_revenue'],2) }}</td><td class="money">{{ number_format($row['purchase_cost'],2) }}</td><td class="money">{{ number_format($row['shipping_cost'],2) }}</td><td class="money">{{ number_format($row['return_fee'],2) }}</td><td class="money">{{ number_format($row['first_cost'],2) }}</td><td class="money {{ $row['first_profit'] >= 0 ? 'positive' : 'negative' }}">{{ number_format($row['first_profit'],2) }}</td><td class="number {{ ($row['first_loss_rate'] ?? 0) >= 0 ? 'positive' : 'negative' }}">{{ $row['first_loss_rate'] !== null ? number_format($row['first_loss_rate'] * 100,2).'%' : '—' }}</td><td class="number">{{ $row['renewal_orders'] }}</td><td class="money">{{ number_format($row['renewal_revenue'],2) }}</td><td class="number">{{ $row['first_orders'] }}</td><td class="number">{{ $row['subscription_rate'] !== null ? number_format($row['subscription_rate'] * 100,2).'%' : '—' }}</td><td class="money">{{ number_format($row['first_revenue'],2) }}</td><td class="money">{{ number_format($row['first_cost'],2) }}</td><td class="number {{ ($row['first_loss_rate'] ?? 0) >= 0 ? 'positive' : 'negative' }}">{{ $row['first_loss_rate'] !== null ? number_format($row['first_loss_rate'] * 100,2).'%' : '—' }}</td><td class="media-summary">@forelse($row['media_summary'] as $media)<span>{{ $media['media_source'] }}：{{ $media['first_orders'] }} 单 / ROI {{ $media['first_roi'] !== null ? number_format($media['first_roi'],2) : '—' }}</span>@empty — @endforelse</td><td class="number">{{ $row['first_roi'] !== null ? number_format($row['first_roi'],2) : '—' }}</td><td class="number">{{ $row['renewal_orders'] }}</td><td class="money">{{ number_format($row['renewal_revenue'],2) }}</td></tr>
            @empty
                <tr><td colspan="28" class="text-center text-muted" style="padding:45px">所选时间范围没有可统计的运营数据</td></tr>
            @endforelse
            </tbody>
            <tfoot><tr><td>合计</td><td></td><td></td><td></td><td class="money ad-spend">{{ number_format($summary['ad_spend'],2) }}</td><td class="number">{{ $summary['subscription_orders'] }}</td><td class="money">{{ number_format($summary['subscription_revenue'],2) }}</td><td class="number">{{ $summary['normal_orders'] }}</td><td class="money">{{ number_format($summary['normal_revenue'],2) }}</td><td class="number">{{ $summary['first_orders'] }}</td><td class="money">{{ number_format($summary['first_revenue'],2) }}</td><td class="money">{{ number_format($summary['purchase_cost'],2) }}</td><td class="money">{{ number_format($summary['shipping_cost'],2) }}</td><td class="money">{{ number_format($summary['return_fee'],2) }}</td><td class="money">{{ number_format($summary['first_cost'],2) }}</td><td class="money {{ $summary['first_profit'] >= 0 ? 'positive' : 'negative' }}">{{ number_format($summary['first_profit'],2) }}</td><td class="number {{ ($summary['first_loss_rate'] ?? 0) >= 0 ? 'positive' : 'negative' }}">{{ $summary['first_loss_rate'] !== null ? number_format($summary['first_loss_rate'] * 100,2).'%' : '—' }}</td><td class="number">{{ $summary['renewal_orders'] }}</td><td class="money">{{ number_format($summary['renewal_revenue'],2) }}</td><td class="number">{{ $summary['first_orders'] }}</td><td class="number">{{ $summary['subscription_rate'] !== null ? number_format($summary['subscription_rate'] * 100,2).'%' : '—' }}</td><td class="money">{{ number_format($summary['first_revenue'],2) }}</td><td class="money">{{ number_format($summary['first_cost'],2) }}</td><td class="number">{{ $summary['first_loss_rate'] !== null ? number_format($summary['first_loss_rate'] * 100,2).'%' : '—' }}</td><td>—</td><td class="number">{{ $summary['first_roi'] !== null ? number_format($summary['first_roi'],2) : '—' }}</td><td class="number">{{ $summary['renewal_orders'] }}</td><td class="money">{{ number_format($summary['renewal_revenue'],2) }}</td></tr></tfoot>
        </table></div>
    </div>
</section>
@endsection

@push('scripts')
<script>
(function () {
    document.querySelectorAll('.weekly-detail-toggle').forEach(function (button) {
        button.addEventListener('click', function () {
            var table = document.getElementById(button.getAttribute('data-table'));
            if (!table) return;
            var collapsed = table.classList.toggle('details-collapsed');
            button.querySelector('span').textContent = collapsed ? '显示明细列' : '隐藏明细列';
        });
    });
})();
</script>
@endpush
