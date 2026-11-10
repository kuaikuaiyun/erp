@extends('portal.layout')
@section('page_title', 'AppLovin账号消耗')
@push('styles')
<link rel="stylesheet" href="{{ asset('vendor/laravel-admin/AdminLTE/plugins/select2/select2.min.css') }}">
<style>
.review-box{border:0;border-radius:12px;box-shadow:0 5px 18px rgba(31,67,98,.09);overflow:hidden}.filters{display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap}.filters .form-group{margin:0}.spend-high{color:#d9534f;font-weight:700}.gift-thumb{width:70px;height:48px;object-fit:cover;border:1px solid #d6e2ea;border-radius:6px;cursor:zoom-in}.pager-wrap{display:flex;justify-content:center;gap:5px;padding:14px}.pager-wrap a,.pager-wrap span{min-width:34px;height:34px;line-height:34px;text-align:center;border:1px solid #d8e4ec;border-radius:7px}.pager-wrap .active{background:#3498db;color:#fff}.store-binding-form{display:flex;gap:6px;align-items:center}.store-binding-form .select2-container{min-width:250px}.select2-container .select2-selection--single{height:30px}.select2-container--default .select2-selection--single .select2-selection__rendered{line-height:28px}
</style>
@endpush
@section('content')
<section class="content-header"><h1>AppLovin账号消耗 <small>自动汇总已审核通过的运营广告费用</small></h1></section>
<section class="content">
@if(session('success'))<div class="alert alert-success"><i class="fa fa-check-circle"></i> {{ session('success') }}</div>@endif
<div class="box review-box"><div class="box-body"><form class="filters" method="get">
<div class="form-group"><label>运营部门</label><select class="form-control" name="department_id"><option value="">全部部门</option>@foreach($departmentOptions as $department)<option value="{{ $department->id }}" @selected((string)$selectedDepartmentId===(string)$department->id)>{{ $department->name }}</option>@endforeach</select></div>
<div class="form-group"><label>累计消耗</label><select class="form-control" name="over_threshold"><option value="">全部数据</option><option value="1" @selected($overThreshold==='1')>大于等于 $5,000</option></select></div>
<div class="form-group"><label>运营人员</label><input class="form-control" name="operator" value="{{ $operator }}"></div>
<button class="btn btn-primary"><i class="fa fa-search"></i> 查询</button><a class="btn btn-default" href="{{ $appLovinIndexUrl }}">重置</a>
</form></div></div>
<div class="box review-box"><div class="table-responsive"><table class="table table-bordered table-hover">
<thead><tr><th>运营部门/人员</th><th>代理账号</th><th>广告社媒</th><th>广告账户</th><th>账户编号</th><th style="min-width:330px">绑定店铺域名</th><th>累计实际消耗</th><th>是否有礼金</th></tr></thead>
<tbody>@forelse($accounts as $account)<tr>
<td>{{ $account['department'] }}<br><small>{{ $account['operator_name'] }}</small></td><td><b>{{ $account['agency'] }}</b></td><td>{{ $account['media'] ?: '—' }}</td><td>{{ $account['account_name'] }}</td><td>{{ $account['account_number'] }}</td>
<td>@if($portal->code==='zhuli')<form method="post" action="{{ route('assistant.applovin-spends.store-binding') }}" class="store-binding-form">@csrf<input type="hidden" name="account_key" value="{{ $account['account_key'] }}"><select class="form-control input-sm js-store-binding" name="store_id" data-placeholder="输入域名或店铺名搜索"><option value="">未绑定</option>@foreach($stores->filter(fn($store)=>in_array((int)$account['operator_user_id'],$store->eligible_owner_ids??[],true)) as $store)<option value="{{ $store->id }}" @selected((int)optional($account['store_binding'])->assistant_domain_store_id===(int)$store->id)>{{ $store->domain }}{{ $store->shop_name ? '（'.$store->shop_name.'）' : '' }}</option>@endforeach</select><button class="btn btn-primary btn-xs" type="submit">保存</button></form>@else @php($boundStore=$stores->firstWhere('id',(int)optional($account['store_binding'])->assistant_domain_store_id)) {{ $boundStore?->domain ?: '—' }} @endif</td>
<td class="{{ $account['total']>=$threshold?'spend-high':'' }}">${{ number_format($account['total'],2) }}</td><td>@if($account['total']>=$threshold && $account['uploaded_screenshot'])<a target="_blank" href="{{ route($appLovinScreenshotRoute,$account['uploaded_screenshot']) }}"><img class="gift-thumb" src="{{ route($appLovinScreenshotRoute,$account['uploaded_screenshot']) }}" alt="礼金图"></a>@elseif($account['total']>=$threshold)<span class="label label-warning">未上传</span>@else<span class="text-muted">—</span>@endif</td>
</tr>@empty<tr><td colspan="8" class="text-center text-muted" style="padding:30px">暂无已审核通过的 AppLovin 数据</td></tr>@endforelse</tbody></table></div>
@if($accounts->hasPages())<div class="pager-wrap">@if($accounts->onFirstPage())<span>‹</span>@else<a href="{{ $accounts->previousPageUrl() }}">‹</a>@endif @for($p=max(1,$accounts->currentPage()-2);$p<=min($accounts->lastPage(),$accounts->currentPage()+2);$p++)@if($p===$accounts->currentPage())<span class="active">{{ $p }}</span>@else<a href="{{ $accounts->url($p) }}">{{ $p }}</a>@endif @endfor @if($accounts->hasMorePages())<a href="{{ $accounts->nextPageUrl() }}">›</a>@else<span>›</span>@endif</div>@endif
</div></section>
@endsection
@push('scripts')
<script src="{{ asset('vendor/laravel-admin/AdminLTE/plugins/select2/select2.full.min.js') }}"></script>
<script>$(function(){$('.js-store-binding').select2({width:'100%',allowClear:true,placeholder:'输入域名或店铺名搜索',language:{noResults:function(){return '没有找到对应运营的店铺'},searching:function(){return '搜索中…'}}});});</script>
@endpush
