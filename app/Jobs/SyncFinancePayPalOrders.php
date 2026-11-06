<?php

namespace App\Jobs;

use App\Models\FinancePaymentAccount;
use App\Portals\Finance\FinancePayPalSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SyncFinancePayPalOrders implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 900;
    public int $tries = 1;
    public ?string $progressId = null;

    public function __construct(public int $accountId, public int $actorId, ?string $progressId = null)
    {
        $this->progressId = $progressId;
        $this->onConnection('finance_orders')->onQueue('finance-orders');
    }

    public function handle(FinancePayPalSyncService $sync): void
    {
        $progress = app(\App\Portals\Finance\FinanceOrderRefreshProgress::class);
        $account = FinancePaymentAccount::query()->find($this->accountId);
        if (! $account || $account->status !== 'enabled' || $account->account_type !== 'paypal') {
            if ($this->progressId) $progress->update($this->progressId, ['status' => 'skipped', 'error' => '账户已停用或删除，已跳过。']);
            return;
        }
        // The sync service records both success and failure in finance_sync_logs.
        if ($this->progressId) {
            $baseline = (int) app(\App\Portals\Finance\FinanceWorkbookService::class)->database()->table('finance_sync_logs')->max('id');
            $progress->update($this->progressId, ['status' => 'running', 'baseline' => $baseline, 'started_at' => time()]);
        }
        try {
            $result = $sync->refreshOrders($account, $this->actorId);
            if ($this->progressId) $progress->update($this->progressId, [
                'status' => $result['ok'] ? 'success' : 'failed', 'count' => $result['count'] ?? 0, 'error' => $result['error'] ?? null,
            ]);
        } catch (\Throwable $e) {
            $this->failed($e);
            throw $e;
        }
    }
    public function failed(?\Throwable $e): void
    {
        if ($this->progressId) {
            $progress = app(\App\Portals\Finance\FinanceOrderRefreshProgress::class);
            $task = $progress->get($this->progressId);
            $progress->update($this->progressId, ['status' => 'failed', 'count' => $task['count'] ?? 0,
                'error' => '后台任务执行失败或超时，请检查同步记录后重试。']);
        }
    }
}
