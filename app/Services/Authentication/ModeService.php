<?php
namespace App\Services\Authentication;

use App\Models\AdminUser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpKernel\Exception\HttpException;

class ModeService
{
    public const KEY = 'erp.authentication';

    public function current(): object
    {
        $mode = config('dingtalk.auth_mode', 'dingtalk');
        if (!in_array($mode, ['dingtalk', 'password'], true) || !Schema::hasTable('erp_authentication_states')) {
            throw new HttpException(503, __('dingtalk.mode.unavailable'));
        }
        // Read from the primary connection: a replica may lag after a mode switch.
        // Steady-state authentication must not attempt an insert or take a row write lock.
        $state = DB::table('erp_authentication_states')->useWritePdo()->where('id', 1)->first();
        if ($state && $state->mode === $mode) {
            return $state;
        }

        return DB::transaction(function () use ($mode): object {
            // Recheck after obtaining the lock; another request may have completed the switch.
            $state = DB::table('erp_authentication_states')->useWritePdo()->where('id', 1)->lockForUpdate()->first();
            if (!$state) {
                $state = (object) ['id' => 1, 'mode' => $mode, 'version' => 1, 'updated_at' => now()];
                DB::table('erp_authentication_states')->insert((array) $state);
                return $state;
            }
            if ($state->mode !== $mode) {
                DB::table('erp_authentication_states')->where('id', 1)->update(['mode' => $mode, 'version' => (int) $state->version + 1, 'updated_at' => now()]);
                DB::table('dingtalk_challenges')->whereNull('consumed_at')->update(['consumed_at' => now()]);
                app(\App\Services\DingTalk\AuditService::class)->record('authentication.mode_changed', null, null,
                    ['previous_mode' => $state->mode, 'mode' => $mode, 'version' => (int) $state->version + 1]);
                $state->mode = $mode;
                $state->version = (int) $state->version + 1;
            }
            return $state;
        }, 3);
    }

    public function allows(Request $request, string $method): bool
    {
        $site = app(LoginSiteService::class)->forRequest($request);

        return $site ? (bool) $site->{$method.'_enabled'} : $this->current()->mode === $method;
    }

    public function requireMethod(Request $request, string $method): object
    {
        $site = app(LoginSiteService::class)->forRequest($request, true);
        $error = $method === 'password' ? 'dingtalk.errors.password_disabled' : 'dingtalk.mode.changed';

        if ($site) {
            abort_unless($site->{$method.'_enabled'}, 403, __($error));

            return (object) [
                'mode' => $method, 'version' => $site->{$method.'_version'},
                'site_id' => (int) $site->id, 'domain' => $site->domain, 'root_domain' => $site->rootDomain(),
                'company_id' => $site->dingtalk_company_id,
            ];
        }

        $state = $this->current();
        abort_unless($state->mode === $method, 403, __($error));

        return $state;
    }


    public function accountVersion(int $id): int
    {
        $version = DB::table('erp_authentication_account_versions')->useWritePdo()->where('account_id', $id)->value('version');
        if ($version !== null) {
            return (int) $version;
        }

        return DB::transaction(function () use ($id): int {
            $version = DB::table('erp_authentication_account_versions')->useWritePdo()
                ->where('account_id', $id)->lockForUpdate()->value('version');
            if ($version === null) {
                DB::table('erp_authentication_account_versions')->insert(['account_id' => $id, 'version' => 1]);
                return 1;
            }
            return (int) $version;
        }, 3);
    }

    public function stamp(Request $request, AdminUser $account, object $expected): void
    {
        $current = $this->requireMethod($request, $expected->mode);
        if ((int) $current->version !== (int) $expected->version
            || ($current->site_id ?? null) !== ($expected->site_id ?? null)
            || ($current->domain ?? null) !== ($expected->domain ?? null)
            || ($current->root_domain ?? null) !== ($expected->root_domain ?? null)) {
            throw new HttpException(401, __('dingtalk.mode.changed'));
        }
        $request->session()->put(self::KEY, ['mode' => $current->mode, 'version' => (int) $current->version,
            'site_id' => $current->site_id ?? null, 'domain' => $current->domain ?? null, 'root_domain' => $current->root_domain ?? null,
            'account_id' => (int) $account->id, 'account_version' => $this->accountVersion((int) $account->id), 'issued_at' => now()->timestamp]);
    }

    public function validate(Request $request, AdminUser $account): string
    {
        $proof = $request->session()->get(self::KEY, []);
        $site = app(LoginSiteService::class)->forRequest($request);
        $mode = $proof['mode'] ?? null;
        if ($site) {
            $validMethod = ($proof['site_id'] ?? null) === (int) $site->id
                && ($proof['domain'] ?? null) === $site->domain
                && ($proof['root_domain'] ?? $proof['domain'] ?? null) === $site->rootDomain()
                && in_array($mode, ['password', 'dingtalk'], true);
        } else {
            $state = $this->current();
            $validMethod = empty($proof['site_id']) && $mode === $state->mode
                && (int) ($proof['version'] ?? 0) === (int) $state->version;
        }
        $fresh = AdminUser::query()->find($account->id);
        if (!$fresh || (isset($fresh->status) && (int) $fresh->status !== AdminUser::STATUS_ACTIVE)
            || !$validMethod
            || (int) ($proof['account_id'] ?? 0) !== (int) $account->id
            || (int) ($proof['account_version'] ?? 0) !== $this->accountVersion((int) $account->id)
            || (int) ($proof['issued_at'] ?? 0) <= 0
            || now()->timestamp >= (int) ($proof['issued_at'] ?? 0) + app(\App\Services\SystemSettingService::class)->loginExpirationSeconds()) {
            throw new HttpException(401, __('dingtalk.mode.changed'));
        }
        $request->attributes->set('erp.auth_mode', $mode);
        if ($mode === 'password') $request->attributes->remove('dingtalk.identity');
        $request->attributes->set('dingtalk.account_id', (int) $account->id);
        return $mode;
    }
}
