<?php

namespace App\Services\FinanceSync;

use Illuminate\Database\Connection;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

final class FinanceSyncPublisher
{
    public function __construct(private FinanceSyncCatalog $catalog, private FinanceSyncOrderFields $orderFields) {}

    public function run(int $batchSize, int $batchCount): int
    {
        $db = FinanceSyncCatalog::connection();
        $published = 0;
        for ($batch = 0; $batch < $batchCount; $batch++) {
            $count = $db->transaction(fn () => $this->publishBatch($db, $batchSize), 3);
            if ($count === null) {
                break;
            }
            $published += $count;
        }

        return $published;
    }

    private function publishBatch(Connection $db, int $size): ?int
    {
        // Allocate versions in commit order even when two workers run at once.
        $state = $db->table(FinanceSyncCatalog::STATE)->where('id', 1)->lockForUpdate()->first();
        $profile = FinanceSyncCatalog::publicationProfile();
        if ($state->publication_profile !== $profile) {
            $state->dataset = (string) Str::uuid();
            $state->publication_profile = $profile;
            $state->ready = false;
            $state->source_index = 0;
            $state->source_after_id = 0;
            $state->source_until_id = null;
        }

        $pending = collect();
        if ($state->ready) {
            $pending = $db->table(FinanceSyncCatalog::PENDING)->orderBy('id')->limit($size)->get();
            if ($pending->isEmpty()) {
                return null;
            }
            $requested = $pending->groupBy('resource')->map(fn (Collection $rows) => $rows->pluck('source_id'))->all();
        } else {
            $requested = $this->nextSnapshotBatch($db, $state, $size);
        }

        $count = $this->writeRecords($db, $state, $requested, $pending->isNotEmpty());
        foreach ($pending->groupBy('revision') as $revision => $rows) {
            // A source write during this batch increases the revision. Its
            // queue entry stays pending even if the older version was published.
            $db->table(FinanceSyncCatalog::PENDING)->where('revision', $revision)
                ->whereIntegerInRaw('id', $rows->pluck('id'))->delete();
        }

        $db->table(FinanceSyncCatalog::STATE)->where('id', 1)->update([
            'dataset' => $state->dataset,
            'version' => $state->version,
            'source_index' => $state->source_index,
            'source_after_id' => $state->source_after_id,
            'source_until_id' => $state->source_until_id,
            'publication_profile' => $state->publication_profile,
            'ready' => $state->ready,
        ]);

        return $count;
    }

    private function nextSnapshotBatch(Connection $db, object $state, int $size): array
    {
        $resources = array_keys(FinanceSyncCatalog::RESOURCES);
        $resource = $resources[$state->source_index] ?? null;
        if ($resource === null) {
            $state->ready = true;

            return [];
        }

        $query = $db->table(FinanceSyncCatalog::table($resource));
        $state->source_until_id ??= (clone $query)->max('id') ?? 0;
        $ids = $query->where('id', '>', $state->source_after_id)->where('id', '<=', $state->source_until_id)
            ->orderBy('id')->limit($size)->pluck('id');

        if ($ids->count() === $size) {
            $state->source_after_id = $ids->last();
        } else {
            $state->source_index++;
            $state->source_after_id = 0;
            $state->source_until_id = null;
        }

        return $ids->isEmpty() ? [] : [$resource => $ids];
    }

