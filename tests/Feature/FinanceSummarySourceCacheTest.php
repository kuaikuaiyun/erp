<?php

namespace Tests\Feature;

use App\Portals\Finance\FinanceSummaryDataVersion;
use App\Portals\Finance\FinanceSummarySourceCache;
use Illuminate\Support\Facades\File;
use Mockery;
use Tests\TestCase;

class FinanceSummarySourceCacheTest extends TestCase
{
    public function test_option_and_order_graph_revisions_change_when_sources_change(): void
    {
        $db = Mockery::mock(\Illuminate\Database\Connection::class);
        $db->shouldReceive('getDriverName')->andReturn('sqlite');
        $db->shouldReceive('getName')->andReturn('cache-revision-test');
        $db->shouldReceive('getDatabaseName')->andReturn('finance');
        $workbooks = Mockery::mock(\App\Portals\Finance\FinanceWorkbookService::class);
        $workbooks->shouldReceive('database')->andReturn($db);
        $versions = Mockery::mock(FinanceSummaryDataVersion::class, [$workbooks])->makePartial();
        $source = 'v1';
        $versions->shouldReceive('current')->andReturnUsing(function () use (&$source) { return $source; });
        $first = $versions->cacheRevision(['paypal', 'credit_card']);
        $this->assertSame($first, $versions->cacheRevision(['paypal', 'credit_card']));
        $source = 'v2';
        $this->assertNotSame($first, $versions->cacheRevision(['paypal', 'credit_card']));
        $source = null;
        $untracked = $versions->cacheRevision(['paypal']);
        $this->assertSame($untracked, $versions->cacheRevision(['paypal']));
        request()->attributes->remove('finance.source_cache_run');
        $this->assertNotSame($untracked, $versions->cacheRevision(['paypal']));
    }

    public function test_untracked_sources_can_only_reuse_rows_within_the_current_verified_query_run(): void
    {
        $db = Mockery::mock(\Illuminate\Database\Connection::class);
        $db->shouldReceive('getDriverName')->andReturn('sqlite');
        $workbooks = Mockery::mock(\App\Portals\Finance\FinanceWorkbookService::class);
        $workbooks->shouldReceive('database')->andReturn($db);
        $versions = new FinanceSummaryDataVersion($workbooks);
        $this->assertNull($versions->current('paypal'));
        request()->attributes->set('finance.source_run', 'verified-query-run');
        try {
            $first = $versions->current('paypal');
            $this->assertNotNull($first);
            $this->assertSame($first, $versions->current('paypal'));
            $this->assertNotSame($first, $versions->current('credit_card'));
            request()->attributes->set('finance.source_run', 'another-verified-query-run');
            $this->assertNotSame($first, $versions->current('paypal'));
        } finally {
            request()->attributes->remove('finance.source_run');
        }
        $this->assertNull($versions->current('paypal'));
    }

    public function test_a_complete_month_reuses_prepared_source_parts_without_recalculating_orders(): void
    {
        $original = config('cache.stores.file.path');
        $directory = storage_path('framework/testing/source-parts-'.bin2hex(random_bytes(6)));
        config(['cache.stores.file.path' => $directory]);
        app('cache')->forgetDriver('file');
        try {
            $versions = Mockery::mock(FinanceSummaryDataVersion::class);
            $versions->shouldReceive('current')->andReturn('source-version');
            $versions->shouldReceive('forRange')->andReturn('source-version');
            $cache = new FinanceSummarySourceCache($versions);
            $start = \Carbon\CarbonImmutable::parse('2026-11-01');
            $expected = [];
            foreach ([1, 8, 15, 22, 29] as $day) {
                $from = $start->day($day);
                $row = ['id' => $day, 'payment_channel' => 'paypal', 'settlement' => (string) $day, 'team' => 'A'];
                $expected[] = $row;
                iterator_to_array($cache->stream('paypal', ['from' => $from->toDateString(),
                    'to' => min($from->addDays(6)->toDateString(), '2026-11-30')], false, fn () => yield $row));
            }
            $this->assertSame(array_reverse($expected), iterator_to_array($cache->stream('paypal', [
                'from' => '2026-11-01', 'to' => '2026-11-30',
            ], false, fn () => $this->fail('Complete prepared source parts must be reused.')), false));
        } finally {
            File::deleteDirectory($directory);
            config(['cache.stores.file.path' => $original]);
            app('cache')->forgetDriver('file');
        }
    }

