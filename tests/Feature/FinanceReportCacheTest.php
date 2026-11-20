<?php

namespace Tests\Feature;

use App\Portals\Finance\FinanceReportCache;
use App\Portals\Finance\FinanceSummaryDataVersion;
use App\Portals\Finance\FinanceWorkbookService;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Cache;
use Mockery;
use Tests\TestCase;

class FinanceReportCacheTest extends TestCase
{
    private function reportCache(array $revisions): FinanceReportCache
    {
        config(['cache.default' => 'array']);
        Cache::store('array')->flush();
        $db = Mockery::mock(Connection::class);
        $db->shouldReceive('getName')->andReturn('test');
        $db->shouldReceive('getDatabaseName')->andReturn('finance');
        $workbooks = Mockery::mock(FinanceWorkbookService::class);
        $workbooks->shouldReceive('database')->andReturn($db);
        $versions = Mockery::mock(FinanceSummaryDataVersion::class);
        $versions->shouldReceive('forCache')->andReturn(...$revisions);
        return new FinanceReportCache($workbooks, $versions);
    }

    public function test_unchanged_queries_survive_elapsed_time_and_switching_between_scopes(): void
    {
        $cache = $this->reportCache(['v1']);
        $this->assertSame(0, $cache->remember('worldpay', ['credit_card'], fn () => 0));
        $this->assertSame(20, $cache->remember('ye', ['credit_card'], fn () => 20));
        $this->travel(2)->days();
        $this->assertSame(0, $cache->get('worldpay', ['credit_card']));
        $this->assertSame(20, $cache->remember('ye', ['credit_card'], fn () => $this->fail('Unchanged inputs must be reused.')));
        $this->travelBack();
    }

    public function test_a_source_edit_replaces_the_previous_cached_count(): void
    {
        $cache = $this->reportCache(['v1', 'v1', 'v2', 'v2', 'v2', 'v2']);
        $this->assertSame(10, $cache->remember('total', ['paypal'], fn () => 10));
        $this->assertNull($cache->get('total', ['paypal']));
        $this->assertSame(11, $cache->remember('total', ['paypal'], fn () => 11));
        $this->assertSame(11, $cache->get('total', ['paypal']));
    }

    public function test_a_build_racing_a_source_change_is_never_published(): void
    {
        $cache = $this->reportCache(['v1', 'v2', 'v2']);
        $this->assertSame(10, $cache->remember('total', ['paypal'], fn () => 10));
        $this->assertNull($cache->get('total', ['paypal']));
    }

    public function test_untracked_sources_are_always_read_again(): void
    {
        $cache = $this->reportCache([null]);
        $this->assertSame(10, $cache->remember('total', ['paypal'], fn () => 10));
        $this->assertSame(11, $cache->remember('total', ['paypal'], fn () => 11));
        $this->assertNull($cache->get('total', ['paypal']));
    }
}
