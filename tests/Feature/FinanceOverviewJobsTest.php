<?php

namespace Tests\Feature;

use App\Portals\Finance\FinanceOverviewJobs;
use App\Portals\Finance\FinanceOverviewWorker;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class FinanceOverviewJobsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:',
            'admin.database.connection' => null, 'cache.default' => 'array']);
        DB::purge('sqlite');
        (require database_path('migrations/2026_11_21_000010_separate_finance_read_models.php'))->up();
    }

    protected function tearDown(): void
    {
        DB::disconnect('sqlite');
        parent::tearDown();
    }

    private function jobs(bool $fail = false): FinanceOverviewJobs
    {
        return new class($fail) extends FinanceOverviewJobs {
            public function __construct(private bool $fail) {}
            protected function build(array $payload): array
            {
                if ($this->fail) throw new \RuntimeException('Simulated background failure');
                return ['calculated_at' => '2026-11-21 19:00:00', 'months' => $payload['months']];
            }
        };
    }

    private function prepare(FinanceOverviewJobs $jobs, ?string $url = null, int $user = 1): array
    {
        return $jobs->prepare(Request::create($url ?? 'http://caiwu.example/finance/reports/losses'),
            'losses', $user, ['2026-11'], ['loss_scope' => 'PayPal']);
    }

    public function test_polls_share_one_task_and_completed_tabs_survive_a_fresh_query(): void
    {
        $jobs = $this->jobs();
        $first = $this->prepare($jobs);
        $duplicate = $this->prepare($jobs);
        $this->assertSame($first['plan']['resume_url'], $duplicate['plan']['resume_url']);
        $this->assertSame(1, DB::table($jobs::TABLE)->count());
        $this->assertSame(0, DB::table($jobs::TABLE)->whereIn('state', ['pending', 'retry', 'running'])->count());
        $this->assertSame(1, $jobs->run()['completed']);
        $completed = $this->prepare($jobs, $first['plan']['resume_url']);
        $this->assertFalse($completed['plan']['waiting']);
        $reopened = $this->prepare($jobs);
        $this->assertSame($completed['result'], $reopened['result']);
        $this->assertSame($completed['plan']['resume_url'], $reopened['plan']['resume_url']);
        $fresh = $this->prepare($jobs, 'http://caiwu.example/finance/reports/losses?_overview_refresh=1');
        $this->assertTrue($fresh['plan']['waiting']);
        $this->assertNotSame($first['plan']['resume_url'], $fresh['plan']['resume_url']);
        $this->assertStringNotContainsString('_overview_refresh', $fresh['plan']['resume_url']);
        $this->assertSame($completed['result'], $this->prepare($jobs, $first['plan']['resume_url'])['result']);
        $this->assertSame('overview_pending', DB::table($jobs::TABLE)->value('state'));
    }

    public function test_links_to_legacy_runs_with_blank_filters_still_resume(): void
    {
        $jobs = $this->jobs();
        $data = ['report' => 'losses', 'userId' => 1, 'months' => ['2026-11'], 'scope' => ['account' => null, 'loss_scope' => 'PayPal']];
        $run = str_repeat('a', 32);
        DB::table($jobs::TABLE)->insert([
            'job_key' => hash('sha256', 'overview-page-v1:'.json_encode($data)),
            'kind' => $jobs::KIND, 'state' => 'overview_pending', 'attempts' => 0, 'available_at' => now(),
            'payload' => json_encode([...$data, 'run' => $run]),
        ]);
        $jobs->run();
        $prepared = $jobs->prepare(Request::create('http://caiwu.example/finance/reports/losses?account=&_overview_run='.$run),
            $data['report'], $data['userId'], $data['months'], $data['scope']);
        $this->assertFalse($prepared['plan']['waiting']);
        $this->assertSame(1, DB::table($jobs::TABLE)->count());
    }

    public function test_clearing_result_cache_requeues_the_same_run_instead_of_waiting_forever(): void
    {
        $jobs = $this->jobs();
        $first = $this->prepare($jobs);
        $jobs->run();
        Cache::store('array')->flush();
        $pending = $this->prepare($jobs, $first['plan']['resume_url']);
        $this->assertTrue($pending['plan']['waiting']);
        $this->assertSame('pending', $pending['plan']['state']);
        $this->assertSame($first['plan']['resume_url'], $pending['plan']['resume_url']);
        $jobs->run();
        $this->assertFalse($this->prepare($jobs, $first['plan']['resume_url'])['plan']['waiting']);
    }

    public function test_failures_are_bounded_and_polls_do_not_restart_failed_work(): void
    {
        $jobs = $this->jobs(true);
        $first = $this->prepare($jobs);
        for ($i = 0; $i < 3; $i++) {
            $this->assertSame(1, $jobs->run()['failed']);
            $this->travel(1)->minutes();
        }
        $failed = $this->prepare($jobs, $first['plan']['resume_url']);
        $this->assertSame('failed', $failed['plan']['state']);
        $this->assertSame(3, (int) DB::table($jobs::TABLE)->value('attempts'));
        $this->assertSame('pending', $this->prepare($jobs)['plan']['state']);
        $this->assertSame(0, (int) DB::table($jobs::TABLE)->value('attempts'));
        $this->travelBack();
    }

    public function test_a_run_cannot_be_read_by_a_different_user(): void
    {
        $jobs = $this->jobs();
        $first = $this->prepare($jobs);
        $jobs->run();
        $this->expectException(ValidationException::class);
        $this->prepare($jobs, $first['plan']['resume_url'], 2);
    }

    public function test_pending_queries_report_the_queue_and_interrupted_jobs_stop_after_three_attempts(): void
    {
        $jobs = $this->jobs();
        $first = $this->prepare($jobs);
        $second = $this->prepare($jobs, user: 2);
        $this->assertStringContainsString('前面还有 1 个报表任务', $second['plan']['message']);
        DB::table($jobs::TABLE)->where('id', $first['plan']['job']['id'])->update(['state' => 'overview_running', 'attempts' => 3]);
        $this->assertSame(['completed' => 1, 'failed' => 1], $jobs->run());
        $this->assertSame('failed', $this->prepare($jobs, $first['plan']['resume_url'])['plan']['state']);
    }

    public function test_waiting_queries_wake_the_worker_and_ready_results_do_not(): void
    {
        $this->mock(FinanceOverviewWorker::class)->shouldReceive('wake')->twice()->andReturnTrue();
        $jobs = $this->jobs();
        $first = $this->prepare($jobs);
        $this->prepare($jobs, $first['plan']['resume_url']);
        $jobs->run();
        $this->assertFalse($this->prepare($jobs, $first['plan']['resume_url'])['plan']['waiting']);
    }

    public function test_worker_launch_failure_is_actionable_and_can_be_retried(): void
    {
        $this->mock(FinanceOverviewWorker::class)->shouldReceive('wake')->twice()->andReturn(false, true);
        $jobs = $this->jobs();
        $failed = $this->prepare($jobs);
        $this->assertSame('unavailable', $failed['plan']['state']);
        $this->assertStringContainsString('无法启动后台计算', $failed['plan']['message']);
        $this->assertSame('pending', $this->prepare($jobs, $failed['plan']['resume_url'])['plan']['state']);
        $this->assertSame(1, DB::table($jobs::TABLE)->count());
    }
}