    public function test_cold_source_defers_offset_indexes_and_builds_only_requested_filter_fields(): void
    {
        $originalPath = config('cache.stores.file.path');
        $cachePath = storage_path('framework/testing/source-cache-'.bin2hex(random_bytes(6)));
        config()->set('cache.stores.file.path', $cachePath);
        app('cache')->forgetDriver('file');

        try {
            $versions = Mockery::mock(FinanceSummaryDataVersion::class);
            $versions->shouldReceive('current')->with('paypal')->andReturn('test-version');
            $versions->shouldReceive('forRange')->with('paypal', Mockery::any())->andReturn('test-version');
            $cache = new FinanceSummarySourceCache($versions);
            $rows = [
                ['id' => 1, 'team' => 'A', '_summary_team' => 'Summary A', 'subscription' => '订阅 A', 'currency' => 'USD', 'owner' => '甲', 'payment_channel' => 'paypal'],
                ['id' => 2, 'team' => 'B', '_summary_team' => 'Summary B', 'subscription' => '订阅 B', 'currency' => 'EUR', 'owner' => '乙', 'payment_channel' => 'paypal'],
            ];
            $build = fn () => yield from $rows;
            $options = null;

            $this->assertSame($rows, iterator_to_array($cache->stream('paypal', [], false, $build, function ($value) use (&$options): void {
                $options = $value;
            }), false));

            $source = collect(File::allFiles($cachePath.'/finance-summary-sources'))
                ->first(fn ($file) => str_ends_with($file->getFilename(), '.jsonl'));
            $this->assertNotNull($source);
            $manifestPath = $source->getPathname().'.index.json';
            $manifest = json_decode(File::get($manifestPath), true, 512, JSON_THROW_ON_ERROR);
            $this->assertSame([], $manifest['fields']);
            $this->assertSame('甲', $options['paypal']['owner']['甲']);
            $this->assertSame([], glob($source->getPathname().'.*.idx') ?: []);

            $filtered = iterator_to_array($cache->stream('paypal', [
                'summary_row_filters' => ['owner' => '甲'],
            ], false, $build), false);
            $this->assertSame([1], array_column($filtered, 'id'));
            $manifest = json_decode(File::get($manifestPath), true, 512, JSON_THROW_ON_ERROR);
            $this->assertSame(['row_owner'], array_keys($manifest['fields']));

            $filtered = iterator_to_array($cache->stream('paypal', [
                'summary_row_filters' => ['subscription' => '订阅 B'],
            ], false, $build), false);
            $this->assertSame([2], array_column($filtered, 'id'));
            $manifest = json_decode(File::get($manifestPath), true, 512, JSON_THROW_ON_ERROR);
            $this->assertEqualsCanonicalizing(['row_owner', 'subscription'], array_keys($manifest['fields']));
            $this->assertArrayNotHasKey('row_subscription', $manifest['fields']);
        } finally {
            File::deleteDirectory($cachePath);
            config()->set('cache.stores.file.path', $originalPath);
            app('cache')->forgetDriver('file');
        }
    }

    public function test_a_channel_tab_reuses_combined_rows_and_keeps_them_after_another_channel_changes(): void
    {
        $original = config('cache.stores.file.path');
        $directory = storage_path('framework/testing/source-tabs-'.bin2hex(random_bytes(6)));
        config(['cache.stores.file.path' => $directory]);
        app('cache')->forgetDriver('file');
        try {
            $broad = 'combined-v1';
            $versions = Mockery::mock(FinanceSummaryDataVersion::class);
            $versions->shouldReceive('forRange')->andReturnUsing(function ($channel, $filters) use (&$broad) {
                return ($filters['summary_payment_channels'] ?? []) === ['ye'] ? 'ye-v1' : $broad;
            });
            $cache = new FinanceSummarySourceCache($versions);
            $filters = ['from' => '2026-11-01', 'to' => '2026-11-07', 'timezone' => 'Asia/Shanghai', 'summary_native_settlement' => true];
            iterator_to_array($cache->stream('credit_card', [...$filters, 'summary_payment_channels' => ['ye', 'worldpay']], true,
                fn () => yield from [['id' => 1, 'payment_channel' => 'ye', 'team' => 'A', 'settlement' => '10'],
                    ['id' => 2, 'payment_channel' => 'worldpay', 'team' => 'B', 'settlement' => '20']]));
            $selected = [...$filters, 'summary_payment_channels' => ['ye']];
            $this->assertTrue($cache->ready('credit_card', $selected));
            $rows = iterator_to_array($cache->stream('credit_card', $selected, true,
                fn () => $this->fail('A tab must read existing compatible calculations.')), false);
            $this->assertSame([1], array_column($rows, 'id'));
            $broad = 'combined-v2';
            $this->travel(2)->days();
            $this->assertTrue($cache->ready('credit_card', $selected));
            $rows = iterator_to_array($cache->stream('credit_card', $selected, true,
                fn () => $this->fail('An unrelated channel or elapsed time must not evict the selected tab.')), false);
            $this->assertSame('10', $rows[0]['settlement']);
            $this->assertFalse($cache->ready('credit_card', [...$filters, 'summary_payment_channels' => ['worldpay']]));
        } finally {
            $this->travelBack();
            File::deleteDirectory($directory);
            config(['cache.stores.file.path' => $original]);
            app('cache')->forgetDriver('file');
        }
    }

