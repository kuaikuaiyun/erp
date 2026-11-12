@extends('portal.layout')

@section('page_title', '广告费分析')

@push('styles')
<style>
.analysis-filter,.analysis-panel{border:0;border-radius:12px;box-shadow:0 4px 16px rgba(28,62,91,.09);overflow:hidden}.analysis-filter .box-body{padding:16px 18px}.analysis-toolbar{display:flex;align-items:flex-end;gap:12px;flex-wrap:wrap}.analysis-toolbar .form-group{margin:0}.analysis-toolbar label{display:block;margin-bottom:6px;color:#657d90;font-size:12px}.analysis-toolbar .form-control{height:38px;min-width:165px;border:1px solid #d7e3ec;border-radius:6px}.analysis-range{height:38px;display:flex;align-items:center;padding:0 13px;border-radius:6px;background:#edf5fa;color:#52738a}.analysis-kpis{display:grid;grid-template-columns:repeat(4,minmax(150px,1fr));gap:12px;margin:16px 0}.analysis-kpi{padding:15px 17px;background:linear-gradient(135deg,#fff,#f5f9fc);border-left:4px solid #2f89ba;border-radius:10px;box-shadow:0 3px 13px rgba(30,65,95,.08)}.analysis-kpi span{display:block;color:#748899;font-size:12px}.analysis-kpi strong{display:block;margin-top:5px;color:#153e5f;font-size:22px}.analysis-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:16px}.analysis-panel .box-header{padding:14px 17px}.analysis-panel .box-title{font-weight:700;color:#173f60}.rank-list{padding:10px 17px 16px}.rank-row{display:grid;grid-template-columns:minmax(100px,160px) minmax(100px,1fr) 100px 65px;gap:10px;align-items:center;padding:9px 0;border-bottom:1px dashed #e3ebf0}.rank-row:last-child{border-bottom:0}.rank-name{font-weight:600;color:#315e7b;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.rank-track{height:12px;background:#e8f0f5;border-radius:7px;overflow:hidden}.rank-fill{height:100%;min-width:3px;border-radius:7px;background:linear-gradient(90deg,#73b9dc,#1b78aa)}.rank-money{text-align:right;font-weight:700;color:#195d87}.rank-share{text-align:right;color:#758a9a}.analysis-empty{padding:48px;text-align:center;color:#8294a2}.analysis-table{margin:0}.analysis-table th{background:#d8e9f4!important;color:#214e6d;text-align:center;white-space:nowrap;padding:11px 9px!important}.analysis-table td{padding:10px 9px!important;border-color:#e1eaf0!important}.analysis-table tbody tr:nth-child(odd)>td{background:#f4f9fc}.analysis-table tbody tr:nth-child(even)>td{background:#fff8ef}.analysis-table .money{text-align:right;font-variant-numeric:tabular-nums}.analysis-table .actual{font-weight:700;color:#176c99}.analysis-table tfoot td{background:#dceaf3!important;font-weight:700}@media(max-width:900px){.analysis-grid{grid-template-columns:1fr}.analysis-kpis{grid-template-columns:repeat(2,1fr)}}
</style>
@endpush

@section('content')
<section class="content-header"><h1>广告费分析 <small>分析 Shopify 订阅项目与服装项目的广告代理、媒体费用</small></h1></section>
<section class="content">
    <div class="box analysis-filter"><div class="box-body">
        <form method="get" action="{{ route('assistant.advertising-spend-analysis.index') }}" class="analysis-toolbar">
            <div class="form-group"><label>开始日期</label><input class="form-control" type="date" name="date_from" value="{{ $dateFrom }}" required></div>
            <div class="form-group"><label>结束日期</label><input class="form-control" type="date" name="date_to" value="{{ $dateTo }}" required></div>
            <div class="form-group"><label>项目类型</label><select class="form-control" name="project"><option value="">全部项目</option><option value="shopify" @selected($selectedProject === 'shopify')>Shopify订阅项目</option><option value="fashion" @selected($selectedProject === 'fashion')>服装项目</option></select></div>
            <div class="form-group"><label>广告代理</label><select class="form-control" name="agency"><option value="">全部代理</option>@foreach($agencyOptions as $agency)<option value="{{ $agency }}" @selected($selectedAgency === $agency)>{{ $agency }}</option>@endforeach</select></div>
            <div class="form-group"><label>广告媒体</label><select class="form-control" name="media"><option value="">全部媒体</option>@foreach($mediaOptions as $media)<option value="{{ $media }}" @selected($selectedMedia === $media)>{{ $media }}</option>@endforeach</select></div>
            <button class="btn btn-primary" type="submit"><i class="fa fa-search"></i> 查询分析</button>
            <a class="btn btn-default" href="{{ route('assistant.advertising-spend-analysis.index') }}"><i class="fa fa-refresh"></i> 重置</a>
            <div class="analysis-range">{{ $dateFrom }} 至 {{ $dateTo }}</div>
        </form>
    </div></div>

    <div class="analysis-kpis">
        <div class="analysis-kpi"><span>账面广告费</span><strong>${{ number_format($totalBookSpend, 2) }}</strong></div>
        <div class="analysis-kpi"><span>实际广告费</span><strong>${{ number_format($totalSpend, 2) }}</strong></div>
        <div class="analysis-kpi"><span>广告代理</span><strong>{{ $agencyRows->count() }} 个</strong></div>
        <div class="analysis-kpi"><span>广告媒体</span><strong>{{ $mediaRows->count() }} 个</strong></div>
    </div>

    <div class="analysis-grid">
        <div class="box analysis-panel"><div class="box-header with-border"><h3 class="box-title">广告代理费用排行</h3></div><div class="box-body no-padding">
            @if($agencyRows->isNotEmpty())<div class="rank-list">@foreach($agencyRows as $row)<div class="rank-row"><div class="rank-name" title="{{ $row['name'] }}">{{ $loop->iteration }}. {{ $row['name'] }}</div><div class="rank-track"><div class="rank-fill" style="width:{{ max(1,$row['share']) }}%"></div></div><div class="rank-money">${{ number_format($row['actual_spend'],2) }}</div><div class="rank-share">{{ number_format($row['share'],1) }}%</div></div>@endforeach</div>@else<div class="analysis-empty">暂无代理费用数据</div>@endif
        </div></div>
        <div class="box analysis-panel"><div class="box-header with-border"><h3 class="box-title">广告媒体费用排行</h3></div><div class="box-body no-padding">
            @if($mediaRows->isNotEmpty())<div class="rank-list">@foreach($mediaRows as $row)<div class="rank-row"><div class="rank-name" title="{{ $row['name'] }}">{{ $loop->iteration }}. {{ $row['name'] }}</div><div class="rank-track"><div class="rank-fill" style="width:{{ max(1,$row['share']) }}%"></div></div><div class="rank-money">${{ number_format($row['actual_spend'],2) }}</div><div class="rank-share">{{ number_format($row['share'],1) }}%</div></div>@endforeach</div>@else<div class="analysis-empty">暂无媒体费用数据</div>@endif
        </div></div>
    </div>

    <div class="box analysis-panel"><div class="box-header with-border"><h3 class="box-title">代理与媒体费用明细</h3></div><div class="box-body no-padding table-responsive">
        <table class="table table-bordered table-hover analysis-table"><thead><tr><th>项目类型</th><th>日期</th><th>广告代理</th><th>广告媒体</th><th>账面广告费($)</th><th>实际广告费($)</th><th>费用占比</th></tr></thead><tbody>
        @forelse($detailRows as $row)<tr><td>{{ $row['project_label'] }}</td><td>{{ $row['date'] }}</td><td>{{ $row['agency'] }}</td><td>{{ $row['media'] }}</td><td class="money">{{ number_format($row['book_spend'],2) }}</td><td class="money actual">{{ number_format($row['actual_spend'],2) }}</td><td class="money">{{ $totalSpend > 0 ? number_format($row['actual_spend']/$totalSpend*100,2) : '0.00' }}%</td></tr>@empty<tr><td colspan="7" class="analysis-empty">当前筛选范围暂无已审核的广告费数据</td></tr>@endforelse
        </tbody>@if($detailRows->isNotEmpty())<tfoot><tr><td colspan="4">合计</td><td class="money">{{ number_format($totalBookSpend,2) }}</td><td class="money">{{ number_format($totalSpend,2) }}</td><td class="money">100.00%</td></tr></tfoot>@endif</table>
    </div>@include('portals.assistant.partials.compact-pagination', ['paginator' => $detailRows])</div>
</section>
@endsection
