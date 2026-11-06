<?php

namespace App\Portals\Finance;

use App\Jobs\SyncFinancePayPalOrders;
use App\Models\FinancePaymentAccount;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class FinanceOrderRefreshProgress
{
    public function attachBatch(int $actor, array $ids, array $failed, array $queued): array
    {
        return Cache::lock('finance:orders:batch-lock:'.$actor, 10)->block(5, function () use ($actor, $ids, $failed, $queued) {
            $batch = Cache::get('finance:orders:latest:'.$actor);
            $previous = $batch ? Cache::get('finance:orders:batch:'.$batch) : null;
            if (! $previous || $previous['actor'] !== $actor) {
                $batch = (string) Str::uuid();
                $previous = ['tasks' => [], 'failed' => []];
            }
            $byAccount = [];
            foreach (array_merge($previous['tasks'], $ids) as $id) {
                if ($task = $this->get($id)) $byAccount[$task['account_id']] = $id;
            }
            $state = ['actor' => $actor, 'tasks' => array_values($byAccount),
                'failed' => array_values(array_unique(array_merge(array_diff($previous['failed'] ?? [], $queued), $failed)))];
            Cache::put('finance:orders:batch:'.$batch, $state, now()->addDay());
            Cache::put('finance:orders:latest:'.$actor, $batch, now()->addDay());
            return ['batch' => $batch, ...$state];
        });
    }

    public function enqueue(FinancePaymentAccount $account, int $actor): string
    {
        return Cache::lock('finance:orders:enqueue:'.$account->id, 10)->block(5, function () use ($account, $actor) {
            $active = Cache::get('finance:orders:active:'.$account->id);
            if ($active && in_array($this->get($active)['status'] ?? '', ['queued', 'running'], true)) return $active;
            $id = (string) Str::uuid();
            Cache::put('finance:orders:task:'.$id, ['id' => $id, 'account_id' => $account->id,
                'account' => FinanceCatalog::simplified($account->account_name), 'status' => 'queued', 'count' => 0,
                'error' => null, 'baseline' => null, 'started_at' => null, 'queued_at' => time()], now()->addDay());
            Cache::put('finance:orders:active:'.$account->id, $id, now()->addDay());
            try {
                SyncFinancePayPalOrders::dispatch($account->id, $actor, $id);
            } catch (\Throwable $e) {
                $this->update($id, ['status' => 'failed', 'error' => '后台任务提交失败，请稍后重试。']);
                throw $e;
            }
            return $id;
        });
    }

    public function update(string $id, array $values): void
    {
        $task = Cache::get('finance:orders:task:'.$id);
        if ($task) Cache::put('finance:orders:task:'.$id, [...$task, ...$values], now()->addDay());
    }

    public function get(string $id): ?array
    {
        $task = Cache::get('finance:orders:task:'.$id);
        if (! $task) return null;
        if ($task['status'] === 'queued' && isset($task['queued_at']) && time() - $task['queued_at'] > 120) {
            $task['warning'] = '已排队超过2分钟，后台执行器可能未运行或前面的账户仍在同步，请检查队列。';
        }
        if ($task['status'] === 'running') {
            $task['count'] = (int) app(FinanceWorkbookService::class)->database()->table('finance_sync_logs')
                ->where('payment_account_id', $task['account_id'])->where('operation', 'transaction_search')
                ->where('id', '>', $task['baseline'] ?? 0)->sum('record_count');
            if ($task['started_at'] && time() - $task['started_at'] > 960) {
                $task['status'] = 'failed';
                $task['error'] = '后台任务超时或中断，请重试；已保存的交易不会重复入账。';
                $this->update($id, $task);
            }
        }
        return $task;
    }
}
