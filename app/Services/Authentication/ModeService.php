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
        return DB::transaction(function () use ($mode): object {
            DB::table('erp_authentication_states')->insertOrIgnore(['id' => 1, 'mode' => $mode, 'version' => 1, 'updated_at' => now()]);
            $state = DB::table('erp_authentication_states')->where('id', 1)->lockForUpdate()->first();
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

    public function requireDingTalk(): object
    {
        $state = $this->current();
        if ($state->mode !== 'dingtalk') throw new HttpException(403, __('dingtalk.mode.changed'));
        return $state;
    }

    public function accountVersion(int $id): int
    {
        DB::table('erp_authentication_account_versions')->insertOrIgnore(['account_id' => $id, 'version' => 1]);
        return (int) DB::table('erp_authentication_account_versions')->where('account_id', $id)->value('version');
    }

    public function stamp(Request $request, AdminUser $account, object $expected): void
    {
        $current = $this->current();
        if ($current->mode !== $expected->mode || (int) $current->version !== (int) $expected->version) {
            throw new HttpException(401, __('dingtalk.mode.changed'));
        }
        $request->session()->put(self::KEY, ['mode' => $current->mode, 'version' => (int) $current->version,
            'account_id' => (int) $account->id, 'account_version' => $this->accountVersion((int) $account->id), 'issued_at' => now()->timestamp]);
    }

    public function validate(Request $request, AdminUser $account): string
    {
        $state = $this->current();
        $proof = $request->session()->get(self::KEY, []);
        $fresh = AdminUser::query()->find($account->id);
        if (!$fresh || (isset($fresh->status) && (int) $fresh->status !== AdminUser::STATUS_ACTIVE)
            || ($proof['mode'] ?? null) !== $state->mode || (int) ($proof['version'] ?? 0) !== (int) $state->version
            || (int) ($proof['account_id'] ?? 0) !== (int) $account->id
            || (int) ($proof['account_version'] ?? 0) !== $this->accountVersion((int) $account->id)
            || (int) ($proof['issued_at'] ?? 0) <= 0
            || now()->timestamp >= (int) ($proof['issued_at'] ?? 0) + app(\App\Services\SystemSettingService::class)->loginExpirationSeconds()) {
            throw new HttpException(401, __('dingtalk.mode.changed'));
        }
        $request->attributes->set('erp.auth_mode', $state->mode);
        if ($state->mode === 'password') $request->attributes->remove('dingtalk.identity');
        $request->attributes->set('dingtalk.account_id', (int) $account->id);
        return $state->mode;
    }
}
