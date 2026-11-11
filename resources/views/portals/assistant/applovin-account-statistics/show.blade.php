@extends('portal.layout')
@section('page_title','AppLovin 账号详情')
@push('styles')
<style>
.aad-box{border:0;border-radius:11px;box-shadow:0 5px 18px rgba(31,67,98,.09);overflow:hidden}.aad-summary{display:flex;gap:12px;margin-bottom:15px;flex-wrap:wrap}.aad-card{min-width:210px;flex:1;padding:17px 20px;background:#fff;border-left:4px solid #24a26a;border-radius:9px;box-shadow:0 4px 14px rgba(31,67,98,.08)}.aad-card span{display:block;color:#74899a;font-size:12px}.aad-card strong{display:block;margin-top:5px;font-size:21px;color:#183f5a}.aad-table th{background:#e9f4fb!important;color:#285675}.aad-table td,.aad-table th{vertical-align:middle!important}.aad-spend{font-weight:700;color:#d26b20}.aad-source{display:inline-block;padding:3px 8px;background:#eef5fa;border-radius:10px;color:#4b718a;font-size:12px}.aad-pagination{padding:12px 16px;text-align:center;background:#f8fbfd}.aad-pagination .pagination{margin:0}
</style>
@endpush
@section('content')
<section class="content-header"><h1>AppLovin 账号详情 <small>{{$department}} · {{$operatorName}}</small></h1><div style="margin-top:10px"><a class="btn btn-default btn-sm" href="{{route('assistant.applovin-account-statistics.index')}}"><i class="fa fa-arrow-left"></i> 返回统计列表</a></div></section>
<section class="content">
<div class="aad-summary"><div class="aad-card"><span>运营团队 / 运营名称</span><strong>{{$department}} · {{$operatorName}}</strong></div><div class="aad-card"><span>AppLovin 户数</span><strong>{{$accounts->total()}} 户</strong></div><div class="aad-card"><span>账号累计消耗</span><strong>${{number_format($totalSpend,2)}}</strong></div></div>
<div class="box aad-box"><div class="table-responsive"><table class="table table-hover table-bordered aad-table"><thead><tr><th>数据来源</th><th>广告代理</th><th>账号名称</th><th>账号编号</th><th>绑定店铺域名</th><th>累计消耗($)</th><th>首次提报</th><th>最近提报</th></tr></thead><tbody>
@forelse($accounts as $account)<tr><td><span class="aad-source">{{$account['sources']}}</span></td><td>{{$account['agency']?:'—'}}</td><td><b>{{$account['account_name']?:'—'}}</b></td><td>{{$account['account_number']?:'—'}}</td><td>{{$account['store_domain']?:'未绑定'}}</td><td class="aad-spend">{{number_format($account['total_spend'],2)}}</td><td>{{$account['first_date']}}</td><td>{{$account['last_date']}}</td></tr>
@empty<tr><td colspan="8" class="text-center text-muted" style="padding:36px">暂无账号明细</td></tr>@endforelse
</tbody></table></div>@if($accounts->hasPages())<div class="aad-pagination">{{$accounts->links()}}</div>@endif</div>
</section>
@endsection
