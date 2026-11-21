@extends('portals.finance.layout')
@push('styles')<style>[data-finance-source-retry][hidden]{display:none!important}</style>@endpush
@section('finance_content')
<div class="box"><div class="box-body" style="padding:24px">
    <p role="status" data-finance-source-progress>正在计算，请稍候…</p>
    <p class="text-muted">@if(($preparationPlan['background'] ?? '') === 'overview')正在独立计算所选月份，完成后自动显示。本次结果会标注计算时间，重新查询可获取新结果。@else正在处理所选日期，完成后自动显示报表。已完成的日期段会复用，无需重复提交。@endif</p>
    <button type="button" class="btn btn-default" data-finance-source-retry hidden>重试</button>
    <script type="application/json" data-finance-source-plan>@json($preparationPlan)</script>
</div></div>
@endsection