    private function writeRecords(Connection $db, object $state, array $requested, bool $includeDependentReferences): int
    {
        $sourceRows = [];
        foreach ($requested as $resource => $ids) {
            $sourceRows[$resource] = $db->table(FinanceSyncCatalog::table($resource))->whereIntegerInRaw('id', $ids)
                ->get(FinanceSyncCatalog::columns($resource))->keyBy('id');
        }

        [$orderFields, $referenceFields] = $this->preparePaymentFields($db, $requested, $sourceRows, $includeDependentReferences);
        $updates = [];
        $changedAt = now('UTC')->format('Y-m-d H:i:s');
        foreach ($requested as $resource => $ids) {
            $previous = $db->table(FinanceSyncCatalog::RECORDS)->where('resource', $resource)->whereIntegerInRaw('source_id', $ids)
                ->get(['source_id', 'is_deleted', 'payload'])->keyBy('source_id');

            foreach ($ids as $id) {
                $source = $sourceRows[$resource]->get($id);
                $old = $previous->get($id);
                $record = $source ? $this->catalog->format(FinanceSyncCatalog::columns($resource), $source, $resource) : null;
                if ($record !== null && $resource === FinanceSyncCatalog::ORDERS) {
                    $record = $this->orderRecord($record, $orderFields[$id]);
                }
                if ($record !== null && $resource === FinanceSyncCatalog::REFERENCES) {
                    $record += $referenceFields[$id] ?? FinanceSyncCatalog::AMOUNT;
                }

                $change = $this->changeType($resource, $record, $old);
                if ($change === null) {
                    continue;
                }
                $updates[] = [
                    'resource' => $resource,
                    'source_id' => $id,
                    'version' => ++$state->version,
                    'change_type' => $change,
                    'is_deleted' => $record === null,
                    'payload' => $record === null ? null : json_encode($record, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                    'changed_at' => $changedAt,
                ];
            }
        }

        foreach (array_chunk($updates, 100) as $chunk) {
            $db->table(FinanceSyncCatalog::RECORDS)->upsert($chunk, ['resource', 'source_id'],
                ['version', 'change_type', 'is_deleted', 'payload', 'changed_at']);
        }

        return count($updates);
    }

    private function preparePaymentFields(Connection $db, array &$requested, array &$sourceRows, bool $expand): array
    {
        $orderIds = $requested[FinanceSyncCatalog::ORDERS] ?? collect();
        $referenceOrderIds = ($sourceRows[FinanceSyncCatalog::REFERENCES] ?? collect())->pluck('logistics_order_id');
        $ids = $orderIds->merge($referenceOrderIds)->filter()->unique()->values();
        if ($ids->isEmpty()) {
            return [[], []];
        }

        $references = $db->table(FinanceSyncCatalog::table(FinanceSyncCatalog::REFERENCES))
            ->whereIntegerInRaw('logistics_order_id', $ids)
            ->get(FinanceSyncCatalog::columns(FinanceSyncCatalog::REFERENCES))->keyBy('id');
        $orders = $sourceRows[FinanceSyncCatalog::ORDERS] ?? collect();
        $moreOrderIds = $ids->diff($orderIds);
        if ($moreOrderIds->isNotEmpty()) {
            $moreOrders = $db->table(FinanceSyncCatalog::table(FinanceSyncCatalog::ORDERS))->whereIntegerInRaw('id', $moreOrderIds)
                ->get(['id', 'paypal_account_id', 'bill_number', 'shopify_payload', 'order_created_at', 'paid_at'])->keyBy('id');
            $orders = $orders->union($moreOrders);
        }

        if ($expand && $orderIds->isNotEmpty()) {
            $affected = $references->whereIn('logistics_order_id', $orderIds);
            if ($affected->isNotEmpty()) {
                $requested[FinanceSyncCatalog::REFERENCES] = ($requested[FinanceSyncCatalog::REFERENCES] ?? collect())
                    ->merge($affected->pluck('id'))->unique()->values();
                $sourceRows[FinanceSyncCatalog::REFERENCES] = ($sourceRows[FinanceSyncCatalog::REFERENCES] ?? collect())->union($affected);
            }
        }

        return $this->orderFields->forOrders($db, $orders, $references);
    }

    private function orderRecord(array $record, array $derived): array
    {
        // The existing shipping column may contain non-USD shopMoney.
        if ($derived['shop_order_currency'] !== null && $derived['shop_order_currency'] !== 'USD') {
            $record['total_shipping_usd'] = null;
        }

        return array_replace($record, $derived);
    }

    private function changeType(string $resource, ?array $record, ?object $old): ?string
    {
        $oldRecord = $old?->payload === null ? null : json_decode($old->payload, true, 512, JSON_THROW_ON_ERROR);
        if ($record === $oldRecord) {
            return null;
        }

        return match (true) {
            $record === null => 'deleted',
            $oldRecord === null => 'created',
            $this->catalog->disabled($resource, $record) && ! $this->catalog->disabled($resource, $oldRecord) => 'disabled',
            default => 'updated',
        };
    }
}
