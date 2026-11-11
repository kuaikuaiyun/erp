<?php

namespace App\Portals\Assistant;

use App\Models\AdminUser;
use App\Models\AssistantDomainStore;
use App\Models\AssistantDomainStoreOwnerHistory;
use App\Models\Department;
use App\Models\LogisticsOrder;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

class AssistantStoreOwnerTimelineService
{
    /**
     * Create the one-time baseline from the store's current selected owner.
     * Existing orders are deliberately left untouched here; the initializer
     * command performs that explicit legacy migration.
     */
    public function ensureBaseline(AssistantDomainStore $store, ?AdminUser $actor = null, ?CarbonInterface $from = null): AssistantDomainStoreOwnerHistory
    {
        $existing = AssistantDomainStoreOwnerHistory::query()
            ->where('assistant_domain_store_id', $store->getKey())
            ->orderBy('effective_from')
            ->first();

        if ($existing) return $existing;

        $owner = $this->ownerForStore($store);
        $firstOrderAt = LogisticsOrder::query()
            ->where('assistant_domain_store_id', $store->getKey())
            ->min('order_created_at');
        $effectiveFrom = $from ?: ($firstOrderAt ? Carbon::parse($firstOrderAt) : now());

        return AssistantDomainStoreOwnerHistory::query()->create([
            'assistant_domain_store_id' => $store->getKey(),
            'owner_user_id' => $owner?->getKey(),
            'owner_name_snapshot' => $this->userName($owner),
            'operation_department_id' => $store->owner_operation_department_id,
            'operation_department_name_snapshot' => $this->operationDepartmentName($store->owner_operation_department_id),
            'event' => AssistantDomainStoreOwnerHistory::EVENT_BASELINE,
            'effective_from' => $effectiveFrom,
            'actor_id' => $actor?->getKey(),
            'actor_name_snapshot' => $this->userName($actor),
        ]);
    }

