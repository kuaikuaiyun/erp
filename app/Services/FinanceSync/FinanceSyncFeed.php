<?php

namespace App\Services\FinanceSync;

use App\Http\Middleware\AuthenticateFinanceSync;
use Illuminate\Database\Query\Builder;

final class FinanceSyncFeed
{
    public function __construct(private FinanceSyncCatalog $catalog) {}

    public function snapshot(string $resource, ?string $cursor, int $limit): array
    {
        $state = $this->state();
        [$after, $anchor] = $cursor === null
            ? [0, (int) $state->version]
            : $this->open($cursor, $state->dataset, 'snapshot', $resource);
        $rows = $this->records($resource)->where('is_deleted', false)->where('source_id', '>', $after)
            ->where('version', '<=', $anchor)->orderBy('source_id')->limit($limit + 1)
            ->get(['source_id', 'payload']);
        $page = $rows->take($limit);
        $more = $rows->count() > $limit;

        return [
            $page->map(fn (object $row) => $this->payload($resource, $row))->all(),
            [
                'next_cursor' => $more ? $this->seal($state->dataset, 'snapshot', $resource, (int) $page->last()->source_id, $anchor) : null,
                'has_more' => $more,
                'changes_cursor' => $this->seal($state->dataset, 'changes', $resource, $anchor),
            ],
        ];
    }

    public function changes(string $resource, string $cursor, int $limit): array
    {
        $state = $this->state();
        [$after] = $this->open($cursor, $state->dataset, 'changes', $resource);
        $rows = $this->records($resource)->where('version', '>', $after)
            ->orderBy('version')->limit($limit + 1)->get(['source_id', 'version', 'change_type', 'changed_at', 'payload']);
        $page = $rows->take($limit);

        return [
            $page->map(fn (object $row) => [
                'change_type' => $row->change_type,
                'change_version' => (string) $row->version,
                'changed_at' => $this->catalog->date($row->changed_at, 'UTC'),
                ...$this->payload($resource, $row),
            ])->all(),
            [
                'next_cursor' => $this->seal($state->dataset, 'changes', $resource, (int) ($page->last()->version ?? $after)),
                'has_more' => $rows->count() > $limit,
            ],
        ];
    }

    public function logistics(int $order): array
    {
        $this->state();
        $record = FinanceSyncCatalog::connection()->table(FinanceSyncCatalog::table(FinanceSyncCatalog::ORDERS))
            ->useWritePdo()->where('id', $order)->first(FinanceSyncCatalog::LOGISTICS);
        abort_if($record === null, 404);

        return $this->catalog->format(FinanceSyncCatalog::LOGISTICS, $record, FinanceSyncCatalog::ORDERS);
    }

    public function logisticsLogs(int $order, ?string $cursor, int $limit): array
    {
        $state = $this->state();
        $resource = 'logistics-logs:'.$order;
        $before = $cursor === null ? null : $this->open($cursor, $state->dataset, 'logs', $resource)[0];
        $db = FinanceSyncCatalog::connection();
        abort_unless($db->table(FinanceSyncCatalog::table(FinanceSyncCatalog::ORDERS))->useWritePdo()->where('id', $order)->exists(), 404);
        $rows = $db->table('logistics_sync_logs')->useWritePdo()->where('logistics_order_id', $order)
            ->when($before !== null, fn (Builder $query) => $query->where('id', '<', $before))
            ->orderByDesc('id')->limit($limit + 1)->get(FinanceSyncCatalog::LOGS);
        $more = $rows->count() > $limit;
        $page = $rows->take($limit);
        $data = $page->map(function (object $row): array {
            $data = $this->catalog->format(FinanceSyncCatalog::LOGS, $row, 'logistics-logs');
            $summary = match ($data['status']) {
                'success', 'completed', 'synced', 'matched' => 'success',
                'failed', 'error' => 'failed',
                'skipped' => 'skipped',
                'pending', 'processing', 'queued' => 'pending',
                default => null,
            };
            $data['message'] = $summary === null ? null : __("finance_sync.log_summary.$summary");

            return $data;
        })->all();

        return [$data, [
            'next_cursor' => $more ? $this->seal($state->dataset, 'logs', $resource, (int) $page->last()->id) : null,
            'has_more' => $more,
        ]];
    }

    private function records(string $resource): Builder
    {
        return FinanceSyncCatalog::connection()->table(FinanceSyncCatalog::RECORDS)->useWritePdo()->where('resource', $resource);
    }

    private function state(): object
    {
        $state = FinanceSyncCatalog::connection()->table(FinanceSyncCatalog::STATE)->useWritePdo()
            ->where('id', 1)->first(['dataset', 'publication_profile', 'ready', 'version']);
        abort_unless($state && $state->ready && $state->publication_profile === FinanceSyncCatalog::publicationProfile(),
            503, 'SYNC_NOT_READY', ['Retry-After' => '60']);

        return $state;
    }

    private function payload(string $resource, object $row): array
    {
        if ($row->payload === null) {
            return ['id' => (string) $row->source_id];
        }

        $payload = json_decode($row->payload, true, 512, JSON_THROW_ON_ERROR);
        [$option, $fields] = FinanceSyncCatalog::OPTIONS[$resource] ?? [null, []];
        if ($option && ! config('finance_sync.'.$option)) {
            $payload = array_diff_key($payload, array_flip($fields));
        }

        return $payload;
    }

    private function seal(string $dataset, string $mode, string $resource, int $pos, int $anchor = 0): string
    {
        $payload = json_encode([$dataset, $mode, $resource, AuthenticateFinanceSync::CLIENT, $pos, $anchor], JSON_THROW_ON_ERROR);
        $encoded = rtrim(strtr(base64_encode($payload), '+/', '-_'), '=');

        return $encoded.'.'.$this->sign($encoded);
    }

    private function open(string $cursor, string $dataset, string $mode, string $resource): array
    {
        $parts = explode('.', $cursor, 2);
        abort_unless(count($parts) === 2 && hash_equals($this->sign($parts[0]), $parts[1]), 422, 'INVALID_CURSOR');
        $json = base64_decode(strtr($parts[0], '-_', '+/'), true);
        $payload = $json === false ? null : json_decode($json, true);
        abort_unless(is_array($payload) && count($payload) === 6
            && array_slice($payload, 0, 4) === [$dataset, $mode, $resource, AuthenticateFinanceSync::CLIENT]
            && is_int($payload[4]) && $payload[4] >= 0 && is_int($payload[5]) && $payload[5] >= 0, 422, 'INVALID_CURSOR');

        return [$payload[4], $payload[5]];
    }

    private function sign(string $encoded): string
    {
        $key = (string) config('app.key');
        abort_if($key === '', 503, 'SERVICE_UNAVAILABLE');

        return hash_hmac('sha256', 'erp.finance-sync.v1.'.$encoded, $key);
    }
}
