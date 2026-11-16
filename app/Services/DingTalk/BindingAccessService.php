<?php
namespace App\Services\DingTalk;

use App\Models\AdminUser;
use App\Models\Department;
use App\Models\DingTalkAudit;
use App\Models\DingTalkBinding;
use App\Models\DingTalkCompany;
use App\Models\DingTalkCompanyGrant;
use App\Services\DepartmentAuthorizationService;
use Encore\Admin\Facades\Admin;
use Symfony\Component\HttpKernel\Exception\HttpException;

class BindingAccessService
{
    public function isOwner(?AdminUser $user=null): bool
    {
        $user ??= Admin::user();
        return $user && $user->exists && (int)$user->id===1 && (int)$user->status===1 && $user->isAdministrator();
    }
    public function canAccess(?AdminUser $user=null): bool
    {
        $user ??= Admin::user();
        return $this->isOwner($user) || ($user && $user->exists && (int)$user->status===1 && !$user->isAdministrator()
            && $user->departments()->wherePivotIn('position',[Department::POSITION_MANAGER,Department::POSITION_DEPUTY])->exists());
    }
    public function managers()
    {
        return AdminUser::query()->with('roles')->where('status',1)->where('id','!=',1)
            ->whereHas('departments',fn ($q)=>$q->whereIn(config('admin.database.role_users_table').'.position',[Department::POSITION_MANAGER,Department::POSITION_DEPUTY]))
            ->orderBy('name')->get()->reject(fn ($u)=>$u->isAdministrator())->values();
    }
    public function companies()
    {
        abort_unless($this->canAccess(),403,__('dingtalk.manage.forbidden'));
        $query=DingTalkCompany::query()->where('enabled',true)->orderBy('name');
        if (!$this->isOwner()) $query->whereIn('id',DingTalkCompanyGrant::query()->where('user_id',Admin::user()->id)->select('company_id'));
        return $query->get(['id','name','corp_id']);
    }
    public function assertCorp(string $corp, bool $allowInactive=false): DingTalkCompany
    {
        abort_unless($this->canAccess(),403,__('dingtalk.manage.forbidden'));
        $query=DingTalkCompany::withTrashed()->where('corp_id',$corp);
        if (!$allowInactive) $query->where('enabled',true)->whereNull('deleted_at');
        $company=$query->first();
        if (!$company || (!$this->isOwner() && !DingTalkCompanyGrant::query()->where('user_id',Admin::user()->id)->where('company_id',$company->id)->exists())) {
            throw new HttpException(403,__('dingtalk.manage.forbidden'));
        }
        return $company;
    }
    public function accounts()
    {
        abort_unless($this->canAccess(),403,__('dingtalk.manage.forbidden'));
        $query=AdminUser::query()->with(['roles','departments'])->orderBy('id');
        if (!$this->isOwner()) {
            $ids=app(DepartmentAuthorizationService::class)->managedDepartmentIds(Admin::user());
            $query->whereHas('departments',fn ($q)=>$q->whereIn(config('admin.database.roles_table').'.id',$ids));
        }
        return $query->get()->filter(fn ($a)=>$this->isOwner() || !$a->isAdministrator())->values();
    }
    public function accountIds(): ?array
    {
        return $this->isOwner() ? null : $this->accounts()->pluck('id')->map(fn ($id)=>(int)$id)->all();
    }
    public function assertAccount(AdminUser $account): void
    {
        if (!$this->canAccess() || (!$this->isOwner() && ($account->isAdministrator() || !app(DepartmentAuthorizationService::class)->canManageAccount(Admin::user(),$account)))) {
            throw new HttpException(403,__('dingtalk.manage.forbidden'));
        }
    }
    public function bindings()
    {
        $ids=$this->accountIds(); $query=DingTalkBinding::query();
        if ($ids!==null) $query->whereIn('account_id',$ids);
        return $query;
    }
    public function accountAudits(AdminUser $account,string $kind)
    {
        $this->assertAccount($account);
        $query=DingTalkAudit::query();
        return $kind==='login' ? $query->where('account_id',$account->id)->where('action','like','login.%')
            : $query->whereIn('action',['binding.created','binding.removed'])->where('metadata->target_account_id',$account->id);
    }
}
