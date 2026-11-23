@php
    $isOverview = ($preparationPlan['background'] ?? '') === 'overview';
@endphp
<div class="box"><div class="box-body" style="padding:24px">
    <p role="status" data-finance-source-progress>{{ $preparationPlan['message'] ?? '正在处理所选日期，请稍候…' }}</p>
    @if(! in_array($preparationPlan['state'] ?? '', ['failed', 'unavailable'], true))
        <p class="text-muted" data-finance-source-detail>{{ $isOverview ? '所选月份全部计算完成后，页面会自动显示结果。' : '已完成的日期段会复用，无需重复提交。' }}</p>
    @endif
    <button type="button" class="btn btn-default" data-finance-source-retry hidden>重试</button>
    <script type="application/json" data-finance-source-plan>@json($preparationPlan)</script>
</div></div>
