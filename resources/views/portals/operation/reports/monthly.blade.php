@extends('portal.layout')

@section('page_title', '运营报表')

@push('styles')
<style>
.op-toolbar{display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap}.op-toolbar .form-group{margin:0}.op-toolbar label{display:block;font-size:12px;color:#607789;margin-bottom:6px}.op-toolbar .form-control,.op-toolbar .btn{height:35px;border-radius:5px}.op-help{color:#7b8e9e;font-size:12px;margin-top:5px}
.monthly-range{color:#78909c;margin-left:8px}.monthly-warning{margin-bottom:12px}.monthly-table-wrap{overflow:auto;max-height:620px}.monthly-table{min-width:2050px;margin-bottom:0}.monthly-table thead th{position:sticky;top:0;z-index:2;background:#edf4f8;color:#173b5e;text-align:center;vertical-align:middle!important;white-space:nowrap}.monthly-table td{vertical-align:middle!important}.monthly-table .identity{background:#fbfdff}.monthly-table .money,.monthly-table .number{text-align:right}.monthly-table tfoot td{position:sticky;bottom:0;background:#ffe1cc;font-weight:700;z-index:1}.monthly-kpis{display:grid;grid-template-columns:repeat(5,minmax(150px,1fr));gap:12px;margin-bottom:16px}.monthly-kpi{background:#fff;border-left:4px solid #3498db;border-radius:8px;padding:14px 16px;box-shadow:0 1px 5px rgba(36,59,83,.08)}.monthly-kpi span{display:block;color:#74879a}.monthly-kpi strong{display:block;margin-top:5px;font-size:23px;color:#173b5e}@media(max-width:1000px){.monthly-kpis{grid-template-columns:repeat(2,1fr)}}
</style>
@endpush

@section('content')
<section class="content-header"><h1>运营报表 <small>默认展示北京时间昨天的业绩</small></h1></section>
<section class="content">
    <div class="box"><div class="box-body">
        <form class="op-toolbar" method="get" action="{{ route('operation.reports.index') }}">
            <div class="form-group"><label>日期</label><input class="form-control input-sm" type="date" name="date" value="{{ $dateFrom }}"></div>
            @if($isBoss)<div class="form-group"><label>运营身份</label><select class="form-control input-sm" name="operator_user_id">@foreach($operatorOptions as $option)<option value="{{ $option->id }}" @selected($selectedOperator->id === $option->id)>{{ $option->name ?: $option->username }}</option>@endforeach</select></div>@endif
            <button class="btn btn-primary btn-sm"><i class="fa fa-search"></i> 查询业绩</button>
            <span class="monthly-range">{{ $dateFrom }}</span>
        </form>
    </div></div>

    @foreach($warnings as $warning)<div class="alert alert-warning monthly-warning"><i class="fa fa-warning"></i> {{ $warning }}</div>@endforeach

    <div class="monthly-kpis">
        <div class="monthly-kpi"><span>首单合计</span><strong>{{ (int)$metricTotals['first_total_orders'] }} 单</strong></div>
        <div class="monthly-kpi"><span>首单营业额</span><strong>${{ number_format($metricTotals['first_total_revenue'],2) }}</strong></div>
        <div class="monthly-kpi"><span>采购费</span><strong>${{ number_format($metricTotals['first_purchase_cost'],2) }}</strong></div>
        <div class="monthly-kpi"><span>运费</span><strong>${{ number_format($metricTotals['first_shipping_cost'],2) }}</strong></div>
        <div class="monthly-kpi"><span>总营业额</span><strong>${{ number_format($metricTotals['total_revenue'],2) }}</strong></div>
    </div>

    <div class="box">
        <div class="box-header with-border"><h3 class="box-title">月度营业额数据</h3><p class="op-help">每一行按日期、店铺域名和媒体汇总；采购费、重量及运费计算方式与广告费提报保持一致。</p></div>
        <div class="box-body no-padding monthly-table-wrap"><table class="table table-bordered table-hover monthly-table">
            <thead><tr><th>日期</th><th>人名</th><th>店铺域名</th><th>媒体</th><th>首单订阅单量</th><th>首单订阅营业额($)</th><th>首单普通单量</th><th>首单普通营业额($)</th><th>首单合计单量</th><th>首单合计营业额($)</th><th>首单总采购费($)</th><th>首单总运费($)</th><th>首单交易退货费($)</th><th>续费单量</th><th>续费营业额($)</th><th>续费费用($)</th><th>总单量</th><th>总营业额($)</th></tr></thead>
            <tbody>@forelse($metricRows as $row)<tr>
                @if(($metricRowspans[$loop->index] ?? 0) > 0)<td class="identity" rowspan="{{ $metricRowspans[$loop->index] }}">{{ $row['date'] }}</td><td class="identity" rowspan="{{ $metricRowspans[$loop->index] }}">{{ $row['operator_name'] }}</td><td class="identity" rowspan="{{ $metricRowspans[$loop->index] }}">{{ $row['shop_domain'] }}</td>@endif
                <td>{{ $row['media_source'] }}</td><td class="number">{{ (int)$row['first_subscription_orders'] }}</td><td class="money">{{ number_format($row['first_subscription_revenue'],2) }}</td><td class="number">{{ (int)$row['first_normal_orders'] }}</td><td class="money">{{ number_format($row['first_normal_revenue'],2) }}</td><td class="number">{{ (int)$row['first_total_orders'] }}</td><td class="money">{{ number_format($row['first_total_revenue'],2) }}</td><td class="money">{{ number_format($row['first_purchase_cost'],2) }}</td><td class="money">{{ number_format($row['first_shipping_cost'],2) }}</td><td class="money">{{ number_format($row['first_return_fee'],2) }}</td><td class="number">{{ (int)$row['renewal_orders'] }}</td><td class="money">{{ number_format($row['renewal_revenue'],2) }}</td><td class="money">{{ number_format($row['renewal_cost'],2) }}</td><td class="number">{{ (int)$row['total_orders'] }}</td><td class="money">{{ number_format($row['total_revenue'],2) }}</td>
            </tr>@empty<tr><td colspan="18" class="text-center text-muted" style="padding:40px">该月份没有可统计的订单</td></tr>@endforelse</tbody>
            <tfoot><tr><td colspan="4">合计</td>@foreach(['first_subscription_orders','first_subscription_revenue','first_normal_orders','first_normal_revenue','first_total_orders','first_total_revenue','first_purchase_cost','first_shipping_cost','first_return_fee','renewal_orders','renewal_revenue','renewal_cost','total_orders','total_revenue'] as $field)<td class="{{ str_contains($field,'orders') ? 'number' : 'money' }}">{{ str_contains($field,'orders') ? (int)$metricTotals[$field] : number_format($metricTotals[$field],2) }}</td>@endforeach</tr></tfoot>
        </table></div>
    </div>

    <div class="box">
        <div class="box-header with-border"><h3 class="box-title">广告费提报记录 <small>共 {{ $submittedReports->count() }} 条</small></h3><p class="op-help">提报记录包含提交时保存的完整业绩、广告费用及 ROI 快照，点击“查看完整数据”查看。</p></div>
        <div class="box-body no-padding table-responsive"><table class="table table-bordered table-hover"><thead><tr><th>日期</th><th>运营</th><th>首单营业额($)</th><th>采购费($)</th><th>运费($)</th><th>实际广告费($)</th><th>首单 ROI</th><th>首单利润($)</th><th>状态</th><th>审核不通过原因</th><th>操作</th></tr></thead><tbody>
        @forelse($submittedReports as $item)@php($firstRevenue = collect($item->metric_rows ?? [])->sum('first_total_revenue'))<tr><td>{{ $item->report_date->format('Y-m-d') }}</td><td>{{ $item->operator_name }}</td><td>{{ number_format($firstRevenue,2) }}</td><td>{{ number_format($item->first_purchase_cost,2) }}</td><td>{{ number_format($item->first_shipping_cost,2) }}</td><td>{{ number_format($item->actual_ad_spend,2) }}</td><td>{{ $item->actual_ad_spend > 0 ? number_format($firstRevenue/$item->actual_ad_spend,2) : '—' }}</td><td>{{ number_format($item->first_profit,2) }}</td><td><span class="label {{ $item->status === 'approved' ? 'label-success' : ($item->status === 'rejected' ? 'label-danger' : 'label-warning') }}">{{ $item->statusLabel() }}</span></td><td>@if($item->status === 'rejected' && $item->review_reason)<span class="text-danger">{{ $item->review_reason }}</span>@else — @endif</td><td><a class="btn btn-info btn-xs" href="{{ route('operation.reports.show',$item) }}"><i class="fa fa-eye"></i> 查看完整数据</a></td></tr>
        @empty<tr><td colspan="11" class="text-center text-muted" style="padding:30px">当前筛选范围没有广告费提报记录</td></tr>@endforelse
        </tbody></table></div>
    </div>
</section>
@endsection
