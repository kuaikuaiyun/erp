@extends('portal.layout')

@section('page_title', '运营报表')

@push('styles')
<style>
.assistant-report-filter,.assistant-report-box{border:0;border-radius:11px;box-shadow:0 4px 16px rgba(27,62,91,.09);overflow:hidden}.assistant-report-filter .box-body{padding:16px 18px}.report-toolbar{display:flex;align-items:flex-end;gap:12px;flex-wrap:wrap}.report-toolbar .form-group{margin:0}.report-toolbar label{display:block;color:#607789;font-size:12px;margin-bottom:6px}.report-toolbar .form-control{height:38px;border:1px solid #d6e2eb;border-radius:6px;min-width:150px}.period-switch{display:flex;border:1px solid #cbdbe7;border-radius:7px;overflow:hidden;height:38px}.period-switch label{margin:0!important;padding:9px 16px;color:#537087;cursor:pointer;background:#fff}.period-switch label.active{background:#256d9b;color:#fff}.period-switch input{display:none}.report-range{height:38px;display:flex;align-items:center;padding:0 13px;border-radius:6px;background:#edf5fa;color:#517188}.report-kpis{display:grid;grid-template-columns:repeat(5,minmax(150px,1fr));gap:12px;margin-bottom:16px}.report-kpi{background:linear-gradient(135deg,#fff,#f5f9fc);border-left:4px solid #3288bd;border-radius:10px;padding:14px 17px;box-shadow:0 3px 13px rgba(30,65,95,.08)}.report-kpi span{display:block;color:#718597;font-size:12px}.report-kpi strong{display:block;color:#173d5f;font-size:22px;margin-top:5px}.assistant-report-box .box-header{padding:15px 18px}.assistant-report-box .box-title{color:#173d5f;font-weight:600}.report-note{color:#6f8496;font-size:12px;margin-top:7px}.report-table-wrap{overflow:auto;max-height:680px}.report-table{min-width:2920px;margin:0}.report-table th{position:sticky;top:0;z-index:2;background:#d7e8f4!important;color:#214d6c;text-align:center;vertical-align:middle!important;white-space:nowrap;padding:11px 8px!important}.report-table td{vertical-align:middle!important;padding:9px 8px!important;border-color:#e0e9ef!important}.report-table tbody tr:nth-child(4n+1)>td,.report-table tbody tr:nth-child(4n+2)>td{background:#f3f8fc}.report-table tbody tr:nth-child(4n+3)>td,.report-table tbody tr:nth-child(4n+4)>td{background:#fff7ed}.report-table tbody tr:hover>td{filter:brightness(.98)}.report-table .identity{font-weight:600;color:#315d7b}.report-table .number,.report-table .money{text-align:right}.report-table .roi{font-weight:700;text-align:center;color:#25688f}.report-table .profit-positive{color:#15805b;font-weight:700}.report-table .profit-negative{color:#d34d46;font-weight:700}.report-table .ad-cell{background:#e3f0f8!important;color:#245f85;font-weight:700;text-align:center}.report-table tfoot td{position:sticky;bottom:0;z-index:1;background:#dce9f2!important;font-weight:700}.empty-report{padding:55px!important;color:#8293a1;text-align:center}.department-badge{display:inline-block;padding:4px 8px;border-radius:12px;background:#e7f1f8;color:#28658d;white-space:nowrap}@media(max-width:1000px){.report-kpis{grid-template-columns:repeat(2,1fr)}}
.submission-badge{display:inline-block;padding:4px 9px;border-radius:12px;white-space:nowrap;font-size:12px}.submission-badge.approved{background:#dff4e9;color:#15774f}.submission-badge.pending{background:#fff0c7;color:#986800}.submission-badge.rejected{background:#fde4e1;color:#b43f36}.submission-badge.missing{background:#e9eef2;color:#687b8b}
</style>
@endpush

@section('content')
<section class="content-header"><h1>运营报表 <small>查看已绑定运营部门的完整报表数据</small></h1></section>
<section class="content">
    <div class="box assistant-report-filter"><div class="box-body">
        <form method="get" action="{{ route('assistant.operation-reports.index') }}" class="report-toolbar" id="assistant-report-filter">
            <div class="form-group"><label>查询方式</label><div class="period-switch">
                <label class="{{ $periodType === 'day' ? 'active' : '' }}"><input type="radio" name="period_type" value="day" @checked($periodType === 'day')>按天</label>
                <label class="{{ $periodType === 'month' ? 'active' : '' }}"><input type="radio" name="period_type" value="month" @checked($periodType === 'month')>按月</label>
            </div></div>
            <div class="form-group period-day" @if($periodType !== 'day') style="display:none" @endif><label>报表日期</label><input class="form-control" type="date" name="report_date" value="{{ $reportDate }}"></div>
            <div class="form-group period-month" @if($periodType !== 'month') style="display:none" @endif><label>报表月份</label><input class="form-control" type="month" name="report_month" value="{{ $reportMonth }}"></div>
            <div class="form-group"><label>运营部门</label><select class="form-control" name="operation_department_id"><option value="">全部绑定部门</option>@foreach($departments as $department)<option value="{{ $department->id }}" @selected($selectedDepartmentId === (int)$department->id)>{{ $department->name }}</option>@endforeach</select></div>
            <button class="btn btn-primary" style="height:38px"><i class="fa fa-search"></i> 查询报表</button>
            <span class="report-range">{{ $dateFrom === $dateTo ? $dateFrom : $dateFrom.' 至 '.$dateTo }}</span>
        </form>
    </div></div>

    <div class="report-kpis">
        <div class="report-kpi"><span>首单合计</span><strong>{{ (int)$totals['first_total_orders'] }} 单</strong></div>
        <div class="report-kpi"><span>首单营业额</span><strong>${{ number_format($totals['first_total_revenue'],2) }}</strong></div>
        <div class="report-kpi"><span>首单总成本</span><strong>${{ number_format($totals['first_total_cost'],2) }}</strong></div>
        <div class="report-kpi"><span>实际广告费</span><strong>${{ number_format($totals['actual_ad_spend'],2) }}</strong></div>
        <div class="report-kpi"><span>首单盈利</span><strong class="{{ $totals['first_profit'] >= 0 ? 'profit-positive' : 'profit-negative' }}">${{ number_format($totals['first_profit'],2) }}</strong></div>
    </div>

    <div class="box assistant-report-box">
        <div class="box-header with-border"><h3 class="box-title">营业额数据</h3><div class="report-note">订单业绩不受广告费是否提报影响，绑定运营部门的订单都会显示；广告费和 ROI 仅在审核通过后计入。</div></div>
        <div class="box-body no-padding report-table-wrap"><table class="table table-bordered table-hover report-table">
            <thead><tr><th>日期</th><th>运营部门</th><th>人名</th><th>店铺域名</th><th>媒体</th><th>媒体 ROI</th><th>首单订阅单量</th><th>首单订阅营业额($)</th><th>首单普通单量</th><th>首单普通营业额($)</th><th>首单合计单量</th><th>首单合计营业额($)</th><th>首单总采购费($)</th><th>首单总运费($)</th><th>首单交易退货费($)</th><th>首单总成本($)</th><th>首单盈利($)</th><th>首单亏损率</th><th>续费单量</th><th>续费营业额($)</th><th>续费费用($)</th><th>总单量</th><th>总营业额($)</th><th>广告费提报</th><th>实际广告费($)</th><th>首单 ROI</th></tr></thead>
            <tbody>@forelse($rows as $row)<tr>
                @if(($reportRowspans[$loop->index] ?? 0) > 0)<td class="identity" rowspan="{{ $reportRowspans[$loop->index] }}">{{ $row['date'] }}</td><td class="identity" rowspan="{{ $reportRowspans[$loop->index] }}"><span class="department-badge">{{ $row['operation_department_name'] }}</span></td><td class="identity" rowspan="{{ $reportRowspans[$loop->index] }}">{{ $row['operator_name'] }}</td>@endif
                @if(($identityRowspans[$loop->index] ?? 0) > 0)<td class="identity" rowspan="{{ $identityRowspans[$loop->index] }}">{{ $row['shop_domain'] }}</td>@endif
                <td>{{ $row['media_source'] }}</td><td class="roi">{{ $row['media_roi'] !== null ? number_format($row['media_roi'],2) : '—' }}</td>
                <td class="number">{{ (int)$row['first_subscription_orders'] }}</td><td class="money">{{ number_format($row['first_subscription_revenue'],2) }}</td>
                <td class="number">{{ (int)$row['first_normal_orders'] }}</td><td class="money">{{ number_format($row['first_normal_revenue'],2) }}</td>
                <td class="number">{{ (int)$row['first_total_orders'] }}</td><td class="money">{{ number_format($row['first_total_revenue'],2) }}</td>
                <td class="money">{{ number_format($row['first_purchase_cost'],2) }}</td><td class="money">{{ number_format($row['first_shipping_cost'],2) }}</td><td class="money">{{ number_format($row['first_return_fee'],2) }}</td>
                <td class="money">{{ number_format($row['first_total_cost'],2) }}</td><td class="money {{ $row['first_profit'] >= 0 ? 'profit-positive' : 'profit-negative' }}">{{ number_format($row['first_profit'],2) }}</td><td class="number {{ ($row['first_loss_rate'] ?? 0) >= 0 ? 'profit-positive' : 'profit-negative' }}">{{ $row['first_loss_rate'] !== null ? number_format($row['first_loss_rate']*100,2).'%' : '—' }}</td>
                <td class="number">{{ (int)$row['renewal_orders'] }}</td><td class="money">{{ number_format($row['renewal_revenue'],2) }}</td><td class="money">{{ number_format($row['renewal_cost'],2) }}</td><td class="number">{{ (int)$row['total_orders'] }}</td><td class="money">{{ number_format($row['total_revenue'],2) }}</td>
                @if(($reportRowspans[$loop->index] ?? 0) > 0)<td class="ad-cell" rowspan="{{ $reportRowspans[$loop->index] }}"><span class="submission-badge {{ $row['submission_status'] ?: 'missing' }}">{{ $row['submission_status_label'] }}</span></td><td class="ad-cell" rowspan="{{ $reportRowspans[$loop->index] }}">{{ $row['report_actual_ad_spend'] !== null ? number_format($row['report_actual_ad_spend'],2) : '—' }}</td><td class="ad-cell" rowspan="{{ $reportRowspans[$loop->index] }}">{{ $row['report_first_roi'] !== null ? number_format($row['report_first_roi'],2) : '—' }}</td>@endif
            </tr>@empty<tr><td colspan="26" class="empty-report"><i class="fa fa-inbox fa-2x"></i><br><br>当前查询范围没有可统计的订单数据</td></tr>@endforelse</tbody>
            <tfoot><tr><td colspan="5">合计</td><td class="roi">—</td>@foreach(['first_subscription_orders','first_subscription_revenue','first_normal_orders','first_normal_revenue','first_total_orders','first_total_revenue','first_purchase_cost','first_shipping_cost','first_return_fee'] as $field)<td class="{{ str_contains($field,'orders') ? 'number' : 'money' }}">{{ str_contains($field,'orders') ? (int)$totals[$field] : number_format($totals[$field],2) }}</td>@endforeach<td class="money">{{ number_format($totals['first_total_cost'],2) }}</td><td class="money {{ $totals['first_profit'] >= 0 ? 'profit-positive' : 'profit-negative' }}">{{ number_format($totals['first_profit'],2) }}</td><td class="number">{{ $totals['first_loss_rate'] !== null ? number_format($totals['first_loss_rate']*100,2).'%' : '—' }}</td>@foreach(['renewal_orders','renewal_revenue','renewal_cost','total_orders','total_revenue'] as $field)<td class="{{ str_contains($field,'orders') ? 'number' : 'money' }}">{{ str_contains($field,'orders') ? (int)$totals[$field] : number_format($totals[$field],2) }}</td>@endforeach<td class="ad-cell">—</td><td class="ad-cell">{{ number_format($totals['actual_ad_spend'],2) }}</td><td class="ad-cell">{{ $totals['first_roi'] !== null ? number_format($totals['first_roi'],2) : '—' }}</td></tr></tfoot>
        </table></div>
    </div>
</section>
@endsection

@push('scripts')
<script>
(function(){
    var form=document.getElementById('assistant-report-filter');
    if(!form)return;
    form.querySelectorAll('input[name="period_type"]').forEach(function(input){input.addEventListener('change',function(){
        form.querySelectorAll('.period-switch label').forEach(function(label){label.classList.remove('active');});
        input.parentElement.classList.add('active');
        form.querySelector('.period-day').style.display=input.value==='day'?'block':'none';
        form.querySelector('.period-month').style.display=input.value==='month'?'block':'none';
    });});
})();
</script>
@endpush