    public function assign(AssistantDomainStore $store, ?AdminUser $newOwner, ?Department $operationDepartment, CarbonInterface $effectiveAt, ?AdminUser $actor = null): bool
    {
        $effectiveAt = Carbon::instance($effectiveAt)->utc();
        $this->ensureBaseline($store, $actor);

        $histories = AssistantDomainStoreOwnerHistory::query()
            ->where('assistant_domain_store_id', $store->getKey())
            ->orderBy('effective_from')
            ->get();
        $active = $histories->first(static fn (AssistantDomainStoreOwnerHistory $history): bool =>
            $history->effective_from->lessThanOrEqualTo($effectiveAt)
            && ($history->effective_to === null || $history->effective_to->greaterThan($effectiveAt))
        );

        if ($active === null) {
            // Historical ownership may be entered after a newer history range
            // already exists. Insert a preceding, closed range in that case.
            $newOwnerId = $newOwner?->getKey();
            $next = $histories->first(static fn (AssistantDomainStoreOwnerHistory $history): bool => $history->effective_from->greaterThan($effectiveAt));

            // An unassigned baseline is system-generated when a store had no
            // owner. It must not split a subsequently backdated assignment.
            if ($next !== null
                && $next->event === AssistantDomainStoreOwnerHistory::EVENT_BASELINE
                && $next->owner_user_id === null) {
                $baseline = $next;
                $next = $histories->first(static fn (AssistantDomainStoreOwnerHistory $history): bool => $history->effective_from->greaterThan($baseline->effective_from));
                $baseline->delete();

                if ($next !== null && (int) ($next->owner_user_id ?? 0) === (int) ($newOwnerId ?? 0)) {
                    $next->update(['effective_from' => $effectiveAt]);
                    $this->refreshSnapshots($store, $effectiveAt);

                    return true;
                }
            }

            AssistantDomainStoreOwnerHistory::query()->create([
                'assistant_domain_store_id' => $store->getKey(),
                'owner_user_id' => $newOwnerId,
                'owner_name_snapshot' => $this->userName($newOwner),
                'operation_department_id' => $operationDepartment?->getKey(),
                'operation_department_name_snapshot' => $operationDepartment?->name,
                'event' => $newOwner === null ? AssistantDomainStoreOwnerHistory::EVENT_CLEARED : AssistantDomainStoreOwnerHistory::EVENT_ASSIGNED,
                'effective_from' => $effectiveAt,
                'effective_to' => $next?->effective_from,
                'actor_id' => $actor?->getKey(),
                'actor_name_snapshot' => $this->userName($actor),
            ]);

            $this->refreshSnapshots($store, $effectiveAt);
            $current = $this->resolveAt($store, now());
            $store->owner_user_id = $current['owner_user_id'];
            $store->owner_operation_department_id = $current['operation_department_id'];
            $store->unsetRelation('ownerUser');
            $store->unsetRelation('ownerOperationDepartment');
            $store->save();

            return true;
        }

        $newOwnerId = $newOwner?->getKey();
        $newOperationDepartmentId = $operationDepartment?->getKey();
        if (
            (int) ($active->owner_user_id ?? 0) === (int) ($newOwnerId ?? 0)
            && (int) ($active->operation_department_id ?? 0) === (int) ($newOperationDepartmentId ?? 0)
        ) return false;

        $next = $histories->first(static fn (AssistantDomainStoreOwnerHistory $history): bool => $history->effective_from->greaterThan($effectiveAt));
        if ($active->effective_from->equalTo($effectiveAt)) {
            $active->update([
                'owner_user_id' => $newOwnerId,
                'owner_name_snapshot' => $this->userName($newOwner),
                'operation_department_id' => $newOperationDepartmentId,
                'operation_department_name_snapshot' => $operationDepartment?->name,
                'event' => $newOwner === null ? AssistantDomainStoreOwnerHistory::EVENT_CLEARED : AssistantDomainStoreOwnerHistory::EVENT_ASSIGNED,
                'actor_id' => $actor?->getKey(),
                'actor_name_snapshot' => $this->userName($actor),
            ]);
        } else {
            $active->update(['effective_to' => $effectiveAt]);
            AssistantDomainStoreOwnerHistory::query()->create([
                'assistant_domain_store_id' => $store->getKey(),
                'owner_user_id' => $newOwnerId,
                'owner_name_snapshot' => $this->userName($newOwner),
                'operation_department_id' => $newOperationDepartmentId,
                'operation_department_name_snapshot' => $operationDepartment?->name,
                'event' => $newOwner === null ? AssistantDomainStoreOwnerHistory::EVENT_CLEARED : AssistantDomainStoreOwnerHistory::EVENT_TRANSFERRED,
                'effective_from' => $effectiveAt,
                'effective_to' => $next?->effective_from,
                'actor_id' => $actor?->getKey(),
                'actor_name_snapshot' => $this->userName($actor),
            ]);
        }

        $this->refreshSnapshots($store, $effectiveAt);
        $current = $this->resolveAt($store, now());
        $store->owner_user_id = $current['owner_user_id'];
        $store->owner_operation_department_id = $current['operation_department_id'];
        $store->unsetRelation('ownerUser');
        $store->unsetRelation('ownerOperationDepartment');
        $store->save();

        return true;
    }

    /**
     * Move one existing ownership-history boundary without changing its owner,
     * event, or audit actor. Adjacent ranges are kept contiguous and every
     * affected logistics-order snapshot is recalculated from the earliest
     * changed boundary.
     */
    public function reschedule(AssistantDomainStore $store, AssistantDomainStoreOwnerHistory $history, CarbonInterface $effectiveAt): void
    {
        if ((int) $history->assistant_domain_store_id !== (int) $store->getKey()) {
            throw new \InvalidArgumentException('该归属历史不属于当前店铺。');
        }

        $effectiveAt = Carbon::instance($effectiveAt)->utc();
        $previousEffectiveAt = Carbon::instance($history->effective_from)->utc();
        $exists = AssistantDomainStoreOwnerHistory::query()
            ->where('assistant_domain_store_id', $store->getKey())
            ->whereKey($history->getKey())
            ->lockForUpdate()
            ->exists();

        if (!$exists) {
            throw new \InvalidArgumentException('未找到该归属历史。');
        }

        // A historical effective time is intentionally free to move across
        // earlier/later records. Re-sort every range afterwards instead of
        // rejecting it because of its former neighbours.
        $history->forceFill([
            'effective_from' => $effectiveAt,
            'effective_to' => null,
        ])->save();

        $histories = AssistantDomainStoreOwnerHistory::query()
            ->where('assistant_domain_store_id', $store->getKey())
            ->orderBy('effective_from')
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->values();

        foreach ($histories as $index => $item) {
            $next = $histories->get($index + 1);
            $effectiveTo = $next?->effective_from;

            if (($item->effective_to?->format('Y-m-d H:i:s.u') ?? null) !== ($effectiveTo?->format('Y-m-d H:i:s.u') ?? null)) {
                $item->forceFill(['effective_to' => $effectiveTo])->save();
            }
        }

        $this->refreshSnapshots($store, $previousEffectiveAt->lessThan($effectiveAt) ? $previousEffectiveAt : $effectiveAt);
        $current = $this->resolveAt($store, now());
        $store->forceFill([
            'owner_user_id' => $current['owner_user_id'],
            'owner_operation_department_id' => $current['operation_department_id'],
        ]);
        $store->unsetRelation('ownerUser');
        $store->unsetRelation('ownerOperationDepartment');
        $store->save();
    }

