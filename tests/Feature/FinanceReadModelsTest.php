<?php

namespace Tests\Feature;

use App\Http\Middleware\MonitorFinancePerformance;
use App\Portals\Finance\FinanceExternalChanges;
use App\Portals\Finance\FinanceReadModelBuilds;
use App\Portals\Finance\FinanceReadModelPending;
use App\Portals\Finance\FinanceReadModelVersions;
use App\Portals\Finance\FinanceReportCache;
use App\Portals\Finance\FinanceSummaryDataVersion;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class FinanceReadModelsTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = storage_path('framework/testing/read-models-'.bin2hex(random_bytes(6)));
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:',
            'admin.database.connection' => null, 'cache.default' => 'array', 'finance.read_models.enabled' => true,
            'finance.diagnostics.enabled' => false, 'finance.summaries.external_change_path' => $this->directory]);
        DB::purge('sqlite');
        DB::connection()->getSchemaBuilder()->create('finance_application_tracking', function (Blueprint $table) { $table->integer('id'); $table->string('mode'); });
        DB::table('finance_application_tracking')->insert(['id' => 1, 'mode' => 'application']);
        DB::connection()->getSchemaBuilder()->create('finance_summary_changes', function (Blueprint $table) {
            $table->increments('id'); $table->string('source'); $table->text('payload'); $table->boolean('scopes_resolved');
        });
        (require database_path('migrations/2026_11_21_000010_separate_finance_read_models.php'))->up();
    }

    protected function tearDown(): void
    {
        DB::disconnect('sqlite');
        File::deleteDirectory($this->directory);
        parent::tearDown();
    }

    private function request(string $query = ''): Request
    {
        $request = Request::create('http://caiwu.example/finance/reports/losses'.$query);
        $request->headers->set('Accept', 'application/json');
        $this->app->instance('request', $request);
        return $request;
    }

    private function sourceTables(): void
    {
        $schema = DB::connection()->getSchemaBuilder();
        $schema->create('logistics_orders', function (Blueprint $table) {
            $table->increments('id'); $table->string('transaction_id'); $table->string('bill_number')->nullable();
        });
    }

    public function test_revisions_ignore_legacy_notification_backlog_and_change_atomically_with_the_source(): void
    {
        $this->request();
        $this->sourceTables();
        $versions = app(FinanceSummaryDataVersion::class);
        $first = $versions->forCache(['paypal']);
        $files = app(FinanceExternalChanges::class);
        $ticket = $files->begin(DB::connection());
        $files->commit(DB::connection(), $ticket, [['source' => 'scope_user', 'values' => []]]);
        $this->assertTrue($files->hasPending(DB::connection()));
        $this->assertSame($first, $versions->forCache(['paypal']));
        $this->assertSame($versions->current('paypal'), $versions->forRange('paypal', ['from' => '2026-11-01', 'to' => '2026-11-30']));
        DB::beginTransaction();
        DB::table('logistics_orders')->insert(['transaction_id' => 'PAY-1']);
        $this->assertNotSame($first, $versions->forCache(['paypal']));
        $this->assertGreaterThan(0, DB::table('finance_summary_changes')->count());
        DB::rollBack();
        $this->assertSame($first, $versions->forCache(['paypal']));
        $this->assertSame(0, DB::table('finance_summary_changes')->count());
        DB::table('logistics_orders')->insert(['transaction_id' => 'PAY-2']);
        $this->assertNotSame($first, $versions->forCache(['paypal']));
        $this->assertSame(1, count(glob($files->directory(DB::connection()).'/change-*.json')));
    }

    public function test_credit_only_changes_do_not_invalidate_paypal_and_old_outbox_cleanup_does_not_change_the_version(): void
    {
        $this->request();
        $versions = app(FinanceSummaryDataVersion::class);
        $paypal = $versions->forCache(['paypal']); $credit = $versions->forCache(['ye']);
        DB::transaction(fn () => FinanceReadModelVersions::touch(DB::connection(), 'fashion', ['id' => 1]));
        $this->assertSame($paypal, $versions->forCache(['paypal']));
        $this->assertNotSame($credit, $versions->forCache(['ye']));
        $this->assertSame($versions->forCache(['ye']), $versions->forCache(['xuzong1']));
    }

    public function test_http_cache_misses_only_enqueue_once_and_never_execute_the_builder(): void
    {
        $cache = app(FinanceReportCache::class);
        $builds = app(FinanceReadModelBuilds::class);
        $build = fn () => $this->fail('An HTTP request must not build an order graph.');
        for ($i = 0; $i < 2; $i++) {
            $response = (new MonitorFinancePerformance)->handle($this->request('?_finance_plan=1'), fn () =>
                response()->json($cache->remember('paypal-order-graph:v3', ['paypal'], $build, ['kind' => 'paypal-graph', 'payload' => []])));
            $this->assertTrue($response->getData(true)['waiting']);
            $this->assertSame('queued', $response->getData(true)['state']);
        }
        $this->assertSame(1, DB::table($builds::TABLE)->count());
        $this->assertSame(0, (int) DB::table($builds::TABLE)->value('attempts'));
        request()->attributes->remove('finance.report_read_only');
        $this->assertSame(['built'], $cache->remember('paypal-order-graph:v3', ['paypal'], fn () => ['built']));
        $response = (new MonitorFinancePerformance)->handle($this->request('?_finance_plan=1'), fn () =>
            response()->json($cache->remember('paypal-order-graph:v3', ['paypal'], $build, ['kind' => 'paypal-graph', 'payload' => []])));
        $this->assertFalse($response->getData(true)['waiting']);
    }

    public function test_worker_failure_is_retried_and_failed_work_is_not_reset_by_polling(): void
    {
        $this->request();
        $builds = new class extends FinanceReadModelBuilds {
            protected function build(string $kind, array $payload): bool { throw new \RuntimeException('Private failure detail'); }
        };
        $this->assertSame('queued', $builds->enqueue('paypal-graph', []));
        for ($i = 0; $i < 5; $i++) {
            $this->assertSame(1, $builds->run(1)['failed']);
            $this->travel(6)->minutes();
        }
        $this->assertSame('failed', $builds->enqueue('paypal-graph', []));
        $this->assertSame(5, (int) DB::table($builds::TABLE)->value('attempts'));
        $this->assertSame(\RuntimeException::class, DB::table($builds::TABLE)->value('error_class'));
        $this->travelBack();
    }

    public function test_cold_summary_sources_never_call_the_http_fallback_or_create_cache_files(): void
    {
        $request = $this->request();
        $request->attributes->set('finance.report_read_only', true);
        config(['finance.summaries.source_cache_path' => $this->directory.'/source']);
        try {
            iterator_to_array(app(\App\Portals\Finance\FinanceSummarySourceCache::class)->stream('paypal',
                ['from' => '2026-11-01', 'to' => '2026-11-30'], false, fn () => $this->fail('HTTP source build')));
            $this->fail('Cold reads must return a pending state.');
        } catch (FinanceReadModelPending $pending) {
            $this->assertSame('queued', $pending->state);
        }
        $this->assertSame('summary-source', DB::table(FinanceReadModelBuilds::TABLE)->value('kind'));
        $this->assertDirectoryDoesNotExist($this->directory.'/source');
    }

    public function test_report_job_failures_do_not_block_order_saves_and_unrelated_tables_do_not_emit_finance_changes(): void
    {
        $this->request();
        $this->sourceTables();
        DB::table(FinanceReadModelBuilds::TABLE)->insert(['job_key' => str_repeat('f', 64), 'kind' => 'paypal-graph',
            'payload' => '{}', 'state' => 'failed', 'attempts' => 5, 'error_class' => \RuntimeException::class]);
        $version = app(FinanceSummaryDataVersion::class)->forCache(['paypal']);
        DB::connection()->getSchemaBuilder()->create('unrelated_business_orders', function (Blueprint $table) { $table->increments('id'); $table->string('reference'); });
        DB::table('unrelated_business_orders')->insert(['reference' => 'UNCHANGED-BUSINESS']);
        $this->assertSame(0, DB::table('finance_summary_changes')->count());
        $this->assertSame($version, app(FinanceSummaryDataVersion::class)->forCache(['paypal']));
        DB::table('logistics_orders')->insert(['transaction_id' => 'ORDER-WHILE-REPORT-FAILED']);
        $this->assertSame(1, DB::table('logistics_orders')->count());
        $this->assertGreaterThan(0, DB::table('finance_summary_changes')->count());
        $this->assertNotSame($version, app(FinanceSummaryDataVersion::class)->forCache(['paypal']));
    }
}
