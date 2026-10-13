<?php

namespace App\Portals\Logistics;

use App\Jobs\AutoMatchTongtuOrderJob;
use App\Models\LogisticsOrder;
use Illuminate\Support\Facades\DB;

class LogisticsTongtuAutomationService
{
    public function scheduleForOrder(LogisticsOrder $order): void
    {
        $dispatch = DB::connection($order->getConnectionName())->transaction(function () use ($order): ?array {
            $locked = LogisticsOrder::query()->whereKey($order->getKey())->lockForUpdate()->first();
            if (!$locked || !$locked->assistant_domain_store_id || !$locked->paid_at) {
                return null;
            }

            $dueAt = $locked->paid_match_due_at ?: $locked->paid_at->copy()->addHours(24);
            if (!$locked->paid_match_due_at) {
                $locked->forceFill(['paid_match_due_at' => $dueAt])->save();
            }

            if ($locked->first_fulfilled_at && !$locked->auto_match_fulfillment_completed_at) {
                if ($locked->auto_match_fulfillment_scheduled_at) {
                    return null;
                }
                $locked->forceFill(['auto_match_fulfillment_scheduled_at' => now()])->save();

                return ['stage' => AutoMatchTongtuOrderJob::STAGE_FULFILLMENT, 'delay' => null];
            }

            if (!$locked->auto_match_paid_completed_at && !$locked->paid_match_scheduled_at) {
                $locked->forceFill(['paid_match_scheduled_at' => now()])->save();

                return ['stage' => AutoMatchTongtuOrderJob::STAGE_PAID_24H, 'delay' => $dueAt];
            }

            return null;
        });

        if ($dispatch === null) {
            return;
        }

        $pending = AutoMatchTongtuOrderJob::dispatch((int) $order->getKey(), $dispatch['stage'])
            ->onConnection('database')
            ->onQueue('logistics-tongtu');
        if ($dispatch['delay']) {
            $pending->delay($dispatch['delay']);
        }
        $pending->afterCommit();
    }
}
