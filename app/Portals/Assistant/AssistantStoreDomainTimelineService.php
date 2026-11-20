<?php

namespace App\Portals\Assistant;

use App\Models\AssistantDomainStoreChangeLog;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class AssistantStoreDomainTimelineService
{
    /** Resolve a page in one query; never substitute the store's current domain. */
    public function domainsForOrders(Collection $orders): array
    {
        $storeIds = $orders->pluck('assistant_domain_store_id')->filter()->unique()->values();
        $logs = $storeIds->isEmpty() ? collect() : AssistantDomainStoreChangeLog::query()
            ->whereIn('domain_store_id', $storeIds)
            ->orderBy('created_at')->orderBy('id')
            ->get(['id', 'domain_store_id', 'event', 'changes', 'created_at'])
            ->filter(fn ($log) => is_array($log->changes['domain'] ?? null))
            ->groupBy('domain_store_id');

        $domains = [];
        foreach ($orders as $order) {
            $domains[$order->getKey()] = $this->resolve(
                $logs->get($order->assistant_domain_store_id, collect()), $order->order_created_at
            );
        }

        return $domains;
    }

    private function resolve(Collection $logs, mixed $orderedAt): ?string
    {
        if ($orderedAt === null || $logs->isEmpty()) return null;
        $at = Carbon::parse($orderedAt)->utc();
        $previous = null;
        foreach ($logs as $log) {
            $change = $log->changes['domain'];
            if ($log->created_at === null) return null;
            if ($at->lessThan($log->created_at)) {
                // An update explicitly records the domain in use before that change.
                // Conflicting adjacent records leave the intervening period unknown.
                $before = $this->domain($change['before'] ?? null);
                if ($previous !== null && $this->domain($previous->changes['domain']['after'] ?? null) !== $before) return null;
                return $before;
            }
            $previous = $log;
        }

        return $this->domain($previous?->changes['domain']['after'] ?? null);
    }

    private function domain(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
