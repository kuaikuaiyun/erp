<?php

namespace App\Portals\Logistics;

use App\Models\LogisticsOrder;
use App\Models\LogisticsPayPalDispute;
use Carbon\Carbon;
use Carbon\CarbonInterface;

class PayPalDisputeEventLogisticsSnapshotService
{
    /** @return array{examined:int,updated:int,missing_tracking:int,missing_checkpoint:int,invalid_payload:int} */
    public function backfill(int $limit = 0, bool $dryRun = false): array
    {
        $summary = ['examined' => 0, 'updated' => 0, 'missing_tracking' => 0, 'missing_checkpoint' => 0, 'invalid_payload' => 0];
        $query = LogisticsPayPalDispute::query()
            ->whereNull('event_logistics_captured_at')
            ->whereNotNull('logistics_order_id')
            ->with('order:id,tracking_number,tracking_raw_payload');

        if ($limit > 0) $query->limit($limit);

        $query->orderBy('id')->chunkById(200, function ($disputes) use (&$summary, $dryRun): void {
            foreach ($disputes as $dispute) {
                $summary['examined']++;
                $order = $dispute->order;
                if (! $order instanceof LogisticsOrder || blank($order->tracking_raw_payload)) {
                    $summary['missing_tracking']++;
                    continue;
                }

                $payload = json_decode((string) $order->tracking_raw_payload, true);
                if (! is_array($payload)) {
                    $summary['invalid_payload']++;
                    continue;
                }

                $checkpoint = $this->checkpointAtOrBefore($payload, $dispute->dispute_created_at);
                if ($checkpoint === null) {
                    $summary['missing_checkpoint']++;
                    continue;
                }

                $summary['updated']++;
                if ($dryRun) continue;

                $status = $this->logisticsStatus((string) ($checkpoint['checkpoint_delivery_status'] ?? ''));
                $dispute->forceFill([
                    'event_logistics_status' => $status,
                    'event_tracking_number' => trim((string) ($payload['tracking_number'] ?? $order->tracking_number)) ?: null,
                    'event_shipped' => in_array($status, [
                        LogisticsOrder::LOGISTICS_SHIPPED,
                        LogisticsOrder::LOGISTICS_IN_TRANSIT,
                        LogisticsOrder::LOGISTICS_DELIVERED,
                    ], true),
                    'event_logistics_captured_at' => now(),
                ])->save();
            }
        });

        return $summary;
    }

    private function checkpointAtOrBefore(array $payload, mixed $eventAt): ?array
    {
        if (! $eventAt instanceof CarbonInterface) return null;

        $checkpoints = array_merge(
            is_array(data_get($payload, 'origin_info.trackinfo')) ? data_get($payload, 'origin_info.trackinfo') : [],
            is_array(data_get($payload, 'destination_info.trackinfo')) ? data_get($payload, 'destination_info.trackinfo') : [],
        );
        $candidate = null;
        foreach ($checkpoints as $checkpoint) {
            if (! is_array($checkpoint)) continue;
            $at = $this->dateTime($checkpoint['checkpoint_date'] ?? null);
            if ($at === null || $at->greaterThan($eventAt)) continue;
            if ($candidate === null || $at->greaterThan($candidate['at'])) $candidate = ['at' => $at, 'row' => $checkpoint];
        }

        return $candidate['row'] ?? null;
    }

    private function dateTime(mixed $value): ?Carbon
    {
        if (! is_string($value) || trim($value) === '') return null;
        try {
            return Carbon::parse($value, config('app.timezone', 'UTC'));
        } catch (\Throwable) {
            return null;
        }
    }

    private function logisticsStatus(string $deliveryStatus): string
    {
        return match (strtolower(trim($deliveryStatus))) {
            'delivered' => LogisticsOrder::LOGISTICS_DELIVERED,
            'pickup', 'transit' => LogisticsOrder::LOGISTICS_IN_TRANSIT,
            'undelivered', 'exception' => LogisticsOrder::LOGISTICS_EXCEPTION,
            'expired' => LogisticsOrder::LOGISTICS_EXPIRED,
            'inforeceived' => LogisticsOrder::LOGISTICS_INFORMATION_RECEIVED,
            'notfound' => LogisticsOrder::LOGISTICS_NO_INFORMATION,
            default => LogisticsOrder::LOGISTICS_WAITING_QUERY,
        };
    }
}
