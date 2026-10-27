@extends('portal.layout')

@section('page_title', '运营报表')

@push('styles')
<style>
.operation-report-page{--report-blue:#287fb8;--report-ink:#24384b;--report-gold:#fff5cf;--report-rose:#fff0f3}.operation-report-page .box{border-radius:8px;box-shadow:0 3px 14px rgba(35,67,92,.07);overflow:hidden}.operation-report-filter-box{border-top:3px solid var(--report-blue)}.operation-report-filter{display:flex;flex-wrap:wrap;gap:14px;align-items:flex-end}.operation-report-filter .form-group{margin:0}.operation-report-filter label{display:block;margin-bottom:6px;color:#516679;font-size:12px;font-weight:600}.operation-report-filter .form-control{height:34px;border-color:#d7e2eb;border-radius:5px}.operation-report-filter .btn{height:34px;border-radius:5px;padding-left:16px;padding-right:16px}.operation-report-summary{display:flex;align-items:center;height:34px;color:#7a8b99;font-size:12px}.operation-report-kpis{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:14px;margin-bottom:16px}.operation-report-kpi{position:relative;min-height:94px;padding:17px 18px;border-radius:9px;background:#fff;box-shadow:0 4px 16px rgba(36,56,75,.08);overflow:hidden}.operation-report-kpi:after{content:'';position:absolute;right:-20px;top:-25px;width:90px;height:90px;border-radius:50%;background:currentColor;opacity:.08}.operation-report-kpi i{float:right;font-size:25px;opacity:.28}.operation-report-kpi span{display:block;color:#789;font-size:12px;margin-bottom:7px}.operation-report-kpi strong{font-size:25px;line-height:1;color:var(--report-ink)}.operation-report-kpi small{margin-left:4px;color:#99a8b4}.operation-report-kpi.is-blue{color:#2685c4;border-left:4px solid #2685c4}.operation-report-kpi.is-green{color:#35a56f;border-left:4px solid #35a56f}.operation-report-kpi.is-gold{color:#e5a72d;border-left:4px solid #e5a72d}.operation-report-kpi.is-rose{color:#df6178;border-left:4px solid #df6178}.operation-report-table-wrap{max-height:calc(100vh - 325px);overflow:auto}.operation-report-table{min-width:1550px;margin:0}.operation-report-table thead th{position:sticky;top:0;z-index:2;vertical-align:middle!important;text-align:center;white-space:normal!important;padding:12px 8px!important;background:#f4f8fb;color:#40576b;border-bottom:2px solid #d7e4ed!important}.operation-report-table td{text-align:right;vertical-align:middle!important;padding:10px 8px!important}.operation-report-table td:nth-child(-n+4){text-align:left}.operation-report-table tbody tr:hover td{filter:brightness(.985)}.operation-report-table .group-first{background:var(--report-gold)}.operation-report-table .group-total{background:var(--report-rose);font-weight:600}.operation-report-table tfoot td{position:sticky;bottom:0;z-index:2;font-weight:700;background:#ffe0c9!important;border-top:2px solid #efb98e!important}.operation-report-table .amount{font-variant-numeric:tabular-nums}.operation-report-table-box .box-title{font-weight:600;color:var(--report-ink)}@media(max-width:900px){.operation-report-kpis{grid-template-columns:repeat(2,minmax(0,1fr))}.operation-report-table-wrap{max-height:none}}@media(max-width:520px){.operation-report-kpis{grid-template-columns:1fr}.operation-report-filter .form-group{width:100%}.operation-report-filter .form-control{width:100%}}
.operation-report-table .identity-cell,.operation-report-table .media-cell{text-align:left!important}.operation-report-table .identity-cell{background:#fff;font-weight:500;border-bottom-color:#d8e2ea!important}.operation-report-table .metric-cell{text-align:right!important}
</style>
@endpush

@section('content')
<section class="content-header"><h1>运营报表 <small>按下单时间统计订单与营业额</small></h1></section>
<section class="content operation-report-page">
    <div class="box box-solid operation-report-filter-box"><div class="box-body">
        <form class="operation-report-filter" method="get" action="{{ route('operation.reports.index') }}">
            <input type="hidden" name="filtered" value="1">
            <div class="form-group"><label>下单开始日期</label><input type="date" class="form-control input-sm" name="date_from" value="{{ $filters['date_from'] }}"></div>
            <div class="form-group"><label>下单结束日期</label><input type="date" class="form-control input-sm" name="date_to" value="{{ $filters['date_to'] }}"></div>
            <div class="form-group"><label>流媒体源</label><select class="form-control input-sm" name="media_source"><option value="">全部</option>@foreach($mediaSources as $source)<option value="{{ $source }}" @selected($filters['media_source'] === $source)>{{ $source }}</option>@endforeach</select></div>
            <button class="btn btn-primary btn-sm" type="submit"><i class="fa fa-search"></i> 查询</button>
            <a class="btn btn-default btn-sm" href="{{ route('operation.reports.index') }}">重置</a>
            <span class="operation-report-summary">查看范围：{{ $reportScopeLabel }}，共 {{ $rows->count() }} 个统计分组</span>
        </form>
    </div></div>
    <div class="operation-report-kpis">
        <div class="operation-report-kpi is-blue"><i class="fa fa-shopping-bag"></i><span>筛选范围总单量</span><strong>{{ (int) $totals['total_orders'] }}</strong><small>单</small></div>
        <div class="operation-report-kpi is-green"><i class="fa fa-usd"></i><span>筛选范围总营业额</span><strong>${{ number_format((float) $totals['total_revenue'], 2) }}</strong></div>
        <div class="operation-report-kpi is-gold"><i class="fa fa-star-o"></i><span>首单合计</span><strong>{{ (int) $totals['first_subscription_orders'] + (int) $totals['first_normal_orders'] }}</strong><small>单</small></div>
        <div class="operation-report-kpi is-rose"><i class="fa fa-refresh"></i><span>续费订单</span><strong>{{ (int) $totals['renewal_orders'] }}</strong><small>单</small></div>
    </div>
    <div class="box box-solid operation-report-table-box"><div class="box-header with-border"><h3 class="box-title"><i class="fa fa-table text-muted"></i> 统计明细</h3></div><div class="box-body no-padding operation-report-table-wrap">
        <table class="table table-bordered table-hover operation-report-table">
            <thead><tr>
                <th>日期</th><th>人名</th><th>店铺域名</th><th>媒体</th>
                <th class="group-first">首单订阅单量</th><th class="group-first">首单订阅营业额($)</th>
                <th class="group-first">首单普通单量</th><th class="group-first">首单普通单营业额($)</th>
                <th class="group-total">首单合计单量</th><th class="group-total">首单合计营业额($)</th>
                <th class="group-first">续费单量</th><th class="group-first">续费营业额($)</th><th class="group-first">续费费用($)<small class="text-muted" style="display:block">无费用填0</small></th>
                <th class="group-total">总单量</th><th class="group-total">总营业额($)</th>
            </tr></thead>
            <tbody>@forelse($rows as $row)
                <tr>
                    @if(($identityRowspans[$loop->index] ?? 0) > 0)
                        <td class="identity-cell" rowspan="{{ $identityRowspans[$loop->index] }}">{{ $row->order_date }}</td>
                        <td class="identity-cell" rowspan="{{ $identityRowspans[$loop->index] }}">{{ $row->operator_name }}</td>
                        <td class="identity-cell" rowspan="{{ $identityRowspans[$loop->index] }}">{{ $row->shop_domain }}</td>
                    @endif
                    <td class="media-cell">{{ $row->media_source }}</td>
                    <td class="group-first metric-cell">{{ (int) $row->first_subscription_orders }}</td><td class="group-first amount metric-cell">{{ number_format((float) $row->first_subscription_revenue, 2) }}</td>
                    <td class="group-first metric-cell">{{ (int) $row->first_normal_orders }}</td><td class="group-first amount metric-cell">{{ number_format((float) $row->first_normal_revenue, 2) }}</td>
                    <td class="group-total metric-cell">{{ (int) $row->first_subscription_orders + (int) $row->first_normal_orders }}</td><td class="group-total amount metric-cell">{{ number_format((float) $row->first_subscription_revenue + (float) $row->first_normal_revenue, 2) }}</td>
                    <td class="group-first metric-cell">{{ (int) $row->renewal_orders }}</td><td class="group-first amount metric-cell">{{ number_format((float) $row->renewal_revenue, 2) }}</td><td class="group-first amount metric-cell">0.00</td>
                    <td class="group-total metric-cell">{{ (int) $row->total_orders }}</td><td class="group-total amount metric-cell">{{ number_format((float) $row->total_revenue, 2) }}</td>
                </tr>
            @empty<tr><td colspan="15" class="text-center text-muted" style="padding:40px">当前时间范围暂无订单数据</td></tr>@endforelse</tbody>
            <tfoot><tr><td colspan="4">合计</td>
                <td>{{ (int) $totals['first_subscription_orders'] }}</td><td>{{ number_format((float) $totals['first_subscription_revenue'], 2) }}</td>
                <td>{{ (int) $totals['first_normal_orders'] }}</td><td>{{ number_format((float) $totals['first_normal_revenue'], 2) }}</td>
                <td>{{ (int) $totals['first_subscription_orders'] + (int) $totals['first_normal_orders'] }}</td><td>{{ number_format((float) $totals['first_subscription_revenue'] + (float) $totals['first_normal_revenue'], 2) }}</td>
                <td>{{ (int) $totals['renewal_orders'] }}</td><td>{{ number_format((float) $totals['renewal_revenue'], 2) }}</td><td>0.00</td>
                <td>{{ (int) $totals['total_orders'] }}</td><td>{{ number_format((float) $totals['total_revenue'], 2) }}</td>
            </tr></tfoot>
        </table>
    </div></div>
</section>
@endsection
