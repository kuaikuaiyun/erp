<?php

namespace Tests\Feature;

use App\Portals\Finance\FinanceSummaryDataVersion;
use App\Portals\Finance\FinanceSummarySourceCache;
use Illuminate\Support\Facades\File;
use Mockery;
use Tests\TestCase;

class FinanceSummarySourceCacheTest extends TestCase
{
    public function test_cold_source_defers_offset_indexes_and_builds_only_requested_filter_fields(): void
    {
        $originalPath = config('cache.stores.file.path');
        $cachePath = storage_path('framework/testing/source-cache-'.bin2hex(random_bytes(6)));
        config()->set('cache.stores.file.path', $cachePath);
        app('cache')->forgetDriver('file');

        try {
            $versions = Mockery::mock(FinanceSummaryDataVersion::class);
            $versions->shouldReceive('current')->with('paypal')->andReturn('test-version');
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
}
