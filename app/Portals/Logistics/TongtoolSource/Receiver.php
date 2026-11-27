<?php

namespace App\Portals\Logistics\TongtoolSource;

use App\Portals\Logistics\SkuDatabaseLookupService;

final class Receiver
{
    public function __construct()
    {
        require_once __DIR__.'/storage.php';
    }

    public function lookup(array $skus): array
    {
        if (!is_file(privateDir().'/sku-database.json')) {
            return ['status' => 'unavailable', 'items' => []];
        }

        return databaseLookup($skus);
    }

    public function enqueue(array $skus): int
    {
        $this->ensureStorage();

        return queueAdminSkus($skus);
    }

    public function handle(string $action, array $body): array
    {
        if ($action === 'admin_status') {
            return $this->status();
        }

        $this->ensureStorage();
        $dryRun = ($body['dryRun'] ?? false) === true;
        $maximumReadAge = ($body['deferred'] ?? false) === true ? SKU_DATABASE_MAX_AGE : 600;
        $rows = [];
        $aliases = [];
        if ($action === 'admin_import') {
            $rows = $this->rows($body, 'rows');
            $rejected = $this->rows($body + ['rejected' => []], 'rejected');
            $moves = $body['reassignments'] ?? [];
            if (!is_array($moves)) {
                fail('SKU 归属更新格式无效', 400, 'invalid_import');
            }
            $result = importAdminRows($rows, $rejected, $dryRun, $moves, $maximumReadAge);
            $rows = array_merge($rows, $rejected);
            if (!$dryRun) {
                $database = readJson(privateDir().'/sku-database.json');
                foreach (array_unique(array_column($rows, 'productId')) as $productId) {
                    foreach ($database['aliasProducts'][$productId]['items'] ?? [] as $entry) {
                        $aliases = array_merge($aliases, $entry['aliases']);
                    }
                }
            }
        } elseif ($action === 'admin_alias_import') {
            $snapshots = $this->rows($body, 'snapshots');
            $result = importAliasSnapshots($snapshots, $dryRun, $maximumReadAge);
            foreach ($snapshots as $snapshot) {
                foreach ($snapshot['items'] as $entry) {
                    $aliases = array_merge($aliases, $entry['aliases']);
                }
            }
        } elseif ($action === 'admin_pcl_import') {
            $result = importProductPcls($this->rows($body, 'products'), $dryRun);
        } elseif ($action === 'admin_conflict') {
            $result = quarantineAdminSkus($this->rows($body, 'skus'), $dryRun);
        } elseif ($action === 'admin_defer') {
            $sku = $body['sku'] ?? null;
            if (!validQueueSku($sku)) {
                fail('SKU 格式无效', 400, 'invalid_import');
            }
            if (!$dryRun) {
                deferAdminSku($sku);
            }
            return ['status' => 'complete'];
        } else {
            fail('接口不存在', 404, 'unknown_action');
        }

        $skus = array_merge(array_column($rows, 'sku'), $aliases);
        if (!$dryRun && $skus !== []) {
            // Acknowledgement is returned only after the ERP can apply this batch.
            // Retrying the same source read is safe if the projection fails.
            $result['erpMatched'] = app(SkuDatabaseLookupService::class)->projectLocal($skus);
        }

        return $result;
    }

    private function ensureStorage(): void
    {
        $dir = privateDir();
        if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
            fail('无法初始化私有 SKU 存储', 503, 'private_storage_failed');
        }
    }

    private function rows(array $body, string $key): array
    {
        $rows = $body[$key] ?? null;
        if (!is_array($rows) || !array_is_list($rows)) {
            fail('上传格式错误', 400, 'invalid_import');
        }
        return $rows;
    }

    private function status(): array
    {
        $db = readJson(privateDir().'/sku-database.json');
        $items = $db['items'] ?? [];
        $aliases = aliasIndex($db);
        $complete = count(array_filter($items, static fn (array $row): bool => empty($row['invalid'])
            && ($row['guideCost'] ?? 0) > 0 && ($row['weightGrams'] ?? 0) > 0
            && strtotime($row['sourceReadAt'] ?? '') >= time() - SKU_DATABASE_MAX_AGE));
        $pending = array_filter(readJson(privateDir().'/admin-pending.json'), static fn (array $entry): bool =>
            ($entry['retryAfter'] ?? 0) <= time() && ($entry['addedAt'] ?? 0) > time() - SKU_DATABASE_MAX_AGE);
        usort($pending, static fn (array $a, array $b): int => $a['addedAt'] <=> $b['addedAt']);

        return [
            'status' => 'ready',
            'version' => SERVICE_VERSION,
            'pendingSkus' => array_slice(array_column($pending, 'sku'), 0, 5),
            'pendingCount' => count($pending),
            'databaseStats' => [
                'total' => count($items), 'complete' => $complete, 'missing' => count($items) - $complete,
                'updatedAt' => $db['updatedAt'] ?? null, 'checkedAt' => gmdate('c'),
                'aliasProducts' => count($db['aliasProducts'] ?? []), 'aliases' => count($aliases),
                'aliasConflicts' => count(array_filter($aliases, static fn (array $matches): bool => count($matches) > 1)),
            ],
        ];
    }
}
