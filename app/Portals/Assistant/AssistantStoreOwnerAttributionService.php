<?php

namespace App\Portals\Assistant;

use App\Models\AssistantDomainStoreOwnerHistory;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class AssistantStoreOwnerAttributionService
{
    /**
     * Load every ownership range once so report calculations can resolve many
     * orders without issuing one history query per order.
     *
     * @param iterable<int|string> $storeIds
     * @return Collection<int,Collection<int,AssistantDomainStoreOwnerHistory>>
     */
    public function timelines(iterable $storeIds): Collection
    {
        $ids = collect($storeIds)->map(fn ($id) => (int) $id)->filter()->unique()->values();
        if ($ids->isEmpty()) return collect();

        return AssistantDomainStoreOwnerHistory::query()
            ->whereIn('assistant_domain_store_id', $ids)
            ->orderBy('effective_from')
            ->orderBy('id')
            ->get()
            ->groupBy(fn (AssistantDomainStoreOwnerHistory $history) => (int) $history->assistant_domain_store_id);
    }

    /**
     * @param Collection<int,Collection<int,AssistantDomainStoreOwnerHistory>> $timelines
     * @return array{has_history:bool,owner_user_id:?int,operation_department_id:?int,owner_name:?string,operation_department_name:?string}
     */
    public function resolve(Collection $timelines, int $storeId, CarbonInterface|string|null $at): array
    {
        $at = $at instanceof CarbonInterface ? Carbon::instance($at)->utc() : Carbon::parse($at)->utc();
        /** @var Collection<int,AssistantDomainStoreOwnerHistory> $historyRows */
        $historyRows = $timelines->get($storeId, collect());
        $history = $historyRows->last(static fn (AssistantDomainStoreOwnerHistory $item): bool =>
            $item->effective_from->lessThanOrEqualTo($at)
            && ($item->effective_to === null || $item->effective_to->greaterThan($at))
        );

        return [
            'has_history' => $historyRows->isNotEmpty(),
            'owner_user_id' => $history?->owner_user_id === null ? null : (int) $history->owner_user_id,
            'operation_department_id' => $history?->operation_department_id === null ? null : (int) $history->operation_department_id,
            'owner_name' => $history?->owner_name_snapshot,
            'operation_department_name' => $history?->operation_department_name_snapshot,
        ];
    }

    /**
     * A lightweight cache version. Editing a historical effective time must
     * invalidate performance results immediately, even when no report changes.
     */
    public function cacheVersion(): string
    {
        $summary = AssistantDomainStoreOwnerHistory::query()
            ->selectRaw('COUNT(*) as total_rows, MAX(updated_at) as last_updated_at')
            ->first();

        return sha1(implode('|', [
            (string) ($summary?->total_rows ?? 0),
            (string) ($summary?->last_updated_at ?? ''),
        ]));
    }
}
