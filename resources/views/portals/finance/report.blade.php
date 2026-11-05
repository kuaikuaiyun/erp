@extends('portals.finance.layout', ['title' => __('finance.menus.'.$report)])
@push('styles')
<style>
.finance-report .box{border-top:0;margin-bottom:18px;border-radius:7px;overflow:hidden}.finance-report .box-body{padding:18px 20px}.finance-report .box-header.with-border{padding:14px 20px;background:#fff}.finance-report .box-title{font-size:16px;font-weight:600}.finance-report .finance-filter{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:16px;margin:0}.finance-report .finance-filter .form-group{min-width:0;margin:0}.finance-report .finance-filter label{font-weight:400;margin-bottom:8px;display:block}.finance-report .finance-filter .form-control{width:100%;height:36px;background:#f4f6f9;border-color:#e3eaf1}.finance-report .finance-report-actions{grid-column:1/-1;display:flex;align-items:center;gap:8px;border-top:1px solid #e9eef3;padding-top:14px}.finance-report-status{margin-left:auto;border-radius:14px;padding:4px 12px;background:#eff4f9;color:#647c92;font-size:12px}.finance-report-status.is-locked{background:#eaf6ef;color:#32815b}.finance-report-help{margin:0 0 18px;color:#718398;font-size:12px}.finance-report-help summary{cursor:pointer;padding:0 4px}.finance-report-help p{padding:10px 14px;background:#f5f8fb;border:1px solid #e3ebf2;border-radius:5px;margin-top:8px;line-height:1.9}.finance-report .finance-table{width:100%;margin:0;table-layout:fixed}.finance-report .finance-table th{font-size:12px;padding:12px 14px;white-space:normal}.finance-report .finance-table td{padding:13px 14px;min-width:0;max-width:none;vertical-align:middle;line-height:1.6}.finance-report .finance-table th:first-child{width:18%}.finance-report .finance-table th:nth-child(2){width:7%}.finance-report .finance-table th:nth-child(3){width:19%}.finance-report .finance-table th:nth-child(4){width:7%}.finance-report .finance-table th:nth-child(n+4),.finance-report .finance-table td:nth-child(n+4){text-align:right;font-variant-numeric:tabular-nums;white-space:nowrap}.finance-report .finance-table tbody tr:nth-child(even){background:#f8fafc}.finance-report .finance-table tbody tr:hover{background:#f0f7fc}.finance-report .report-amount-missing{color:#94a2b0;font-size:12px}@media(max-width:1100px){.finance-report .finance-filter{grid-template-columns:repeat(3,minmax(0,1fr))}.finance-report .finance-table{min-width:1000px}}@media(max-width:650px){.finance-report .finance-filter{grid-template-columns:1fr 1fr}.finance-report .box-body{padding:14px}.finance-report-status{margin-left:0}.finance-report .finance-report-actions{flex-wrap:wrap}}
</style>
<style>
.finance-report .finance-table{border:1px solid #dce6ef;border-collapse:collapse}
.finance-report .finance-table th,.finance-report .finance-table td,.finance-report .finance-table th:nth-child(n+4),.finance-report .finance-table td:nth-child(n+4){text-align:center;vertical-align:middle!important;border:1px solid #dce6ef}
.finance-report .finance-table.summary-grid{table-layout:auto}.finance-report .summary-grid th,.finance-report .summary-grid td{width:auto;white-space:nowrap}.finance-report .finance-table-wrap{overflow-x:auto}
</style>
@endpush
@section('finance_content')
<div class="finance-report">
@include('portals.finance.partials.summary-tabs', ['activeTab' => 'transactions'])
<div class="box"><div class="box-body"><form method="get" action="{{ route('finance.report', $report) }}" class="finance-filter">
    <input type="hidden" name="view" value="transactions">
    <input type="hidden" name="per_page" value="{{ $paginator->perPage() }}">
    <div class="form-group"><label>会计月份（北京时间）</label><input type="month" name="month" value="{{ $filters['month'] }}" class="form-control" required></div>
    <div class="form-group"><label>支付账户</label><select name="payment_account_id" class="form-control"><option value="">全部账户</option>@foreach($reportAccounts as $account)<option value="{{ $account->id }}" @selected(($filters['payment_account_id'] ?? '') == $account->id)>{{ \App\Portals\Finance\FinanceCatalog::simplified((string) ($account->account_name)) }}</option>@endforeach</select></div>
    <div class="form-group"><label>支付大类</label><select name="channel" class="form-control">@foreach($summaryTabs as $key => $label)<option value="{{ $key }}" @selected($tab === $key)>{{ $label }}</option>@endforeach</select></div>
    <div class="form-group"><label for="report-currency">币种</label><input id="report-currency" name="currency" list="report-currencies" value="{{ $filters['currency'] ?? '' }}" class="form-control" placeholder="全部币种，可输入或选择" maxlength="3" pattern="[A-Z]{3}"><datalist id="report-currencies">@foreach($currencyOptions as $currency)<option value="{{ $currency }}">@endforeach</datalist></div>
    <div class="form-group"><label>区域</label><input name="team" value="{{ $filters['team'] ?? '' }}" class="form-control" maxlength="100"></div>
    <div class="form-group"><label>订阅类型</label><input list="report-subscriptions" name="subscription" value="{{ $filters['subscription'] ?? '' }}" class="form-control" maxlength="100"><datalist id="report-subscriptions">@foreach($subscriptionOptions as $type)<option value="{{ $type }}">@endforeach</datalist></div>
    @include('portals.finance.partials.filter-conditions')
    <div class="form-group finance-report-actions"><button class="btn btn-primary"><i class="fa fa-search" aria-hidden="true"></i> 查询</button><a class="btn btn-default" href="{{ route('finance.report', ['report' => $report, 'view' => 'transactions', 'channel' => $tab]) }}">重置</a><button type="button" class="btn btn-default" id="settlement-add-condition">添加筛选条件</button><button type="button" data-finance-download  class="btn btn-default" ><i class="fa fa-download" aria-hidden="true"></i> 导出 Excel</button><span @class(['finance-report-status', 'is-locked' => $isLocked])>{{ $isLocked ? '已锁定' : '未锁定' }}</span></div>
</form></div></div>
@if($tab === 'credit_card')
    <p class="finance-report-help">信用卡数据来自「信用卡结算明细」导入记录。
        @if($creditMonths)
            已导入月份：
            @foreach($creditMonths as $month)
                <a href="{{ route('finance.report', ['report' => $report, ...$filters, 'view' => 'transactions', 'month' => $month, 'channel' => $tab, 'download' => null, 'page' => null]) }}">{{ $month }}</a>{{ $loop->last ? '。' : '、' }}
            @endforeach
        @else
            当前账户暂无导入记录。
        @endif
    </p>
@endif
<p class="finance-report-help">按币种、区域和订阅类型汇总。结算相关金额以美元计，物流和采购成本以人民币计。物流、采购未录入时，匹配到的物流订单按商品重量估算运费，按 SKU 指导价估算采购成本。缺少匹配或成本资料时留空。</p>
<div class="box">
    <ul class="nav nav-tabs" aria-label="交易汇总通道">
        @foreach($summaryTabs as $key => $label)
            <li @class(['active' => $tab === $key])><a href="{{ $tabUrls[$key] }}" @if($tab === $key) aria-current="page" @endif>{{ $label }}</a></li>
        @endforeach
    </ul>
    <div class="box-body finance-table-wrap">
        <table class="table finance-table summary-grid">
            <thead><tr><th class="finance-selection"><input type="checkbox" data-export-all aria-label="全选当前页"></th>@foreach($columns as $label)<th scope="col">{{ $label }}</th>@endforeach</tr></thead>
            <tbody>
                @forelse($items as $item)
                    <tr><td class="finance-selection"><input type="checkbox" data-export-row value="{{ \App\Portals\Finance\FinanceExportSelection::key($item) }}" aria-label="选择此行"></td>@foreach($columns as $field => $label)
                        <td>{{ ($item[$field] ?? null) === null ? '' : ($loop->index < 6 ? $item[$field] : \App\Portals\Finance\FinanceCatalog::reportAmount($item[$field])) }}</td>
                    @endforeach</tr>
                @empty
                    <tr><td colspan="{{ count($columns) + 1 }}">无符合条件的记录</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="box-body">@include('portals.finance.pagination', ['paginator' => $paginator])</div>
</div>
</div>
@endsection