    public function test_the_total_tab_combines_existing_channel_calculations_without_reloading_orders(): void
    {
        $original = config('cache.stores.file.path');
        $directory = storage_path('framework/testing/source-total-'.bin2hex(random_bytes(6)));
        config(['cache.stores.file.path' => $directory]);
        app('cache')->forgetDriver('file');
        try {
            $versions = Mockery::mock(FinanceSummaryDataVersion::class);
            $versions->shouldReceive('forRange')->andReturn('unchanged');
            $cache = new FinanceSummarySourceCache($versions);
            $filters = ['from' => '2026-11-01', 'to' => '2026-11-07', 'timezone' => 'Asia/Shanghai', 'logistics_costs' => true];
            foreach ([['xuzong1', 'xuzong2'], ['ye', 'worldpay']] as $platforms) {
                iterator_to_array($cache->stream('credit_card', [...$filters, 'summary_payment_channels' => $platforms], false,
                    function () use ($platforms) {
                        foreach ($platforms as $platform) yield ['id' => $platform, 'payment_channel' => $platform, 'team' => 'A'];
                    }));
            }
            $this->assertTrue($cache->ready('credit_card', $filters));
            $rows = iterator_to_array($cache->stream('credit_card', $filters, false,
                fn () => $this->fail('The total must reuse channel calculations.')), false);
            $this->assertSame(['xuzong1', 'xuzong2', 'ye', 'worldpay'], array_column($rows, 'id'));
            $this->assertFalse($cache->ready('credit_card', [...$filters, 'summary_native_settlement' => true]));
            $this->assertFalse($cache->ready('credit_card', [...$filters, 'timezone' => '-04:00']));
        } finally {
            File::deleteDirectory($directory);
            config(['cache.stores.file.path' => $original]);
            app('cache')->forgetDriver('file');
        }
    }

    public function test_settlement_and_transaction_views_share_one_complete_build_in_both_directions(): void
    {
        $original = config('cache.stores.file.path');
        $directory = storage_path('framework/testing/shared-views-'.bin2hex(random_bytes(6)));
        config(['cache.stores.file.path' => $directory]);
        app('cache')->forgetDriver('file');
        try {
            $versions = Mockery::mock(FinanceSummaryDataVersion::class);
            $versions->shouldReceive('forRange')->andReturn('unchanged');
            $cache = new FinanceSummarySourceCache($versions);
            foreach ([false, true] as $nativeFirst) {
                $count = 0;
                $filters = ['from' => $nativeFirst ? '2026-10-01' : '2026-11-01', 'to' => $nativeFirst ? '2026-10-07' : '2026-11-07',
                    'summary_shared_source' => true, 'summary_payment_channels' => ['ye']];
                $build = function ($canonical) use (&$count) {
                    $count++;
                    $this->assertTrue($canonical['logistics_costs']);
                    $this->assertArrayNotHasKey('summary_settlement_only', $canonical);
                    $this->assertArrayNotHasKey('summary_account_counts', $canonical);
                    $this->assertArrayNotHasKey('summary_native_settlement', $canonical);
                    yield ['id' => 1, 'payment_channel' => 'ye', 'team' => 'A', 'settlement' => '30', 'fee' => '3', 'logistics' => '100',
                        '_summary_shared_version' => 1, '_summary_native_currency' => 'EUR', '_summary_native_values' => ['settlement' => '20', 'fee' => '2']];
                };
                $settlement = [...$filters, 'summary_settlement_only' => true, 'summary_account_counts' => true, 'logistics_costs' => false];
                $transaction = [...$filters, 'summary_native_settlement' => true, 'logistics_costs' => true];
                $first = $nativeFirst ? $transaction : $settlement;
                $second = $nativeFirst ? $settlement : $transaction;
                $rows = iterator_to_array($cache->stream('credit_card', $first, $nativeFirst, $build), false);
                $this->assertSame($nativeFirst ? '20' : '30', $rows[0]['settlement']);
                $this->assertTrue($cache->ready('credit_card', $second));
                $rows = iterator_to_array($cache->stream('credit_card', $second, ! $nativeFirst, $build), false);
                $this->assertSame($nativeFirst ? '30' : '20', $rows[0]['settlement']);
                $this->assertSame('100', $rows[0]['logistics']);
                $this->assertSame(1, $count);
            }
        } finally {
            File::deleteDirectory($directory);
            config(['cache.stores.file.path' => $original]);
            app('cache')->forgetDriver('file');
        }
    }
}
