@extends('portal.layout')

@section('page_title', '异常SKU')

@push('styles')
<style>
.abnormal-summary{display:flex;gap:14px;flex-wrap:wrap;margin-bottom:16px}.abnormal-card{flex:1;min-width:260px;padding:16px 18px;border-radius:8px;background:#fff;border-left:4px solid #f39c12;box-shadow:0 1px 5px rgba(36,59,83,.08)}.abnormal-card.original{border-left-color:#dd4b39}.abnormal-card span{display:block;color:#74879a}.abnormal-card strong{font-size:26px;color:#243b53}.abnormal-table th{background:#edf4f8;color:#29435c;white-space:nowrap}.abnormal-table td{vertical-align:middle!important}.abnormal-sku{color:#c94b28;font-family:Consolas,monospace;word-break:break-all}.abnormal-pagination{display:flex;align-items:center;justify-content:space-between;padding:12px 15px;border-top:1px solid #edf1f4}.abnormal-pagination .btn{margin-left:5px}.missing-label{margin-right:4px}.abnormal-help{color:#78909c;margin:5px 0 0}.filter-card{padding:12px 15px;background:#fff;border-radius:7px;margin-bottom:16px}
</style>
@endpush

@section('content')
<section class="content-header"><h1>异常SKU <small>运营报表异常商品数据</small></h1></section>
<section class="content">
    <div class="filter-card">
        <form method="get" class="form-inline" action="{{ route('caigou.abnormal-skus.index') }}">
            <div class="form-group"><label>统计范围</label> <span class="form-control-static">截至当前时间的全部订单</span></div>
            <div class="form-group" style="margin-left:10px"><label>每页</label> <select class="form-control input-sm" name="per_page">@foreach([20,50,100] as $size)<option value="{{ $size }}" @selected($perPage===$size)>{{ $size }}条</option>@endforeach</select></div>
            <button class="btn btn-primary btn-sm" style="margin-left:8px"><i class="fa fa-search"></i> 查询</button>
            <a class="btn btn-default btn-sm" href="{{ route('caigou.abnormal-skus.index') }}">重置</a>
        </form>
    </div>

    <div class="abnormal-summary">
        <div class="abnormal-card"><span>指导价或重量缺失 SKU</span><strong>{{ $missing['total'] }}</strong></div>
        <div class="abnormal-card original"><span>全部订单原始 SKU 为空商品</span><strong>{{ $original['total'] }}</strong></div>
    </div>

    <div class="box box-solid">
        <div class="box-header with-border"><h3 class="box-title"><i class="fa fa-exclamation-circle text-orange"></i> 指导价或重量缺失 SKU</h3><p class="abnormal-help">与运营报表 Boss 账号的数据口径一致；资料补齐后会自动移出列表。</p></div>
        <div class="box-body no-padding table-responsive">
            <table class="table table-bordered table-hover abnormal-table"><thead><tr><th style="width:70px">序号</th><th style="width:270px">SKU</th><th>商品名称</th><th style="width:150px">缺失项目</th><th style="width:100px">出现次数</th><th style="width:100px">商品数量</th><th style="width:175px">最近下单时间</th></tr></thead><tbody>
            @forelse($missing['items'] as $item)
                <tr><td>{{ $missing['from'] + $loop->index }}</td><td class="abnormal-sku">{{ $item['sku'] }}</td><td>{{ $item['product_name'] }}</td><td>@foreach($item['missing_fields'] as $field)<span class="label label-warning missing-label">缺{{ $field }}</span>@endforeach</td><td>{{ $item['item_count'] }}</td><td>{{ rtrim(rtrim(number_format($item['quantity'],4,'.',''),'0'),'.') }}</td><td>{{ $item['latest_order_at'] ?: '—' }}</td></tr>
            @empty<tr><td colspan="7" class="text-center text-muted" style="padding:35px"><i class="fa fa-check-circle text-green"></i> 当前没有指导价或重量缺失的SKU</td></tr>@endforelse
            </tbody></table>
        </div>
        @include('portals.caigou.abnormal-skus.pagination', ['data'=>$missing, 'pageKey'=>'missing_page', 'otherPageKey'=>'original_page', 'otherPage'=>$original['page']])
    </div>

    <div class="box box-solid">
        <div class="box-header with-border"><h3 class="box-title"><i class="fa fa-ban text-red"></i> 原始 SKU 为空的商品</h3><p class="abnormal-help">统计截至当前时间的全部历史订单；数据不按运营人员隔离，按商品去重后展示。</p></div>
        <div class="box-body no-padding table-responsive">
            <table class="table table-bordered table-hover abnormal-table"><thead><tr><th style="width:70px">序号</th><th>商品名称</th><th style="width:260px">店铺域名</th><th style="width:100px">商品数量</th><th style="width:175px">下单时间</th><th style="width:100px">订单ID</th></tr></thead><tbody>
            @forelse($original['items'] as $item)
                <tr><td>{{ $original['from'] + $loop->index }}</td><td>{{ $item['product_name'] }}</td><td>{{ $item['shop_domain'] }}</td><td>{{ rtrim(rtrim(number_format($item['quantity'],4,'.',''),'0'),'.') }}</td><td>{{ $item['ordered_at'] }}</td><td><code>{{ $item['order_id'] }}</code></td></tr>
            @empty<tr><td colspan="6" class="text-center text-muted" style="padding:35px"><i class="fa fa-check-circle text-green"></i> 当天没有原始SKU为空的商品</td></tr>@endforelse
            </tbody></table>
        </div>
        @include('portals.caigou.abnormal-skus.pagination', ['data'=>$original, 'pageKey'=>'original_page', 'otherPageKey'=>'missing_page', 'otherPage'=>$missing['page']])
    </div>
</section>
@endsection
