@extends('portal.layout')
@include('portals.assistant.daily-submissions.partials.template-assets')
@section('page_title', 'AppLovin账号消耗')
@push('styles')
<link rel="stylesheet" href="{{ asset('vendor/laravel-admin/AdminLTE/plugins/select2/select2.min.css') }}">
<style>
.review-box{border:0;border-radius:12px;box-shadow:0 5px 18px rgba(31,67,98,.09);overflow:hidden}.review-filter-box{overflow:visible}.filters{display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap}.filters .form-group{margin:0}.spend-high{color:#d9534f;font-weight:700}.gift-thumb{width:70px;height:48px;object-fit:cover;border:1px solid #d6e2ea;border-radius:6px;cursor:zoom-in}.pager-wrap{display:flex;justify-content:center;gap:5px;padding:14px}.pager-wrap a,.pager-wrap span{min-width:34px;height:34px;line-height:34px;text-align:center;border:1px solid #d8e4ec;border-radius:7px}.pager-wrap .active{background:#3498db;color:#fff}.store-binding-form{display:flex;gap:6px;align-items:center}.store-binding-form .select2-container{min-width:250px}.select2-container .select2-selection--single{height:30px}.select2-container--default .select2-selection--single .select2-selection__rendered{line-height:28px}.usage-history{min-width:240px;display:flex;flex-direction:column;gap:5px}.usage-item,.usage-item.current{padding:5px 7px;border-left:3px solid #3498db;background:#f4f9fd;border-radius:4px;line-height:1.35}.usage-head{display:flex;align-items:center;justify-content:space-between;gap:10px}.usage-person{font-weight:600;color:#24445c}.usage-spend{white-space:nowrap;color:#d9534f;font-weight:700}.usage-date{display:block;color:#718596;font-size:12px;margin-top:2px}
</style>
<link rel="stylesheet" href="{{ asset('css/assistant-applovin-list-template.css') }}?v=20261126-1">
@endpush
@section('content')
<section class="content-header"><h1>AppLovin账号消耗 <small>自动汇总已审核通过的运营广告费用</small></h1></section>
<section class="content applovin-list-template daily-submission-template-page">
@if(session('success'))<div class="alert alert-success"><i class="fa fa-check-circle"></i> {{ session('success') }}</div>@endif
<div class="box review-box review-filter-box"><div class="box-body"><form class="filters" method="get">
@include('portals.assistant.partials.operation-department-multi-filter',['departments'=>$departmentOptions,'selectedIds'=>$selectedDepartmentIds,'inputName'=>'department_ids','label'=>'运营部门'])
<div class="form-group"><label>累计消耗</label><select class="form-control" name="over_threshold"><option value="">全部数据</option><option value="1" @selected($overThreshold==='1')>大于等于 $5,000</option></select></div>
<div class="form-group"><label>运营人员</label><input class="form-control" name="operator" value="{{ $operator }}"></div>
<div class="form-group"><label>账号编号</label><input class="form-control" name="account_number" value="{{ $accountNumber }}" placeholder="输入账号编号"></div>
<button class="btn btn-primary"><i class="fa fa-search"></i> 查询</button><a class="btn btn-default" href="{{ $appLovinIndexUrl }}">重置</a>
</form></div></div>
<div class="box review-box"><div class="table-responsive"><table class="table table-bordered table-hover applovin-data-table">
<thead><tr><th>使用人员及开始时间</th><th>代理账号</th><th>广告社媒</th><th>广告账户</th><th>账户编号</th><th style="min-width:330px">绑定店铺域名</th><th>账户限额/风控等因素</th><th>累计实际消耗</th><th>是否有礼金</th></tr></thead>
<tbody>@forelse($accounts as $account)<tr>
<td><div class="usage-history">@foreach($account['usage_history'] as $usage)<div @class(['usage-item','current'=>$loop->last])><div class="usage-head"><span class="usage-person">{{ $usage['department'] ?: '—' }} · {{ $usage['operator_name'] ?: '—' }}</span><span class="usage-spend">${{ number_format($usage['spend'],2) }}</span></div><span class="usage-date">从 {{ $usage['start_date'] }} 开始{{ $loop->last ? '（当前）' : '' }}</span></div>@endforeach</div></td><td><b>{{ $account['agency'] }}</b></td><td>{{ $account['media'] ?: '—' }}</td><td>{{ $account['account_name'] }}</td><td>{{ $account['account_number'] }}</td>
<td>@if(in_array($portal->code,['zhuli','yunying-kanban'],true))<form method="post" action="{{ route($appLovinBindingRoute) }}" class="store-binding-form">@csrf<input type="hidden" name="account_key" value="{{ $account['account_key'] }}"><select class="form-control input-sm js-store-binding" name="store_id" data-placeholder="输入域名或店铺名搜索"><option value="">未绑定</option>@foreach($stores->filter(fn($store)=>in_array((int)$account['operator_user_id'],$store->eligible_owner_ids??[],true)) as $store)<option value="{{ $store->id }}" @selected((int)optional($account['store_binding'])->assistant_domain_store_id===(int)$store->id)>{{ $store->domain }}{{ $store->shop_name ? '（'.$store->shop_name.'）' : '' }}</option>@endforeach</select><button class="btn btn-primary btn-xs" type="submit">保存</button></form>@else @php($boundStore=$stores->firstWhere('id',(int)optional($account['store_binding'])->assistant_domain_store_id)) {{ $boundStore?->domain ?: '—' }} @endif</td>
<td class="applovin-risk-cell"><div class="applovin-risk-readonly">{{ $account['risk_factors'] ?: '—' }}</div></td><td class="{{ $account['total']>=$threshold?'spend-high':'' }}">${{ number_format($account['total'],2) }}</td><td>@if($account['total']>=$threshold && $account['uploaded_screenshot'])<a target="_blank" href="{{ route($appLovinScreenshotRoute,$account['uploaded_screenshot']) }}"><img class="gift-thumb" src="{{ route($appLovinScreenshotRoute,$account['uploaded_screenshot']) }}" alt="礼金图"></a>@elseif($account['total']>=$threshold)<span class="label label-warning">未上传</span>@else<span class="text-muted">—</span>@endif</td>
</tr>@empty<tr><td colspan="9" class="text-center text-muted applovin-empty">暂无已审核通过的 AppLovin 数据</td></tr>@endforelse</tbody></table></div>
@include('portals.assistant.partials.advertising-template-pagination', ['paginator' => $accounts, 'unit' => '个账号'])
</div></section>
@endsection
@push('scripts')
<script src="{{ asset('vendor/laravel-admin/AdminLTE/plugins/select2/select2.full.min.js') }}"></script>
<script>$(function(){$('.js-store-binding').select2({width:'100%',allowClear:true,placeholder:'输入域名或店铺名搜索',language:{noResults:function(){return '没有找到对应运营的店铺'},searching:function(){return '搜索中…'}}});});</script>
@endpush
