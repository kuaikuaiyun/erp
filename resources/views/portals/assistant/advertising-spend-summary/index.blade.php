@extends('portal.layout')

@section('page_title', '广告费汇总')

@push('styles')
<style>
.ad-summary-filter,.ad-summary-box{border:0;border-radius:12px;box-shadow:0 4px 16px rgba(28,62,91,.09);overflow:hidden}.ad-summary-filter .box-body{padding:16px 18px}.ad-summary-toolbar{display:flex;align-items:flex-end;gap:12px;flex-wrap:wrap}.ad-summary-toolbar .form-group{margin:0}.ad-summary-toolbar label{display:block;margin-bottom:6px;color:#657d90;font-size:12px}.ad-summary-toolbar .form-control{height:38px;border:1px solid #d7e3ec;border-radius:6px;min-width:160px}.ad-mode{display:flex}.ad-mode label{margin:0}.ad-mode input{position:absolute;opacity:0}.ad-mode span{display:block;padding:9px 18px;background:#fff;border:1px solid #cfdde7;color:#547086;cursor:pointer}.ad-mode label:first-child span{border-radius:6px 0 0 6px}.ad-mode label:last-child span{border-radius:0 6px 6px 0;border-left:0}.ad-mode input:checked+span{background:#287eaf;border-color:#287eaf;color:#fff}.ad-range{height:38px;display:flex;align-items:center;padding:0 14px;background:#edf5fa;border-radius:6px;color:#51738b}.ad-summary-kpis{display:grid;grid-template-columns:repeat(5,minmax(145px,1fr));gap:12px;margin:16px 0}.ad-summary-kpi{padding:15px 17px;background:linear-gradient(135deg,#fff,#f5f9fc);border-left:4px solid #2f8bbe;border-radius:10px;box-shadow:0 3px 13px rgba(30,65,95,.08)}.ad-summary-kpi span{display:block;color:#73889a;font-size:12px}.ad-summary-kpi strong{display:block;margin-top:5px;color:#153f60;font-size:22px}.ad-summary-box .box-header{padding:15px 18px}.ad-summary-box .box-title{font-weight:600;color:#173f60}.ad-summary-note{margin:5px 0 0;color:#73899a;font-size:12px}.ad-summary-table{margin:0}.ad-summary-table th{background:#d8e9f4!important;color:#214f6e;text-align:center;white-space:nowrap;padding:11px 9px!important}.ad-summary-table td{vertical-align:middle!important;padding:10px 9px!important;border-color:#e1eaf0!important}.ad-summary-table tbody tr:nth-child(odd)>td{background:#f4f9fc}.ad-summary-table tbody tr:nth-child(even)>td{background:#fff8ef}.ad-summary-table .identity{font-weight:600;color:#315f7c}.ad-summary-table .money{text-align:right;font-variant-numeric:tabular-nums}.ad-summary-table .actual{font-weight:700;color:#176d9a}.ad-summary-table tfoot td{background:#dceaf3!important;font-weight:700}.ad-empty{text-align:center;color:#8193a1;padding:48px!important}@media(max-width:900px){.ad-summary-kpis{grid-template-columns:repeat(2,1fr)}}
.ad-summary-kpis{grid-template-columns:repeat(4,minmax(145px,1fr))}.ad-chart-box{border:0;border-radius:12px;box-shadow:0 4px 16px rgba(28,62,91,.09);overflow:hidden;margin-bottom:16px}.ad-chart-box .box-header{padding:15px 18px}.ad-chart-title{font-weight:600;color:#173f60}.ad-chart-legend{display:flex;gap:18px;margin-top:7px;color:#687f91;font-size:12px}.ad-chart-legend span:before{content:"";display:inline-block;width:10px;height:10px;border-radius:2px;margin-right:5px}.ad-chart-legend .book:before{background:#7eb9dd}.ad-chart-legend .actual:before{background:#237dac}.ad-chart-scroll{overflow-x:auto;padding:18px 18px 8px}.ad-chart{height:280px;display:flex;align-items:flex-end;gap:13px;min-width:max-content;border-bottom:1px solid #dce7ee;background:repeating-linear-gradient(to top,transparent 0,transparent 54px,#edf2f6 55px)}.ad-chart-group{width:72px;height:100%;display:flex;flex-direction:column;justify-content:flex-end;align-items:center}.ad-chart-bars{height:232px;display:flex;align-items:flex-end;justify-content:center;gap:5px}.ad-chart-bar{width:24px;min-height:2px;border-radius:5px 5px 0 0;position:relative;transition:filter .15s}.ad-chart-bar:hover{filter:brightness(.9)}.ad-chart-bar.book{background:linear-gradient(180deg,#8fc9e9,#62a8d1)}.ad-chart-bar.actual{background:linear-gradient(180deg,#35a0d3,#176d9d)}.ad-chart-bar span{display:none;position:absolute;left:50%;bottom:calc(100% + 7px);transform:translateX(-50%);padding:4px 7px;border-radius:5px;background:#173f60;color:#fff;font-size:11px;white-space:nowrap;z-index:3}.ad-chart-bar:hover span{display:block}.ad-chart-date{height:38px;padding-top:7px;color:#577187;font-size:11px;white-space:nowrap}.ad-chart-empty{height:190px;display:flex;align-items:center;justify-content:center;color:#8496a4}
</style>
<style>
.ad-breakdown-wrap{padding:16px;background:#f4f8fb}.ad-breakdown-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(390px,1fr));gap:14px}.ad-day-card{background:#fff;border:1px solid #dae6ee;border-radius:11px;overflow:hidden;box-shadow:0 2px 8px rgba(31,69,96,.06)}.ad-day-head{display:flex;align-items:center;justify-content:space-between;padding:12px 15px;background:linear-gradient(135deg,#e8f3fa,#f7fbfd);border-bottom:1px solid #dce8ef}.ad-day-date{font-size:15px;font-weight:700;color:#204f70}.ad-day-total{color:#607a8d;font-size:12px}.ad-day-total strong{margin-left:5px;color:#176d9d;font-size:17px}.ad-breakdown-list{padding:8px 15px 13px}.ad-breakdown-row{display:grid;grid-template-columns:minmax(125px,180px) minmax(110px,1fr) 90px;align-items:center;gap:10px;padding:8px 0;border-bottom:1px dashed #e5edf2}.ad-breakdown-row:last-child{border-bottom:0}.ad-breakdown-name{min-width:0}.ad-breakdown-name b,.ad-breakdown-name span{display:block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.ad-breakdown-name b{color:#2c5876;font-size:13px}.ad-breakdown-name span{color:#7b8f9f;font-size:11px;margin-top:2px}.ad-breakdown-track{height:13px;border-radius:8px;background:#eaf1f5;overflow:hidden}.ad-breakdown-fill{height:100%;min-width:3px;border-radius:8px;background:linear-gradient(90deg,#67b1d8,#1876a7)}.ad-breakdown-value{text-align:right;color:#174f73;font-weight:700;font-variant-numeric:tabular-nums}.ad-chart-help{margin:5px 0 0;color:#73899a;font-size:12px}@media(max-width:760px){.ad-breakdown-grid{grid-template-columns:1fr}.ad-breakdown-row{grid-template-columns:110px minmax(80px,1fr) 78px}.ad-breakdown-wrap{padding:10px}}
</style>
@endpush

@section('content')
<section class="content-header"><h1>广告费汇总 <small>统计审核通过的运营广告费提报</small></h1></section>
<section class="content">
    <div class="box ad-summary-filter"><div class="box-body">
        <form method="get" action="{{ route('assistant.advertising-spend-summary.index') }}" class="ad-summary-toolbar" id="ad-summary-filter">
            <div class="form-group"><label>开始日期</label><input class="form-control" type="date" name="date_from" value="{{ $dateFrom }}" required></div>
            <div class="form-group"><label>结束日期</label><input class="form-control" type="date" name="date_to" value="{{ $dateTo }}" required></div>
            <button class="btn btn-primary" type="submit"><i class="fa fa-search"></i> 查询汇总</button>
            <div class="ad-range">{{ $dateFrom }} 至 {{ $dateTo }}</div>
        </form>
    </div></div>

    <div class="ad-summary-kpis">
        <div class="ad-summary-kpi"><span>账面广告费</span><strong>${{ number_format($totals['book_spend'], 2) }}</strong></div>
        <div class="ad-summary-kpi"><span>实际总消耗</span><strong>${{ number_format($totals['actual_spend'], 2) }}</strong></div>
        <div class="ad-summary-kpi"><span>已审核提报</span><strong>{{ $totals['report_count'] }} 份</strong></div>
        <div class="ad-summary-kpi"><span>提报运营人数</span><strong>{{ $totals['operator_count'] }} 人</strong></div>
    </div>

    <div class="box ad-chart-box">
        <div class="box-header with-border"><div class="ad-chart-title">每日代理与渠道消耗分布</div><p class="ad-chart-help">每天按广告代理和广告渠道拆分实际总消耗，柱形越长表示消耗越高。</p></div>
        <div class="box-body no-padding">
            @if($chartDays->isNotEmpty())
                <div class="ad-breakdown-wrap"><div class="ad-breakdown-grid">
                    @foreach($chartDays as $chartDay)
                        <div class="ad-day-card">
                            <div class="ad-day-head"><span class="ad-day-date">{{ $chartDay['date'] }}</span><span class="ad-day-total">当日合计 <strong>${{ number_format($chartDay['total'], 2) }}</strong></span></div>
                            <div class="ad-breakdown-list">
                                @foreach($chartDay['items'] as $chartItem)
                                    <div class="ad-breakdown-row">
                                        <div class="ad-breakdown-name"><b title="{{ $chartItem['agency'] }}">{{ $chartItem['agency'] }}</b><span title="{{ $chartItem['media'] }}">{{ $chartItem['media'] }}</span></div>
                                        <div class="ad-breakdown-track" title="{{ $chartItem['agency'] }} / {{ $chartItem['media'] }}：${{ number_format($chartItem['actual_spend'], 2) }}"><div class="ad-breakdown-fill" style="width:{{ max(1, ($chartItem['actual_spend'] / $chartMax) * 100) }}%"></div></div>
                                        <div class="ad-breakdown-value">${{ number_format($chartItem['actual_spend'], 2) }}</div>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @endforeach
                </div></div>
            @else
                <div class="ad-chart-empty">当前筛选范围暂无可展示的广告费数据</div>
            @endif
        </div>
    </div>

    <div class="box ad-summary-box">
        <div class="box-header with-border"><h3 class="box-title">每日代理与渠道消耗</h3><p class="ad-summary-note">同一天、同一广告代理、同一广告渠道的数据自动合并；待审核和审核不通过的数据不参与统计。</p></div>
        <div class="box-body no-padding table-responsive">
            <table class="table table-bordered table-hover ad-summary-table">
                <thead><tr><th>日期</th><th>广告代理</th><th>广告渠道</th><th>账面广告费($)</th><th>实际总消耗($)</th><th>提报数</th><th>运营人数</th></tr></thead>
                <tbody>
                @forelse($rows as $row)
                    <tr><td class="identity">{{ $row['date'] }}</td><td class="identity">{{ $row['agency'] }}</td><td>{{ $row['media'] }}</td><td class="money">{{ number_format($row['book_spend'], 2) }}</td><td class="money actual">{{ number_format($row['actual_spend'], 2) }}</td><td class="text-center">{{ $row['report_count'] }}</td><td class="text-center">{{ $row['operator_count'] }}</td></tr>
                @empty
                    <tr><td colspan="7" class="ad-empty">当前时间范围内暂无已审核的广告费数据</td></tr>
                @endforelse
                </tbody>
                @if($rows->isNotEmpty())<tfoot><tr><td colspan="3">合计</td><td class="money">{{ number_format($totals['book_spend'], 2) }}</td><td class="money">{{ number_format($totals['actual_spend'], 2) }}</td><td class="text-center">{{ $totals['report_count'] }}</td><td class="text-center">{{ $totals['operator_count'] }}</td></tr></tfoot>@endif
            </table>
        </div>
    </div>
</section>
@endsection
