@extends('portals.finance.layout', ['title' => __('finance.menus.accounting')])
@push('styles')
<style>
.finance-accounting-fold{margin-bottom:20px;border:1px solid #dbe5ec;border-radius:6px;background:#fff}.finance-accounting-fold>summary{padding:15px 18px;color:#3b5870;font-size:15px;cursor:pointer}.finance-accounting-fold>summary small{margin-left:8px;color:#718398;font-size:12px}.finance-accounting-fold>.box-body{padding:0 18px 18px}.finance-accounting-fold .finance-rule{padding:12px 0;border-top:1px solid #e5edf3}.finance-accounting-fold .finance-rule>summary{cursor:pointer;margin-bottom:12px}.finance-accounting-fold .finance-filter{display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:12px}.finance-accounting-fold .finance-filter .form-group{margin:0}.finance-accounting-fold .finance-filter .form-control{width:100%}.finance-close-actions{display:flex;gap:8px;flex-wrap:wrap}.finance-accounting-table{overflow-x:auto}.finance-accounting-help{margin:0 0 16px;color:#718398;font-size:13px}
</style>
@endpush
@section('finance_content')
<div class="box"><div class="box-header with-border"><h3 class="box-title">月度人民币汇率</h3></div><div class="box-body">
<p class="finance-accounting-help">填写 1 单位原币折合的人民币金额，用于当月报表。已结账月份不可修改。</p>
@php
    $rateCurrencies = collect(['USD','HKD','EUR','GBP','AUD','CAD','JPY','SGD','CNY'])
        ->merge($rateCurrencies)->merge($rates->pluck('currency'))->push(old('currency', 'USD'))->unique()->sort()->values();
    $rateData = $rates->map(fn($rate) => \Illuminate\Support\Arr::only((array)$rate, ['month','currency','cny_rate','source']))->values();
@endphp
<form method="post" action="{{ route('finance.accounting.save', 'rate') }}" class="finance-filter" id="finance-monthly-rate">@csrf
    <div class="form-group"><label>会计月份</label><input type="month" name="month" class="form-control" value="{{ old('month', now('Asia/Shanghai')->format('Y-m')) }}" required></div>
    <div class="form-group"><label for="monthly-rate-currency">原币币种</label><select id="monthly-rate-currency" name="currency" class="form-control" required>@foreach($rateCurrencies as $currency)<option value="{{ $currency }}" @selected(old('currency', 'USD') === $currency)>{{ $currency }}</option>@endforeach</select></div>
    <div class="form-group"><label for="monthly-rate-value">1 <span id="monthly-rate-unit">{{ old('currency', 'USD') }}</span> 折合人民币（CNY）</label><input id="monthly-rate-value" name="cny_rate" class="form-control" type="number" step="0.00000001" min="0.00000001" value="{{ old('cny_rate') }}" required></div>
    <div class="form-group"><label>来源说明（选填）</label><input name="source" class="form-control" maxlength="255" value="{{ old('source') }}" placeholder="如：财务确认汇率"></div>
    <div class="form-group"><button class="btn btn-primary" id="monthly-rate-save">保存汇率</button></div>
</form>
<p class="finance-accounting-help" id="monthly-rate-status" aria-live="polite"></p>
<div class="finance-accounting-table">
<table class="table finance-table"><thead><tr><th>月份</th><th>币种</th><th>人民币汇率</th><th>来源</th><th>更新人 / 更新时间</th></tr></thead><tbody>@forelse($rates as $rate)<tr><td>{{ \App\Portals\Finance\FinanceCatalog::simplified((string) ($rate->month)) }}</td><td>{{ \App\Portals\Finance\FinanceCatalog::simplified((string) ($rate->currency)) }}</td><td>{{ \App\Portals\Finance\FinanceCatalog::simplified((string) ($rate->cny_rate)) }}</td><td>{{ \App\Portals\Finance\FinanceCatalog::simplified((string) ($rate->source)) }}</td><td>{{ $rate->updated_by }} / {{ $rate->updated_at }}</td></tr>@empty<tr><td colspan="5">尚未配置月度汇率</td></tr>@endforelse</tbody></table>
</div></div></div>
<div class="box"><div class="box-header with-border"><h3 class="box-title">信用卡美元折算汇率</h3></div><div class="box-body">
<p class="finance-accounting-help">用于 ConfluxAPI 明细的「结算-美元」，按北京时间交易日匹配。单日优先，其次日期区间，再按整月。区间重叠时优先选开始较晚的区间，开始相同则选结束较早的区间。日期区间包含首尾两天。</p>
<p class="finance-accounting-help">未手动填写时，系统使用欧洲央行当天公布的参考汇率。需要更正时，可在这里填写汇率覆盖自动取值。当天没有参考汇率时，明细显示「汇率未填写」。</p>
@php($usdScopes = \App\Portals\Finance\FinanceCreditUsdRates::SCOPES)
<form method="post" action="{{ route('finance.accounting.save', 'credit-usd-rate') }}" class="finance-filter" id="finance-credit-usd-rate">@csrf
    <div class="form-group"><label for="credit-usd-scope">适用范围</label><select id="credit-usd-scope" name="usd_scope" class="form-control">@foreach($usdScopes as $value => $label)<option value="{{ $value }}" @selected(old('usd_scope', 'day') === $value)>{{ $label }}</option>@endforeach</select></div>
    <div class="form-group" data-usd-scopes="day range"><label for="credit-usd-start">开始日期</label><input id="credit-usd-start" type="date" name="usd_start" class="form-control" value="{{ old('usd_start', now('Asia/Shanghai')->toDateString()) }}"></div>
    <div class="form-group" data-usd-scopes="range"><label for="credit-usd-end">结束日期</label><input id="credit-usd-end" type="date" name="usd_end" class="form-control" value="{{ old('usd_end') }}"></div>
    <div class="form-group" data-usd-scopes="month"><label for="credit-usd-month">适用月份</label><input id="credit-usd-month" type="month" name="usd_month" class="form-control" value="{{ old('usd_month', now('Asia/Shanghai')->format('Y-m')) }}"></div>
    <div class="form-group"><label for="credit-usd-currency">原币币种</label><input id="credit-usd-currency" name="usd_currency" list="credit-usd-currencies" class="form-control" required maxlength="3" placeholder="如 GBP" value="{{ old('usd_currency') }}"><datalist id="credit-usd-currencies">@foreach($rateCurrencies->reject(fn ($currency) => $currency === 'USD') as $currency)<option value="{{ $currency }}"></option>@endforeach</datalist></div>
    <div class="form-group"><label for="credit-usd-value">1 单位原币折合美元</label><input id="credit-usd-value" name="usd_rate" class="form-control" type="number" step="0.00000001" min="0.00000001" value="{{ old('usd_rate') }}" required></div>
    <div class="form-group"><button class="btn btn-primary">保存美元汇率</button></div>
</form>
<p class="finance-accounting-help">相同币种和适用范围可重新保存以更正汇率。涉及已结账月份的汇率不可修改。</p>
<div class="finance-accounting-table">
<table class="table finance-table"><thead><tr><th>币种</th><th>适用范围</th><th>适用日期</th><th>美元汇率</th><th>更新人 / 更新时间</th></tr></thead><tbody>
@forelse($creditUsdRates as $rate)
    <tr><td>{{ $rate->currency }}</td><td>{{ $usdScopes[$rate->scope] }}</td><td>{{ $rate->starts_on }}@if($rate->ends_on !== $rate->starts_on) 至 {{ $rate->ends_on }}@endif</td><td>{{ $rate->usd_rate }}</td><td>{{ $rate->updated_by }} / {{ $rate->updated_at }}</td></tr>
@empty
    <tr><td colspan="5">尚未配置信用卡美元折算汇率</td></tr>
@endforelse
</tbody></table>
</div></div></div>
<div class="box"><div class="box-header with-border"><h3 class="box-title">月度结账</h3></div><div class="box-body">
<p class="finance-accounting-help">更新本月数据会重新应用分类与汇率。确认结账将锁定该月，之后不可修改或补录交易。</p>
<form method="post" action="{{ route('finance.accounting.save', 'reapply') }}" class="finance-filter" id="finance-close-period">@csrf
    <div class="form-group"><label for="finance-close-month">会计月份</label><input id="finance-close-month" type="month" name="month" class="form-control" required value="{{ old('month', now('Asia/Shanghai')->subMonthNoOverflow()->format('Y-m')) }}"></div>
    <div class="form-group finance-close-actions"><button class="btn btn-primary">更新本月数据</button><button class="btn btn-default" formaction="{{ route('finance.accounting.save', 'lock') }}" data-lock-period>确认结账</button></div>
</form>
<p class="finance-accounting-help">仅可结账已结束的月份；未分类交易或缺少汇率时，系统会提示补齐。</p>
</div></div>
<details class="finance-accounting-fold" id="finance-advanced-rules"><summary>高级分类规则 <small>特殊分类时使用</small></summary><div class="box-body">
<p class="finance-accounting-help">日常分类请在 <a href="{{ route('finance.settings', 'types') }}">PayPal类型分类</a> 维护。这里的规则优先匹配，数字越小越优先；修改后请更新对应月份的数据。</p>
@foreach($rules->prepend(null) as $rule)
<details class="finance-rule" @if(!$rule) open @endif><summary>{{ \App\Portals\Finance\FinanceCatalog::simplified((string) ($rule ? $rule->name : '添加规则')) }}</summary>
<form method="post" action="{{ route('finance.accounting.save', 'rule') }}" class="finance-filter">@csrf
    @if($rule)<input type="hidden" name="id" value="{{ $rule->id }}">@endif
    <div class="form-group"><label>规则名称</label><input name="name" class="form-control" maxlength="100" required value="{{ $rule->name ?? '' }}"></div>
    <div class="form-group"><label>来源字段</label><select name="source_field" class="form-control">@foreach(\App\Portals\Finance\FinanceAccountingService::SOURCES as $source)<option value="{{ $source }}" @selected(($rule->source_field ?? '') === $source)>{{ __('finance.accounting.sources.'.$source) }}</option>@endforeach</select></div>
    <div class="form-group"><label>匹配方式</label><select name="match_type" class="form-control">@foreach(['equals' => '等于', 'contains' => '包含', 'prefix' => '前缀'] as $type => $label)<option value="{{ $type }}" @selected(($rule->match_type ?? '') === $type)>{{ \App\Portals\Finance\FinanceCatalog::simplified((string) ($label)) }}</option>@endforeach</select></div>
    <div class="form-group"><label>原始值</label><input name="match_value" class="form-control" maxlength="191" required value="{{ $rule->match_value ?? '' }}"></div>
    <div class="form-group"><label>财务分类</label><input name="category" class="form-control" maxlength="100" required value="{{ $rule->category ?? '' }}"></div>
    <div class="form-group"><label>收支方向</label><select name="direction" class="form-control">@foreach(['income' => '收入', 'expense' => '支出', 'neutral' => '不影响余额'] as $type => $label)<option value="{{ $type }}" @selected(($rule->direction ?? '') === $type)>{{ \App\Portals\Finance\FinanceCatalog::simplified((string) ($label)) }}</option>@endforeach</select></div>
    <div class="form-group"><label>损耗科目</label><select name="loss_subject" class="form-control"><option value="">不归入损耗</option>@foreach(\App\Portals\Finance\FinanceAccountingService::SUBJECTS as $subject)<option value="{{ $subject }}" @selected(($rule->loss_subject ?? '') === $subject)>{{ __('finance.accounting.subjects.'.$subject) }}</option>@endforeach</select></div>
    <div class="form-group"><label>优先级</label><input name="priority" type="number" min="0" max="100000" class="form-control" required value="{{ $rule->priority ?? 100 }}"></div>
    <div class="form-group"><label>状态</label><select name="is_enabled" class="form-control"><option value="1" @selected($rule->is_enabled ?? true)>启用</option><option value="0" @selected($rule && !$rule->is_enabled)>停用</option></select></div>
    <div class="form-group"><button class="btn btn-primary">{{ \App\Portals\Finance\FinanceCatalog::simplified((string) ($rule ? '保存修改' : '添加规则')) }}</button></div>
</form></details>
@endforeach
</div></details>
<details class="finance-accounting-fold"><summary>已结账记录 <small>{{ $locks->count() }} 个月</small></summary><div class="box-body finance-accounting-table">
<table class="table finance-table"><thead><tr><th>已结账月份</th><th>操作人</th><th>结账时间</th></tr></thead><tbody>@forelse($locks as $lock)<tr><td>{{ \App\Portals\Finance\FinanceCatalog::simplified((string) ($lock->month)) }}</td><td>{{ \App\Portals\Finance\FinanceCatalog::simplified((string) ($lock->locked_by)) }}</td><td>{{ \App\Portals\Finance\FinanceCatalog::simplified((string) ($lock->updated_at)) }}</td></tr>@empty<tr><td colspan="3">暂无已结账月份</td></tr>@endforelse</tbody></table>
</div></details>
@endsection
@push('scripts')<script>
(function(){
    const scope = document.getElementById('credit-usd-scope');
    const fields = scope.form.querySelectorAll('[data-usd-scopes]');
    function showPeriodFields() {
        fields.forEach(field => {
            const visible = field.dataset.usdScopes.split(' ').includes(scope.value);
            const input = field.querySelector('input');
            field.hidden = !visible;
            input.disabled = !visible;
            input.required = visible;
        });
    }
    scope.addEventListener('change', showPeriodFields);
    showPeriodFields();
})();
(function(){
    var form=document.getElementById('finance-monthly-rate');
    var rates=@json($rateData);
    var locks=@json($locks->pluck('month')->values());
    function fillRate(preserve){
        var currency=form.elements.currency.value,month=form.elements.month.value;
        var rate=rates.find(function(item){return item.month===month&&item.currency===currency;});
        var locked=locks.indexOf(month)!==-1;
        document.getElementById('monthly-rate-unit').textContent=currency;
        if(!preserve){form.elements.cny_rate.value=rate?rate.cny_rate:(currency==='CNY'?'1':'');form.elements.source.value=rate?(rate.source||''):'';}
        if(currency==='CNY')form.elements.cny_rate.value='1';
        form.elements.cny_rate.readOnly=currency==='CNY'||locked;
        form.elements.source.readOnly=locked;
        var button=document.getElementById('monthly-rate-save');
        button.textContent=locked?'该月已结账':(rate?'更新汇率':'保存汇率');
        button.disabled=locked;
        document.getElementById('monthly-rate-status').textContent=locked?'该月份已结账，不能修改汇率。':(rate?'已加载该月份和币种的汇率，修改后点击“更新汇率”。':'该月份和币种尚未配置汇率。');
    }
    form.elements.month.addEventListener('change',function(){fillRate(false);});
    form.elements.currency.addEventListener('change',function(){fillRate(false);});
    fillRate(@json(session()->hasOldInput('cny_rate')));
})();
document.getElementById('finance-close-period').addEventListener('submit',function(event){
    if(event.submitter&&event.submitter.hasAttribute('data-lock-period')&&!confirm('确认结账并锁定 '+this.elements.month.value+'？结账后不能修改汇率、分类或补录交易。'))event.preventDefault();
});
</script>@endpush
