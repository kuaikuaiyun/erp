<?php

namespace App\Portals\Finance;

use Illuminate\Database\Connection;

/** Durable, deduplicated work descriptions; HTTP never executes their builders. */
class FinanceReadModelBuilds
{
    public const TABLE = 'finance_read_model_jobs';
    private const KINDS = ['summary-source', 'paypal-graph', 'paypal-page', 'region-options', 'record-options', 'conflux-business'];

    public static function reader(): bool
    {
        return config('finance.read_models.enabled', true) && FinanceExternalChanges::readOnly();
    }

    public function database(): Connection { return app(FinanceWorkbookService::class)->database(); }

    public function installed(): bool
    {
        return FinanceReadModelVersions::installed($this->database()) && $this->database()->getSchemaBuilder()->hasTable(self::TABLE);
    }

    public function pending(string $kind, array $payload): never
    {
        throw new FinanceReadModelPending($this->enqueue($kind, $payload));
    }

    public function enqueue(string $kind, array $payload): string
    {
        if (! in_array($kind, self::KINDS, true)) throw new \InvalidArgumentException('Unknown finance read model.');
        if (! $this->installed()) return 'unavailable';
        $db = $this->database();
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $key = hash('sha256', $kind.':'.$json);
        $now = now();
        $db->table(self::TABLE)->insertOrIgnore(['job_key' => $key, 'kind' => $kind, 'payload' => $json,
            'state' => 'pending', 'attempts' => 0, 'available_at' => $now, 'created_at' => $now, 'updated_at' => $now]);
        // Repeated polls leave active work alone. A new cache miss can request a
        // completed scope again, but cannot reset an active worker's attempt count.
        $db->table(self::TABLE)->where('job_key', $key)->where('state', 'ready')->update([
            'state' => 'pending', 'attempts' => 0, 'available_at' => $now, 'updated_at' => $now,
            'error_class' => null, 'finished_at' => null,
        ]);
        $job = $db->table(self::TABLE)->where('job_key', $key)->first();
        request()->attributes->set('finance.read-model-job', ['id' => $job->id, 'kind' => $job->kind, 'state' => $job->state,
            'attempts' => $job->attempts, 'started_at' => $job->started_at, 'available_at' => $job->available_at]);
        return match ($job->state) { 'failed' => 'failed', 'running' => 'running', 'retry' => 'retry', default => 'queued' };
    }

    /** The caller owns the command's process/session lock. */
    public function run(int $seconds = 45, int $limit = 20): array
    {
        $db = $this->database();
        if (! $this->installed()) return ['completed' => 0, 'failed' => 0, 'pending' => 0];
        // Acquiring the session lock proves any previous running owner has exited.
        $db->table(self::TABLE)->where('state', 'running')->update(['state' => 'pending', 'available_at' => now()]);
        $deadline = hrtime(true) + $seconds * 1e9;
        $stats = ['completed' => 0, 'failed' => 0, 'pending' => 0];
        for ($i = 0; $i < $limit && hrtime(true) < $deadline; $i++) {
            $job = $db->table(self::TABLE)->whereIn('state', ['pending', 'retry'])->where('available_at', '<=', now())->orderBy('available_at')->orderBy('id')->first();
            if (! $job) break;
            $db->table(self::TABLE)->where('id', $job->id)->update(['state' => 'running', 'started_at' => now(), 'attempts' => $job->attempts + 1]);
            FinanceQueryDiagnostics::current()?->event('read_model.job.start', ['job_id' => $job->id, 'kind' => $job->kind, 'attempt' => $job->attempts + 1], true);
            try {
                $ready = FinanceQueryDiagnostics::measure('read_model.build', fn () => $this->build($job->kind,
                    json_decode($job->payload, true, 512, JSON_THROW_ON_ERROR)), ['job_id' => $job->id, 'kind' => $job->kind]);
                $db->table(self::TABLE)->where('id', $job->id)->update(['state' => $ready ? 'ready' : 'retry',
                    'available_at' => now()->addSeconds(5), 'finished_at' => $ready ? now() : null,
                    'updated_at' => now(), 'error_class' => null]);
                $stats[$ready ? 'completed' : 'pending']++;
                FinanceQueryDiagnostics::current()?->event('read_model.job.end', ['job_id' => $job->id, 'kind' => $job->kind, 'ready' => $ready], true);
            } catch (\Throwable $error) {
                FinanceQueryDiagnostics::current()?->event('read_model.job.failed', ['job_id' => $job->id, 'kind' => $job->kind, 'exception' => $error::class], true);
                $db->table(self::TABLE)->where('id', $job->id)->update(['state' => $job->attempts + 1 >= 5 ? 'failed' : 'retry',
                    'available_at' => now()->addSeconds(min(300, 15 * ($job->attempts + 1))), 'updated_at' => now(), 'error_class' => $error::class]);
                report($error);
                $stats['failed']++;
            }
        }
        return $stats;
    }

