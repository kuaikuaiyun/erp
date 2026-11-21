<?php

namespace Tests\Feature;

use App\Models\AssistantDomainStore;
use App\Models\LogisticsOrder;
use App\Portals\Assistant\AssistantStoreOwnerTimelineService;
use App\Portals\Finance\FinanceExternalChanges;
use App\Portals\Finance\FinanceSqliteConnection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AssistantOwnerSnapshotBatchTest extends TestCase
{
    private string $notificationRoot;
    private AssistantDomainStore $store;

    protected function setUp(): void
    {
        parent::setUp();
        $this->notificationRoot = sys_get_temp_dir().DIRECTORY_SEPARATOR.'erp-owner-batch-'.bin2hex(random_bytes(8));
        config([
            'database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:',
            'admin.database.connection' => null,
            'finance.summaries.external_change_path' => $this->notificationRoot,
        ]);
        DB::purge('sqlite');
        self::assertInstanceOf(FinanceSqliteConnection::class, DB::connection());
        Schema::create('assistant_domain_store_owner_histories', function (Blueprint $t): void {
            $t->id(); $t->unsignedBigInteger('assistant_domain_store_id');
            $t->unsignedBigInteger('owner_user_id')->nullable(); $t->string('owner_name_snapshot')->nullable();
            $t->unsignedBigInteger('operation_department_id')->nullable();
            $t->string('operation_department_name_snapshot')->nullable();
            $t->string('event')->default('assigned');
            $t->dateTime('effective_from'); $t->dateTime('effective_to')->nullable(); $t->timestamps();
        });
        Schema::create('logistics_orders', function (Blueprint $t): void {
            $t->id(); $t->unsignedBigInteger('assistant_domain_store_id'); $t->dateTime('order_created_at')->nullable();
            $t->unsignedBigInteger('assigned_owner_user_id')->nullable(); $t->string('assigned_owner_name')->nullable();
            $t->dateTime('owner_assignment_effective_from')->nullable();
            $t->dateTime('owner_assignment_resolved_at')->nullable();
            $t->string('transaction_id')->nullable(); $t->decimal('total_amount', 18, 2)->default(12.34);
            $t->timestamps();
        });
        Schema::create('finance_summary_changes', function (Blueprint $t): void {
            $t->id(); $t->string('source'); $t->text('payload'); $t->boolean('scopes_resolved')->default(false); $t->timestamps();
        });
        $this->store = new AssistantDomainStore();
        $this->store->setRawAttributes(['id' => 177]);
        Carbon::setTestNow(Carbon::parse('2026-11-21 08:00:00', 'UTC'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        // Delete only files created under this test's explicitly named temporary root.
        $prefix = sys_get_temp_dir().DIRECTORY_SEPARATOR.'erp-owner-batch-';
        if (isset($this->notificationRoot) && str_starts_with($this->notificationRoot, $prefix)) {
            foreach (glob($this->notificationRoot.'/*/change-*.json') ?: [] as $file) unlink($file);
            foreach (glob($this->notificationRoot.'/*') ?: [] as $directory) {
                if (is_dir($directory)) rmdir($directory);
            }
            if (is_dir($this->notificationRoot)) rmdir($this->notificationRoot);
        }
        parent::tearDown();
    }

    private function history(?int $owner, string $from, ?string $to = null, ?string $name = null): void
    {
        DB::table('assistant_domain_store_owner_histories')->insert([
            'assistant_domain_store_id' => 177, 'owner_user_id' => $owner,
            'owner_name_snapshot' => $name, 'operation_department_id' => $owner === null ? null : 22,
            'effective_from' => $from, 'effective_to' => $to,
        ]);
    }

    private function order(?string $at, int $store = 177): int
    {
        return DB::table('logistics_orders')->insertGetId([
            'assistant_domain_store_id' => $store, 'order_created_at' => $at,
            'total_amount' => 12.34, 'transaction_id' => 'TX-'.bin2hex(random_bytes(6)),
        ]);
    }

    private function clearSeedNotifications(): void
    {
        self::assertTrue(app(FinanceExternalChanges::class)->drain(DB::connection()));
        DB::table('finance_summary_changes')->delete();
    }

    private function files(): array
    {
        return glob(app(FinanceExternalChanges::class)->directory(DB::connection()).'/change-*.json') ?: [];
    }

    public function test_batch_matches_existing_resolution_at_boundaries_gaps_null_dates_and_escaped_names(): void
    {
        $this->history(7, '2026-01-01 00:00:00', '2026-01-15 00:00:00', "O'Neil ? 中文");
        $this->history(8, '2026-01-15 00:00:00', '2026-02-01 00:00:00', 'Second');
        $this->history(null, '2026-02-01 00:00:00', '2026-02-05 00:00:00');
        $this->history(9, '2026-02-10 00:00:00', null, 'Latest');
        $timeline = app(AssistantStoreOwnerTimelineService::class);
        $expected = [];
        foreach (['2025-12-31 23:59:59', '2026-01-01 00:00:00', '2026-01-14 23:59:59',
            '2026-01-15 00:00:00', '2026-02-01 00:00:00', '2026-02-05 00:00:00',
            '2026-02-10 00:00:00', null] as $at) {
            $expected[$this->order($at)] = $timeline->resolveAt($this->store, $at);
        }
        $foreign = $this->order('2026-01-01 00:00:00', 188);
        $this->clearSeedNotifications();
        self::assertSame(8, DB::transaction(fn () => $timeline->refreshSnapshots($this->store)));
        foreach ($expected as $id => $assignment) {
            $row = DB::table('logistics_orders')->find($id);
            self::assertSame($assignment['owner_user_id'], $row->assigned_owner_user_id);
            self::assertSame($assignment['owner_name'], $row->assigned_owner_name);
            self::assertSame($assignment['effective_from']?->format('Y-m-d H:i:s'), $row->owner_assignment_effective_from);
            self::assertSame('2026-11-21 08:00:00', $row->owner_assignment_resolved_at);
            self::assertSame(12.34, (float) $row->total_amount);
        }
        self::assertNull(DB::table('logistics_orders')->find($foreign)->owner_assignment_resolved_at);
        self::assertCount(1, $this->files());
    }

    public function test_from_filter_leaves_earlier_and_other_store_orders_unchanged(): void
    {
        $this->history(7, '2026-01-01 00:00:00', null, 'Owner');
        $earlier = $this->order('2026-01-14 23:59:59');
        $included = $this->order('2026-01-15 00:00:00');
        $foreign = $this->order('2026-01-15 00:00:00', 188);
        $this->clearSeedNotifications();
        $count = app(AssistantStoreOwnerTimelineService::class)->refreshSnapshots($this->store, Carbon::parse('2026-01-15 00:00:00', 'UTC'));
        self::assertSame(1, $count);
        self::assertSame(7, DB::table('logistics_orders')->find($included)->assigned_owner_user_id);
        foreach ([$earlier, $foreign] as $id) self::assertNull(DB::table('logistics_orders')->find($id)->owner_assignment_resolved_at);
    }

    public function test_2500_orders_use_25_updates_and_keep_finance_events_for_every_order(): void
    {
        $this->history(7, '2026-01-01 00:00:00', null, 'Owner');
        for ($batch = 0; $batch < 25; $batch++) {
            $rows = [];
            for ($i = 0; $i < 100; $i++) $rows[] = [
                'assistant_domain_store_id' => 177, 'order_created_at' => '2026-11-01 00:00:00',
                'transaction_id' => 'TX-'.$batch.'-'.$i, 'total_amount' => 12.34,
            ];
            DB::table('logistics_orders')->insert($rows);
        }
        $this->clearSeedNotifications();
        $db = DB::connection();
        $db->enableQueryLog(); $db->flushQueryLog();
        $openBefore = count(get_resources('stream'));
        $db->transaction(function () use ($openBefore): void {
            self::assertSame(2500, app(AssistantStoreOwnerTimelineService::class)->refreshSnapshots($this->store));
            self::assertCount(25, $this->files());
            self::assertLessThanOrEqual(30, count(get_resources('stream')) - $openBefore);
            // On Windows flock prevents a second handle from reading pending
            // tickets. Count live streams here; inspect their content after commit.
        });
        $queries = $db->getQueryLog(); $db->disableQueryLog();
        self::assertCount(25, array_filter($queries, fn ($entry) => preg_match('/^UPDATE ["\x60]?logistics_orders/i', $entry['query'])));
        self::assertCount(1, array_filter($queries, fn ($entry) => preg_match('/^select .*from ["\x60]?assistant_domain_store_owner_histories/i', $entry['query'])));
        self::assertSame(2500, DB::table('logistics_orders')->where('assigned_owner_user_id', 7)->count());
        self::assertSame(2500, DB::table('logistics_orders')->where('total_amount', 12.34)->count());
        foreach ($this->files() as $file) {
            $data = json_decode(file_get_contents($file), true);
            self::assertSame('committed', $data['state']);
            self::assertCount(200, $data['events']);
            self::assertSame(['order'], array_values(array_unique(array_column($data['events'], 'source'))));
        }
        self::assertTrue(app(FinanceExternalChanges::class)->drain($db));
        self::assertSame(5000, $db->table('finance_summary_changes')->where('source', 'order')->count());
        $ids = $db->table('finance_summary_changes')->where('source', 'order')->pluck('payload')
            ->map(fn ($payload) => json_decode($payload, true)['id'])->unique();
        self::assertCount(2500, $ids);
        self::assertSame([], $this->files());
    }

    public function test_failure_after_multiple_batches_rolls_back_snapshots_and_discards_finance_tickets(): void
    {
        $this->history(7, '2026-01-01 00:00:00', null, 'Owner');
        $rows = array_fill(0, 205, ['assistant_domain_store_id' => 177, 'order_created_at' => '2026-11-01 00:00:00']);
        foreach (array_chunk($rows, 100) as $chunk) DB::table('logistics_orders')->insert($chunk);
        $this->clearSeedNotifications();
        try {
            DB::transaction(function (): void {
                self::assertSame(205, app(AssistantStoreOwnerTimelineService::class)->refreshSnapshots($this->store));
                self::assertCount(3, $this->files());
                throw new \RuntimeException('Simulate later save failure');
            });
            self::fail('Rollback must propagate the original exception.');
        } catch (\RuntimeException $error) {
            self::assertSame('Simulate later save failure', $error->getMessage());
        }
        self::assertSame(205, DB::table('logistics_orders')->whereNull('assigned_owner_user_id')->whereNull('owner_assignment_resolved_at')->count());
        self::assertSame([], $this->files());
        self::assertSame(0, DB::table('finance_summary_changes')->count());
    }
}
