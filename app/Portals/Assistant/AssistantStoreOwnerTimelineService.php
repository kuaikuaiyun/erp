<?php

namespace App\Portals\Assistant;

use App\Models\AdminUser;
use App\Models\AssistantDomainStore;
use App\Models\AssistantDomainStoreOwnerHistory;
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
            'event' => AssistantDomainStoreOwnerHistory::EVENT_BASELINE,
            'effective_from' => $effectiveFrom,
            'actor_id' => $actor?->getKey(),
            'actor_name_snapshot' => $this->userName($actor),
        ]);
    }

    public function assign(AssistantDomainStore $store, ?AdminUser $newOwner, CarbonInterface $effectiveAt, ?AdminUser $actor = null): bool
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
            throw new \InvalidArgumentException('归属生效时间不能早于该店铺已有归属记录。');
        }

        $newOwnerId = $newOwner?->getKey();
        if ((int) ($active->owner_user_id ?? 0) === (int) ($newOwnerId ?? 0)) return false;

        $next = $histories->first(static fn (AssistantDomainStoreOwnerHistory $history): bool => $history->effective_from->greaterThan($effectiveAt));
        if ($active->effective_from->equalTo($effectiveAt)) {
            $active->update([
                'owner_user_id' => $newOwnerId,
                'owner_name_snapshot' => $this->userName($newOwner),
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
        $store->unsetRelation('ownerUser');
        $store->save();

        return true;
    }

    /** @return array{owner_user_id:?int,owner_name:?string,effective_from:?CarbonInterface} */
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
            'effective_from' => $history?->effective_from,
        ];
    }

    public function applySnapshotIfMissing(LogisticsOrder $order, ?AssistantDomainStore $store): void
    {
        if ($order->owner_assignment_resolved_at !== null) return;

        if ($store !== null) {
            $this->ensureBaseline($store);
        }

        $assignment = $store === null ? ['owner_user_id' => null, 'owner_name' => null, 'effective_from' => null] : $this->resolveAt($store, $order->order_created_at);
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
}
