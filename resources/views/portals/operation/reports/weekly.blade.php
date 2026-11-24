@extends('portal.layout')

@section('page_title', '运营周报')

@push('styles')
<link rel="stylesheet" href="{{ rtrim(request()->getSchemeAndHttpHost(), '/') }}/css/frontend-list-template.css?v=20261124-20">
<style>
.weekly-page{padding:15px}.weekly-page .weekly-filter{margin-bottom:15px;padding:18px 20px 15px}.weekly-filter-grid{display:grid;grid-template-columns:repeat(5,minmax(150px,1fr));gap:14px 18px;align-items:end}.weekly-filter-grid>label{min-width:0}.weekly-single-field{min-width:0}.weekly-single-field>.flt-field-label{margin-bottom:8px}.weekly-single-picker summary{color:#24455b}.weekly-single-picker[open] summary:after{transform:translateY(-20%) rotate(225deg)}.weekly-single-picker .flt-picker-panel{z-index:108}.weekly-single-picker .flt-picker-options{margin-top:0}.weekly-single-picker .flt-picker-options label{font-weight:400}.weekly-single-picker .flt-picker-options span{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.weekly-filter-actions{display:flex;align-items:center;justify-content:flex-end;gap:9px;margin-top:13px}.weekly-range{margin-right:auto;padding:8px 13px;border-radius:6px;background:#edf6fb;color:#315f7e;font-weight:600}.weekly-kpis{display:grid;grid-template-columns:repeat(6,minmax(145px,1fr));gap:12px;margin:15px 0}.weekly-kpi{padding:15px 17px;border:1px solid #e1eaf1;border-left:4px solid #3498db;border-radius:10px;background:#fff;box-shadow:0 3px 12px rgba(22,63,99,.06)}.weekly-kpi span{display:block;color:#74889a;font-size:12px}.weekly-kpi strong{display:block;margin-top:5px;color:#163d60;font-size:22px}.weekly-data{overflow:hidden}.weekly-data .flt-toolbar{padding:12px 18px}.weekly-table-wrap{max-height:calc(100vh - 330px);overflow:auto}.weekly-table{width:100%;min-width:2850px!important;margin:0;border-collapse:separate!important;border-spacing:0!important;table-layout:auto!important}.weekly-table.details-collapsed{min-width:1600px!important}.weekly-table.details-collapsed tr>th:nth-child(n+6):nth-child(-n+19),.weekly-table.details-collapsed tbody tr>td:nth-child(n+6):nth-child(-n+19),.weekly-table.details-collapsed tfoot tr>td:nth-child(n+6):nth-child(-n+19){display:none}.weekly-table th{position:sticky;top:0;z-index:4;background:#eaf4fa!important;color:#345c75!important;text-align:center;white-space:nowrap;padding:11px 9px!important;border-right:1px solid #dce8ef!important}.weekly-table td{vertical-align:middle!important;padding:10px 9px!important;border-right:1px solid #e7eef3!important;border-bottom:1px solid #e7eef3!important}.weekly-table tbody tr:nth-child(odd)>td{background:#fff}.weekly-table tbody tr:nth-child(even)>td{background:#f3f9fd}.weekly-table tbody tr:hover>td{background:#eaf5fb!important}.weekly-table .identity{font-weight:600;color:#285b7e}.weekly-table .number,.weekly-table .money{text-align:right}.weekly-table .ad-spend{color:#15618f;font-weight:700}.weekly-table .positive{color:#16815d;font-weight:700}.weekly-table .negative{color:#d9534f;font-weight:700}.weekly-table .domains{min-width:190px;white-space:normal}.weekly-table .media-summary{min-width:360px;white-space:normal;line-height:1.55}.weekly-media-item{padding:5px 7px;margin-bottom:5px;border:1px solid #d7e6ef;border-radius:5px;background:rgba(255,255,255,.72)}.weekly-media-main{display:block;font-weight:600}.weekly-account-spends{display:flex;flex-wrap:wrap;gap:3px 9px;margin-top:3px;color:#607b8e;font-size:11px}.weekly-account-spend b{color:#285d7e}.weekly-table tfoot td{background:#dcebf4!important;font-weight:700}.weekly-detail-toggle i{margin-right:5px}@media(max-width:1250px){.weekly-filter-grid{grid-template-columns:repeat(3,minmax(160px,1fr))}.weekly-kpis{grid-template-columns:repeat(3,1fr)}}@media(max-width:767px){.weekly-page{padding:10px}.weekly-filter-grid{grid-template-columns:1fr}.weekly-kpis{grid-template-columns:repeat(2,1fr)}.weekly-filter-actions{justify-content:flex-start}.weekly-range{width:100%;margin:0}.weekly-single-picker .flt-picker-panel{position:relative;top:5px}}
</style>
@endpush

@section('content')
<section class="content-header"><h1>运营周报 <small>按 UTC-4 自然周（周一至周日）统计运营业绩</small></h1></section>
<section class="content flt weekly-page">
    <form class="flt-card flt-filters weekly-filter" method="get" action="{{ route('operation.weekly-reports.index') }}">
        <div class="weekly-filter-grid">
            @if($isBoss || $isReportDepartmentLeader)
                @include('portals.operation.reports.partials.weekly-single-picker', ['label'=>'运营部门','name'=>'operation_department_id','selected'=>$selectedDepartmentId === null ? '' : (string)$selectedDepartmentId,'options'=>[''=>'全部运营部门'] + $operationDepartments->mapWithKeys(fn($department)=>[(int)$department->id=>$department->name])->all(),'autoSubmit'=>true,'departmentPicker'=>true])
            @endif
            @if($canSelectOperator)
                @include('portals.operation.reports.partials.weekly-single-picker', ['label'=>'运营人员','name'=>'operator_user_id','selected'=>$selectAllOperators ? 'all' : (string)($selectedOperator?->id ?? ''),'options'=>['all'=>'全部运营人员'] + $operatorOptions->mapWithKeys(fn($option)=>[(int)$option->id=>($option->name ?: $option->username)])->all()])
            @endif
            <label>开始日期<input type="date" name="date_from" value="{{ $inputDateFrom }}" required></label>
            <label>结束日期<input type="date" name="date_to" value="{{ $inputDateTo }}" required></label>
            @include('portals.operation.reports.partials.weekly-single-picker', ['label'=>'统计时区','name'=>'timezone','selected'=>$timezone,'options'=>$timezoneOptions])
        </div>
        <div class="weekly-filter-actions"><span class="weekly-range">{{ $dateFrom }} 至 {{ $dateTo }}</span><button class="flt-btn flt-primary" type="submit"><i class="fa fa-search"></i> 查询周报</button><a class="flt-btn" href="{{ route('operation.weekly-reports.index') }}"><i class="fa fa-refresh"></i> 重置</a></div>
    </form>

    <div class="weekly-kpis">
        <div class="weekly-kpi"><span>首单总量</span><strong>{{ $summary['first_orders'] }} 单</strong></div>
        <div class="weekly-kpi"><span>首单营业额</span><strong>${{ number_format($summary['first_revenue'], 2) }}</strong></div>
        <div class="weekly-kpi"><span>首单总成本</span><strong>${{ number_format($summary['first_cost'], 2) }}</strong></div>
        <div class="weekly-kpi"><span>广告费</span><strong>${{ number_format($summary['ad_spend'], 2) }}</strong></div>
        <div class="weekly-kpi"><span>首单盈利</span><strong class="{{ $summary['first_profit'] >= 0 ? 'positive' : 'negative' }}">${{ number_format($summary['first_profit'], 2) }}</strong></div>
        <div class="weekly-kpi"><span>首单 ROI</span><strong>{{ $summary['first_roi'] !== null ? number_format($summary['first_roi'], 2) : '—' }}</strong></div>
    </div>

    <div class="flt-card flt-records weekly-data">
        <div class="flt-toolbar"><div class="flt-selection"><strong>周业绩明细</strong><span class="flt-toolbar-divider"></span><span>共 {{ $weeklyRows->count() }} 条记录</span></div><div class="flt-toolbar-actions"><button type="button" class="flt-btn weekly-detail-toggle" data-table="operation-weekly-report-table"><i class="fa fa-columns"></i><span>显示明细列</span></button></div></div>
        <div class="flt-table-scroll weekly-table-wrap"><table class="weekly-table details-collapsed" id="operation-weekly-report-table">
            <thead><tr><th>统计周期</th><th>运营部门</th><th>人名</th><th>店铺域名</th><th>广告费($)</th><th>首单订阅单量</th><th>首单订阅营业额($)</th><th>首单普通单量</th><th>首单普通营业额($)</th><th>首单合计单量</th><th>首单合计营业额($)</th><th>首单总采购费($)</th><th>首单总运费($)</th><th>首单交易退货费($)</th><th>首单总成本($)</th><th>首单盈利($)</th><th>首单亏损率</th><th>续费单量</th><th>续费营业额($)</th><th>首单总量</th><th>首单订阅率</th><th>首单总营业额($)</th><th>首单总成本($)</th><th>首单总亏损率</th><th>媒体 / 单量 / 广告花费</th><th>首单总 ROI</th><th>续费单总单量</th><th>续费单总营业额($)</th></tr></thead>
            <tbody>
            @forelse($weeklyRows as $row)
                <tr><td class="identity">{{ $row['period_from'] }} 至 {{ $row['period_to'] }}</td><td class="identity">{{ $row['department_name'] }}</td><td class="identity">{{ $row['operator_name'] }}</td><td class="domains">{{ $row['shop_domains'] ? implode('、', $row['shop_domains']) : '—' }}</td><td class="money ad-spend">{{ number_format($row['ad_spend'],2) }}</td><td class="number">{{ $row['subscription_orders'] }}</td><td class="money">{{ number_format($row['subscription_revenue'],2) }}</td><td class="number">{{ $row['normal_orders'] }}</td><td class="money">{{ number_format($row['normal_revenue'],2) }}</td><td class="number">{{ $row['first_orders'] }}</td><td class="money">{{ number_format($row['first_revenue'],2) }}</td><td class="money">{{ number_format($row['purchase_cost'],2) }}</td><td class="money">{{ number_format($row['shipping_cost'],2) }}</td><td class="money">{{ number_format($row['return_fee'],2) }}</td><td class="money">{{ number_format($row['first_cost'],2) }}</td><td class="money {{ $row['first_profit'] >= 0 ? 'positive' : 'negative' }}">{{ number_format($row['first_profit'],2) }}</td><td class="number {{ ($row['first_loss_rate'] ?? 0) >= 0 ? 'positive' : 'negative' }}">{{ $row['first_loss_rate'] !== null ? number_format($row['first_loss_rate'] * 100,2).'%' : '—' }}</td><td class="number">{{ $row['renewal_orders'] }}</td><td class="money">{{ number_format($row['renewal_revenue'],2) }}</td><td class="number">{{ $row['first_orders'] }}</td><td class="number">{{ $row['subscription_rate'] !== null ? number_format($row['subscription_rate'] * 100,2).'%' : '—' }}</td><td class="money">{{ number_format($row['first_revenue'],2) }}</td><td class="money">{{ number_format($row['first_cost'],2) }}</td><td class="number {{ ($row['first_loss_rate'] ?? 0) >= 0 ? 'positive' : 'negative' }}">{{ $row['first_loss_rate'] !== null ? number_format($row['first_loss_rate'] * 100,2).'%' : '—' }}</td><td class="media-summary">@forelse($row['media_summary'] as $media)<div class="weekly-media-item"><span class="weekly-media-main">{{ $media['media_source'] }}：{{ $media['first_orders'] }} 单 / 广告花费 ${{ number_format($media['actual_ad_spend'],2) }}</span>@if(!empty($media['accounts']))<div class="weekly-account-spends">@foreach($media['accounts'] as $account)<span class="weekly-account-spend"><b>{{ $account['account_name'] ?: ($account['account_number'] ?: '未命名账户') }}</b>@if($account['account_name'] && $account['account_number'])（{{ $account['account_number'] }}）@endif：${{ number_format($account['actual_ad_spend'],2) }}</span>@endforeach</div>@endif</div>@empty — @endforelse</td><td class="number">{{ $row['first_roi'] !== null ? number_format($row['first_roi'],2) : '—' }}</td><td class="number">{{ $row['renewal_orders'] }}</td><td class="money">{{ number_format($row['renewal_revenue'],2) }}</td></tr>
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
    var pickers = Array.prototype.slice.call(document.querySelectorAll('[data-weekly-single-picker]'));
    pickers.forEach(function (picker) {
        var summary = picker.querySelector('[data-picker-summary]');
        picker.querySelectorAll('input[type="radio"]').forEach(function (input) {
            input.addEventListener('change', function () {
                summary.textContent = input.closest('label').querySelector('span').textContent;
                picker.removeAttribute('open');
                if (picker.dataset.autoSubmit === '1') {
                    if (picker.dataset.departmentPicker === '1') document.querySelectorAll('[name="operator_user_id"]').forEach(function (field) { field.disabled = true; });
                    picker.closest('form').submit();
                }
            });
        });
    });
    document.addEventListener('click', function (event) { pickers.forEach(function (picker) { if (!picker.contains(event.target)) picker.removeAttribute('open'); }); });
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
