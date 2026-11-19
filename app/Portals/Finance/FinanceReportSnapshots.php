<?php

namespace App\Portals\Finance;

use App\Models\FinancePaymentAccount;
use App\Portals\Finance\Http\Controllers\FinanceController;
use Carbon\CarbonImmutable;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Artisan;
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

    /**
     * Keep the normal monthly report screens warm before anyone opens them.
     *
     * Ad-hoc raw-field conditions still get their own scope, but ordinary menu
     * switches and dimension filters reuse these channel/month snapshots.
     */
    public function registerDefaultScopes(int $monthLimit = 12): int
    {
        $db = $this->workbooks->database();
        $schema = $db->getSchemaBuilder();
        if (! $schema->hasTable('finance_report_scopes')) {
            return 0;
        }

        $months = $schema->hasTable('finance_summary_periods')
            ? $db->table('finance_summary_periods')->where('month', '<>', '')
                ->select('month')->distinct()->orderByDesc('month')->limit($monthLimit)->pluck('month')
            : collect();
        $months->push(now('Asia/Shanghai')->format('Y-m'));
        $months = $months->filter(fn ($month) => preg_match('/^\d{4}-\d{2}$/', (string) $month) === 1)
            ->unique()->sortDesc()->take($monthLimit)->values();

        // Prewarmed scopes stay active but rank below a scope explicitly used
        // by a person. This prevents a cold deployment from delaying the month
        // somebody is currently waiting for.
        $prewarmAt = now()->subDays(6);
        $scopes = [];
        $add = function (string $report, string $month, array $filters) use (&$scopes, $prewarmAt): void {
            $filters = array_filter($filters, fn ($value) => $value !== null && $value !== '');
            ksort($filters);
            $scope = hash('sha256', json_encode([$report, $month, $filters], JSON_THROW_ON_ERROR));
            $scopes[] = compact('scope', 'month', 'report') + [
                'filters' => json_encode($filters, JSON_THROW_ON_ERROR),
                'used_at' => $prewarmAt,
            ];
        };

        $overviewChannels = [null, 'paypal', 'xuzong', 'xuzong1', 'xuzong2', 'ye', 'worldpay', 'credit_card'];
        foreach ($months as $month) {
            foreach (array_keys(FinanceTransactionSummary::VIEWS) as $channel) {
                $add('transactions', $month, [
                    '_settlement_currency_summary' => 3,
                    ...($channel === 'combined' ? ['_combined_channel_rollup' => 10] : []),
                    'channel' => $channel,
                    'month' => $month,
                ]);
            }
            foreach ($overviewChannels as $channel) {
                $add('summary', $month, ['_include_options' => true, 'channel' => $channel]);
                $add('losses', $month, ['_include_options' => true, 'channel' => $channel, 'layer' => 'business']);
            }
        }

        if ($scopes !== []) {
            $db->table('finance_report_scopes')->insertOrIgnore($scopes);
            $db->table('finance_report_scopes')->whereIn('scope', array_column($scopes, 'scope'))
                ->where('used_at', '<', now()->subDays(7))->update(['used_at' => $prewarmAt]);
        }

        return count($scopes);
    }

    /**
     * Publication normally runs only in the CLI scheduler. An explicitly
     * enabled legacy web fallback is gated across requests and still shares
     * the database mutex with the CLI worker. It is off by default because
     * deferred callbacks still occupy PHP-FPM workers after the response.
     */
    private function requestBackgroundRefresh(): void
    {
        if (! config('finance.summaries.web_refresh', false)) return;
        $driver = $this->workbooks->database()->getDriverName();
        if (! in_array($driver, ['mysql', 'mariadb'], true)) {
            return;
        }

        defer(function (): void {
            try {
                if (! Cache::add('finance:summaries:web-refresh', now()->timestamp, now()->addMinutes(5))) {
                    return;
                }
                Artisan::call('finance:summaries:refresh');
            } catch (Throwable $exception) {
                report($exception);
            }
        }, 'finance:summaries:web-refresh');
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
        $builds = 0;
        $buildLimit = max(1, (int) config('finance.summaries.snapshots_per_run', 2));
        $deadline = microtime(true) + max(1, (int) config('finance.summaries.snapshot_budget_seconds', 45));
        $publications = $db->table('finance_report_snapshots')->selectRaw('scope AS snapshot_scope, MAX(created_at) AS last_snapshot_at')->groupBy('scope');
        $activeScopes = $db->table('finance_report_scopes')
            ->leftJoinSub($publications, 'publications', fn ($join) => $join->on('finance_report_scopes.scope', '=', 'publications.snapshot_scope'))
            ->select('finance_report_scopes.*')
            ->where('used_at', '>=', $activeSince)
            ->where('month', '<>', '')
            // User-requested scopes precede prewarming. Within that tier,
            // missing and oldest publications precede recently refreshed ones.
            // Otherwise the five-minute fallback revision can expire before a
            // long month range ever receives its first complete set of results.
            ->orderByRaw('CASE WHEN used_at >= ? THEN 0 ELSE 1 END', [now()->subDay()])
            ->orderBy('last_snapshot_at')->orderByDesc('used_at')->orderBy('scope')->get();
        foreach ($activeScopes as $scope) {
            $started = hrtime(true);
            if (function_exists('memory_reset_peak_usage')) {
                memory_reset_peak_usage();
            }
            try {
                $filters = json_decode($scope->filters, true, 512, JSON_THROW_ON_ERROR);
                $revision = $this->revision($db, $scope->report, $scope->month, $filters, $tracksRevisions);
                if (! $db->table('finance_report_snapshots')->where(['scope' => $scope->scope, 'revision' => $revision])->exists()) {
                    // Source-file mode deliberately has no database projection
                    // to publish. Only the background worker may build from
                    // those sources; requiring a projection here stalls forever.
                    if ($builds >= $buildLimit || microtime(true) >= $deadline || ($tracksRevisions
                        && ! app(FinanceSummaryProjection::class)->usesSourceFiles()
                        && ! $this->projectionsReady($db, $scope->month, $filters))) {
                        continue;
                    }
                    $builds++;
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

        // Overview reports can scan several months on a cold cache. Their existing
        // scheduler must do that work outside the PHP-FPM request timeout.
        if (in_array($report, ['summary', 'losses'], true)) {
            $live = false;
        }
        $queryCurrentData = $report === 'transactions';
        if ($queryCurrentData) {
            // A transaction report may run synchronously only when its caller has
            // verified that every source projection is current. Otherwise the web
            // request must serve the previous snapshot (or a pending state) and let
            // the scheduler rebuild it, rather than falling back to raw ledgers.
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
                // Usage metadata keeps active pagination/export versions from being pruned
                // and lets the scheduler refresh them after a source revision changes.
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
        if (! $current && ! $statusOnly && in_array($report, ['summary', 'losses'], true)
            && $this->projectionsReady($db, $month, $filters)) {
            try {
                $result = $db->transaction($build);
                if (! $db->table('finance_summary_changes')->exists()
                    && $revision === $this->revision($db, $report, $month, $filters, true)) {
                    $snapshot = $this->publish($db, $scope, $revision, $result);
                    Log::info('Finance report snapshot published from projection', [
                        'report' => $report, 'month' => $month, 'scope' => $scope,
                    ]);
                    return [$result, $snapshot, ['state' => 'ready', 'updated_at' => now()]];
                }
                unset($result);
            } catch (Throwable $exception) {
                unset($result);
                report($exception);
            }
        }
        $saved ??= $query->first(['id', 'created_at']);
        $current = $current && ! ($tracksRevisions && $db->table('finance_summary_changes')->exists());
        $failed = ! $current && $db->table('finance_report_scopes')->where('scope', $scope)->whereNotNull('failed_at')->exists();
        $status = [
            'state' => $current ? 'ready' : ($failed ? 'failed' : ($saved ? 'updating' : 'pending')),
            'updated_at' => $saved?->created_at,
        ];
        if (! $current) {
            $status['detail'] = $this->pendingDetail($db, $month, $filters, $tracksRevisions);
            $this->requestBackgroundRefresh();
        }
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

    private function pendingDetail(Connection $db, string $month, array $filters, bool $tracksRevisions): string
    {
        try {
            $worker = Cache::get('finance:summaries:worker-status');
        } catch (Throwable) {
            $worker = null;
        }
        $detail = '';
        if ($tracksRevisions) {
            if ($db->table('finance_summary_changes')->exists()) {
                $detail = '等待关联订单、成本等依赖变更处理。';
            } else {
                $channels = match ($filters['channel'] ?? null) {
                    'paypal' => ['paypal'],
                    null, '', 'combined' => ['paypal', 'credit_card'],
                    default => ['credit_card'],
                };
                $periods = $db->table('finance_summary_periods')->where('month', $month)
                    ->whereIn('channel', $channels)->get()->keyBy('channel');
                foreach ($channels as $channel) {
                    $period = $periods->get($channel);
                    $name = $channel === 'paypal' ? 'PayPal' : '信用卡（含叶总支付、Worldpay）';
                    if (! $period) {
                        $detail .= $name.'缺少月份登记。';
                    } elseif ((int) $period->published === 0 || (int) $period->published !== (int) $period->revision) {
                        $detail .= $name.'投影待发布（已发布 '.$period->published.' / 最新 '.$period->revision.'）。';
                    }
                }
                if ($detail === '') $detail = '月份投影已就绪，等待汇总快照发布。';
            }
        } else {
            $detail = '未启用完整版本跟踪，等待后台生成快照。';
        }
        return $detail.' 后台任务：'.(is_array($worker)
            ? ($worker['stage'] ?? '未知').'（记录时间 '.($worker['at'] ?? '').'，北京时间；不代表当前仍在运行）。'
            : '暂无执行记录（需部署诊断版本后观察）。');
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
        $transactionVersion = 14;
        // Installations without trigger privileges still generate reports in the background.
        if (! $tracksRevisions) {
            $bucket = (string) intdiv(now()->timestamp, 300);

            $prefix = 'transactions:'.$transactionVersion.':';

            return hash('sha256', match ($report) {
                'transactions' => $prefix.$bucket,
                'summary' => 'summary:8:'.$bucket,
                default => $bucket,
            });
        }
        $periods = $db->table('finance_summary_periods')->whereIn('month', $report === 'summary'
                ? [$month, CarbonImmutable::parse($month.'-01')->addMonth()->format('Y-m')]
                : [$month])
            ->when($channel === 'paypal', fn ($query) => $query->where('channel', 'paypal'))
            ->when(filled($channel) && ! in_array($channel, ['paypal', 'combined'], true), fn ($query) => $query->where('channel', 'credit_card'))
            ->orderBy('month')->orderBy('channel')->get(['month', 'channel', 'revision']);
        $rates = $db->table('finance_monthly_exchange_rates')->where(['month' => $month, 'currency' => 'USD'])
            ->orderBy('channel')->get(['channel', 'cny_rate']);

        $version = match ($report) {
            'transactions' => $transactionVersion,
            'summary' => 9,
            default => 7,
        };
        return hash('sha256', json_encode([$version, $periods, $rates], JSON_THROW_ON_ERROR));
    }

    private function projectionsReady(Connection $db, string $month, array $filters): bool
    {
        if (! FinanceSummaryTriggers::available($db)
            || $db->table('finance_summary_changes')->exists()
            || ! $db->getSchemaBuilder()->hasColumn('finance_summary_rows', 'timezone')
            || app(FinanceSummaryProjection::class)->usesSourceFiles()) {
            return false;
        }
        $channels = match ($filters['channel'] ?? null) {
            'paypal' => ['paypal'],
            null, '', 'combined' => ['paypal', 'credit_card'],
            default => ['credit_card'],
        };
        $periods = $db->table('finance_summary_periods')->where('month', $month)
            ->whereIn('channel', $channels)->get(['channel', 'revision', 'published'])->keyBy('channel');
        foreach ($channels as $channel) {
            $period = $periods->get($channel);
            if (! $period || (int) $period->published === 0 || (int) $period->published !== (int) $period->revision) {
                return false;
            }
        }

        return true;
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
