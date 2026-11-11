@extends('portal.layout')
@section('page_title','运营账号统计')
@push('styles')
<style>
.aas-box{border:0;border-radius:11px;box-shadow:0 5px 18px rgba(31,67,98,.09);overflow:hidden}.aas-filter{display:flex;align-items:flex-end;gap:12px;flex-wrap:wrap}.aas-filter .form-group{margin:0}.aas-filter label{display:block;font-size:12px;color:#60788c;margin-bottom:5px}.aas-kpis{display:grid;grid-template-columns:repeat(3,minmax(180px,1fr));gap:12px;margin-bottom:15px}.aas-kpi{padding:17px 20px;border-left:4px solid #3498db;border-radius:9px;background:#fff;box-shadow:0 4px 14px rgba(31,67,98,.08)}.aas-kpi span{display:block;color:#72889a;font-size:12px}.aas-kpi strong{display:block;margin-top:5px;color:#163f5c;font-size:22px}.aas-table th{background:#e9f4fb!important;color:#285675}.aas-table td,.aas-table th{vertical-align:middle!important}.aas-count{display:inline-block;min-width:42px;padding:5px 11px;border-radius:15px;background:#e4f5ed;color:#168355;font-weight:700;text-align:center}.aas-spend{font-weight:700;color:#d26b20}.aas-pagination{padding:12px 16px;text-align:center;background:#f8fbfd}.aas-pagination .pagination{margin:0}@media(max-width:800px){.aas-kpis{grid-template-columns:1fr}}
</style>
@endpush
@section('content')
<section class="content-header"><h1>运营账号统计 <small>统计运营人员当前已有的 AppLovin 账号</small></h1></section>
<section class="content">
<div class="box aas-box"><div class="box-body"><form class="aas-filter" method="get">
<div class="form-group"><label>运营团队</label><select class="form-control" name="department_id"><option value="">全部团队</option>@foreach($departmentOptions as $department)<option value="{{$department->id}}" @selected((int)$filters['department_id']===(int)$department->id)>{{$department->name}}</option>@endforeach</select></div>
<div class="form-group"><label>运营名称</label><input class="form-control" name="operator" value="{{$filters['operator']}}" placeholder="输入运营姓名"></div>
<button class="btn btn-primary"><i class="fa fa-search"></i> 查询</button><a class="btn btn-default" href="{{route('assistant.applovin-account-statistics.index')}}">重置</a>
</form></div></div>
<div class="aas-kpis"><div class="aas-kpi"><span>运营人数</span><strong>{{$operators->total()}}</strong></div><div class="aas-kpi"><span>当前页账号数</span><strong>{{$operators->getCollection()->sum('account_count')}} 户</strong></div><div class="aas-kpi"><span>当前页累计消耗</span><strong>${{number_format($operators->getCollection()->sum('total_spend'),2)}}</strong></div></div>
<div class="box aas-box"><div class="table-responsive"><table class="table table-hover table-bordered aas-table"><thead><tr><th>运营团队</th><th>运营名称</th><th>AppLovin 户数</th><th>累计消耗($)</th><th>操作</th></tr></thead><tbody>
@forelse($operators as $row)<tr><td>{{$row['department']}}</td><td><b>{{$row['operator_name']}}</b></td><td><span class="aas-count">{{$row['account_count']}} 户</span></td><td class="aas-spend">{{number_format($row['total_spend'],2)}}</td><td><a class="btn btn-info btn-xs" href="{{route('assistant.applovin-account-statistics.show',$row['operator_user_id'])}}"><i class="fa fa-eye"></i> 查看详情</a></td></tr>
@empty<tr><td colspan="5" class="text-center text-muted" style="padding:36px">暂无 AppLovin 账号数据</td></tr>@endforelse
</tbody></table></div>@if($operators->hasPages())<div class="aas-pagination">{{$operators->links()}}</div>@endif</div>
</section>
@endsection
