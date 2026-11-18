<?php

namespace App\Portals\Finance;

use App\Models\FinancePaymentAccount;
use App\Portals\Finance\Http\Controllers\FinanceController;
use Carbon\CarbonImmutable;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class FinanceReportSnapshots
{
    public function __construct(private readonly FinanceWorkbookService $workbooks) {}

    public function runsInBackground(): bool
    {
        return in_array($this->workbooks->database()->getDriverName(), ['mysql', 'mariadb'], true);
    }

    public function refresh(FinanceController $controller, bool $tracksRevisions): bool
    {
        $db = $this->workbooks->database();
        $activeSince = now()->subDays(7);
        if ($tracksRevisions && $db->table('finance_summary_changes')->exists()) {
            // Dependency changes must be reflected in the revision before publishing.
            return false;
        }
        $failed = false;
        $activeScopes = $db->table('finance_report_scopes')->where('used_at', '>=', $activeSince)
            ->whereNotIn('report', ['summary', 'transactions', 'losses'])->where('month', '<>', '')
            ->orderByDesc('used_at')->orderBy('scope')->get();
        foreach ($activeScopes as $scope) {
            $started = hrtime(true);
            if (function_exists('memory_reset_peak_usage')) {
                memory_reset_peak_usage();
            }
            try {
                $filters = json_decode($scope->filters, true, 512, JSON_THROW_ON_ERROR);
                $revision = $this->revision($db, $scope->report, $scope->month, $filters, $tracksRevisions);
                if (! $db->table('finance_report_snapshots')->where(['scope' => $scope->scope, 'revision' => $revision])->exists()) {
                    $result = $db->transaction(fn () => $this->build($controller, $scope, $filters));
                    if ($tracksRevisions && ($db->table('finance_summary_changes')->exists()
                        || $revision !== $this->revision($db, $scope->report, $scope->month, $filters, true))) {
                        unset($result);
                        continue;
                    }
                    $this->publish($db, $scope->scope, $revision, $result);
                    unset($result);
                    Log::info('Finance report snapshot published', $this->refreshContext($scope, $started));
                }
                if ($scope->failed_at !== null) {
                    $db->table('finance_report_scopes')->where('scope', $scope->scope)->update(['failed_at' => null]);
                }
            } catch (Throwable $exception) {
                unset($result);
                $failed = true;
                $db->table('finance_report_scopes')->where('scope', $scope->scope)->update(['failed_at' => now()]);
                Log::error('Finance report snapshot refresh failed', [
                    ...$this->refreshContext($scope, $started), 'exception' => $exception,
                ]);
            }
        }

        // Keep the last successful result for an active scope even during a prolonged refresh failure.
        foreach ($db->table('finance_report_snapshots')->select('id', 'scope', 'created_at')->where('created_at', '<', $activeSince)->lazyById(200) as $old) {
            $active = $db->table('finance_report_scopes')->where('scope', $old->scope)->where('used_at', '>=', $activeSince)->exists();
            $newer = $db->table('finance_report_snapshots')->where('scope', $old->scope)->where('created_at', '>', $old->created_at)->exists();
            if (! $active || $newer) {
                $db->table('finance_report_snapshots')->where('id', $old->id)->delete();
            }
        }
        $db->table('finance_report_scopes')->where('used_at', '<', $activeSince)->delete();

        return ! $failed;
    }

    private function build(FinanceController $controller, object $scope, array $filters): array
    {
        if ($scope->report === 'transactions') {
            $account = isset($filters['payment_account_id']) ? FinancePaymentAccount::find($filters['payment_account_id']) : null;

            return $controller->buildTransactionReport($filters['channel'], $filters, CarbonImmutable::parse($scope->month.'-01', 'Asia/Shanghai'), $account);
        }

        return $controller->buildSettlementOverviewMonth($scope->month, $scope->report, $filters, $filters['_include_options'], reuse: true);
    }

    public function result(string $report, string $month, array $filters, callable $build, ?string $id = null, bool $statusOnly = false, bool $live = false, bool $reusePublishedRevision = false): array
    {
        $filters = array_filter($filters, fn ($value) => $value !== null && $value !== '');
        if (isset($filters['payment_account_id'])) {
            $filters['payment_account_id'] = (int) $filters['payment_account_id'];
        }
        ksort($filters);
        $scope = hash('sha256', json_encode([$report, $month, $filters], JSON_THROW_ON_ERROR));
        $db = $this->workbooks->database();

        // These reports query current channel data; an absent filter-specific cache must
        // never register a background job and return an empty report to the user.
        $queryCurrentData = in_array($report, ['summary', 'transactions', 'losses'], true);
        if ($queryCurrentData) {
            $live = true;
            $reusePublishedRevision = $reusePublishedRevision || FinanceSummaryTriggers::available($db);
        }

        $background = ! $live && $this->runsInBackground();
        if ($statusOnly && ! $background) {
            return [null, null, ['state' => 'ready']];
        }
        if (! $background && $id === null && ! $db->getSchemaBuilder()->hasTable('finance_report_snapshots')) {
            return [$db->transaction($build), null, ['state' => 'ready']];
        }
        if ($id !== null) {
            $saved = $db->table('finance_report_snapshots')->where(compact('id', 'scope'))->first();
            if (! $saved) {
                throw ValidationException::withMessages(['report_snapshot' => '报表版本已过期或筛选已变更，请重新查询后导出。']);
            }

            $payload = json_decode($saved->payload, true, 512, JSON_THROW_ON_ERROR);
            if ($report === 'transactions' && ! is_array($payload[0] ?? null)) {
                throw ValidationException::withMessages(['report_snapshot' => '报表版本已过期，请重新查询后导出。']);
            }

            return [$payload, $id, ['state' => 'ready', 'updated_at' => $saved->created_at]];
        }
        if (! $background) {
            $revision = null;
            // The caller enables reuse only after every source projection is published at its current revision.
            if ($reusePublishedRevision && ! $db->table('finance_summary_changes')->exists()) {
                // Usage metadata keeps active pagination/export versions from being pruned.
                // refresh() no longer enqueues these report types for background generation.
                $db->table('finance_report_scopes')->upsert([
                    compact('scope', 'month', 'report') + ['filters' => json_encode($filters, JSON_THROW_ON_ERROR), 'used_at' => now()],
                ], ['scope'], ['used_at']);
                $revision = $this->queryRevision($db, $report, $month, $filters);
                $saved = $db->table('finance_report_snapshots')->where(compact('scope', 'revision'))
                    ->latest('created_at')->first(['id', 'created_at']);
                if ($saved) {
                    return [$this->payload($db, $saved->id), $saved->id, ['state' => 'ready', 'updated_at' => $saved->created_at]];
                }
            }
            $result = $db->transaction($build);
            if ($revision !== null && ($db->table('finance_summary_changes')->exists()
                || $revision !== $this->queryRevision($db, $report, $month, $filters))) {
                $revision = null;
            }

            return [$result, $this->publish($db, $scope, $revision ?? hash('sha256', Str::uuid()), $result), ['state' => 'ready']];
        }

        $db->table('finance_report_scopes')->upsert([
            compact('scope', 'month', 'report') + ['filters' => json_encode($filters, JSON_THROW_ON_ERROR), 'used_at' => now()],
        ], ['scope'], ['used_at']);

        $tracksRevisions = FinanceSummaryTriggers::available($db);
        $revision = $this->revision($db, $report, $month, $filters, $tracksRevisions);
        $query = $db->table('finance_report_snapshots')->where('scope', $scope)->latest('created_at')->orderByDesc('id');
        if ($report === 'transactions' && in_array($db->getDriverName(), ['mysql', 'mariadb'], true)) {
            // Older transaction snapshots stored totals and options in a JSON object.
            $query->whereRaw("JSON_TYPE(payload) = 'ARRAY'");
        }
        $saved = (clone $query)->where('revision', $revision)->first(['id', 'created_at']);
        $current = $saved !== null;
        $saved ??= $query->first(['id', 'created_at']);
        $current = $current && ! ($tracksRevisions && $db->table('finance_summary_changes')->exists());
        $failed = ! $current && $db->table('finance_report_scopes')->where('scope', $scope)->whereNotNull('failed_at')->exists();
        $status = [
            'state' => $current ? 'ready' : ($failed ? 'failed' : ($saved ? 'updating' : 'pending')),
            'updated_at' => $saved?->created_at,
        ];
        if ($statusOnly) {
            return [null, $saved?->id, $status];
        }
        Log::debug('Finance report snapshot read', [
            'report' => $report, 'month' => $month, 'scope' => $scope,
            'state' => $status['state'], 'snapshot_id' => $saved?->id,
        ]);
        if (! $saved) {
            return [$report === 'transactions' ? [[], []] : [[], [], null], null, $status];
        }

        return [$this->payload($db, $saved->id), $saved->id, $status];
    }

    private function queryRevision(Connection $db, string $report, string $month, array $filters): string
    {
        $channels = match ($filters['channel'] ?? null) {
            'paypal' => ['paypal'],
            null, '', 'combined' => ['paypal', 'credit_card'],
            default => ['credit_card'],
        };
        $sources = array_map(fn ($channel) => app(FinanceSummaryDataVersion::class)->current($channel), $channels);
        return hash('sha256', json_encode(['current-channel-data:3', $sources,
            $this->revision($db, $report, $month, $filters, true)], JSON_THROW_ON_ERROR));
    }

    private function revision(Connection $db, string $report, string $month, array $filters, bool $tracksRevisions): string
    {
        $channel = $filters['channel'] ?? null;
        $transactionVersion = in_array($channel, ['ye', 'worldpay'], true) ? 11 : 12;
        // Installations without trigger privileges still generate reports in the background.
        if (! $tracksRevisions) {
            $bucket = (string) intdiv(now()->timestamp, 300);

            $prefix = 'transactions:'.$transactionVersion.':';

            return hash('sha256', match ($report) {
                'transactions' => $prefix.$bucket,
                'summary' => 'summary:7:'.$bucket,
                default => $bucket,
            });
        }
        $periods = $db->table('finance_summary_periods')->whereIn('month', $report === 'summary'
                ? [$month, CarbonImmutable::parse($month.'-01')->addMonth()->format('Y-m')]
                : [$month])
            ->when($channel === 'paypal', fn ($query) => $query->where('channel', 'paypal'))
            ->when(filled($channel) && ! in_array($channel, ['paypal', 'combined'], true), fn ($query) => $query->where('channel', 'credit_card'))
            ->orderBy('month')->orderBy('channel')->get(['month', 'channel', 'revision']);
        $rate = $db->table('finance_monthly_exchange_rates')->where(['month' => $month, 'currency' => 'USD'])->value('cny_rate');

        $version = match ($report) {
            'transactions' => $transactionVersion,
            'summary' => 7,
            default => 5,
        };
        return hash('sha256', json_encode([$version, $periods, $rate], JSON_THROW_ON_ERROR));
    }

    private function publish(Connection $db, string $scope, string $revision, array $result): string
    {
        $id = (string) Str::uuid();
        $db->table('finance_report_snapshots')->insert(compact('id', 'scope', 'revision') + [
            'payload' => json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'created_at' => now(),
        ]);

        return $id;
    }

    private function payload(Connection $db, string $id): array
    {
        $key = 'finance:report:snapshot:'.$id;
        try {
            $cached = Cache::store('redis')->get($key);
            if (is_array($cached)) {
                return $cached;
            }
        } catch (Throwable) {
            // The published result is also persisted in the database.
        }
        $saved = $db->table('finance_report_snapshots')->where('id', $id)->value('payload');
        if ($saved === null) {
            throw ValidationException::withMessages(['report_snapshot' => '报表版本已过期，请重新查询。']);
        }
        $payload = json_decode($saved, true, 512, JSON_THROW_ON_ERROR);
        try {
            Cache::store('redis')->put($key, $payload, now()->addMinutes(5));
        } catch (Throwable) {
            // Redis is optional.
        }

        return $payload;
    }

    private function refreshContext(object $scope, int $started): array
    {
        return ['report' => $scope->report, 'month' => $scope->month, 'scope' => $scope->scope,
            'duration_ms' => (int) ((hrtime(true) - $started) / 1e6), 'peak_bytes' => memory_get_peak_usage(true)];
    }
}
