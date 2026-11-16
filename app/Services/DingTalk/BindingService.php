<?php

namespace App\Services\DingTalk;

use App\Models\AdminUser;
use App\Models\DingTalkBinding;
use App\Models\DingTalkIdentity;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

class BindingService
{
    public function __construct(private readonly Client $client, private readonly AuditService $audit) {}

    public function bind(int $accountId, string $userId, bool $requireAdministrator = false, ?int $companyId = null): DingTalkBinding
    {
        $companies = app(CompanyService::class);
        $options = $companyId ? null : $companies->options();
        $company = $companyId ? $companies->enabled($companyId) : ($options->count() === 1 ? $companies->enabled((int)$options->first()->id) : throw new HttpException(422, __('dingtalk.company.choose')));
        $companyVersion = (int)$company->version;
        $expectedVersion = DingTalkIdentity::query()->where('corp_id', $company->corp_id)->where('user_id', $userId)->value('version');
        $startedAt = now();
        $member = $this->client->forCompany($company)->memberByUserId($userId);
        if (!$member) {
            throw new HttpException(403, __('dingtalk.errors.not_member'));
        }
        return DB::transaction(function () use ($accountId, $member, $startedAt, $expectedVersion, $requireAdministrator, $company, $companyVersion): DingTalkBinding {
            // Serializes all binding changes for one ERP account, including concurrent administrators.
            $account = AdminUser::query()->lockForUpdate()->findOrFail($accountId);
            app(CompanyService::class)->assertVersion($company, $companyVersion);
            $this->assertCurrentOperator();
            if (request()->attributes->has('dingtalk.account_id') || !app()->runningInConsole()) { app(BindingAccessService::class)->assertAccount($account); app(BindingAccessService::class)->assertCorp($company->corp_id); }
            if ($requireAdministrator && (!$account->isAdministrator() || (isset($account->status) && (int) $account->status !== AdminUser::STATUS_ACTIVE))) {
                throw new HttpException(403, __('dingtalk.console.admin_required'));
            }
            $identity = DingTalkIdentity::query()->firstOrCreate(
                ['corp_id' => $member['corp_id'], 'user_id' => $member['user_id']],
                ['union_id' => $member['union_id'], 'name' => $member['name'], 'active' => true, 'verified_at' => $startedAt],
            );
            $created = $identity->wasRecentlyCreated;
            $identity = DingTalkIdentity::query()->lockForUpdate()->findOrFail($identity->id);
            if (!$account->isAdministrator() && DingTalkBinding::query()->where('account_id', $accountId)
                ->where('active', true)->where('identity_id', '!=', $identity->id)->exists()) {
                throw new HttpException(422, __('dingtalk.errors.single_member'));
            }
            if (!$created && ($expectedVersion === null || $identity->version !== (int) $expectedVersion)) {
                throw new HttpException(409, __('dingtalk.errors.binding_changed'));
            }
            if (!$identity->active || !hash_equals($identity->union_id, $member['union_id'])) {
                // Explicit admin action is required for rejoining or a changed identity; old bindings stay disabled.
                DingTalkBinding::query()->where('identity_id', $identity->id)->where('active', true)
                    ->update(['active' => false, 'version' => DB::raw('version + 1'), 'updated_at' => now()]);
                $identity->version++;
            }
            $identity->forceFill(['union_id' => $member['union_id'], 'name' => $member['name'], 'active' => true,
                'verified_at' => $startedAt, 'next_check_at' => now()->addSeconds(config('dingtalk.reconcile_seconds'))])->save();
            $binding = DingTalkBinding::query()->firstOrNew(['account_id' => $accountId, 'identity_id' => $identity->id]);
            if ($binding->exists && $binding->active) {
                return $binding;
            }
            $binding->forceFill(['active' => true, 'version' => ($binding->exists ? $binding->version : 0) + 1])->save();
            $this->audit->record('binding.created', $identity, $accountId,
                ['target_corp_id' => $identity->corp_id, 'target_company_name' => $company->name, 'target_name' => $identity->name,
                    'target_identity_id' => $identity->id, 'target_user_id' => $identity->user_id, 'target_account_id' => $accountId]);
            return $binding;
        }, 3);
    }

