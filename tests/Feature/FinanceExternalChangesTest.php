<?php

namespace Tests\Feature;

use App\Portals\Finance\FinanceExternalChanges;
use App\Portals\Finance\FinanceSummarySourceChanges;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class FinanceExternalChangesTest extends TestCase
{
    private string $path;
    private FinanceExternalChanges $notifications;

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'admin.database.connection' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:']);
        DB::purge('sqlite');
        $this->path = storage_path('framework/testing/finance-notifications-'.bin2hex(random_bytes(6)));
        config(['finance.summaries.external_change_path' => $this->path]);
        $this->notifications = app(FinanceExternalChanges::class);
        DB::connection()->getSchemaBuilder()->create('finance_summary_changes', function (Blueprint $table): void {
            $table->increments('id'); $table->string('source'); $table->text('payload'); $table->boolean('scopes_resolved');
        });
    }

    protected function tearDown(): void
    {
        DB::disconnect('sqlite');
        File::deleteDirectory($this->path);
        parent::tearDown();
    }

    private function enqueue(int $events = 1): string
    {
        $ticket = $this->notifications->begin(DB::connection());
        $this->assertNotNull($ticket);
        $this->notifications->commit(DB::connection(), $ticket, array_fill(0, $events,
            ['source' => 'scope_user', 'values' => []]));
        return $ticket['path'];
    }

    public function test_delivered_leftovers_do_not_consume_the_limit_or_block_later_notifications(): void
    {
        $pending = $this->enqueue();
        $directory = dirname($pending);
        // Sort old delivered files before the pending UUID regardless of its random prefix.
        for ($i = 0; $i < 1050; $i++) file_put_contents($directory.'/change-00000000-0000-0000-0000-'.sprintf('%012x', $i).'.json', '{"state":"delivered"}');
        $this->assertTrue($this->notifications->hasPending(DB::connection()));
        $this->assertTrue($this->notifications->drain(DB::connection(), 1));
        $this->assertSame(1, DB::table('finance_summary_changes')->count());
        $this->assertFileDoesNotExist($pending);
        $this->assertSame([], glob($directory.'/change-*.json'));
        $this->assertFalse($this->notifications->hasPending(DB::connection()));
        $this->assertTrue($this->notifications->drain(DB::connection(), 1));
        $this->assertSame(1, DB::table('finance_summary_changes')->count());
    }

    public function test_notifications_are_committed_in_batches_and_are_not_delivered_twice(): void
    {
        for ($i = 0; $i < 120; $i++) $this->enqueue();
        $commits = 0;
        Event::listen(TransactionCommitted::class, function ($event) use (&$commits): void {
            if ($event->connection === DB::connection()) $commits++;
        });
        $this->assertTrue($this->notifications->drain(DB::connection()));
        $this->assertSame(3, $commits);
        $this->assertSame(120, DB::table('finance_summary_changes')->count());
        $this->assertTrue($this->notifications->drain(DB::connection()));
        $this->assertSame(3, $commits);
        $this->assertSame(120, DB::table('finance_summary_changes')->count());
    }

    public function test_unreadable_file_does_not_starve_other_notifications_and_is_not_acknowledged(): void
    {
        $poison = $this->enqueue();
        $good = $this->enqueue();
        $db = DB::connection();
        $directory = dirname($poison);
        $notifications = new class($directory, $poison) extends FinanceExternalChanges {
            public function __construct(private string $path, private string $poison) { parent::__construct(); }
            public function directory(\Illuminate\Database\Connection $db): string { return $this->path; }
            protected function openNotification(string $path, string $mode, ?string &$error)
            {
                if ($path === $this->poison) { $error = 'Permission denied'; return false; }
                return parent::openNotification($path, $mode, $error);
            }
        };
        $this->assertFalse($notifications->drain($db));
        $this->assertFileExists($poison);
        $this->assertFileDoesNotExist($good);
        $this->assertSame(1, DB::table('finance_summary_changes')->count());
        $this->assertSame('committed', json_decode(file_get_contents($poison), true)['state']);
    }

    public function test_large_directory_is_inspected_in_bounded_batches_and_never_certifies_a_partial_scan(): void
    {
        $db = DB::connection();
        $directory = $this->notifications->directory($db);
        File::ensureDirectoryExists($directory);
        for ($i = 0; $i < 100; $i++) file_put_contents($directory.'/change-00000000-0000-0000-0000-'.sprintf('%012x', $i).'.json', '{"state":"delivered"}');
        config(['finance.summaries.notification_scan_limit' => 10, 'finance.summaries.notification_read_scan_limit' => 5]);
        $this->assertTrue($this->notifications->hasPending($db));
        $status = $this->notifications->status($db);
        $this->assertSame(10, $status['pending']);
        $this->assertFalse($status['count_complete']);
        $this->assertFalse($this->notifications->drain($db));
        // The consumer lock also counts against the directory-entry scan budget.
        $this->assertGreaterThanOrEqual(90, count(glob($directory.'/change-*.json')));
        $this->assertLessThanOrEqual(91, count(glob($directory.'/change-*.json')));
        for ($i = 0; $i < 12 && $this->notifications->hasPending($db); $i++) $this->notifications->drain($db);
        $this->assertFalse($this->notifications->hasPending($db));
        $this->assertSame(0, DB::table('finance_summary_changes')->count());
    }

    public function test_accepted_batches_publish_revisions_while_more_notification_files_are_pending(): void
    {
        $db = DB::connection();
        $schema = $db->getSchemaBuilder();
        $schema->create('finance_summary_source_revisions', function (Blueprint $table): void {
            $table->string('channel'); $table->string('source_date'); $table->unsignedBigInteger('revision');
            $table->unique(['channel', 'source_date']);
        });
        $schema->create('finance_summary_periods', function (Blueprint $table): void {
            $table->id(); $table->string('month'); $table->string('channel'); $table->integer('revision'); $table->integer('published');
        });
        for ($i = 0; $i < 1001; $i++) $this->enqueue();
        $this->assertFalse(app(FinanceSummarySourceChanges::class)->publish($db));
        $this->assertSame(2, $db->table('finance_summary_source_revisions')->count());
        $this->assertSame(1000, $db->table('finance_summary_changes')->where('scopes_resolved', true)->count());
        $this->assertTrue($this->notifications->hasPending($db));
    }

    public function test_failed_batch_rolls_back_and_keeps_the_notification_for_retry(): void
    {
        $path = $this->enqueue(300);
        $fail = true; $inserts = 0;
        Event::listen(QueryExecuted::class, function ($event) use (&$fail, &$inserts): void {
            if ($fail && str_starts_with($event->sql, 'insert into "finance_summary_changes"') && ++$inserts === 2) {
                throw new \RuntimeException('Simulated queue failure');
            }
        });
        $this->assertFalse($this->notifications->drain(DB::connection()));
        $this->assertSame(0, DB::table('finance_summary_changes')->count());
        $this->assertSame('committed', json_decode(file_get_contents($path), true)['state']);
        $fail = false;
        $this->assertTrue($this->notifications->drain(DB::connection()));
        $this->assertSame(300, DB::table('finance_summary_changes')->count());
        $this->assertFileDoesNotExist($path);
    }

    public function test_readers_and_competing_consumers_do_not_import_or_wait_on_locked_files(): void
    {
        $path = $this->enqueue();
        request()->attributes->set('finance.report_read_only', true);
        $this->assertFalse($this->notifications->drain(DB::connection()));
        $this->assertSame(0, DB::table('finance_summary_changes')->count());
        request()->attributes->remove('finance.report_read_only');
        $lock = fopen(dirname($path).'/.consumer.lock', 'c+b');
        flock($lock, LOCK_EX);
        try {
            $this->assertFalse($this->notifications->drain(DB::connection()));
            $this->assertSame(0, DB::table('finance_summary_changes')->count());
        } finally { fclose($lock); }
        $this->assertTrue($this->notifications->drain(DB::connection()));
    }

    public function test_report_readiness_waits_for_file_delivery_and_scope_resolution_without_mutating_either(): void
    {
        $schema = DB::connection()->getSchemaBuilder();
        $schema->create('finance_summary_source_revisions', fn (Blueprint $table) => $table->id());
        $schema->create('finance_application_tracking', function (Blueprint $table): void { $table->id(); $table->string('mode'); });
        DB::table('finance_application_tracking')->insert(['mode' => 'application']);
        $changes = app(FinanceSummarySourceChanges::class);
        $path = $this->enqueue();
        request()->attributes->set('finance.report_read_only', true);
        $this->assertFalse($changes->reportReady(DB::connection()));
        $this->assertFalse($changes->publish(DB::connection()));
        $this->assertFileExists($path);
        $this->assertSame(0, DB::table('finance_summary_changes')->count());
        request()->attributes->remove('finance.report_read_only');
        $this->assertTrue($this->notifications->drain(DB::connection()));
        request()->attributes->set('finance.report_read_only', true);
        $this->assertFalse($changes->reportReady(DB::connection()));
        $this->assertFalse($changes->publish(DB::connection()));
        $this->assertSame(0, DB::table('finance_summary_changes')->where('scopes_resolved', true)->count());
        DB::table('finance_summary_changes')->update(['scopes_resolved' => true]);
        $this->assertTrue($changes->reportReady(DB::connection()));
        $this->assertTrue($changes->publish(DB::connection()));
    }
}
