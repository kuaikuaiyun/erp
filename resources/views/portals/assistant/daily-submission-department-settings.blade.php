@extends('portal.layout')
@section('page_title', '日常提报部门设置')
@push('styles')
<style>.department-setting-box{border:0;border-radius:12px;box-shadow:0 5px 18px rgba(31,67,98,.09);overflow:hidden}.department-setting-body{padding:20px}.department-setting-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:12px}.department-option{display:flex;align-items:flex-start;gap:9px;padding:13px 14px;border:1px solid #d9e5ed;border-radius:8px;background:#f9fcfe;cursor:pointer}.department-option:hover{border-color:#72acd0;background:#f2f9fd}.department-option input{margin-top:3px}.department-parent{display:block;color:#8296a5;font-size:12px;margin-top:3px}.department-actions{display:flex;gap:10px;margin-top:20px;padding-top:16px;border-top:1px solid #edf1f4}</style>
@endpush
@section('content')
<section class="content-header"><h1>日常提报部门设置 <small>统一控制日常提报系统五个菜单的数据范围</small></h1></section>
<section class="content">
@if(session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
@if($errors->any())<div class="alert alert-danger">{{ $errors->first() }}</div>@endif
<div class="alert alert-info"><i class="fa fa-info-circle"></i> 此处只显示当前助理部门已经绑定的运营部门。保存后，日常提报系统五个菜单都会按所选部门显示数据。</div>
@if(!$canConfigure)<div class="alert alert-warning"><i class="fa fa-shield"></i> BOSS 和管理员账号始终拥有全部数据权限，无需设置部门显示范围。</div>@endif
<div class="box department-setting-box"><div class="box-header with-border"><h3 class="box-title">选择需要显示的运营部门</h3></div>
<form method="post" action="{{ route('assistant.daily-submission-departments.update') }}">@csrf @method('PUT')<div class="department-setting-body">
@if(!$canConfigure)<div class="text-muted">当前账号无需选择部门。</div>@elseif($departments->isEmpty())<div class="alert alert-warning" style="margin:0">当前助理部门尚未绑定运营部门，请先完成助理与运营部门绑定。</div>@else
<div class="department-setting-grid">@foreach($departments as $department)<label class="department-option"><input type="checkbox" name="department_ids[]" value="{{ $department->id }}" @checked(in_array((int)$department->id, old('department_ids', $selectedDepartmentIds), true))><span><strong>{{ $department->name }}</strong>@if($department->parent)<span class="department-parent">上级：{{ $department->parent->name }}</span>@endif</span></label>@endforeach</div>
<div class="department-actions"><button class="btn btn-primary" type="submit"><i class="fa fa-save"></i> 保存统一显示范围</button><button class="btn btn-default" type="button" id="select-all-departments">全选</button></div>
@endif</div></form></div></section>
@endsection
@push('scripts')
<script>document.getElementById('select-all-departments')?.addEventListener('click',function(){document.querySelectorAll('input[name="department_ids[]"]').forEach(function(input){input.checked=true;});});</script>
@endpush
