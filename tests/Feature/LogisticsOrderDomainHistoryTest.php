<?php

namespace Tests\Feature;

use App\Models\AssistantDomainStoreChangeLog;
use App\Models\LogisticsOrder;
use App\Portals\Assistant\AssistantStoreDomainTimelineService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class LogisticsOrderDomainHistoryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default'=>'sqlite', 'database.connections.sqlite.database'=>':memory:', 'admin.database.connection'=>null]);
        DB::purge('sqlite');
        Schema::create((new AssistantDomainStoreChangeLog())->getTable(), function (Blueprint $table): void {
            $table->increments('id');
            $table->integer('domain_store_id');
            $table->string('event');
            $table->text('changes');
            $table->timestamps();
        });
    }

    private function change(string $at, ?string $before, string $after, string $event = 'updated'): void
    {
        AssistantDomainStoreChangeLog::query()->forceCreate([
            'domain_store_id'=>1, 'event'=>$event, 'changes'=>['domain'=>compact('before','after')],
            'created_at'=>$at, 'updated_at'=>$at,
        ]);
    }

    private function resolve(array $dates, int $store = 1): array
    {
        $orders = collect($dates)->map(fn ($date, $i) => (new LogisticsOrder())->forceFill([
            'id'=>$i+1, 'assistant_domain_store_id'=>$store,
            'order_created_at'=>$date === null ? null : \Illuminate\Support\Carbon::parse($date)->utc(),
        ]));
        DB::enableQueryLog(); DB::flushQueryLog();
        $result = app(AssistantStoreDomainTimelineService::class)->domainsForOrders($orders);
        self::assertCount(1, DB::getQueryLog(), 'History must be loaded once per page.');
        DB::disableQueryLog();
        return array_values($result);
    }

    public function test_old_orders_and_exact_boundaries_use_historical_domain_in_one_query(): void
    {
        $this->change('2026-11-15 04:00:00','a.com','b.com');
        $this->change('2026-11-18 04:00:00','b.com','c.com');
        self::assertSame(['a.com','a.com','b.com','b.com','c.com'], $this->resolve([
            '2026-11-10 00:00:00Z', '2026-11-15T11:59:59+08:00',
            '2026-11-15T12:00:00+08:00', '2026-11-17 00:00:00Z', '2026-11-18 04:00:00Z',
        ]));
    }

    public function test_missing_history_missing_date_and_other_stores_do_not_use_current_domain(): void
    {
        self::assertSame([null,null], $this->resolve(['2026-11-10', null]));
        $this->change('2026-11-15 04:00:00',null,'b.com','created');
        self::assertSame([null,'b.com'], $this->resolve(['2026-11-10','2026-11-16']));
        self::assertSame([null], $this->resolve(['2026-11-16'],2));
    }

    public function test_inconsistent_history_marks_gap_unknown(): void
    {
        $this->change('2026-11-15 04:00:00','a.com','b.com');
        $this->change('2026-11-18 04:00:00','unrecorded.com','c.com');
        self::assertSame(['a.com',null,'c.com'], $this->resolve(['2026-11-10','2026-11-16','2026-11-19']));
    }
}
