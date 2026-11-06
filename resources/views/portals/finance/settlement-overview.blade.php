@extends('portals.finance.layout', ['title' => __('finance.menus.'.$report)])
@push('styles')
<style>
.settlement-overview{--line:#e2eaf1;--muted:#8092a4;--ink:#31516b}.settlement-overview .box{border:1px solid var(--line);border-top:0;border-radius:8px;box-shadow:0 2px 5px #183e5d04;overflow:hidden}.settlement-overview .box-body{padding:20px}.settlement-overview .finance-tools{display:flex;gap:4px;margin:0 0 18px;border-bottom:1px solid #d7e3ed}.settlement-overview .finance-tools .btn{border:0;border-radius:0;background:transparent;padding:12px 20px;color:#7b8fa1;border-bottom:3px solid transparent}.settlement-overview .finance-tools .is-active{color:#258bc7;border-bottom-color:#258bc7;font-weight:600}.settlement-overview form{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));align-items:end;gap:16px 20px}.settlement-overview form>div{min-width:0}.settlement-overview label{display:block;font-weight:400;color:var(--ink);font-size:12px;margin-bottom:7px}.settlement-overview .form-control{width:100%;height:36px;border:1px solid #dce6ef;border-radius:5px;background:#f8fafc;box-shadow:none}.overview-actions{grid-column:1/-1;display:flex;gap:8px;align-items:center;padding-top:14px;border-top:1px solid var(--line)}.overview-actions .btn{height:34px;padding:6px 18px;border-radius:5px}.overview-rate{display:flex;gap:8px;align-items:center;margin:16px 0 0;font-size:12px;color:var(--muted)}.overview-rate.is-missing{color:#af7e31}.overview-stats{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:16px;margin-bottom:20px}.overview-stat{padding:17px 20px;background:#fff;border:1px solid var(--line);border-radius:8px}.overview-stat span{display:block;font-size:12px;color:var(--muted);margin-bottom:9px}.overview-stat strong{font-size:24px;color:var(--ink);font-weight:600;font-variant-numeric:tabular-nums}.overview-stat small{color:var(--muted);font-size:11px;margin-left:5px}.loss-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:18px;align-items:start}.loss-grid .box{margin:0}.loss-card-head{padding:17px 20px;display:flex;align-items:center;gap:12px;border-bottom:1px solid var(--line);background:#fff}.loss-card-head h3{font-size:15px;font-weight:600;color:var(--ink);line-height:1.6;margin:0;overflow-wrap:anywhere}.loss-channel{flex-shrink:0;margin-left:auto;padding:3px 9px;border-radius:12px;font-size:11px;color:#2e86b9;background:#eef6fc}.loss-card-meta{display:flex;gap:20px;flex-wrap:wrap;font-size:11px;color:var(--muted);padding:11px 20px;background:#fafcfe;border-bottom:1px solid var(--line)}.loss-card-meta strong{color:#526e85;font-weight:500;margin-left:4px}.settlement-overview .loss-card .box-body{padding:0}.settlement-overview table{width:100%;margin:0;min-width:600px}.settlement-overview .loss-card table{min-width:0;table-layout:fixed}.settlement-overview th,.settlement-overview td{padding:11px 20px!important;border:0!important;border-bottom:1px solid #edf2f6!important;vertical-align:middle!important;font-variant-numeric:tabular-nums}.settlement-overview th{background:#f5f9fc;color:#71889d;font-size:12px;font-weight:500}.settlement-overview th:first-child,.settlement-overview td:first-child{text-align:left}.settlement-overview th:not(:first-child),.settlement-overview td:not(:first-child){text-align:right}.settlement-overview .loss-card td{min-width:0;max-width:none;font-size:13px;color:#526e85}.settlement-overview .loss-card tbody tr:hover{background:#f8fbfe}.settlement-overview .loss-card th:last-child{width:38%}.settlement-overview .loss-total td{background:#eef6fc;color:#206c9c;font-weight:600;padding-top:14px!important;padding-bottom:14px!important;border-bottom:0!important}.settlement-overview .pending{color:#b08b53!important}.overview-empty{grid-column:1/-1;text-align:center;padding:60px 20px!important;color:var(--muted)}.overview-method{margin-top:20px;padding:14px 18px;border:1px solid var(--line);border-radius:6px;font-size:12px;color:var(--muted)}.overview-method summary{cursor:pointer;color:#6d859b}.overview-method p{margin:12px 0 0;line-height:1.9}@media(min-width:1800px){.loss-grid{grid-template-columns:repeat(3,minmax(0,1fr))}}@media(max-width:1100px){.settlement-overview form{grid-template-columns:repeat(3,minmax(0,1fr))}.loss-grid{grid-template-columns:1fr}}@media(max-width:700px){.settlement-overview form{grid-template-columns:repeat(2,minmax(0,1fr))}.overview-stats{grid-template-columns:repeat(2,minmax(0,1fr))}.overview-stat strong{font-size:20px}.settlement-overview .finance-tools .btn{padding:10px 12px}.loss-card-meta{gap:10px}.settlement-overview .box-body{padding:16px}}@media(max-width:420px){.settlement-overview form{grid-template-columns:1fr}.overview-actions{flex-wrap:wrap}}
.overview-summary .finance-tools { margin-bottom: 10px; }
.overview-summary .finance-tools .btn { padding-top: 8px; padding-bottom: 8px; }
.overview-summary .box { margin-bottom: 12px; }
.overview-summary .box-body { padding: 12px 14px; }
.overview-summary form { gap: 8px 12px; }
.overview-summary label { margin-bottom: 4px; }
.overview-summary .form-control,
.overview-summary .overview-actions .btn { height: 32px; }
.overview-summary .overview-actions { padding-top: 8px; flex-wrap: wrap; }
.overview-summary .overview-rate { margin: 8px 0 0; }
.overview-summary .overview-method { margin-top: 12px; }
.overview-results-head { display: flex; align-items: baseline; gap: 10px; padding: 10px 14px; }
.overview-results-head h2 { margin: 0; color: var(--ink); font-size: 15px; font-weight: 600; }
.overview-results-head span { color: var(--muted); font-size: 12px; }
.overview-summary .finance-table-wrap { padding: 0; overflow-x: auto; }
.overview-summary .finance-table th,
.overview-summary .finance-table td { padding: 8px 14px !important; }
.overview-summary .finance-table th:nth-child(2),
.overview-summary .finance-table td:nth-child(2) { text-align: center; }
.overview-summary .finance-table tbody tr:nth-child(even) { background: #f7fafc; }
.overview-summary .finance-table tbody tr:hover { background: #edf5fb; }
.overview-summary .finance-table tbody td:last-child { color: #206c9c; font-weight: 600; }
@media (min-width: 1500px) {
    .overview-summary form { grid-template-columns: repeat(7, minmax(0, 1fr)); }
}

.loss-grid{grid-template-columns:repeat(3,minmax(0,1fr));gap:12px;align-items:stretch}
.loss-card{display:flex;flex-direction:column}
.loss-card-head{padding:10px 12px;min-height:44px}.loss-card-head h3{font-size:13px;line-height:1.4}
.loss-card-meta{padding:7px 12px;gap:5px 12px;min-height:34px}
.settlement-overview .loss-card th,.settlement-overview .loss-card td{padding:5px 12px!important;font-size:12px;line-height:18px;height:29px}
.settlement-overview .loss-card .loss-total td{padding:7px 12px!important;height:34px}
.settlement-overview .loss-card th:last-child{width:32%}
.loss-channel{font-size:10px;padding:2px 7px}
@media(min-width:1800px){.loss-grid{grid-template-columns:repeat(4,minmax(0,1fr))}}
@media(max-width:1250px){.loss-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media(max-width:750px){.loss-grid{grid-template-columns:1fr}}
.loss-comparison{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px;align-items:start}.loss-month-panel{margin:0!important}.loss-month-scroll{overflow:auto}.settlement-overview .loss-month-panel table{min-width:420px;table-layout:fixed}.settlement-overview .loss-month-panel th,.settlement-overview .loss-month-panel td{padding:5px 10px!important;font-size:12px;line-height:18px}.loss-month-panel th:first-child{width:200px}.loss-month-panel thead th{background:#dce8f3;font-weight:600}.settlement-overview .loss-month-panel th:not(:first-child),.settlement-overview .loss-month-panel td:not(:first-child){text-align:center}.loss-month-panel tbody tr:hover{background:#f5f9fc}.overview-stats{gap:10px;margin-bottom:12px}.overview-stat{padding:10px 14px}.overview-stat span{margin-bottom:3px}.overview-stat strong{font-size:20px}@media(max-width:1100px){.loss-comparison{grid-template-columns:1fr}}
.loss-browser{background:#fff;border:1px solid var(--line);border-radius:6px;padding:0 14px 14px}.loss-scope-tabs,.loss-category-tabs{display:flex;gap:6px;flex-wrap:wrap}.loss-scope-tabs{border-bottom:1px solid var(--line);padding:0 0 0;margin-bottom:12px}.loss-scope-tabs a{padding:12px 16px;color:var(--ink);border-bottom:3px solid transparent}.loss-scope-tabs a.is-active{color:#187ab5;border-bottom-color:#187ab5;font-weight:600}.loss-category-tabs a{padding:5px 12px;color:#526e85;background:#f3f6f9;border:1px solid var(--line);border-radius:4px;font-size:12px}.loss-category-tabs a.is-active{color:#fff;background:#257fae;border-color:#257fae}.loss-selection{margin:14px 0 8px;color:var(--ink);font-weight:600;font-size:13px}.loss-selection span{margin-left:12px;color:var(--muted);font-size:12px;font-weight:400}.loss-browser .loss-comparison{display:block;max-width:900px}.loss-browser .loss-month-panel{border-radius:3px;border-top:1px solid var(--line)}
.loss-month-panel th:nth-child(2),.loss-month-panel td:nth-child(2){text-align:left!important}
</style>
@endpush
@section('finance_content')
<div @class(['settlement-overview', 'overview-summary' => $report === 'summary'])>
@if($report === 'summary')@include('portals.finance.partials.summary-tabs', ['activeTab' => 'settlement'])@endif
<div class="box"><div class="box-body"><form method="get" action="{{ route('finance.report', $report) }}">@if($report === 'summary')<input type="hidden" name="view" value="settlement">@endif
@if($report === 'losses')<input type="hidden" name="loss_scope" value="{{ request('loss_scope', 'Shopify') }}"><input type="hidden" name="loss_category" value="{{ request('loss_category', '总计') }}">@endif
@if($report === 'losses')
<div><label for="loss-month-from">开始月份（北京时间）</label><input id="loss-month-from" type="month" name="month_from" value="{{ $filters['month_from'] }}" max="{{ $filters['month_to'] }}" class="form-control" required></div>
<div><label for="loss-month-to">结束月份（北京时间）</label><input id="loss-month-to" type="month" name="month_to" value="{{ $filters['month_to'] }}" min="{{ $filters['month_from'] }}" class="form-control" required></div>
@else
<div><label>月份（北京时间）</label><input type="month" name="month" value="{{ $filters['month'] }}" class="form-control" required></div>
@endif
<div><label>支付通道</label><select name="channel" class="form-control"><option value="">全部</option><option value="paypal" @selected(($filters['channel'] ?? '') === 'paypal')>PayPal</option><option value="credit_card" @selected(($filters['channel'] ?? '') === 'credit_card')>信用卡</option></select></div>
<div><label>汇总维度</label><select name="layer" class="form-control">@foreach(['business'=>'Shopify / PayPal / 信用卡','classification'=>'支付通道 / 订阅分类','project_classification'=>'Shopify / 订阅分类','payment_method'=>'支付方式分类','project'=>'项目合计','channel'=>'支付通道汇总','account'=>'账户汇总','team'=>'团队汇总','subscription'=>'订阅类型汇总','detail'=>'账户 / 团队 / 订阅类型'] as $layer=>$label)<option value="{{ $layer }}" @selected(($filters['layer'] ?? ($report === 'losses' ? 'business' : 'account')) === $layer)>{{ $label }}</option>@endforeach</select></div>
@foreach(['account' => '支付账户', 'team' => '团队', 'owner' => '使用人', 'subscription' => '订阅类型', 'payment_method' => '支付方式'] as $field => $label)<div><label for="overview-{{ $field }}">{{ $label }}</label><input id="overview-{{ $field }}" name="{{ $field }}" value="{{ $filters[$field] ?? '' }}" list="overview-options-{{ $field }}" class="form-control" placeholder="输入或选择"><datalist id="overview-options-{{ $field }}">@foreach($filterOptions[$field] as $option)<option value="{{ $option['value'] }}">{{ $option['label'] }}</option>@endforeach</datalist></div>@endforeach
<div class="overview-actions"><button class="btn btn-primary"><i class="fa fa-search" aria-hidden="true"></i> 查询</button><a class="btn btn-default" href="{{ route('finance.report', ['report' => $report, 'month' => $filters['month'], ...($report === 'losses' ? ['month_from' => $filters['month_from'], 'month_to' => $filters['month_to']] : [])]) }}">重置</a><button type="button" data-finance-download   class="btn btn-default"><i class="fa fa-download" aria-hidden="true"></i> 导出 Excel</button></div>@if(($filters['channel'] ?? '') === 'credit_card')
    <p class="overview-rate">信用卡数据来自「信用卡结算明细」导入记录。
        @if($creditMonths)
            已导入月份：
            @foreach($creditMonths as $month)
                <a href="{{ route('finance.report', ['report' => $report, ...$filters, 'month' => $month]) }}">{{ $month }}</a>{{ $loop->last ? '。' : '、' }}
            @endforeach
        @else
            当前账户暂无导入记录。
        @endif
    </p>
@endif
</form>
<p class="overview-rate {{ $rate === null ? 'is-missing' : '' }}"><i class="fa fa-info-circle" aria-hidden="true"></i>{{ $rate === null ? '本月美元兑人民币汇率未配置，物流和采购损耗占比暂不能计算。' : '本月汇率：1 USD = '.\App\Portals\Finance\FinanceCatalog::reportAmount($rate).' CNY' }}</p>
</div></div>
@if($report === 'summary')
<div class="box"><header class="overview-results-head"><h2>汇总结果</h2><span>{{ count($groups) }} 组</span></header><div class="box-body finance-table-wrap"><table class="table finance-table"><thead><tr><th class="finance-selection"><input type="checkbox" data-export-all aria-label="全选当前页"></th><th>项目</th><th>支付通道</th><th>成功订单数</th><th>失败交易数</th><th>结算金额（美元）</th></tr></thead><tbody>@forelse($groups as $group)<tr><td class="finance-selection"><input type="checkbox" data-export-row value="{{ \App\Portals\Finance\FinanceExportSelection::key($group) }}" aria-label="选择此行"></td><td>{{ $group['name'] }}</td><td>{{ match($group['channel']) { 'paypal' => 'PayPal', 'combined' => '全部通道', default => '信用卡' } }}</td><td>{{ number_format($group['success_count']) }}</td><td>{{ number_format($group['failed_count']) }}</td><td>{{ $group['settlement'] === null ? '' : \App\Portals\Finance\FinanceCatalog::reportAmount($group['settlement']) }}</td></tr>@empty<tr><td colspan="6">无符合条件的记录</td></tr>@endforelse</tbody></table></div></div>
@else
@php
    $overallGroups = array_values(array_filter($groups, fn ($group) => $group['is_total'] ?? false));
    $overviewGroups = $overallGroups ?: $groups;
    $overviewSuccess = array_sum(array_column($overviewGroups, 'success_count'));
    $overviewFailed = array_sum(array_column($overviewGroups, 'failed_count'));
    $overviewSettlement = array_reduce($overviewGroups, fn ($sum, $group) => bcadd($sum, $group['settlement'] ?? '0', 8), '0');
@endphp
<div class="overview-stats"><div class="overview-stat"><span>当前分类</span><strong>{{ count($groups) }}</strong><small>组</small></div><div class="overview-stat"><span>成功订单</span><strong>{{ number_format($overviewSuccess) }}</strong><small>单</small></div><div class="overview-stat"><span>失败交易</span><strong>{{ number_format($overviewFailed) }}</strong><small>笔</small></div><div class="overview-stat"><span>结算金额 · USD</span><strong>{{ \App\Portals\Finance\FinanceCatalog::reportAmount($overviewSettlement) }}</strong></div></div>
@php
    $lossMetricKeys = array_values(array_unique(array_merge(...array_map(fn ($group) => array_keys($group['ratios']), $groups))));
@endphp
@php
    $comparison = $monthlyLossGroups ?? [$filters['month'] => $groups];
    $allPanels = collect($comparison)->flatten(1)->unique(fn ($group) => $group['name'].'|'.$group['channel'])->values();
    $panelScope = fn ($group) => $group['scope'] ?? (match($group['channel']) { 'paypal' => 'PayPal', 'combined' => 'Shopify', default => '信用卡' });
    $panelCategory = fn ($group) => $group['category'] ?? (explode(' / ', $group['name'], 2)[1] ?? '总计');
    $availableScopes = $allPanels->map($panelScope)->unique();
    $scopes = collect(['Shopify', 'PayPal', '信用卡'])->filter(fn ($scope) => $availableScopes->contains($scope))->merge($availableScopes)->unique()->values();
    if ($scopes->isEmpty()) { $scopes = collect(['Shopify']); }
    $selectedScope = in_array(request('loss_scope'), $scopes->all(), true) ? request('loss_scope') : ($allPanels->isEmpty() ? 'Shopify' : $panelScope($allPanels->first()));
    $scopePanels = $allPanels->filter(fn ($group) => $panelScope($group) === $selectedScope);
    $categoriesByScope = $scopes->mapWithKeys(function ($scope) use ($allPanels, $panelScope, $panelCategory) {
        $base = ['总计', '订阅', '无订阅', '加购', '订阅复购1次', '订阅复购2次'];
        if (in_array($scope, ['Shopify', 'PayPal'], true)) { $base[] = '刷单'; }
        $categories = collect($base)->merge($allPanels->filter(fn ($panel) => $panelScope($panel) === $scope)->map($panelCategory))
            ->reject(fn ($category) => $category === '未分类' || ($scope === '信用卡' && $category === '刷单'))->unique()->values();
        return [$scope => $categories];
    });
    $categories = $categoriesByScope[$selectedScope];

    $selectedCategory = in_array(request('loss_category'), $categories->all(), true) ? request('loss_category') : ($categories->first() ?? '总计');
    $panels = $scopePanels->filter(fn ($group) => $panelCategory($group) === $selectedCategory);
    $switchFilters = request()->except(['loss_scope', 'loss_category', 'download', 'page']);
@endphp
<div class="loss-browser" data-loss-browser>
<nav class="loss-scope-tabs" aria-label="损耗报表项目">
@foreach($scopes as $scope)<a href="{{ route('finance.report', ['report' => $report, ...$switchFilters, 'loss_scope' => $scope]) }}" @class(['is-active' => $scope === $selectedScope]) @if($scope === $selectedScope) aria-current="page" @endif>{{ $scope }}</a>@endforeach
</nav>
<nav class="loss-category-tabs" aria-label="损耗报表分类">
@foreach($categories as $category)<a href="{{ route('finance.report', ['report' => $report, ...$switchFilters, 'loss_scope' => $selectedScope, 'loss_category' => $category]) }}" @class(['is-active' => $category === $selectedCategory]) @if($category === $selectedCategory) aria-current="page" @endif>{{ $category }}</a>@endforeach
</nav>
<p class="loss-selection">{{ $selectedScope }} / {{ $selectedCategory }}<span>月度对照 · {{ array_key_first($comparison) }} 至 {{ array_key_last($comparison) }}</span></p>
<div class="loss-comparison">
@forelse($panels as $panel)
<div class="box loss-month-panel">@include('portals.finance.partials.loss-month-table')</div>
@empty<div class="box overview-empty"><p>当前分类暂无数据</p><small>可切换分类或调整月份与筛选条件</small></div>@endforelse
</div>
@php
    $switchPanels = $allPanels->map(fn ($panel, $index) => ['scope' => $panelScope($panel), 'category' => $panelCategory($panel), 'template' => 'loss-table-'.$index]);
@endphp
<script type="application/json" data-loss-categories>@json($categoriesByScope)</script>
<script type="application/json" data-loss-panels>@json($switchPanels)</script>
@foreach($allPanels as $index => $panel)<template id="loss-table-{{ $index }}">@include('portals.finance.partials.loss-month-table')</template>@endforeach
</div>@endif
<details class="overview-method"><summary>统计口径</summary><p>月度对照按所选开始月份至结束月份展示，包含起止月份。PayPal 中团队或使用人包含“刷单”的记录归入 Shopify、PayPal 的“刷单”分类，并计入总计；信用卡不设置刷单分类。缺少订阅类型的记录只计入总计，不推断为无订阅；Shopify 为 PayPal 与信用卡合计，通道分项不再重复计入顶部汇总。无结算基数或缺少资料时显示“—”。结算和费用按美元计算，物流、采购先按本月汇率换算美元，再除以结算金额。PayPal手续费扣除返还手续费，争议冻结退款计入争议退款与冻结、扣除解除冻结。按明细表的SUM口径汇总，空白费用不参与加总；缺少汇率只影响物流和采购，结算金额为零时不计算占比。信用卡保留失败记录的单笔费用。ERP订单、团队和订阅数据尚未匹配时不自动推断。物流、采购未录入时，匹配到的物流订单按商品重量估算运费，按 SKU 指导价估算采购成本。缺少匹配或成本资料时留空。</p></details></div>
@endsection

@push('scripts')
@if($report === 'losses')<script src="{{ asset('assets/erp/finance-loss-switcher.js') }}?v=3"></script>@endif
@endpush
