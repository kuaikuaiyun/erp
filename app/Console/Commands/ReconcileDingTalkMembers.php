<?php

namespace App\Console\Commands;

use App\Models\DingTalkIdentity;
use App\Services\DingTalk\AuditService;
use App\Services\DingTalk\MembershipService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

class ReconcileDingTalkMembers extends Command
{
    protected $signature = 'dingtalk:reconcile';

    public function __construct()
    {
        parent::__construct();
        $this->setDescription(__('dingtalk.console.reconcile_description'));
    }

    public function handle(MembershipService $memberships, AuditService $audit): int
    {
        $lock = Cache::lock('dingtalk:reconcile', max(120, config('dingtalk.reconcile_batch') * (config('dingtalk.http_timeout') * 3 + 5)));
        if (!$lock->get()) {
            return self::SUCCESS;
        }
        $checked = $failed = 0;
        try {
            $identities = DingTalkIdentity::query()->whereIn('corp_id', \App\Models\DingTalkCompany::query()->where('enabled',true)->select('corp_id'))->where('active', true)
                ->where(fn ($query) => $query->whereNull('next_check_at')->orWhere('next_check_at', '<=', now()))
                ->orderBy('next_check_at')->orderBy('id')->limit(config('dingtalk.reconcile_batch'))->get();
            foreach ($identities as $identity) {
                // Move failed rows back as well, preventing an outage for one member from starving the rest.
                DingTalkIdentity::query()->whereKey($identity->id)->update(['next_check_at' => now()->addSeconds(config('dingtalk.reconcile_seconds'))]);
                try {
                    $memberships->refresh($identity);
                    $checked++;
                } catch (HttpException $exception) {
                    $failed++;
                    $audit->record('membership.check_failed', $identity, null, ['status' => $exception->getStatusCode()]);
                }
            }
            DB::table('dingtalk_challenges')->where('expires_at', '<', now()->subDay())->delete();
            $this->line(__('dingtalk.console.reconciled', ['checked' => $checked, 'failed' => $failed]));
            return $failed > 0 ? self::FAILURE : self::SUCCESS;
        } finally {
            $lock->release();
        }
    }
}
