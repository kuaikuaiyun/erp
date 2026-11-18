<?php

namespace App\Portals\AssetManager;

use App\Models\AdminUser;
use App\Models\DingTalkBinding;
use App\Models\DingTalkCompany;
use App\Models\DingTalkCompanyGrant;
use App\Services\DingTalk\BindingAccessService;

class AccountDingTalkAccessService extends BindingAccessService
{
    public function __construct(private AdminUser $actor, private AccountAuthorizationService $authorization) {}

    public function isOwner(?AdminUser $user = null): bool
    {
        return parent::isOwner($user ?? $this->actor);
    }

    public function canAccess(?AdminUser $user = null): bool
    {
        $user ??= $this->actor;

        return parent::canAccess($user) && $this->authorization->canManageOrganization($user);
    }

    public function companies()
    {
        abort_unless($this->canAccess(), 403, __('dingtalk.manage.forbidden'));
        $query = DingTalkCompany::where('enabled', true)->orderBy('name');
        if (! $this->isOwner()) {
            $query->whereIn('id', DingTalkCompanyGrant::where('user_id', $this->actor->id)->select('company_id'));
        }

        return $query->get(['id', 'name', 'corp_id']);
    }

    public function assertCorp(string $corp, bool $allowInactive = false): DingTalkCompany
    {
        abort_unless($this->canAccess(), 403, __('dingtalk.manage.forbidden'));
        $query = DingTalkCompany::withTrashed()->where('corp_id', $corp);
        if (! $allowInactive) {
            $query->where('enabled', true)->whereNull('deleted_at');
        }
        $company = $query->first();
        abort_unless($company && ($this->isOwner() || DingTalkCompanyGrant::where('user_id', $this->actor->id)->where('company_id', $company->id)->exists()), 403, __('dingtalk.manage.forbidden'));

        return $company;
    }

    public function accounts()
    {
        abort_unless($this->canAccess(), 403, __('dingtalk.manage.forbidden'));

        return $this->authorization->accounts($this->actor, true)->with(['roles', 'departments'])->orderBy('id')->get()
            ->filter(fn ($account) => $this->isOwner() || ! $account->isAdministrator())->values();
    }

    public function assertAccount(AdminUser $account): void
    {
        abort_unless($this->canAccess() && ($this->isOwner() || (! $account->isAdministrator() && $this->authorization->canManageAccount($this->actor, $account))), 403, __('dingtalk.manage.forbidden'));
    }

    public function bindings()
    {
        abort_unless($this->canAccess(), 403, __('dingtalk.manage.forbidden'));

        return DingTalkBinding::query()->whereIn('account_id', $this->authorization->accounts($this->actor, true)->select('id'));
    }
}