    public function unbind(int $bindingId): void
    {
        $binding = DingTalkBinding::query()->findOrFail($bindingId);
        DB::transaction(function () use ($binding): void {
            $account = AdminUser::query()->lockForUpdate()->findOrFail($binding->account_id);
            $this->assertCurrentOperator();
            if (request()->attributes->has('dingtalk.account_id') || !app()->runningInConsole()) {
                app(BindingAccessService::class)->assertAccount($account);
                app(BindingAccessService::class)->assertCorp((string) $binding->identity()->value('corp_id'), true);
            }
            $identity = DingTalkIdentity::query()->lockForUpdate()->findOrFail($binding->identity_id);
            $current = DingTalkBinding::query()->lockForUpdate()->findOrFail($binding->id);
            if ($current->active) {
                $current->forceFill(['active' => false, 'version' => $current->version + 1])->save();
                $this->audit->record('binding.removed', $identity, $binding->account_id,
                    ['target_corp_id' => $identity->corp_id, 'target_name' => $identity->name, 'target_user_id' => $identity->user_id,
                        'target_company_name' => \App\Models\DingTalkCompany::withTrashed()->where('corp_id',$identity->corp_id)->value('name'),
                        'target_identity_id' => $identity->id, 'target_account_id' => $binding->account_id]);
            }
        }, 3);
    }

    public function eligibleAccount(AdminUser $account): bool
    {
        if (isset($account->status) && (int) $account->status !== AdminUser::STATUS_ACTIVE) {
            return false;
        }
        // A former superadmin with several bindings must be corrected before anyone can use that account.
        return $account->isAdministrator() || DingTalkBinding::query()->where('account_id', $account->id)->where('active', true)->count() <= 1;
    }

    private function assertCurrentOperator(): void
    {
        $actor = request()->attributes->get('dingtalk.identity');
        if (!$actor) {
            if (request()->attributes->get('erp.auth_mode') === 'password') {
                $account = AdminUser::query()->find(request()->attributes->get('dingtalk.account_id'));
                if ($account && app(BindingAccessService::class)->canAccess($account) && app(\App\Services\Authentication\ModeService::class)->validate(request(), $account) === 'password') return;
                throw new HttpException(403, __('dingtalk.errors.revoked'));
            }
            // Only a server-side bootstrap command may operate without a browser actor.
            if (!app()->runningInConsole()) {
                throw new HttpException(403, __('dingtalk.errors.revoked'));
            }
            return;
        }
        $current = DingTalkIdentity::query()->lockForUpdate()->find($actor->id);
        $account = AdminUser::query()->find(request()->attributes->get('dingtalk.account_id'));
        $proof = request()->session()->get(LoginService::PROOF_KEY, []);
        $operatorBinding = DingTalkBinding::query()->find($proof['binding_id'] ?? 0);
        if (!$current || !$current->active || $current->version !== $actor->version || !$account || !app(BindingAccessService::class)->canAccess($account)
            || (isset($account->status) && (int) $account->status !== AdminUser::STATUS_ACTIVE)
            || !$operatorBinding || !$operatorBinding->active || $operatorBinding->version !== (int) ($proof['binding_version'] ?? 0)
            || (int) $operatorBinding->identity_id !== (int) $current->id || (int) $operatorBinding->account_id !== (int) $account->id) {
            throw new HttpException(403, __('dingtalk.errors.revoked'));
        }
        if (app(\App\Services\Authentication\ModeService::class)->validate(request(), $account) !== 'dingtalk') throw new HttpException(403, __('dingtalk.errors.revoked'));
        $operatorCompany=app(CompanyService::class)->enabled((int)($proof['company_id'] ?? 0));
        app(CompanyService::class)->assertVersion($operatorCompany,(int)($proof['company_version'] ?? 0));
        if (!hash_equals($operatorCompany->corp_id,$current->corp_id)) throw new HttpException(403, __('dingtalk.errors.revoked'));
        if (!$current->verified_at || $current->verified_at->timestamp + config('dingtalk.membership_seconds') <= now()->timestamp) {
            throw new HttpException(401, __('dingtalk.errors.expired'));
        }
    }
}