    /** @return array{owner_user_id:?int,owner_name:?string,operation_department_id:?int,operation_department_name:?string,effective_from:?CarbonInterface} */
    public function resolveAt(AssistantDomainStore $store, CarbonInterface|string|null $at): array
    {
        $at = $at ? Carbon::parse($at)->utc() : now()->utc();
        $history = AssistantDomainStoreOwnerHistory::query()
            ->where('assistant_domain_store_id', $store->getKey())
            ->where('effective_from', '<=', $at)
            ->where(static fn ($query) => $query->whereNull('effective_to')->orWhere('effective_to', '>', $at))
            ->orderByDesc('effective_from')
            ->first();

        return [
            'owner_user_id' => $history?->owner_user_id === null ? null : (int) $history->owner_user_id,
            'owner_name' => $history?->owner_name_snapshot,
            'operation_department_id' => $history?->operation_department_id === null ? null : (int) $history->operation_department_id,
            'operation_department_name' => $history?->operation_department_name_snapshot,
            'effective_from' => $history?->effective_from,
        ];
    }

    public function applySnapshotIfMissing(LogisticsOrder $order, ?AssistantDomainStore $store): void
    {
        if ($order->owner_assignment_resolved_at !== null) return;

        if ($store !== null) {
            $this->ensureBaseline($store);
        }

        $assignment = $store === null ? ['owner_user_id' => null, 'owner_name' => null, 'operation_department_id' => null, 'operation_department_name' => null, 'effective_from' => null] : $this->resolveAt($store, $order->order_created_at);
        $order->assigned_owner_user_id = $assignment['owner_user_id'];
        $order->assigned_owner_name = $assignment['owner_name'];
        $order->owner_assignment_effective_from = $assignment['effective_from'];
        $order->owner_assignment_resolved_at = now();
    }

    public function refreshSnapshots(AssistantDomainStore $store, ?CarbonInterface $from = null): int
    {
        $count = 0;
        LogisticsOrder::query()
            ->where('assistant_domain_store_id', $store->getKey())
            ->when($from, static fn ($query) => $query->where('order_created_at', '>=', $from))
            ->orderBy('id')
            ->chunkById(200, function ($orders) use ($store, &$count): void {
                foreach ($orders as $order) {
                    $assignment = $this->resolveAt($store, $order->order_created_at);
                    $order->forceFill([
                        'assigned_owner_user_id' => $assignment['owner_user_id'],
                        'assigned_owner_name' => $assignment['owner_name'],
                        'owner_assignment_effective_from' => $assignment['effective_from'],
                        'owner_assignment_resolved_at' => now(),
                    ])->save();
                    $count++;
                }
            });

        return $count;
    }

    private function ownerForStore(AssistantDomainStore $store): ?AdminUser
    {
        if ($store->relationLoaded('ownerUser')) return $store->ownerUser;
        return $store->owner_user_id === null ? null : AdminUser::query()->find($store->owner_user_id);
    }

    private function userName(?AdminUser $user): ?string
    {
        return $user === null ? null : (string) ($user->name ?: $user->username ?: $user->getKey());
    }

    private function operationDepartmentName(?int $departmentId): ?string
    {
        return $departmentId === null ? null : Department::query()->whereKey($departmentId)->value('name');
    }
}
