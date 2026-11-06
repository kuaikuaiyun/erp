@extends('portal.layout')

@section('page_title', '业绩报表')

@push('styles')
<style>
.performance-filter{display:flex;align-items:flex-end;gap:12px;flex-wrap:wrap;padding:16px 18px}.performance-filter .form-group{margin:0}.performance-filter label{display:block;margin-bottom:6px;color:#647d90;font-size:12px}.performance-filter .form-control{height:38px;min-width:150px;border:1px solid #d7e3ec;border-radius:6px}.performance-cards{display:grid;grid-template-columns:repeat(4,minmax(170px,1fr));gap:12px;margin-bottom:16px}.performance-card{padding:16px 18px;background:#fff;border-radius:10px;border-left:4px solid #2d91c5;box-shadow:0 3px 14px rgba(32,76,105,.08)}.performance-card span{color:#718797;font-size:13px}.performance-card strong{display:block;margin-top:6px;color:#123f60;font-size:24px}.performance-box{border:0;border-radius:11px;overflow:hidden;box-shadow:0 4px 16px rgba(24,61,89,.08)}.performance-table{margin:0}.performance-table th{padding:11px 9px!important;text-align:center;white-space:nowrap;background:#d9eaf5!important;color:#214e6c}.performance-table td{padding:11px 9px!important;vertical-align:middle!important;border-color:#e1eaf0!important}.performance-table tbody tr:nth-child(odd) td{background:#f4f9fc}.performance-table tbody tr:nth-child(even) td{background:#fff8ef}.operator-name{font-weight:700;color:#1f587b}.store-name{font-weight:600;color:#245675}.store-domain{color:#7b8d9a;font-size:12px}.number{text-align:right;font-variant-numeric:tabular-nums}.empty{text-align:center;padding:45px!important;color:#8195a3}.pagination-wrap{padding:13px 18px;background:#f7fafc;border-top:1px solid #dce7ee}.pagination-wrap .pagination{margin:0}@media(max-width:900px){.performance-cards{grid-template-columns:1fr 1fr}}
</style>
@endpush

@section('content')
<section class="content-header"><h1>业绩报表 <small>按运营绑定的 XShoppy 店铺统计已支付订单</small></h1></section>
<section class="content">
    <div class="box performance-box"><form method="get" action="{{ route('fashion.performance-report.index') }}" class="performance-filter">
        <div class="form-group"><label>开始日期</label><input class="form-control" type="date" name="date_from" value="{{ $filters['date_from'] }}"></div>
        <div class="form-group"><label>结束日期</label><input class="form-control" type="date" name="date_to" value="{{ $filters['date_to'] }}"></div>
        @if($user->isAdministrator())<div class="form-group"><label>运营人员</label><select class="form-control" name="operator_id"><option value="">全部运营人员</option>@foreach($operators as $operator)<option value="{{ $operator->id }}" @selected((string)$filters['operator_id'] === (string)$operator->id)>{{ $operator->name ?: $operator->username }}</option>@endforeach</select></div>@endif
        <div class="form-group"><label>每页</label><select class="form-control" name="per_page">@foreach([20,50,100] as $size)<option value="{{ $size }}" @selected($filters['per_page'] === $size)>{{ $size }} 条</option>@endforeach</select></div>
        <button class="btn btn-primary" type="submit"><i class="fa fa-search"></i> 查询业绩</button><a class="btn btn-default" href="{{ route('fashion.performance-report.index') }}"><i class="fa fa-refresh"></i> 重置</a>
    </form></div>

    <div class="performance-cards">
        <div class="performance-card"><span>订单总量</span><strong>{{ $summary['order_count'] }} 单</strong></div>
        <div class="performance-card"><span>营业额</span><strong>${{ number_format($summary['revenue'], 2) }}</strong></div>
        <div class="performance-card"><span>客单价</span><strong>${{ number_format($summary['average_order_value'], 2) }}</strong></div>
        <div class="performance-card"><span>出单店铺</span><strong>{{ $summary['store_count'] }} 个</strong></div>
    </div>

    <div class="box performance-box"><div class="box-header with-border"><h3 class="box-title">每日业绩明细 <small>共 {{ $rows->total() }} 条</small></h3></div><div class="box-body no-padding table-responsive">
        <table class="table table-bordered performance-table"><thead><tr><th>日期</th><th>运营人员</th><th>店铺</th><th>域名</th><th>订单量</th><th>营业额</th><th>客单价</th></tr></thead><tbody>
        @forelse($rows as $row)<tr><td>{{ $row->report_date }}</td><td><span class="operator-name">{{ $row->owner_name ?: $row->owner_username ?: '未绑定' }}</span></td><td><span class="store-name">{{ $row->shop_name ?: $row->domain }}</span></td><td><span class="store-domain">{{ $row->domain ?: '—' }}</span></td><td class="number"><strong>{{ $row->order_count }}</strong> 单</td><td class="number"><strong>${{ number_format((float)$row->revenue, 2) }}</strong></td><td class="number">${{ number_format($row->order_count > 0 ? (float)$row->revenue / $row->order_count : 0, 2) }}</td></tr>
        @empty<tr><td colspan="7" class="empty"><i class="fa fa-inbox fa-2x"></i><br>当前月份暂无归属店铺的已支付订单</td></tr>@endforelse
        </tbody></table>
    </div>@if($rows->hasPages())<div class="pagination-wrap">{{ $rows->links() }}</div>@endif</div>
</section>
@endsection