    protected function build(string $kind, array $payload): bool
    {
        if ($kind === 'summary-source') {
            $channel = $payload['channel'];
            if (! in_array($channel, ['paypal', 'credit_card'], true)) throw new \InvalidArgumentException('Unknown finance channel.');
            $filters = $payload['filters'];
            $transactions = (bool) ($payload['transactions'] ?? false);
            $source = app(FinanceSummarySourceCache::class);
            $version = app(FinanceSummaryDataVersion::class)->forRange($channel, $filters);
            $count = 0;
            try {
                foreach ($source->stream($channel, $filters, $transactions, function ($canonical) use ($channel, $transactions) {
                    if (isset($canonical['_paypal_export'])) {
                        $export = $canonical['_paypal_export'];
                        return app(FinancePayPalSettlementOrders::class)->exportRows($export['filters'], $export['match_candidates'], $export['record_ids']);
                    }
                    return app(FinanceSettlementService::class)->summaryRowStream($channel, $canonical, $transactions);
                }) as $_) {
                    if (++$count % 2000 === 0) FinanceQueryDiagnostics::current()?->event('read_model.rows', ['channel' => $channel, 'rows' => $count]);
                }
                FinanceQueryDiagnostics::current()?->event('read_model.rows.complete', ['channel' => $channel, 'rows' => $count], true);
                $ready = $source->ready($channel, $filters);
                if (! $ready && $version === app(FinanceSummaryDataVersion::class)->forRange($channel, $filters)) {
                    throw new \RuntimeException('后台计算结果未能保存，请检查源行目录权限及存储空间。');
                }
                return $ready;
            } finally {
                app(FinancePayPalSettlementOrders::class)->releaseExportGraph();
            }
        }
        if ($kind === 'paypal-graph') {
            app(FinancePayPalSettlementOrders::class)->warmGraph();
            return app(FinanceReportCache::class)->get('paypal-order-graph:v3', ['paypal']) !== null;
        }
        if ($kind === 'paypal-page') {
            app(FinancePayPalSettlementOrders::class)->page($payload['filters'], $payload['offset'], $payload['limit'], $payload['stopAtLimit']);
            return app(FinanceReportCache::class)->get($payload['scope'], ['paypal']) !== null;
        }
        if ($kind === 'region-options') {
            app(FinanceSettlementService::class)->regionOptions($payload['report']);
            return app(FinanceReportCache::class)->get('active-region-options:v3:'.$payload['report'], $payload['channels']) !== null;
        }
        if ($kind === 'record-options') {
            app(\App\Portals\Finance\Http\Controllers\FinanceController::class)->warmConditionChoices($payload['dataset'], (bool) $payload['xuzong']);
            return app(FinanceReportCache::class)->get($payload['scope'], $payload['channels']) !== null;
        }
        if ($kind === 'conflux-business') {
            app(FinanceSettlementService::class)->confluxBusinessQuery($payload['filters']);
            return app(FinanceReportCache::class)->get($payload['scope'], ['ye']) !== null;
        }
        throw new \InvalidArgumentException('Unknown finance read model.');
    }
}
