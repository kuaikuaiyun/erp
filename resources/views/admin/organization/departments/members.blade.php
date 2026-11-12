<style>
.department-members-page{display:grid;gap:18px}.department-members-card{border:0;border-radius:10px;box-shadow:0 4px 16px rgba(35,70,95,.09);overflow:hidden}.department-members-head{display:flex;align-items:center;justify-content:space-between;padding:15px 18px;border-bottom:1px solid #e3ebf0;background:#f8fbfd}.department-members-head h3{margin:0;color:#244f6b;font-size:17px}.department-members-summary{color:#718797}.department-member-name{font-weight:700;color:#265773}.department-member-position{display:inline-block;padding:4px 9px;border-radius:12px;background:#e8f4fa;color:#28739b}.department-members-empty{padding:35px;text-align:center;color:#899aa6}.department-candidate-tools{display:flex;gap:8px;align-items:center}.department-candidate-tools .department-filter{width:210px}.department-candidate-tools .department-search{width:260px}.department-add-button{white-space:nowrap}.department-batch-bar{display:flex;align-items:center;gap:10px;padding:10px 15px;border-top:1px solid #eee}.department-candidate-pagination{display:flex;align-items:center;justify-content:space-between;gap:15px;flex-wrap:wrap;padding:13px 16px!important;border-top:1px solid #e5edf2;background:#f8fbfd}.department-page-summary{color:#6d8293;font-size:13px}.department-page-summary strong{color:#285d7d}.department-page-list{display:flex;align-items:center;gap:5px}.department-page-link,.department-page-current,.department-page-disabled,.department-page-gap{display:inline-flex;align-items:center;justify-content:center;min-width:34px;height:32px;padding:0 10px;border-radius:6px;font-size:13px}.department-page-link{border:1px solid #d5e2ea;background:#fff;color:#39789e;transition:.15s}.department-page-link:hover{border-color:#348cbd;background:#348cbd;color:#fff;text-decoration:none}.department-page-current{border:1px solid #348cbd;background:#348cbd;color:#fff;font-weight:700;box-shadow:0 2px 5px rgba(52,140,189,.24)}.department-page-disabled{border:1px solid #e1e8ed;background:#f1f4f6;color:#acbac4}.department-page-gap{min-width:22px;padding:0;color:#91a2ae}.organization-capacity-link{display:inline-block;color:inherit}.organization-capacity-link:hover{text-decoration:none;filter:brightness(.96)}
</style>
<div class="department-members-page">
    <div><a class="btn btn-default" href="{{ admin_url('organization/departments') }}"><i class="fa fa-arrow-left"></i> 返回部门管理</a></div>
    <div class="box department-members-card">
        <div class="department-members-head"><h3><i class="fa fa-users text-aqua"></i> {{ $department->name }} · 当前成员</h3><span class="department-members-summary">共 {{ $members->count() }} 人</span></div>
        <div class="table-responsive">
            <table class="table table-hover table-striped" style="margin:0">
                <thead><tr><th>姓名</th><th>登录账号</th><th>当前岗位</th><th>其他所属部门</th><th>操作</th></tr></thead>
                <tbody>
                @forelse($members as $member)
                    <tr>
                        <td class="department-member-name">{{ $member->name ?: '未设置姓名' }}</td>
                        <td>{{ $member->username }}</td>
                        <td><span class="department-member-position">{{ $positions[$member->pivot->position] ?? $member->pivot->position }}</span></td>
                        <td>@php($otherDepartments=$member->departments->where('id','!=',$department->id)) @forelse($otherDepartments as $other)<span class="label label-default">{{ $other->name }}</span> @empty<span class="text-muted">—</span>@endforelse</td>
                        <td><a class="btn btn-xs btn-primary" href="{{ admin_url('organization/accounts/'.$member->id.'/edit') }}"><i class="fa fa-edit"></i> 编辑账号</a></td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="department-members-empty">当前部门暂无成员</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="box department-members-card">
        <div class="department-members-head">
            <h3><i class="fa fa-user-plus text-green"></i> 添加其他人员</h3>
            @if($canAddMembers)<form class="department-candidate-tools" method="get"><select class="form-control department-filter" name="member_department_id"><option value="">全部部门</option>@foreach($memberDepartmentGroups as $group)@php($filterDepartment=$group['department'])<option value="{{ $filterDepartment->id }}" @selected($selectedMemberDepartmentId === (int) $filterDepartment->id)>{{ $filterDepartment->name }}</option>@foreach($group['children'] as $childDepartment)<option value="{{ $childDepartment->id }}" @selected($selectedMemberDepartmentId === (int) $childDepartment->id)>　└ {{ $childDepartment->name }}</option>@endforeach @endforeach</select><input class="form-control department-search" name="search" value="{{ $search }}" placeholder="搜索姓名或登录账号"><button class="btn btn-info"><i class="fa fa-search"></i> 搜索</button><a class="btn btn-default" href="{{ admin_url('organization/departments/'.$department->id.'/members') }}">重置</a></form>@endif
        </div>
        @if(!$canAddMembers)
            <div class="alert alert-info" style="margin:16px"><i class="fa fa-info-circle"></i> 当前一级部门包含下级部门，请进入对应的末级部门添加普通成员。</div>
        @else
            <form method="post" action="{{ admin_url('organization/departments/'.$department->id.'/members') }}" id="department-batch-add-form">@csrf<input type="hidden" name="return_to" value="{{ url()->full() }}"><div class="table-responsive"><table class="table table-hover" style="margin:0"><thead><tr><th style="width:46px"><input type="checkbox" data-select-all aria-label="全选本页"></th><th>姓名</th><th>登录账号</th><th>现有部门</th></tr></thead><tbody>
            @forelse($candidateUsers as $candidate)
                <tr><td><input type="checkbox" name="user_ids[]" value="{{ $candidate->id }}" data-member-checkbox aria-label="选择 {{ $candidate->name ?: $candidate->username }}"></td><td class="department-member-name">{{ $candidate->name ?: '未设置姓名' }}</td><td>{{ $candidate->username }}</td><td>@forelse($candidate->departments as $item)<span class="label label-default">{{ $item->name }}</span> @empty<span class="text-muted">暂无部门</span>@endforelse</td></tr>
            @empty<tr><td colspan="4" class="department-members-empty">没有可添加的人员</td></tr>@endforelse
            </tbody></table></div><div class="department-batch-bar"><button class="btn btn-success department-add-button" type="submit" data-batch-submit disabled><i class="fa fa-plus"></i> 批量添加所选人员</button><span class="text-muted" data-selected-count>已选择 0 人</span></div></form>
            @if($candidateUsers->total()>0)
                @php($pageStart=max(1,$candidateUsers->currentPage()-2))
                @php($pageEnd=min($candidateUsers->lastPage(),$candidateUsers->currentPage()+2))
                <div class="box-footer department-candidate-pagination">
                    <div class="department-page-summary">显示 <strong>{{ $candidateUsers->firstItem() }}-{{ $candidateUsers->lastItem() }}</strong> 人，共 <strong>{{ $candidateUsers->total() }}</strong> 人　 第 <strong>{{ $candidateUsers->currentPage() }}</strong> / {{ $candidateUsers->lastPage() }} 页</div>
                    @if($candidateUsers->hasPages())<div class="department-page-list">
                        @if($candidateUsers->onFirstPage())<span class="department-page-disabled"><i class="fa fa-angle-left"></i>&nbsp;上一页</span>@else<a class="department-page-link" href="{{ $candidateUsers->previousPageUrl() }}"><i class="fa fa-angle-left"></i>&nbsp;上一页</a>@endif
                        @if($pageStart>1)<a class="department-page-link" href="{{ $candidateUsers->url(1) }}">1</a>@if($pageStart>2)<span class="department-page-gap">…</span>@endif @endif
                        @for($page=$pageStart;$page<=$pageEnd;$page++)@if($page===$candidateUsers->currentPage())<span class="department-page-current">{{ $page }}</span>@else<a class="department-page-link" href="{{ $candidateUsers->url($page) }}">{{ $page }}</a>@endif @endfor
                        @if($pageEnd<$candidateUsers->lastPage())@if($pageEnd<$candidateUsers->lastPage()-1)<span class="department-page-gap">…</span>@endif<a class="department-page-link" href="{{ $candidateUsers->url($candidateUsers->lastPage()) }}">{{ $candidateUsers->lastPage() }}</a>@endif
                        @if($candidateUsers->hasMorePages())<a class="department-page-link" href="{{ $candidateUsers->nextPageUrl() }}">下一页&nbsp;<i class="fa fa-angle-right"></i></a>@else<span class="department-page-disabled">下一页&nbsp;<i class="fa fa-angle-right"></i></span>@endif
                    </div>@endif
                </div>
            @endif
        @endif
    </div>
</div>
<script>
(function(){var form=document.getElementById('department-batch-add-form');if(!form)return;var all=form.querySelector('[data-select-all]'),items=[].slice.call(form.querySelectorAll('[data-member-checkbox]')),submit=form.querySelector('[data-batch-submit]'),count=form.querySelector('[data-selected-count]');function refresh(){var selected=items.filter(function(item){return item.checked}).length;count.textContent='已选择 '+selected+' 人';submit.disabled=selected===0;all.checked=items.length>0&&selected===items.length;all.indeterminate=selected>0&&selected<items.length;}all.addEventListener('change',function(){items.forEach(function(item){item.checked=all.checked});refresh()});items.forEach(function(item){item.addEventListener('change',refresh)});form.addEventListener('submit',function(event){var selected=items.filter(function(item){return item.checked}).length;if(!selected||!confirm('确认将所选 '+selected+' 人添加到当前部门？'))event.preventDefault()});refresh()})();
</script>
