<?php

namespace App\Console\Commands;

use App\Models\LogisticsOrder;
use App\Portals\Logistics\SubscriptionOrderReference;
use Illuminate\Console\Command;

class BackfillLogisticsSubscriptionReferences extends Command
{
    protected $signature = 'logistics:subscription-references:backfill
        {--limit=0 : Maximum orders to inspect; 0 processes all}
        {--dry-run : Inspect matching records without saving changes}';

    protected $description = 'Backfill explicit subscription origin references from stored Shopify order payloads';

    public function handle(): int
    {
        $limit = max(0, (int) $this->option('limit'));
        $dryRun = (bool) $this->option('dry-run');
        $processed = 0;
        $matched = 0;
        $updated = 0;

        LogisticsOrder::query()
            ->select(['id', 'shopify_payload', 'subscription_type', 'subscription_origin_order_id', 'subscription_link_source', 'subscription_link_status'])
            ->whereNotNull('shopify_payload')
            ->orderBy('id')
            ->chunkById(200, function ($orders) use ($limit, $dryRun, &$processed, &$matched, &$updated): bool {
                foreach ($orders as $order) {
                    if ($limit > 0 && $processed >= $limit) {
                        return false;
                    }
                    $processed++;

                    $payload = json_decode((string) $order->shopify_payload, true);
                    if (! is_array($payload) || ! $this->isRecurring($payload['tags'] ?? [])) {
                        continue;
                    }

                    $attributes = $this->attributes($payload['customAttributes'] ?? []);
                    $origin = SubscriptionOrderReference::originOrderId($attributes);
                    if ($origin === null) {
                        continue;
                    }
                    $matched++;

                    $number = SubscriptionOrderReference::recurringNumber($attributes);
                    $values = [
                        'subscription_origin_order_id' => $origin,
                        'subscription_link_source' => __('logistics.subscription_reference_backfill.source'),
                        'subscription_link_status' => __('logistics.subscription_reference_backfill.confirmed'),
                    ];
                    if ($number !== null) {
                        $values['subscription_type'] = '订阅复购'.$number.'次';
                    }

                    if ($order->only(array_keys($values)) === $values) {
                        continue;
                    }
                    $updated++;
                    if (! $dryRun) {
                        $order->forceFill($values)->save();
                    }
                }

                return true;
            });

        $this->info(__('logistics.subscription_reference_backfill.completed', [
            'processed' => $processed,
            'matched' => $matched,
            'updated' => $updated,
            'mode' => $dryRun ? __('logistics.subscription_reference_backfill.dry_run') : __('logistics.subscription_reference_backfill.saved'),
        ]));

        return self::SUCCESS;
    }

    private function isRecurring(mixed $tags): bool
    {
        return collect(is_array($tags) ? $tags : [])
            ->map(static fn ($tag): string => mb_strtolower(trim((string) $tag)))
            ->contains('ouyicart subscription recurring order');
    }

    /** @return array<string,string> */
    private function attributes(mixed $attributes): array
    {
        $mapped = [];
        foreach (is_array($attributes) ? $attributes : [] as $attribute) {
            $key = trim((string) ($attribute['key'] ?? ''));
            if ($key !== '') {
                $mapped[$key] = trim((string) ($attribute['value'] ?? ''));
            }
        }

        return $mapped;
    }
}
