@extends('portal.layout')

@section('page_title', '账号部门查看权限')

@push('styles')
<style>
.scope-card{border:0;border-radius:12px;box-shadow:0 5px 18px rgba(31,67,98,.09);overflow:hidden}.scope-layout{display:grid;grid-template-columns:340px 1fr;min-height:560px}.scope-users{border-right:1px solid #dce7ee;background:#f8fbfd;padding:18px}.scope-search{display:flex;gap:7px;margin-bottom:14px}.scope-search .form-control{border-radius:7px}.scope-user-list{max-height:470px;overflow:auto}.scope-user{display:block;padding:11px 13px;margin-bottom:7px;border:1px solid #d9e6ee;border-radius:8px;background:#fff;color:#355d77}.scope-user:hover,.scope-user.active{border-color:#3c8dbc;background:#eaf6fd;color:#1475a8;text-decoration:none}.scope-user strong,.scope-user span{display:block}.scope-user span{margin-top:3px;color:#8496a4;font-size:12px}.scope-main{padding:22px}.scope-heading{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:18px}.scope-heading h3{margin:0;color:#173f60}.scope-tools,.scope-group-tools{display:flex;gap:7px}.scope-department-search{max-width:360px;margin-bottom:14px}.scope-groups{display:flex;flex-direction:column;gap:12px;max-height:410px;overflow:auto;padding:3px 6px 3px 3px}.scope-department-group{flex:0 0 auto;border:1px solid #d5e5ee;border-radius:10px;background:#fff;overflow:hidden;box-shadow:0 2px 7px rgba(39,91,124,.05)}.scope-group-header{display:flex;align-items:center;justify-content:space-between;gap:12px;min-height:54px;padding:11px 13px;background:linear-gradient(90deg,#eef8fd 0,#f8fcfe 72%,#fff 100%);border-bottom:1px solid #dcebf3}.scope-root-option{display:flex;align-items:center;gap:10px;min-width:0;margin:0;cursor:pointer}.scope-root-option input,.scope-child-option input{margin:0;flex:0 0 auto}.scope-root-icon{display:inline-flex;align-items:center;justify-content:center;width:31px;height:31px;flex:0 0 31px;border-radius:8px;background:#3c8dbc;color:#fff}.scope-root-copy,.scope-child-copy{min-width:0}.scope-root-copy strong,.scope-root-copy small,.scope-child-copy strong,.scope-child-copy small{display:block}.scope-root-copy strong{font-size:14px;color:#173f60}.scope-root-copy small{margin-top:2px;color:#6d8799;font-weight:400}.scope-group-tools{flex:0 0 auto}.scope-group-tools .btn{padding:4px 9px;border-radius:6px}.scope-children{display:grid;grid-template-columns:repeat(3,minmax(190px,1fr));gap:8px;padding:11px 12px 12px}.scope-child-option{display:flex;align-items:flex-start;gap:8px;min-height:58px;min-width:0;margin:0;padding:10px 11px;border:1px solid #e0e9ef;border-radius:8px;background:#fff;cursor:pointer;transition:.15s ease}.scope-child-option:hover{border-color:#75b5d7;background:#f4fbff;box-shadow:0 2px 5px rgba(60,141,188,.08)}.scope-child-copy strong{overflow:hidden;text-overflow:ellipsis;white-space:nowrap;color:#294c63}.scope-child-copy small{margin-top:4px;color:#8496a4;font-weight:400;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.scope-level-badge{display:inline-block!important;margin-right:5px;padding:1px 6px;border-radius:9px;background:#e6f3fa;color:#247ca9;font-size:11px}.scope-empty{padding:40px 15px;text-align:center;color:#8496a4;border:1px dashed #cadbe5;border-radius:9px}.scope-footer{display:flex;align-items:center;justify-content:space-between;gap:16px;margin-top:18px;padding-top:16px;border-top:1px solid #e2ebf1}.scope-help{color:#718799;font-size:12px}@media(max-width:1200px){.scope-children{grid-template-columns:repeat(2,minmax(180px,1fr))}}@media(max-width:1000px){.scope-layout{grid-template-columns:1fr}.scope-users{border-right:0;border-bottom:1px solid #dce7ee}}@media(max-width:700px){.scope-group-header{align-items:flex-start;flex-direction:column}.scope-children{grid-template-columns:1fr}.scope-heading{align-items:flex-start;flex-direction:column}}
.scope-card{height:calc(100vh - 135px);min-height:620px}.scope-layout{height:100%;min-height:0}.scope-users{display:flex;min-height:0;flex-direction:column}.scope-users>h4,.scope-users>.scope-search{flex:0 0 auto}.scope-user-list{min-height:0;max-height:none;flex:1 1 auto}.scope-main{min-width:0;min-height:0}.scope-main>form{display:flex;height:100%;min-height:0;flex-direction:column}.scope-heading,.scope-department-search,.scope-footer{flex:0 0 auto}.scope-groups{min-height:0;max-height:none;flex:1 1 auto}.scope-footer{margin-top:12px;padding-top:12px}
@media(max-width:1000px),(max-height:720px){.scope-card{height:auto;min-height:560px}.scope-layout{height:auto}.scope-main>form{height:auto}.scope-groups{max-height:520px;flex:none}.scope-user-list{max-height:330px;flex:none}}
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
                    @php($departmentGroups=$departments->groupBy(fn($item)=>(int)($item->hierarchy_root_id??$item->id)))
                    <div class="scope-groups" data-scope-groups>
                        @forelse($departmentGroups as $departmentGroup)
                            @php($root=$departmentGroup->first(fn($item)=>(int)($item->hierarchy_depth??0)===0)??$departmentGroup->first())
                            @php($children=$departmentGroup->filter(fn($item)=>(int)$item->id!==(int)$root->id))
                            <section class="scope-department-group" data-scope-group>
                                <div class="scope-group-header">
                                    <label class="scope-root-option" data-scope-option data-name="{{mb_strtolower($root->name)}}">
                                        <input type="checkbox" name="department_ids[]" value="{{$root->id}}" @checked(in_array((int)$root->id,$selectedDepartmentIds,true))>
                                        <span class="scope-root-icon"><i class="fa fa-sitemap"></i></span>
                                        <span class="scope-root-copy"><strong>{{$root->name}}</strong><small>一级部门@if($children->isNotEmpty()) · {{$children->count()}} 个下级部门@endif</small></span>
                                    </label>
                                    @if($children->isNotEmpty())
                                        <div class="scope-group-tools"><button type="button" class="btn btn-info btn-xs" data-scope-group-all><i class="fa fa-check-square-o"></i> 本组全选</button><button type="button" class="btn btn-default btn-xs" data-scope-group-clear><i class="fa fa-square-o"></i> 本组清空</button></div>
                                    @endif
                                </div>
                                @if($children->isNotEmpty())
                                    <div class="scope-children">
                                        @foreach($children as $department)
                                            @php($depth=(int)($department->hierarchy_depth??1))
                                            @php($parentNames=(array)($department->hierarchy_parent_names??[]))
                                            <label class="scope-child-option" data-scope-option data-name="{{mb_strtolower(implode(' ',$parentNames).' '.$department->name)}}">
                                                <input type="checkbox" name="department_ids[]" value="{{$department->id}}" @checked(in_array((int)$department->id,$selectedDepartmentIds,true))>
                                                <span class="scope-child-copy"><strong>{{$department->name}}</strong><small><span class="scope-level-badge">{{$depth+1}}级部门</span>{{implode(' › ',$parentNames)}}</small></span>
                                            </label>
                                        @endforeach
                                    </div>
                                @endif
                            </section>
                        @empty
                            <div class="scope-empty">暂无可配置部门</div>
                        @endforelse
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
(function(){
    var form=document.getElementById('dashboardScopeForm');
    if(!form)return;
    var groups=[].slice.call(form.querySelectorAll('[data-scope-group]'));
    var options=[].slice.call(form.querySelectorAll('[data-scope-option]'));
    var checks=options.map(function(option){return option.querySelector('input')});
    form.querySelector('[data-scope-all]')?.addEventListener('click',function(){checks.forEach(function(check){check.checked=true})});
    form.querySelector('[data-scope-clear]')?.addEventListener('click',function(){checks.forEach(function(check){check.checked=false})});
    groups.forEach(function(group){
        group.querySelector('[data-scope-group-all]')?.addEventListener('click',function(){group.querySelectorAll('input[name="department_ids[]"]').forEach(function(check){check.checked=true})});
        group.querySelector('[data-scope-group-clear]')?.addEventListener('click',function(){group.querySelectorAll('input[name="department_ids[]"]').forEach(function(check){check.checked=false})});
    });
    form.querySelector('[data-scope-department-search]')?.addEventListener('input',function(){
        var keyword=this.value.trim().toLowerCase();
        groups.forEach(function(group){
            var root=group.querySelector('.scope-root-option');
            var children=[].slice.call(group.querySelectorAll('.scope-child-option'));
            var rootMatch=!keyword||(root.dataset.name||'').indexOf(keyword)>=0;
            var childMatches=0;
            children.forEach(function(child){
                var matched=!keyword||(child.dataset.name||'').indexOf(keyword)>=0;
                child.style.display=rootMatch||matched?'flex':'none';
                if(matched)childMatches++;
            });
            group.style.display=rootMatch||childMatches>0?'block':'none';
        });
    });
})();
</script>
@endpush
