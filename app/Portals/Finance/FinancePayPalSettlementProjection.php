<?php

namespace App\Portals\Finance;

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
        $source = $this->versions->forCache(['paypal']);
        return $source === null ? null : hash('sha256', 'paypal-settlement-relational-v5:'.$source);
    }

    public function refresh(): bool
    {
        $storage = app(FinancePayPalListStorage::class);
        if (! $storage->installed()) return true;
        $db = $this->workbooks->database();
        $revision = $this->sourceRevision();
        if ($revision === null) return false;
        $published = $db->table('finance_paypal_list_publications')->where('id', 1)->first();
        if ($published && hash_equals((string) $published->source_revision, $revision)) return true;

        $cooldown = max(1, (int) config('finance.summaries.paypal_rebuild_cooldown_seconds', 300));
        try {
            if (! Cache::add('finance:paypal-projection:attempt:'.hash('sha256', $db->getDatabaseName()),
                now()->timestamp, now()->addSeconds($cooldown))) return false;
        } catch (Throwable $exception) {
            report($exception);
            return false;
        }
        $generation = (string) Str::uuid();
        $previousGeneration = $published?->generation;
        $position = 0;
        try {
            $batch = [];
            foreach ($this->orders->projectionRows() as $row) {
                $batch[] = $row;
                if (count($batch) === 200) { $storage->insert($generation, $position, $batch); $batch = []; }
            }
            if ($batch !== []) $storage->insert($generation, $position, $batch);
            if ($revision !== $this->sourceRevision()) {
                $storage->delete($generation);
                return false;
            }
            $db->table('finance_paypal_list_publications')->updateOrInsert(['id' => 1], [
                'generation' => $generation, 'source_revision' => $revision, 'row_count' => $position, 'published_at' => now(),
            ]);
            foreach ($db->table('finance_paypal_list_rows')->where('generation', '<>', $generation)
                ->when($previousGeneration !== null, fn ($query) => $query->where('generation', '<>', $previousGeneration))
                ->distinct()->pluck('generation') as $obsolete) $storage->delete($obsolete);
        } catch (Throwable $exception) {
            $storage->delete($generation);
            throw $exception;
        } finally {
            $this->orders->releaseExportGraph();
        }
        return true;
    }

    public function page(array $filters, int $offset, int $limit): ?array
    {
        $storage = app(FinancePayPalListStorage::class);
        if (! $storage->installed() || ($filters['timezone'] ?? 'Asia/Shanghai') !== 'Asia/Shanghai'
            || collect($filters)->only(['account', 'accounts', 'reference', 'from', 'to', 'team', 'teams', 'region', 'regions', 'subscription', 'subscriptions', 'domain', 'conditions'])
                ->contains(fn ($value) => filled($value))) return null;
        $published = $this->workbooks->database()->table('finance_paypal_list_publications')->where('id', 1)->first();
        $revision = $this->sourceRevision();
        if (! $published || $revision === null || ! hash_equals((string) $published->source_revision, $revision)) return null;
        $total = filled($filters['order_status'] ?? null)
            ? $storage->matchingQuery($published->generation, $filters)->count() : (int) $published->row_count;
        $rows = $storage->page($published->generation, $offset, $limit, $filters);
        if ($rows === null || $revision !== $this->sourceRevision()) return null;
        return [$rows, $total, $published->published_at];
    }

    public function forget(): void
    {
        $db = $this->workbooks->database();
        if ($db->getSchemaBuilder()->hasTable('finance_paypal_list_publications')) {
            $db->table('finance_paypal_list_publications')->where('id', 1)->delete();
        }
    }
}
