<?php

namespace App\Portals\Finance;

use Illuminate\Support\Facades\Cache;

/** Keep one published value per query, replacing it only when tracked inputs change. */
class FinanceReportCache
{
    public function __construct(
        private readonly FinanceWorkbookService $workbooks,
        private readonly FinanceSummaryDataVersion $versions,
    ) {}

    private function store(): \Illuminate\Contracts\Cache\Repository
    {
        $store = config('cache.default');
        return Cache::store(config("cache.stores.{$store}.driver") === 'database' ? 'file' : $store);
    }

    private function key(string $scope): string
    {
        $db = $this->workbooks->database();
        return 'finance:report-inputs:v1:'.hash('sha256', $db->getName().':'.$db->getDatabaseName().':'.$scope);
    }

    public function get(string $scope, array $channels): mixed
    {
        $version = $this->versions->forCache($channels);
        if ($version === null) return null;
        $cached = $this->store()->get($this->key($scope));
        return is_array($cached) && ($cached['version'] ?? null) === $version ? $cached['value'] : null;
    }

    public function remember(string $scope, array $channels, callable $build): mixed
    {
        $version = $this->versions->forCache($channels);
        if ($version === null) return $build();
        $key = $this->key($scope);
        $cached = $this->store()->get($key);
        if (is_array($cached) && ($cached['version'] ?? null) === $version) return $cached['value'];
        $value = $build();
        // Never publish a calculation that raced a source edit.
        if ($version === $this->versions->forCache($channels)) {
            $this->store()->forever($key, ['version' => $version, 'value' => $value]);
        }
        return $value;
    }

    public function forget(string $scope): void
    {
        $this->store()->forget($this->key($scope));
    }
}
