@extends('portal.layout')

@section('page_title', '运营报表')

@push('styles')
<style>
.filter-box,.report-data-box{border:0;border-radius:10px;box-shadow:0 4px 16px rgba(31,67,98,.08);overflow:hidden}.filter-box .box-body{padding:16px 18px}.op-toolbar{display:flex;gap:14px;align-items:flex-end;flex-wrap:wrap}.op-toolbar .form-group{margin:0}.op-toolbar label{display:block;font-size:12px;color:#607789;margin-bottom:6px}.op-toolbar .form-control{height:38px;min-width:145px;border:1px solid #d8e3eb;border-radius:6px;background:#fff}.op-toolbar .btn{height:38px;border-radius:6px;padding:0 18px}.op-help{color:#7b8e9e;font-size:12px;margin:5px 0 0}
.monthly-range{display:inline-flex;align-items:center;height:38px;padding:0 13px;color:#527087;background:#eef5fa;border-radius:6px}.monthly-warning{margin-bottom:12px}.monthly-table-wrap{overflow:auto;max-height:650px}.monthly-table{min-width:2350px;margin-bottom:0}.monthly-table thead th{position:sticky;top:0;z-index:2;background:#dceaf5;color:#123d63;text-align:center;vertical-align:middle!important;white-space:nowrap;padding:12px 8px}.monthly-table tbody tr.day-band-0>td:not(.ad-metric){background:#f4f9fd}.monthly-table tbody tr.day-band-1>td:not(.ad-metric){background:#e8f2fa}.monthly-table tbody tr:hover>td:not(.ad-metric){background:#d8eaf7}.monthly-table td{vertical-align:middle!important;padding:10px 8px}.monthly-table .identity{font-weight:500;color:#234f73}.monthly-table .money,.monthly-table .number{text-align:right}.monthly-table .media-roi{color:#123f66;font-weight:700;text-align:center}.monthly-table thead .media-roi{background:#bad7ec;color:#0d3558}.monthly-table .ad-metric{background:#dcebf6!important;color:#123f66;font-weight:700;text-align:center}.monthly-table thead .ad-metric{background:#a9cce5!important;color:#0d3558}.monthly-table tfoot td{position:sticky;bottom:0;background:#c9deee;font-weight:700;z-index:1}.monthly-table tfoot .ad-metric{background:#a9cce5!important}.monthly-kpis{display:grid;grid-template-columns:repeat(5,minmax(150px,1fr));gap:12px;margin-bottom:16px}.monthly-kpi{background:linear-gradient(135deg,#fff 0%,#f8fbfd 100%);border-left:4px solid #3498db;border-radius:10px;padding:16px 18px;box-shadow:0 4px 14px rgba(36,59,83,.08)}.monthly-kpi span{display:block;color:#74879a}.monthly-kpi strong{display:block;margin-top:6px;font-size:23px;color:#173b5e}.report-data-box .box-header{padding:15px 18px}.report-data-box .box-title{font-weight:600;color:#173b5e}@media(max-width:1000px){.monthly-kpis{grid-template-columns:repeat(2,1fr)}}
</style>
@endpush

@section('content')
<section class="content-header"><h1>运营报表 <small>默认展示北京时间当前月份业绩</small></h1></section>
<section class="content">
    <div class="box filter-box"><div class="box-body">
        <form class="op-toolbar" method="get" action="{{ route('operation.reports.index') }}">
            <div class="form-group"><label>开始日期</label><input class="form-control input-sm" type="date" name="date_from" value="{{ $dateFrom }}"></div>
            <div class="form-group"><label>结束日期</label><input class="form-control input-sm" type="date" name="date_to" value="{{ $dateTo }}"></div>
            @if($isBoss)<div class="form-group"><label>运营身份</label><select class="form-control input-sm" name="operator_user_id">@foreach($operatorOptions as $option)<option value="{{ $option->id }}" @selected($selectedOperator->id === $option->id)>{{ $option->name ?: $option->username }}</option>@endforeach</select></div>@endif
            <button class="btn btn-primary btn-sm"><i class="fa fa-search"></i> 查询业绩</button>
            <span class="monthly-range">{{ $dateFrom }} 至 {{ $dateTo }}</span>
        </form>
    </div></div>

    <div class="monthly-kpis">
        <div class="monthly-kpi"><span>首单合计</span><strong>{{ (int)$metricTotals['first_total_orders'] }} 单</strong></div>
        <div class="monthly-kpi"><span>首单营业额</span><strong>${{ number_format($metricTotals['first_total_revenue'],2) }}</strong></div>
        <div class="monthly-kpi"><span>采购费</span><strong>${{ number_format($metricTotals['first_purchase_cost'],2) }}</strong></div>
        <div class="monthly-kpi"><span>运费</span><strong>${{ number_format($metricTotals['first_shipping_cost'],2) }}</strong></div>
        <div class="monthly-kpi"><span>总营业额</span><strong>${{ number_format($metricTotals['total_revenue'],2) }}</strong></div>
    </div>

    <div class="box report-data-box">
        <div class="box-header with-border"><h3 class="box-title">月度营业额数据</h3><p class="op-help">每一行按日期、店铺域名和媒体汇总；当天媒体 ROI = 当天该媒体首单营业额 ÷ 当天该媒体实际广告费。</p></div>
        <div class="box-body no-padding monthly-table-wrap"><table class="table table-bordered table-hover monthly-table">
            <thead><tr><th>日期</th><th>人名</th><th>店铺域名</th><th>媒体</th><th class="media-roi">媒体 ROI</th><th>首单订阅单量</th><th>首单订阅营业额($)</th><th>首单普通单量</th><th>首单普通营业额($)</th><th>首单合计单量</th><th>首单合计营业额($)</th><th>首单总采购费($)</th><th>首单总运费($)</th><th>首单交易退货费($)</th><th>续费单量</th><th>续费营业额($)</th><th>续费费用($)</th><th>总单量</th><th>总营业额($)</th><th class="ad-metric">实际广告费($)</th><th class="ad-metric">首单 ROI</th></tr></thead>
            <tbody>@forelse($metricRows as $row)<tr class="day-band-{{ $metricDateBands[$loop->index] ?? 0 }}">
                @if(($metricRowspans[$loop->index] ?? 0) > 0)<td class="identity" rowspan="{{ $metricRowspans[$loop->index] }}">{{ $row['date'] }}</td><td class="identity" rowspan="{{ $metricRowspans[$loop->index] }}">{{ $row['operator_name'] }}</td><td class="identity" rowspan="{{ $metricRowspans[$loop->index] }}">{{ $row['shop_domain'] }}</td>@endif
                @php($mediaRoi = $submittedMediaRoiByDate[$row['date']][mb_strtolower(trim((string)$row['media_source']))] ?? null)
                <td>{{ $row['media_source'] }}</td><td class="media-roi">{{ $mediaRoi && $mediaRoi['first_roi'] !== null ? number_format($mediaRoi['first_roi'],2) : '—' }}</td><td class="number">{{ (int)$row['first_subscription_orders'] }}</td><td class="money">{{ number_format($row['first_subscription_revenue'],2) }}</td><td class="number">{{ (int)$row['first_normal_orders'] }}</td><td class="money">{{ number_format($row['first_normal_revenue'],2) }}</td><td class="number">{{ (int)$row['first_total_orders'] }}</td><td class="money">{{ number_format($row['first_total_revenue'],2) }}</td><td class="money">{{ number_format($row['first_purchase_cost'],2) }}</td><td class="money">{{ number_format($row['first_shipping_cost'],2) }}</td><td class="money">{{ number_format($row['first_return_fee'],2) }}</td><td class="number">{{ (int)$row['renewal_orders'] }}</td><td class="money">{{ number_format($row['renewal_revenue'],2) }}</td><td class="money">{{ number_format($row['renewal_cost'],2) }}</td><td class="number">{{ (int)$row['total_orders'] }}</td><td class="money">{{ number_format($row['total_revenue'],2) }}</td>
                @if(($metricDateRowspans[$loop->index] ?? 0) > 0)@php($dayReport = $submittedReportsByDate->get($row['date']))<td class="ad-metric" rowspan="{{ $metricDateRowspans[$loop->index] }}">{{ $dayReport ? number_format($dayReport->actual_ad_spend,2) : '—' }}</td><td class="ad-metric" rowspan="{{ $metricDateRowspans[$loop->index] }}">{{ $dayReport && $dayReport->actual_ad_spend > 0 ? number_format(collect($dayReport->metric_rows ?? [])->sum('first_total_revenue') / $dayReport->actual_ad_spend,2) : '—' }}</td>@endif
            </tr>@empty<tr><td colspan="21" class="text-center text-muted" style="padding:40px">该月份没有可统计的订单</td></tr>@endforelse</tbody>
            <tfoot><tr><td colspan="4">合计</td><td class="media-roi">—</td>@foreach(['first_subscription_orders','first_subscription_revenue','first_normal_orders','first_normal_revenue','first_total_orders','first_total_revenue','first_purchase_cost','first_shipping_cost','first_return_fee','renewal_orders','renewal_revenue','renewal_cost','total_orders','total_revenue'] as $field)<td class="{{ str_contains($field,'orders') ? 'number' : 'money' }}">{{ str_contains($field,'orders') ? (int)$metricTotals[$field] : number_format($metricTotals[$field],2) }}</td>@endforeach<td class="ad-metric">{{ number_format($submittedAdSpendTotal,2) }}</td><td class="ad-metric">{{ $submittedFirstRoi !== null ? number_format($submittedFirstRoi,2) : '—' }}</td></tr></tfoot>
        </table></div>
    </div>

</section>
@endsection
