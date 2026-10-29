<?php

namespace App\Jobs;

use App\Portals\Logistics\TongtuSkuMappingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class RefreshTongtuSkuMappingsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $timeout = 900;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [60, 300];

    /** @param list<int> $mappingIds */
    public function __construct(public readonly array $mappingIds)
    {
    }

    public function handle(TongtuSkuMappingService $mappings): void
    {
        $mappings->refreshMappings($this->mappingIds, true);
    }

    public function failed(Throwable $exception): void
    {
        app(TongtuSkuMappingService::class)->markTechnicalFailure(
            $this->mappingIds,
            mb_substr($exception->getMessage(), 0, 1000),
        );
    }
}
