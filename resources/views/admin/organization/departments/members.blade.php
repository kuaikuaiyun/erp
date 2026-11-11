<style>
.department-members-page{display:grid;gap:18px}.department-members-card{border:0;border-radius:10px;box-shadow:0 4px 16px rgba(35,70,95,.09);overflow:hidden}.department-members-head{display:flex;align-items:center;justify-content:space-between;padding:15px 18px;border-bottom:1px solid #e3ebf0;background:#f8fbfd}.department-members-head h3{margin:0;color:#244f6b;font-size:17px}.department-members-summary{color:#718797}.department-member-name{font-weight:700;color:#265773}.department-member-position{display:inline-block;padding:4px 9px;border-radius:12px;background:#e8f4fa;color:#28739b}.department-members-empty{padding:35px;text-align:center;color:#899aa6}.department-candidate-tools{display:flex;gap:8px;align-items:center}.department-candidate-tools .department-filter{width:210px}.department-candidate-tools .department-search{width:260px}.department-add-button{white-space:nowrap}.organization-capacity-link{display:inline-block;color:inherit}.organization-capacity-link:hover{text-decoration:none;filter:brightness(.96)}
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
            @if($canAddMembers)<form class="department-candidate-tools" method="get"><select class="form-control department-filter" name="member_department_id"><option value="">全部部门</option>@foreach($memberDepartmentOptions as $filterDepartment)<option value="{{ $filterDepartment->id }}" @selected($selectedMemberDepartmentId === (int) $filterDepartment->id)>{{ $filterDepartment->parent_id ? '└ ' : '' }}{{ $filterDepartment->name }}</option>@endforeach</select><input class="form-control department-search" name="search" value="{{ $search }}" placeholder="搜索姓名或登录账号"><button class="btn btn-info"><i class="fa fa-search"></i> 搜索</button><a class="btn btn-default" href="{{ admin_url('organization/departments/'.$department->id.'/members') }}">重置</a></form>@endif
        </div>
        @if(!$canAddMembers)
            <div class="alert alert-info" style="margin:16px"><i class="fa fa-info-circle"></i> 当前一级部门包含下级部门，请进入对应的末级部门添加普通成员。</div>
        @else
            <div class="table-responsive"><table class="table table-hover" style="margin:0"><thead><tr><th>姓名</th><th>登录账号</th><th>现有部门</th><th style="width:120px">操作</th></tr></thead><tbody>
            @forelse($candidateUsers as $candidate)
                <tr><td class="department-member-name">{{ $candidate->name ?: '未设置姓名' }}</td><td>{{ $candidate->username }}</td><td>@forelse($candidate->departments as $item)<span class="label label-default">{{ $item->name }}</span> @empty<span class="text-muted">暂无部门</span>@endforelse</td><td><form method="post" action="{{ admin_url('organization/departments/'.$department->id.'/members') }}">@csrf<input type="hidden" name="user_id" value="{{ $candidate->id }}"><button class="btn btn-xs btn-success department-add-button" onclick="return confirm('确认将该人员添加到当前部门？')"><i class="fa fa-plus"></i> 添加成员</button></form></td></tr>
            @empty<tr><td colspan="4" class="department-members-empty">没有可添加的人员</td></tr>@endforelse
            </tbody></table></div>
            <div class="box-footer clearfix">{{ $candidateUsers->links() }}</div>
        @endif
    </div>
</div>
