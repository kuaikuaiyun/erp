<?php

namespace App\Portals\Finance;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;

/** Only summary/loss overview queries. No source-write observer or global cache changes. */
class FinanceOverviewJobs
{
    public const TABLE = 'finance_read_model_jobs';
    public const KIND = 'overview-page';
    private const MAX_ATTEMPTS = 3;
    private const STATES = ['pending' => 'overview_pending', 'running' => 'overview_running',
        'retry' => 'overview_retry', 'ready' => 'overview_ready', 'failed' => 'overview_failed'];

    private function database() { return app(FinanceWorkbookService::class)->database(); }

    private function cache()
    {
        $store = config('cache.default');
        return Cache::store(config("cache.stores.{$store}.driver") === 'database' ? 'file' : $store);
    }

    private function resultKey(int $id, string $run): string
    {
        $db = $this->database();
        return 'finance:overview-result:'.hash('sha256', $db->getName().':'.$db->getDatabaseName().':'.$id.':'.$run);
    }

    public function installed(): bool { return $this->database()->getSchemaBuilder()->hasTable(self::TABLE); }

    public function prepare(Request $request, string $report, int $userId, array $months, array $scope): array
    {
        if (! in_array($report, ['summary', 'losses'], true)) throw new \InvalidArgumentException('Unknown overview.');
        if (count($months) > 24) throw ValidationException::withMessages(['month_from' => '请选择不超过24个月的日期范围。']);
        $query = $request->except(['_finance_plan', '_source_ready', '_source_run', '_source_prepare', '_source_from', '_source_to', '_overview_run', '_overview_refresh']);
        $query['month_from'] = $months[0];
        $query['month_to'] = $months[array_key_last($months)];
        $url = $request->url();
        if (! $this->installed()) return ['plan' => ['waiting' => true, 'background' => 'overview', 'state' => 'unavailable',
            'message' => '汇总和损耗计算任务尚未配置，请执行财务辅助表迁移。', 'months' => [], 'resume_url' => $url]];
        // A blank form field and an omitted menu-link field mean the same thing.
        $descriptor = compact('report', 'userId', 'months') + ['scope' => array_filter($scope, fn ($value) => $value !== null && $value !== '')];
        $keyFor = fn (array $data) => hash('sha256', 'overview-page-v1:'.json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        $key = $keyFor($descriptor);
        $db = $this->database();
        $run = $request->query('_overview_run');
        if ($run !== null && (! is_string($run) || ! preg_match('/^[a-f0-9]{32}$/D', $run))) {
            throw ValidationException::withMessages(['_overview_run' => '本次查询已失效，请重新查询。']);
        }
        if ($run !== null && $scope !== $descriptor['scope']) {
            // Links for a calculation started before normalization can still resume it.
            $legacyKey = $keyFor([...$descriptor, 'scope' => $scope]);
            $legacy = $db->table(self::TABLE)->where('job_key', $legacyKey)->where('kind', self::KIND)->first(['id', 'payload']);
            if ($legacy && (hash_equals(json_decode($legacy->payload, true, 512, JSON_THROW_ON_ERROR)['run'], $run)
                || is_array($this->cache()->get($this->resultKey($legacy->id, $run))))) $key = $legacyKey;
        }
        $refresh = $request->boolean('_overview_refresh');
        $task = $db->transaction(function () use ($db, $key, $descriptor, $run, $refresh) {
            $payload = [...$descriptor, 'run' => bin2hex(random_bytes(16))];
            $db->table(self::TABLE)->insertOrIgnore(['job_key' => $key, 'kind' => self::KIND,
                'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), 'state' => self::STATES['pending'],
                'attempts' => 0, 'available_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
            $job = $db->table(self::TABLE)->where('job_key', $key)->where('kind', self::KIND)->lockForUpdate()->first();
            $current = json_decode($job->payload, true, 512, JSON_THROW_ON_ERROR);
            if ($run !== null && ! hash_equals($current['run'], $run)) {
                // A second tab can request the same filters again. Keep the
                // first tab's completed snapshot usable until its own expiry.
                $previous = $this->cache()->get($this->resultKey($job->id, $run));
                if (! is_array($previous)) throw ValidationException::withMessages(['_overview_run' => '本次查询已失效，请重新查询。']);
                $job->state = self::STATES['ready'];
                return ['job' => $job, 'payload' => [...$current, 'run' => $run], 'result' => $previous];
            }
            // Browsing reuses a completed snapshot; an explicit query refreshes it.
            // Concurrent visits share any work already pending or running.
            if ($run === null && ($job->state === self::STATES['failed'] || ($refresh && $job->state === self::STATES['ready']))) {
                $db->table(self::TABLE)->where('id', $job->id)->update(['payload' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                    'state' => self::STATES['pending'], 'attempts' => 0, 'available_at' => now(), 'started_at' => null,
                    'finished_at' => null, 'error_class' => null, 'updated_at' => now()]);
                $job = $db->table(self::TABLE)->where('id', $job->id)->first();
            }
            $current = json_decode($job->payload, true, 512, JSON_THROW_ON_ERROR);
            $result = $job->state === self::STATES['ready'] ? $this->cache()->get($this->resultKey($job->id, $current['run'])) : null;
            if ($job->state === self::STATES['ready'] && $result === null) {
                $db->table(self::TABLE)->where('id', $job->id)->update(['state' => self::STATES['pending'], 'attempts' => 0,
                    'available_at' => now(), 'started_at' => null, 'finished_at' => null, 'error_class' => null, 'updated_at' => now()]);
                $job = $db->table(self::TABLE)->where('id', $job->id)->first();
            }
            return ['job' => $job, 'payload' => $current, 'result' => $result];
        });
        ['job' => $job, 'payload' => $payload, 'result' => $result] = $task;
        $state = array_search($job->state, self::STATES, true);
        $resume = $url.'?'.http_build_query([...$query, '_overview_run' => $payload['run']]);
        $ahead = $state === 'pending' ? $db->table(self::TABLE)->where('kind', self::KIND)
            ->where(fn ($queue) => $queue->where('state', self::STATES['running'])
                ->orWhere(fn ($waiting) => $waiting->whereIn('state', [self::STATES['pending'], self::STATES['retry']])
                    ->where('available_at', '<=', now())->where(fn ($earlier) => $earlier->where('available_at', '<', $job->available_at)
                        ->orWhere(fn ($tie) => $tie->where('available_at', $job->available_at)->where('id', '<', $job->id)))))
            ->count() : 0;
        $progress = $state === 'running' ? $this->cache()->get($this->resultKey($job->id, $payload['run']).':progress') : null;
        $message = match ($state) {
            'running' => $progress
                ? "正在计算 {$progress['month']}（{$progress['index']}/".count($months).'），完成后自动显示。'
                : '后台正在计算所选月份，完成后自动显示。',
            'retry' => '后台计算等待重试，请稍候。',
            'failed' => '后台计算失败，请重试或联系管理员。',
            'ready' => '计算完成。',
            default => $ahead ? "查询已排队，前面还有 {$ahead} 个报表任务。" : '查询已排队，正在等待后台接手。',
        };
        if ($result === null && in_array($state, ['pending', 'running', 'retry'], true)
            && ! app(FinanceOverviewWorker::class)->wake()) {
            $state = 'unavailable';
            $message = '无法启动后台计算，请重试；如仍失败，请联系管理员检查报表计算服务。';
        }
        return ['result' => $result, 'plan' => ['waiting' => $result === null, 'background' => 'overview', 'state' => $state,
            'message' => $message, 'months' => [], 'poll_url' => $resume.'&_finance_plan=1', 'resume_url' => $resume, 'run' => $payload['run'],
            'job' => ['id' => $job->id, 'state' => $state, 'attempts' => $job->attempts, 'started_at' => $job->started_at]]];
    }

    /** Called under the dedicated command's session lock. */
    public function run(int $seconds = 45, int $limit = 4): array
    {
        $db = $this->database();
        // Older global workers only select pending/retry/running. Distinct states
        // prevent one still finishing during deployment from claiming these jobs.
        $interrupted = $db->table(self::TABLE)->where('kind', self::KIND)->where('state', self::STATES['running']);
        $failed = (clone $interrupted)->where('attempts', '>=', self::MAX_ATTEMPTS)
            ->update(['state' => self::STATES['failed'], 'finished_at' => now(), 'updated_at' => now()]);
        $interrupted->where('attempts', '<', self::MAX_ATTEMPTS)
            ->update(['state' => self::STATES['pending'], 'available_at' => now()]);
        $deadline = hrtime(true) + $seconds * 1e9;
        $stats = ['completed' => 0, 'failed' => $failed];
        for ($i = 0; $i < $limit && hrtime(true) < $deadline; $i++) {
            $job = $db->table(self::TABLE)->where('kind', self::KIND)->whereIn('state', [self::STATES['pending'], self::STATES['retry']])
                ->where('available_at', '<=', now())->orderBy('available_at')->orderBy('id')->first();
            if (! $job) break;
            $payload = json_decode($job->payload, true, 512, JSON_THROW_ON_ERROR);
            $payload['progress_key'] = $this->resultKey($job->id, $payload['run']).':progress';
            $this->cache()->forget($payload['progress_key']);
            $db->table(self::TABLE)->where('id', $job->id)->update(['state' => self::STATES['running'], 'attempts' => $job->attempts + 1, 'started_at' => now()]);
            FinanceQueryDiagnostics::current()?->event('overview.job.start', ['job_id' => $job->id, 'report' => $payload['report']], true);
            try {
                $result = $this->build($payload);
                if (! $this->cache()->put($this->resultKey($job->id, $payload['run']), $result, 3600)) throw new \RuntimeException('无法保存本次报表计算结果。');
                $db->table(self::TABLE)->where('id', $job->id)->update(['state' => self::STATES['ready'], 'finished_at' => now(), 'updated_at' => now(), 'error_class' => null]);
                FinanceQueryDiagnostics::current()?->event('overview.job.end', ['job_id' => $job->id, 'report' => $payload['report']], true);
                $stats['completed']++;
            } catch (\Throwable $error) {
                $db->table(self::TABLE)->where('id', $job->id)->update(['state' => $job->attempts + 1 >= self::MAX_ATTEMPTS ? self::STATES['failed'] : self::STATES['retry'],
                    'available_at' => now()->addSeconds(30), 'updated_at' => now(), 'error_class' => $error::class]);
                FinanceQueryDiagnostics::current()?->event('overview.job.failed', ['job_id' => $job->id, 'exception' => $error::class], true);
                report($error);
                $stats['failed']++;
            }
        }
        return $stats;
    }

    protected function build(array $payload): array
    {
        $db = $this->database();
        if (! in_array($payload['report'], ['summary', 'losses'], true)) throw new \InvalidArgumentException('Unknown overview.');
        $attributes = request()->attributes;
        $previous = [$attributes->get('finance.overview_worker'), $attributes->get('finance.report_read_only')];
        $attributes->set('finance.overview_worker', true);
        $attributes->set('finance.report_read_only', true);
        try {
            if (in_array($db->getDriverName(), ['mysql', 'mariadb'], true)) $db->statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            return $db->transaction(function () use ($db, $payload) {
                // Every selected month reads one database snapshot, independent
                // of notification backlog. No shared projection/cache is rebuilt.
                $db->table('finance_records')->limit(1)->get(['id']);
                $result = ['calculated_at' => now('Asia/Shanghai')->format('Y-m-d H:i:s'), 'months' => []];
                $controller = app(\App\Portals\Finance\Http\Controllers\FinanceController::class);
                foreach ($payload['months'] as $index => $month) {
                    $this->cache()->put($payload['progress_key'], ['month' => $month, 'index' => $index + 1], 1800);
                    $result['months'][$month] = FinanceQueryDiagnostics::measure('overview.month',
                        fn () => $controller->buildSettlementOverviewMonth($month, $payload['report'], $payload['scope'], true, false), ['month' => $month]);
                }
                return $result;
            });
        } finally {
            [$worker, $readOnly] = $previous;
            $attributes->set('finance.overview_worker', $worker);
            $attributes->set('finance.report_read_only', $readOnly);
        }
    }
}
