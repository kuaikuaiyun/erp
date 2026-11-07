@extends('portal.layout')
@section('page_title','广告账号授权')
@push('styles')
<style>.auth-box{border:0;border-radius:12px;box-shadow:0 5px 18px rgba(31,67,98,.09);overflow:hidden}.auth-form{display:grid;grid-template-columns:repeat(3,minmax(190px,1fr));gap:14px;padding:18px}.auth-form .form-group{margin:0}.auth-form label{color:#526d82}.auth-form .form-control{height:38px;border-radius:6px}.auth-actions,.old-mail-option{grid-column:1/-1}.old-mail-option{padding:10px 14px;background:#f5f9fc;border-radius:7px}.auth-table{min-width:1300px;margin:0}.auth-table th{background:#eaf3f9;text-align:center}.status-pill{display:inline-block;padding:4px 10px;border-radius:14px;font-size:12px}.status-pill.pending{background:#fff0c2;color:#986700}.status-pill.processed{background:#dff5e9;color:#18784c}@media(max-width:900px){.auth-form{grid-template-columns:1fr}}</style>
@endpush
@section('content')
<section class="content-header"><h1>广告账号授权 <small>提交广告账号邮箱授权或旧邮箱弃用需求</small></h1></section>
<section class="content">
@if(session('success'))<div class="alert alert-success">{{session('success')}}</div>@endif
@if($errors->any())<div class="alert alert-danger"><strong>提交失败：</strong>{{$errors->first()}}</div>@endif
<div class="box auth-box"><div class="box-header with-border"><h3 class="box-title">新增授权记录</h3></div>
<form method="post" action="{{route('operation.daily-submissions.advertising-account-authorization.store')}}">@csrf<div class="auth-form">
@if($isBoss)<div class="form-group"><label>提交运营账号 *</label><select class="form-control" id="auth-operator" name="operator_user_id" data-url="{{route('operation.daily-submissions.index',['type'=>'advertising-account-authorization'])}}" required>@foreach($operatorOptions as $operator)<option value="{{$operator->id}}" @selected((int)$selectedOperator->id===(int)$operator->id)>{{$operator->name?:$operator->username}}（{{$operator->username}}）</option>@endforeach</select></div>@endif
<div class="form-group"><label>广告代理 *</label><select class="form-control" id="auth-agency-name" required><option value="">请选择广告代理</option>@foreach(array_keys($advertisingAgencyGroups) as $agencyName)<option value="{{$agencyName}}">{{$agencyName}}</option>@endforeach</select></div>
<div class="form-group"><label>社媒 *</label><select class="form-control" id="auth-agency" name="agency_id" required disabled><option value="">请先选择广告代理</option></select></div>
<div class="form-group"><label>广告账号名称 *</label><input class="form-control" name="account_name" value="{{old('account_name')}}" required maxlength="191"></div>
<div class="form-group"><label>广告账号编号 *</label><input class="form-control" name="account_number" value="{{old('account_number')}}" required maxlength="191"></div>
<div class="form-group"><label>新邮箱 *</label><input class="form-control" type="email" name="new_email" value="{{old('new_email')}}" required maxlength="191"></div>
<div class="form-group"><label>旧邮箱 *</label><input class="form-control" id="auth-old-email" type="email" name="old_email" value="{{old('old_email')}}" maxlength="191" required placeholder="请填写旧邮箱"></div>
<label class="old-mail-option"><input id="auth-abandoned" type="checkbox" name="old_email_abandoned" value="1" @checked(old('old_email_abandoned'))> 旧邮箱是否弃用</label>
<div class="auth-actions"><button class="btn btn-primary"><i class="fa fa-send"></i> 提交授权需求</button></div>
</div></form></div>
<div class="box auth-box"><div class="box-header with-border"><h3 class="box-title">提交记录 <small>共 {{$history->total()}} 条</small></h3></div><div class="table-responsive"><table class="table table-bordered table-hover auth-table"><thead><tr><th>提交时间</th><th>运营</th><th>广告代理</th><th>社媒</th><th>账号名称</th><th>账号编号</th><th>新邮箱</th><th>旧邮箱</th><th>旧邮箱弃用</th><th>状态</th><th>助理回复</th><th>处理人/时间</th></tr></thead><tbody>
@forelse($history as $item)<tr><td>{{$item->created_at?->format('Y-m-d H:i')}}</td><td>{{$item->operator_name}}</td><td>{{$item->agency_name}}</td><td>{{$item->media_name?:'—'}}</td><td>{{$item->account_name}}</td><td>{{$item->account_number}}</td><td>{{$item->new_email}}</td><td>{{$item->old_email?:'—'}}</td><td>{{$item->old_email ? ($item->old_email_abandoned?'是':'否') : '—'}}</td><td><span class="status-pill {{$item->status}}">{{$item->statusLabel()}}</span></td><td style="white-space:pre-wrap;min-width:200px">{{$item->response_text?:'—'}}</td><td>{{$item->processed_by_name?:'—'}}@if($item->processed_at)<br><small>{{$item->processed_at->format('Y-m-d H:i')}}</small>@endif</td></tr>
@empty<tr><td colspan="12" class="text-center text-muted">暂无广告账号授权记录</td></tr>@endforelse
</tbody></table></div><div class="box-footer clearfix"><span class="text-muted">共 {{$history->total()}} 条，第 {{$history->currentPage()}} / {{$history->lastPage()}} 页，每页 {{$history->perPage()}} 条</span>@if($history->hasPages())<div class="pull-right">{{$history->links()}}</div>@endif</div></div>
</section>
@endsection
@push('scripts')
<script>(function(){var groups=@json($advertisingAgencyGroups),agencyName=document.getElementById('auth-agency-name'),agency=document.getElementById('auth-agency'),operator=document.getElementById('auth-operator'),oldEmail=document.getElementById('auth-old-email'),abandoned=document.getElementById('auth-abandoned');function fill(){var options=groups[agencyName.value]||[];agency.innerHTML='<option value="">请选择社媒</option>';options.forEach(function(item){var option=document.createElement('option');option.value=item.key;option.textContent=item.media;agency.appendChild(option)});agency.disabled=!options.length}function toggleOld(){abandoned.disabled=!oldEmail.value.trim();if(abandoned.disabled)abandoned.checked=false}agencyName.addEventListener('change',fill);oldEmail.addEventListener('input',toggleOld);toggleOld();if(operator)operator.addEventListener('change',function(){location.href=this.dataset.url+'?operator_user_id='+encodeURIComponent(this.value)});})();</script>
@endpush
