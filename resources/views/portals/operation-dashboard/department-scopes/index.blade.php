@extends('portal.layout')

@section('page_title', '账号部门查看权限')

@push('styles')
<style>
.scope-card{border:0;border-radius:12px;box-shadow:0 5px 18px rgba(31,67,98,.09);overflow:hidden}.scope-layout{display:grid;grid-template-columns:340px 1fr;min-height:560px}.scope-users{border-right:1px solid #dce7ee;background:#f8fbfd;padding:18px}.scope-search{display:flex;gap:7px;margin-bottom:14px}.scope-search .form-control{border-radius:7px}.scope-user-list{max-height:470px;overflow:auto}.scope-user{display:block;padding:11px 13px;margin-bottom:7px;border:1px solid #d9e6ee;border-radius:8px;background:#fff;color:#355d77}.scope-user:hover,.scope-user.active{border-color:#3c8dbc;background:#eaf6fd;color:#1475a8;text-decoration:none}.scope-user strong,.scope-user span{display:block}.scope-user span{margin-top:3px;color:#8496a4;font-size:12px}.scope-main{padding:22px}.scope-heading{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:18px}.scope-heading h3{margin:0;color:#173f60}.scope-tools{display:flex;gap:7px}.scope-department-search{max-width:360px;margin-bottom:14px}.scope-grid{display:grid;grid-template-columns:repeat(3,minmax(180px,1fr));gap:9px;max-height:390px;overflow:auto;padding:3px}.scope-option{display:flex;align-items:flex-start;gap:9px;margin:0;padding:11px 12px;border:1px solid #d9e6ee;border-radius:8px;background:#fff;cursor:pointer}.scope-option:hover{border-color:#65acd3;background:#f1f9fd}.scope-option input{margin-top:3px}.scope-option strong,.scope-option small{display:block}.scope-option small{margin-top:3px;color:#8496a4}.scope-footer{display:flex;align-items:center;justify-content:space-between;gap:16px;margin-top:18px;padding-top:16px;border-top:1px solid #e2ebf1}.scope-help{color:#718799;font-size:12px}@media(max-width:1000px){.scope-layout{grid-template-columns:1fr}.scope-users{border-right:0;border-bottom:1px solid #dce7ee}.scope-grid{grid-template-columns:repeat(2,1fr)}}
</style>
@endpush

@section('content')
<section class="content-header"><h1>账号部门查看权限 <small>统一控制运营看板各菜单可查看的部门数据</small></h1></section>
<section class="content">
    @if(session('success'))<div class="alert alert-success"><i class="fa fa-check-circle"></i> {{session('success')}}</div>@endif
    <div class="box scope-card"><div class="scope-layout">
        <aside class="scope-users">
            <h4><i class="fa fa-user-circle"></i> {{ $canManage ? '搜索并选择账号' : '当前账号' }}</h4>
            @if($canManage)
                <form class="scope-search" method="get"><input class="form-control" name="account_keyword" value="{{$keyword}}" placeholder="搜索姓名或登录账号"><button class="btn btn-primary" title="搜索"><i class="fa fa-search"></i></button></form>
            @endif
            <div class="scope-user-list">
                @forelse($users as $scopeUser)
                    <a class="scope-user {{$selectedUserId===(int)$scopeUser->id?'active':''}}" href="{{route('operation-dashboard.department-scopes.index',['user_id'=>$scopeUser->id,'account_keyword'=>$keyword?:null])}}"><strong>{{$scopeUser->name?:$scopeUser->username}}</strong><span>{{$scopeUser->username}}</span></a>
                @empty<div class="text-muted text-center" style="padding:30px 0">没有找到匹配账号</div>@endforelse
            </div>
        </aside>
        <main class="scope-main">
            @if($selectedUserId)
                @php($selectedUser=$users->first(fn($item)=>(int)$item->id===$selectedUserId))
                <form method="post" action="{{route('operation-dashboard.department-scopes.update')}}" id="dashboardScopeForm">@csrf
                    <input type="hidden" name="user_id" value="{{$selectedUserId}}">
                    <div class="scope-heading"><h3>{{$selectedUser?->name?:$selectedUser?->username}} <small>{{$selectedUser?->username}}</small></h3><div class="scope-tools"><button type="button" class="btn btn-default btn-sm" data-scope-all>全选</button><button type="button" class="btn btn-default btn-sm" data-scope-clear>清空</button></div></div>
                    <input class="form-control scope-department-search" type="search" placeholder="搜索部门名称" data-scope-department-search>
                    <div class="scope-grid">
                        @foreach($departments as $department)<label class="scope-option" data-scope-option data-name="{{mb_strtolower($department->name)}}"><input type="checkbox" name="department_ids[]" value="{{$department->id}}" @checked(in_array((int)$department->id,$selectedDepartmentIds,true))><span><strong>{{$department->name}}</strong>@if($department->parent)<small>上级：{{$department->parent->name}}</small>@endif</span></label>@endforeach
                    </div>
                    <div class="scope-footer"><span class="scope-help"><i class="fa fa-info-circle"></i> 勾选后，该账号在运营看板所有报表中只能查看所选部门；全部清空表示不限制部门。</span>@if($canManage)<button class="btn btn-primary"><i class="fa fa-save"></i> 保存查看权限</button>@endif</div>
                </form>
            @else<div class="text-muted text-center" style="padding:100px 0">请先搜索并选择账号</div>@endif
        </main>
    </div></div>
</section>
@endsection

@push('scripts')
<script>
(function(){var form=document.getElementById('dashboardScopeForm');if(!form)return;var options=[].slice.call(form.querySelectorAll('[data-scope-option]')),checks=options.map(function(x){return x.querySelector('input')});form.querySelector('[data-scope-all]')?.addEventListener('click',function(){checks.forEach(function(x){x.checked=true})});form.querySelector('[data-scope-clear]')?.addEventListener('click',function(){checks.forEach(function(x){x.checked=false})});form.querySelector('[data-scope-department-search]')?.addEventListener('input',function(){var keyword=this.value.trim().toLowerCase();options.forEach(function(option){option.style.display=!keyword||option.dataset.name.indexOf(keyword)>=0?'flex':'none'})})})();
</script>
@endpush
