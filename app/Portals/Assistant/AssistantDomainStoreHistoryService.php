<?php

namespace App\Portals\Assistant;

use App\Models\AdminUser;
use App\Models\AssistantDomainStore;
use App\Models\AssistantDomainStoreChangeLog;

class AssistantDomainStoreHistoryService
{
    public function recordCreated(AssistantDomainStore $store, AdminUser $actor): void
    {
        $this->recordNew($store, $actor, 'created');
    }

    public function recordImported(AssistantDomainStore $store, AdminUser $actor): void
    {
        $this->recordNew($store, $actor, 'imported');
    }

    private function recordNew(AssistantDomainStore $store, AdminUser $actor, string $event): void
    {
        $changes = [];

        foreach ($store->historySnapshot() as $field => $value) {
            if ($value !== null && $value !== '') {
                $changes[$field] = ['before' => null, 'after' => $value];
            }
        }

        $this->record($store, $event, $changes, $actor);
    }

    /**
     * @param array<string, mixed> $before
     * @param array<string, mixed> $after
     */
    public function recordUpdated(AssistantDomainStore $store, array $before, array $after, AdminUser $actor): void
    {
        $changes = [];

        foreach ($after as $field => $value) {
            $previous = $before[$field] ?? null;

            if ($previous !== $value) {
                $changes[$field] = ['before' => $previous, 'after' => $value];
            }
        }

        if ($changes !== []) {
            $this->record($store, 'updated', $changes, $actor);
        }
    }

    /** @param array<string, array{before: mixed, after: mixed}> $changes */
    private function record(AssistantDomainStore $store, string $event, array $changes, AdminUser $actor): void
    {
        AssistantDomainStoreChangeLog::query()->create([
            'domain_store_id' => $store->getKey(),
            'event' => $event,
            'changes' => $changes,
            'actor_id' => $actor->getKey(),
            'actor_name_snapshot' => $actor->name,
        ]);
    }
}


