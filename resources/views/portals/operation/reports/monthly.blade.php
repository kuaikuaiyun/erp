@extends('portal.layout')

@section('page_title', '运营报表')

@push('styles')
<style>
.filter-box,.report-data-box{border:0;border-radius:10px;box-shadow:0 4px 16px rgba(31,67,98,.08);overflow:hidden}.filter-box .box-body{padding:16px 18px}.op-toolbar{display:flex;gap:14px;align-items:flex-end;flex-wrap:wrap}.op-toolbar .form-group{margin:0}.op-toolbar label{display:block;font-size:12px;color:#607789;margin-bottom:6px}.op-toolbar .form-control{height:38px;min-width:145px;border:1px solid #d8e3eb;border-radius:6px;background:#fff}.op-toolbar .btn{height:38px;border-radius:6px;padding:0 18px}.op-help{color:#7b8e9e;font-size:12px;margin:5px 0 0}
.monthly-range{display:inline-flex;align-items:center;height:38px;padding:0 13px;color:#527087;background:#eef5fa;border-radius:6px}.monthly-warning{margin-bottom:12px}.monthly-table-wrap{overflow:auto;max-height:650px}.monthly-table{min-width:2750px;margin-bottom:0;border-color:#dfe8ef}.monthly-table thead th{position:sticky;top:0;z-index:2;background:#dbeaf5;color:#234d6d;text-align:center;vertical-align:middle!important;white-space:nowrap;padding:12px 8px;border-color:#c8dbe8}.monthly-table tbody tr.day-band-0>td{background:#f4f9fd}.monthly-table tbody tr.day-band-1>td{background:#fff8ef}.monthly-table tbody tr.day-band-0:hover>td{background:#e8f3fb}.monthly-table tbody tr.day-band-1:hover>td{background:#fff0df}.monthly-table td{vertical-align:middle!important;padding:10px 8px;border-color:#e1e9ef}.monthly-table .identity{font-weight:500;color:#315c7b}.monthly-table .date-cell{font-weight:700}.monthly-table tbody tr.day-band-0>.date-cell{border-left:4px solid #5c9dca}.monthly-table tbody tr.day-band-1>.date-cell{border-left:4px solid #e5a25b;color:#86511c}.monthly-table .money,.monthly-table .number{text-align:right}.monthly-table .media-roi{color:#245d84;font-weight:700;text-align:center}.monthly-table thead .media-roi{background:#c8deed;color:#1b4c6e}.monthly-table .ad-metric{color:#245d84;font-weight:700;text-align:center}.monthly-table tbody tr.day-band-0>.ad-metric{background:#e4f1fa!important}.monthly-table tbody tr.day-band-1>.ad-metric{background:#ffedd8!important;color:#89501a}.monthly-table thead .ad-metric{background:#bdd8ea!important;color:#1b4c6e}.monthly-table .profit-positive{color:#16825d;font-weight:700}.monthly-table .profit-negative{color:#d9534f;font-weight:700}.monthly-table tfoot td{position:sticky;bottom:0;background:#dde9f1;font-weight:700;z-index:1;border-color:#c7d9e5}.monthly-table tfoot .ad-metric{background:#c4dceb!important}.monthly-kpis{display:grid;grid-template-columns:repeat(5,minmax(150px,1fr));gap:12px;margin-bottom:16px}.monthly-kpi{background:linear-gradient(135deg,#fff 0%,#f8fbfd 100%);border-left:4px solid #3498db;border-radius:10px;padding:16px 18px;box-shadow:0 4px 14px rgba(36,59,83,.08)}.monthly-kpi span{display:block;color:#74879a}.monthly-kpi strong{display:block;margin-top:6px;font-size:23px;color:#173b5e}.report-data-box .box-header{padding:15px 18px}.report-data-box .box-title{font-weight:600;color:#173b5e}.formula-list{display:flex;flex-wrap:wrap;gap:7px 16px;margin-top:9px;color:#587087;font-size:12px;line-height:1.7}.formula-list span{position:relative;padding-left:10px}.formula-list span:before{content:'·';position:absolute;left:0;color:#2e75a7;font-weight:700}@media(max-width:1000px){.monthly-kpis{grid-template-columns:repeat(2,1fr)}}
.order-count-link{border:0;background:transparent;color:#1677a8;font-weight:700;text-decoration:underline;padding:2px 5px}.order-count-link:hover{color:#0d4f72}.order-detail-modal .modal-dialog{width:min(1180px,94vw)}.order-detail-modal .modal-content{border:0;border-radius:12px;overflow:hidden}.order-detail-modal .modal-header{background:linear-gradient(135deg,#173b5e,#2f79a8);color:#fff}.order-detail-modal .modal-header .close{color:#fff;opacity:.85}.detail-summary{display:grid;grid-template-columns:repeat(5,1fr);gap:10px;margin-bottom:16px}.detail-summary>div{background:#f3f8fc;border-left:3px solid #3498db;border-radius:7px;padding:11px 13px}.detail-summary span{display:block;color:#708699;font-size:12px}.detail-summary strong{display:block;color:#173b5e;font-size:18px;margin-top:3px}.detail-order{border:1px solid #dce7ef;border-radius:9px;margin-bottom:14px;overflow:hidden}.detail-order-head{display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap;background:#edf5fa;padding:11px 14px;color:#315c7b}.detail-order-metrics{display:flex;gap:15px;flex-wrap:wrap}.detail-item-table{margin:0}.detail-item-table th{background:#f8fafc;color:#567086;white-space:nowrap}.detail-product{display:flex;align-items:center;gap:9px;min-width:220px}.detail-product img{width:42px;height:42px;object-fit:cover;border-radius:5px;border:1px solid #e0e7ed}.detail-formula{color:#788b9b;font-size:12px;margin-top:4px}@media(max-width:800px){.detail-summary{grid-template-columns:repeat(2,1fr)}}
.detail-pagination{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-top:16px;padding:12px 14px;background:#f4f8fb;border-radius:8px}.detail-pagination-info{color:#657c8f}.detail-pagination-buttons{display:flex;gap:5px}.detail-pagination-buttons .btn{min-width:34px}.detail-pagination-buttons .btn.active{background:#2d78a7;color:#fff;border-color:#2d78a7}
.daily-media-list{display:flex;flex-direction:column;gap:4px;min-width:90px}.daily-media-list span{display:block;white-space:nowrap}.daily-summary-cell{text-align:right}.daily-summary-cell.daily-media-cell{text-align:left}
.monthly-table.details-collapsed .detail-metric-column,.monthly-table.details-collapsed thead th:nth-child(n+4):nth-child(-n+17),.monthly-table.details-collapsed tfoot td:nth-child(n+2):nth-child(-n+15){display:none}.detail-column-toggle{float:right;border-radius:6px}.detail-column-toggle i{margin-right:5px}
</style>
@endpush

@section('content')
<section class="content-header"><h1>运营报表 <small>默认展示 UTC-4 当前月份业绩</small></h1></section>
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
        <div class="box-header with-border"><h3 class="box-title">营业额数据</h3><button type="button" class="btn btn-default btn-sm detail-column-toggle" data-table="operation-report-table"><i class="fa fa-columns"></i><span>显示明细列</span></button><div class="formula-list"><span>订单按固定 UTC-4 时区的下单日期统计（不区分夏令时）</span><span>每单采购费＝采购价(CNY) × 数量 ÷ 6.5</span><span>每单运费＝（每单 SKU 重量 ÷ 1000 × 80 ＋ 20）÷ 6.5</span><span>交易退货费＝首单营业额 × 12%</span><span>首单总成本＝首单交易退货费 ＋ 首单采购费 ＋ 首单运费</span><span>首单盈利＝首单营业额 － 实际广告费 － 首单总成本</span><span>首单亏损率＝首单盈利 ÷ 首单总成本</span><span>手续费＝账面广告费 × 结算系数</span><span>实际广告费＝账面广告费 ＋ 手续费</span><span>媒体 ROI＝当天该媒体首单营业额 ÷ 当天该媒体实际广告费</span><span>首单 ROI＝当天首单合计营业额 ÷ 当天实际广告费</span></div></div>
        <div class="box-body no-padding monthly-table-wrap"><table class="table table-bordered table-hover monthly-table details-collapsed" id="operation-report-table">
            <thead><tr><th>日期</th><th>人名</th><th>店铺域名</th><th>首单订阅单量</th><th>首单订阅营业额($)</th><th>首单普通单量</th><th>首单普通营业额($)</th><th>首单合计单量</th><th>首单合计营业额($)</th><th>首单总采购费($)</th><th>首单总运费($)</th><th>首单交易退货费($)</th><th>首单总成本($)</th><th>首单盈利($)</th><th>首单亏损率</th><th>续费单量</th><th>续费营业额($)</th><th>首单总量</th><th>首单总营业额($)</th><th>首单总成本($)</th><th>首单总亏损率</th><th>媒体</th><th>媒体总 ROI</th><th>首单总 ROI</th><th>续费单总单量</th><th>续费单总营业额($)</th></tr></thead>
            <tbody>@forelse($metricRows as $row)<tr class="day-band-{{ $metricDateBands[$loop->index] ?? 0 }}">
                @if(($metricPersonRowspans[$loop->index] ?? 0) > 0)<td class="identity date-cell" rowspan="{{ $metricPersonRowspans[$loop->index] }}">{{ $row['date'] }}</td><td class="identity" rowspan="{{ $metricPersonRowspans[$loop->index] }}">{{ $row['operator_name'] }}</td>@endif
                @if(($metricRowspans[$loop->index] ?? 0) > 0)<td class="identity" rowspan="{{ $metricRowspans[$loop->index] }}">{{ $row['shop_domain'] }}</td>@endif
                @foreach([['first_subscription_orders','first_subscription'],['first_normal_orders','first_normal'],['first_total_orders','first_total'],['renewal_orders','renewal']] as [$countField,$detailType])
                    @if($countField === 'first_subscription_orders' || $countField === 'first_normal_orders' || $countField === 'first_total_orders' || $countField === 'renewal_orders')
                        <td class="number detail-metric-column">@if((int)$row[$countField] > 0)<button type="button" class="order-count-link" data-date="{{ $row['date'] }}" data-domain="{{ $row['shop_domain'] }}" data-media="{{ $row['media_source'] }}" data-type="{{ $detailType }}">{{ (int)$row[$countField] }} 单 <i class="fa fa-angle-right"></i></button>@else 0 @endif</td>
                        @if($countField === 'first_subscription_orders')<td class="money detail-metric-column">{{ number_format($row['first_subscription_revenue'],2) }}</td>@endif
                        @if($countField === 'first_normal_orders')<td class="money detail-metric-column">{{ number_format($row['first_normal_revenue'],2) }}</td>@endif
                        @if($countField === 'first_total_orders')<td class="money detail-metric-column">{{ number_format($row['first_total_revenue'],2) }}</td><td class="money detail-metric-column">{{ number_format($row['first_purchase_cost'],2) }}</td><td class="money detail-metric-column">{{ number_format($row['first_shipping_cost'],2) }}</td><td class="money detail-metric-column">{{ number_format($row['first_return_fee'],2) }}</td><td class="money detail-metric-column">{{ number_format($row['first_total_cost'],2) }}</td><td class="money detail-metric-column {{ $row['first_profit'] >= 0 ? 'profit-positive' : 'profit-negative' }}">{{ number_format($row['first_profit'],2) }}</td><td class="number detail-metric-column {{ ($row['first_loss_rate'] ?? 0) >= 0 ? 'profit-positive' : 'profit-negative' }}">{{ $row['first_loss_rate'] !== null ? number_format($row['first_loss_rate'] * 100,2).'%' : '—' }}</td>@endif
                        @if($countField === 'renewal_orders')<td class="money detail-metric-column">{{ number_format($row['renewal_revenue'],2) }}</td>@endif
                    @endif
                @endforeach
                @if(($metricDateRowspans[$loop->index] ?? 0) > 0)@php($daily = $dailySummaries[$row['date']])
                    <td class="daily-summary-cell" rowspan="{{ $metricDateRowspans[$loop->index] }}">{{ $daily['first_total_orders'] }}</td><td class="daily-summary-cell" rowspan="{{ $metricDateRowspans[$loop->index] }}">{{ number_format($daily['first_total_revenue'],2) }}</td><td class="daily-summary-cell" rowspan="{{ $metricDateRowspans[$loop->index] }}">{{ number_format($daily['first_total_cost'],2) }}</td><td class="daily-summary-cell" rowspan="{{ $metricDateRowspans[$loop->index] }}">{{ $daily['first_loss_rate'] !== null ? number_format($daily['first_loss_rate']*100,2).'%' : '—' }}</td>
                    <td class="daily-summary-cell daily-media-cell" rowspan="{{ $metricDateRowspans[$loop->index] }}"><div class="daily-media-list">@foreach($daily['media_names'] as $media)<span>{{ $media }}</span>@endforeach</div></td><td class="daily-summary-cell" rowspan="{{ $metricDateRowspans[$loop->index] }}"><div class="daily-media-list">@foreach($daily['media_rois'] as $roi)<span>{{ $roi !== null ? number_format($roi,2) : '—' }}</span>@endforeach</div></td>
                    <td class="daily-summary-cell" rowspan="{{ $metricDateRowspans[$loop->index] }}">{{ $daily['first_roi'] !== null ? number_format($daily['first_roi'],2) : '—' }}</td><td class="daily-summary-cell" rowspan="{{ $metricDateRowspans[$loop->index] }}">{{ $daily['renewal_orders'] }}</td><td class="daily-summary-cell" rowspan="{{ $metricDateRowspans[$loop->index] }}">{{ number_format($daily['renewal_revenue'],2) }}</td>
                @endif
            </tr>@empty<tr><td colspan="26" class="text-center text-muted" style="padding:40px">该月份没有可统计的订单</td></tr>@endforelse</tbody>
            <tfoot><tr><td colspan="3">合计</td>@foreach(['first_subscription_orders','first_subscription_revenue','first_normal_orders','first_normal_revenue','first_total_orders','first_total_revenue','first_purchase_cost','first_shipping_cost','first_return_fee'] as $field)<td class="{{ str_contains($field,'orders') ? 'number' : 'money' }}">{{ str_contains($field,'orders') ? (int)$metricTotals[$field] : number_format($metricTotals[$field],2) }}</td>@endforeach<td class="money">{{ number_format($metricTotals['first_total_cost'],2) }}</td><td class="money {{ $metricTotals['first_profit'] >= 0 ? 'profit-positive' : 'profit-negative' }}">{{ number_format($metricTotals['first_profit'],2) }}</td><td class="number {{ ($metricTotals['first_loss_rate'] ?? 0) >= 0 ? 'profit-positive' : 'profit-negative' }}">{{ $metricTotals['first_loss_rate'] !== null ? number_format($metricTotals['first_loss_rate'] * 100,2).'%' : '—' }}</td>@foreach(['renewal_orders','renewal_revenue'] as $field)<td class="{{ str_contains($field,'orders') ? 'number' : 'money' }}">{{ str_contains($field,'orders') ? (int)$metricTotals[$field] : number_format($metricTotals[$field],2) }}</td>@endforeach<td>{{ (int)$metricTotals['first_total_orders'] }}</td><td>{{ number_format($metricTotals['first_total_revenue'],2) }}</td><td>{{ number_format($metricTotals['first_total_cost'],2) }}</td><td>{{ $metricTotals['first_loss_rate'] !== null ? number_format($metricTotals['first_loss_rate']*100,2).'%' : '—' }}</td><td>—</td><td>—</td><td>{{ $submittedFirstRoi !== null ? number_format($submittedFirstRoi,2) : '—' }}</td><td>{{ (int)$metricTotals['renewal_orders'] }}</td><td>{{ number_format($metricTotals['renewal_revenue'],2) }}</td></tr></tfoot>
        </table></div>
    </div>

    <div class="modal fade order-detail-modal" id="orderDetailModal" tabindex="-1" role="dialog"><div class="modal-dialog modal-lg" role="document"><div class="modal-content">
        <div class="modal-header"><button type="button" class="close" data-dismiss="modal"><span>&times;</span></button><h4 class="modal-title"><i class="fa fa-list-alt"></i> 订单明细与计算数据</h4></div>
        <div class="modal-body" id="orderDetailBody"><div class="text-center text-muted" style="padding:50px"><i class="fa fa-spinner fa-spin"></i> 正在加载订单明细...</div></div>
        <div class="modal-footer"><button type="button" class="btn btn-default" data-dismiss="modal">关闭</button></div>
    </div></div></div>

</section>
@endsection

@push('scripts')
<script>
(function(){document.querySelectorAll('.detail-column-toggle').forEach(function(button){button.addEventListener('click',function(){var table=document.getElementById(button.getAttribute('data-table'));if(!table)return;var collapsed=table.classList.toggle('details-collapsed');button.querySelector('span').textContent=collapsed?'显示明细列':'隐藏明细列';});});})();
(function ($) {
    var endpoint = @json(route('operation.reports.order-details'));
    var operatorId = @json((int) $selectedOperator->id);
    var activeQuery = null;
    var typeLabels = {first_subscription:'首单订阅',first_normal:'首单普通',first_total:'首单合计',renewal:'续费单',total:'全部订单'};
    function esc(value){ return $('<div>').text(value == null ? '' : String(value)).html(); }
    function money(value){ return '$' + Number(value || 0).toFixed(2); }
    function number(value){ return Number(value || 0).toLocaleString(undefined,{maximumFractionDigits:2}); }
    function render(data, type){
        var s=data.summary, html='<div class="detail-formula" style="margin-bottom:12px"><strong>'+esc(typeLabels[type])+'</strong> · '+esc(s.date)+' · '+esc(s.shop_domain)+' · '+esc(s.media_source)+'</div>';
        html+='<div class="detail-summary"><div><span>订单数量</span><strong>'+esc(s.order_count)+' 单</strong></div><div><span>营业额</span><strong>'+money(s.revenue)+'</strong></div><div><span>采购费</span><strong>'+money(s.purchase_cost)+'</strong></div><div><span>运费</span><strong>'+money(s.shipping_cost)+'</strong></div><div><span>交易退货费</span><strong>'+money(s.return_fee)+'</strong></div></div>';
        data.orders.forEach(function(order){
            html+='<div class="detail-order"><div class="detail-order-head"><div><strong>订单 '+esc(order.order_number)+'</strong> <span class="label label-info">'+esc(order.subscription_type)+'</span><div class="detail-formula">订单ID：'+esc(order.shopify_order_id)+' · 系统ID：'+esc(order.id)+' · 下单时间（UTC-4）：'+esc(order.ordered_at)+'</div></div><div class="detail-order-metrics"><span>营业额 <b>'+money(order.revenue)+'</b></span><span>采购费 <b>'+money(order.purchase_cost)+'</b></span><span>重量 <b>'+number(order.total_weight_gram)+'g</b></span><span>运费 <b>'+money(order.shipping_cost)+'</b></span><span>退货费 <b>'+money(order.return_fee)+'</b></span></div></div>';
            html+='<div class="table-responsive"><table class="table detail-item-table"><thead><tr><th>商品</th><th>SKU</th><th>数量</th><th>采购价(CNY)</th><th>采购费($)</th><th>单件重量</th><th>商品总重量</th></tr></thead><tbody>';
            order.items.forEach(function(item){
                var image=item.image_url?'<img src="'+esc(item.image_url)+'" alt="">':'';
                html+='<tr><td><div class="detail-product">'+image+'<span>'+esc(item.product_name)+'</span></div></td><td>'+esc(item.sku)+'</td><td>'+number(item.quantity)+'</td><td>'+(item.guide_cost_cny==null?'<span class="text-danger">缺失</span>':'¥'+number(item.guide_cost_cny))+'</td><td>'+money(item.purchase_cost_usd)+'</td><td>'+(item.unit_weight_gram==null?'<span class="text-danger">缺失</span>':number(item.unit_weight_gram)+'g')+'</td><td>'+number(item.total_weight_gram)+'g</td></tr>';
            });
            html+='</tbody></table></div><div class="detail-formula" style="padding:0 14px 12px">采购费＝采购价 × 数量 ÷ 6.5；运费＝（订单商品总重量 ÷ 1000 × 80 ＋ 20）÷ 6.5；首单交易退货费＝营业额 × 12%</div></div>';
        });
        if(!data.orders.length) html+='<div class="alert alert-info">没有找到符合条件的订单。</div>';
        var p=data.pagination;
        if(p && p.total>0){
            html+='<div class="detail-pagination"><div class="detail-pagination-info">显示第 '+p.from+'-'+p.to+' 条，共 '+p.total+' 个订单</div><div class="detail-pagination-buttons">';
            html+='<button type="button" class="btn btn-default btn-sm detail-page-btn" data-page="'+(p.current_page-1)+'" '+(p.current_page<=1?'disabled':'')+'><i class="fa fa-angle-left"></i></button>';
            var start=Math.max(1,p.current_page-2), end=Math.min(p.last_page,start+4); start=Math.max(1,end-4);
            for(var page=start;page<=end;page++) html+='<button type="button" class="btn btn-default btn-sm detail-page-btn '+(page===p.current_page?'active':'')+'" data-page="'+page+'">'+page+'</button>';
            html+='<button type="button" class="btn btn-default btn-sm detail-page-btn" data-page="'+(p.current_page+1)+'" '+(p.current_page>=p.last_page?'disabled':'')+'><i class="fa fa-angle-right"></i></button></div></div>';
        }
        return html;
    }
    function loadOrders(page){
        var body=$('#orderDetailBody');
        body.html('<div class="text-center text-muted" style="padding:50px"><i class="fa fa-spinner fa-spin"></i> 正在加载订单明细...</div>');
        $.get(endpoint,$.extend({},activeQuery,{page:page,per_page:10}))
            .done(function(data){body.html(render(data,activeQuery.type));})
            .fail(function(xhr){body.html('<div class="alert alert-danger">订单明细加载失败：'+esc((xhr.responseJSON&&xhr.responseJSON.message)||'请稍后重试')+'</div>');});
    }
    $(document).on('click','.order-count-link',function(){
        var button=$(this), type=button.data('type'), modal=$('#orderDetailModal'), body=$('#orderDetailBody');
        activeQuery={date:button.data('date'),shop_domain:button.data('domain'),media_source:button.data('media'),type:type,operator_user_id:operatorId};
        modal.modal('show'); loadOrders(1);
    });
    $(document).on('click','.detail-page-btn:not([disabled])',function(){loadOrders(Number($(this).data('page')));});
})(jQuery);
</script>
@endpush
