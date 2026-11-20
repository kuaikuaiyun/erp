<?php

namespace App\Portals\Finance;

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Cache;
use Throwable;

class FinancePayPalSettlementProjection
{
    public function __construct(
        private readonly FinanceWorkbookService $workbooks,
        private readonly FinancePayPalSettlementOrders $orders,
        private readonly FinanceSummaryDataVersion $versions,
    ) {}

    private function sourceRevision(): ?string
    {
        $source = $this->versions->current('paypal');
        return $source === null ? null : hash('sha256', 'paypal-settlement-attribution-v2:'.$source);
    }

    public function refresh(): bool
    {
        $db = $this->workbooks->database();
        $schema = $db->getSchemaBuilder();
        if (! $schema->hasTable('finance_paypal_settlement_projections')
            || ! $schema->hasTable('finance_paypal_settlement_rows')) {
            return true;
        }

        $revision = $this->sourceRevision();
        if ($revision === null) {
            return true;
        }
        $published = $db->table('finance_paypal_settlement_projections')->where('id', 1)->first();
        if ($published && hash_equals((string) $published->source_revision, $revision)) {
            return true;
        }

        // Continuous imports can invalidate an expensive full-history build.
        // Cool down failed and successful attempts alike, preserving the last
        // published generation while the scheduled worker waits for a retry.
        $cooldown = max(1, (int) config('finance.summaries.paypal_rebuild_cooldown_seconds', 300));
        try {
            if (! Cache::add('finance:paypal-projection:attempt:'.hash('sha256', $db->getDatabaseName()),
                now()->timestamp, now()->addSeconds($cooldown))) return false;
        } catch (Throwable $exception) {
            // Failing closed avoids a full rebuild on every request when the
            // throttle store is unavailable. Retry on the next scheduler run.
            report($exception);
            return false;
        }

        $generation = (string) Str::uuid();
        $previousGeneration = $published?->generation;
        $position = 0;
        $batch = [];
        try {
            foreach ($this->orders->projectionRows() as $row) {
                $position++;
                $occurredAt = $row['date'] ?? null;
                try {
                    $occurredAt = filled($occurredAt) ? CarbonImmutable::parse($occurredAt, 'Asia/Shanghai')->utc() : null;
                } catch (Throwable) {
                    $occurredAt = null;
                }
                unset($row['unlinked_date']);
                foreach ($row['payments'] ?? [] as &$payment) {
                    unset($payment['filter_date']);
                }
                unset($payment);
                $batch[] = [
                    'generation' => $generation,
                    'position' => $position,
                    'record_id' => $row['id'] ?? null,
                    'occurred_at' => $occurredAt,
                    'account' => $row['account'] ?? null,
                    'reference' => $row['reference'] ?? null,
                    'team' => $row['team'] ?? null,
                    'subscription' => $row['subscription'] ?? null,
                    'domain' => $row['domain'] ?? null,
                    'order_status' => $row['order_status'] ?? null,
                    'payload' => json_encode($row, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                ];
                if (count($batch) === 100) {
                    $db->table('finance_paypal_settlement_rows')->insert($batch);
                    $batch = [];
                }
            }
            if ($batch !== []) {
                $db->table('finance_paypal_settlement_rows')->insert($batch);
            }
            if ($revision !== $this->sourceRevision()) {
                $db->table('finance_paypal_settlement_rows')->where('generation', $generation)->delete();

                return false;
            }
            $db->transaction(function () use ($db, $generation, $previousGeneration, $revision, $position): void {
                $db->table('finance_paypal_settlement_projections')->updateOrInsert(['id' => 1], [
                    'generation' => $generation,
                    'source_revision' => $revision,
                    'row_count' => $position,
                    'published_at' => now(),
                ]);
                $obsolete = $db->table('finance_paypal_settlement_rows')->where('generation', '<>', $generation);
                if ($previousGeneration !== null) {
                    // Keep the previously published generation so a request that
                    // read its metadata immediately before this commit can finish.
                    $obsolete->where('generation', '<>', $previousGeneration);
                }
                $obsolete->delete();
            });
        } catch (Throwable $exception) {
            $db->table('finance_paypal_settlement_rows')->where('generation', $generation)->delete();
            throw $exception;
        }

        return true;
    }

    public function page(array $filters, int $offset, int $limit): ?array
    {
        $db = $this->workbooks->database();
        $schema = $db->getSchemaBuilder();
        if (! $schema->hasTable('finance_paypal_settlement_projections')
            || ! $schema->hasTable('finance_paypal_settlement_rows')
            || ($filters['timezone'] ?? 'Asia/Shanghai') !== 'Asia/Shanghai'
            || collect($filters)->only(['account', 'accounts', 'reference', 'from', 'to', 'team', 'teams', 'region', 'regions', 'subscription', 'subscriptions', 'domain', 'order_status', 'conditions'])
                ->contains(fn ($value) => filled($value))) {
            return null;
        }
        $published = $db->table('finance_paypal_settlement_projections')->where('id', 1)->first();
        $revision = $this->sourceRevision();
        if (! $published || $revision === null || ! hash_equals((string) $published->source_revision, $revision)) {
            return null;
        }
        $rows = $db->table('finance_paypal_settlement_rows')->where('generation', $published->generation)
            ->orderBy('position')->offset($offset)->limit($limit)->pluck('payload')
            ->map(function ($payload) {
                $row = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
                $row['operation_region'] = FinanceSettlementService::operationRegion((string) ($row['team'] ?? ''));
                return $row;
            });

        return [$rows, (int) $published->row_count, $published->published_at];
    }

    public function forget(): void
    {
        $db = $this->workbooks->database();
        if ($db->getSchemaBuilder()->hasTable('finance_paypal_settlement_projections')) {
            $db->table('finance_paypal_settlement_projections')->where('id', 1)->delete();
        }
    }
}
