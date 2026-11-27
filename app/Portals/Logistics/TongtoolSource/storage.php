<?php

declare(strict_types=1);

namespace App\Portals\Logistics\TongtoolSource;

use JsonException;
use RuntimeException;
use stdClass;

// Matching and storage functions from the Tongtool SKU handover service v2.9.6.
// The Laravel controller supplies authorization and exposes only the collector API.
const SERVICE_VERSION = '2.9.6';
const SKU_DATABASE_MAX_AGE = 604800;

function privateDir(): string
{
    return config('logistics.tongtool_receiver.path') ?: storage_path('app/private/tongtool-sku');
}

function respond(int $status, array $body): never
{
    throw new SourceResponse($status, $body);
}

function fail(string $message, int $status = 503, string $code = 'service_error'): never
{
    respond($status, ['status' => 'unavailable', 'message' => $message, 'errorCode' => $code, 'items' => new stdClass()]);
}
function readJson(string $file): array {
    $data = is_file($file) ? json_decode((string)file_get_contents($file), true) : [];
    return is_array($data) ? $data : [];
}
function writeAllBytes($handle, string $bytes): void {
    $offset = 0; $length = strlen($bytes);
    while ($offset < $length) {
        $written = fwrite($handle, $offset ? substr($bytes, $offset) : $bytes);
        if ($written === false || $written === 0) throw new RuntimeException('private_storage_failed');
        $offset += $written;
    }
}
function streamJsonValue(mixed $value, callable $emit, int $depth = 0): void {
    if ($depth > 512) throw new JsonException('Maximum stack depth exceeded');
    if (!is_array($value)) {
        $emit(json_encode($value, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)); return;
    }
    $list = array_is_list($value); $emit($list ? '[' : '{'); $first = true;
    foreach ($value as $key => $entry) {
        if (!$first) $emit(','); $first = false;
        if (!$list) $emit(json_encode((string)$key, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . ':');
        streamJsonValue($entry, $emit, $depth + 1);
    }
    $emit($list ? ']' : '}');
}
function writeJson(string $file, array $data): void {
    // Encode bounded chunks instead of allocating a second full database string.
    // The destination remains untouched until the complete new file is durable.
    $temporary = $file . '.' . bin2hex(random_bytes(8)) . '.new';
    $handle = fopen($temporary, 'xb');
    if ($handle === false) throw new RuntimeException('private_storage_failed');
    try {
        if (!chmod($temporary, 0600)) throw new RuntimeException('private_storage_failed');
        $buffer = '';
        $emit = static function(string $bytes) use ($handle, &$buffer): void {
            $buffer .= $bytes;
            if (strlen($buffer) >= 65536) {writeAllBytes($handle, $buffer); $buffer = '';}
        };
        streamJsonValue($data, $emit);
        if ($buffer !== '') writeAllBytes($handle, $buffer);
        if (!fflush($handle) || (function_exists('fsync') && !fsync($handle))) throw new RuntimeException('private_storage_failed');
        fclose($handle); $handle = null;
        if (!rename($temporary, $file)) throw new RuntimeException('private_storage_failed');
    } finally {
        if (is_resource($handle)) fclose($handle);
        if (is_file($temporary)) unlink($temporary);
    }
}
function backupJsonFile(string $source, string $destination): void {
    // Called under the existing database lock. Copy original bytes without a
    // second json_decode() tree or json_encode() string in the PHP worker heap.
    $temporary = $destination . '.' . bin2hex(random_bytes(8)) . '.new';
    try {
        if (!copy($source, $temporary) || !chmod($temporary, 0600) || !rename($temporary, $destination))
            throw new RuntimeException('private_storage_failed');
    } finally {if (is_file($temporary)) unlink($temporary);}
}
function canonicalDatabaseItem(array $database, string $sku, ?int $now = null): ?array {
    if (($database['schemaVersion'] ?? null) !== 1) return null;
    $row = $database['items'][hash('sha256', $sku)] ?? null;
    if (!is_array($row) || ($row['sku'] ?? null) !== $sku || ($row['guideField'] ?? null) !== 'goodsGuideCost') return null;
    $metadata = $database['productPcls'][$row['productId'] ?? ''] ?? [];
    $pcl = $metadata['pcl'] ?? null; $imageUrl = $metadata['imageUrl'] ?? null; $productName = $metadata['productName'] ?? null;
    if (($row['invalid'] ?? false) === true) {
        $timestamp = strtotime($row['sourceReadAt'] ?? '');
        $now ??= time();
        $reason = $row['invalidReason'] ?? '';
        $fresh = $timestamp !== false && $timestamp <= $now + 300 && $now - $timestamp <= SKU_DATABASE_MAX_AGE;
        return ['status' => 'confirmed', 'imageUrl' => $imageUrl, 'productName' => $productName, 'pcl' => $pcl,
            'sku' => $sku, 'productId' => $row['productId'] ?? null, 'productType' => $row['productType'] ?? null,
            'weightGrams' => $fresh && $reason === 'missing_guide' ? ($row['partialWeightGrams'] ?? null) : null,
            'guideCost' => $fresh && $reason === 'missing_weight' ? ($row['partialGuideCost'] ?? null) : null,
            'currentCost' => null, 'averageCost' => null, 'dataSource' => 'sku_database',
            'readAt' => $row['sourceReadAt'] ?? null, 'sourceReadAt' => $row['sourceReadAt'] ?? null,
            'source' => '通途商品资料 · 私有SKU库', 'guideField' => 'goodsGuideCost', 'missingReason' => $reason];
    }
    $guide = $row['guideCost'] ?? null; $weight = $row['weightGrams'] ?? null;
    if (!is_int($guide) && !is_float($guide)) return null;
    if ((!is_int($weight) && !is_float($weight)) || !is_finite((float)$weight) || $weight <= 0
        || !is_finite((float)$guide) || $guide < 0) return null;
    $timestamp = is_string($row['sourceReadAt'] ?? null) ? strtotime($row['sourceReadAt']) : false;
    $now ??= time();
    if ($timestamp === false || $timestamp > $now + 300) return null;
    $stale = $now - $timestamp > SKU_DATABASE_MAX_AGE;
    return ['status' => 'confirmed', 'imageUrl' => $imageUrl, 'productName' => $productName, 'pcl' => $pcl, 'sku' => $sku,
        'productId' => $row['productId'] ?? null, 'productType' => $row['productType'] ?? null, 'weightGrams' => $stale ? null : (float)$weight,
        'guideCost' => $stale ? null : (float)$guide, 'currentCost' => null, 'averageCost' => null,
        'readAt' => gmdate('c', $timestamp), 'sourceReadAt' => gmdate('c', $timestamp),
        'source' => '通途商品资料 · 私有SKU库', 'dataSource' => 'sku_database', 'guideField' => 'goodsGuideCost', 'stale' => $stale,
        'maxAgeSeconds' => SKU_DATABASE_MAX_AGE, 'message' => $stale ? 'SKU库资料超过7天，需更新后再核算' : '来自已核实的SKU级指导成本'];
}
// Only explicit ERP SKU aliases; never fuzzy names, suffix stripping or chains.
function aliasIndex(array $database): array {
    $index = [];
    foreach (($database['aliasProducts'] ?? []) as $productId => $snapshot) {
        if (!is_array($snapshot) || ($snapshot['complete'] ?? false) !== true || !is_array($snapshot['items'] ?? null)) continue;
        foreach ($snapshot['items'] as $entry) {
            foreach (($entry['aliases'] ?? []) as $alias) {
                if (!is_string($alias)) continue;
                $key = hash('sha256', $alias); $identity = (string)$productId . ':' . ($entry['sku'] ?? '');
                $index[$key][$identity] = ['alias' => $alias, 'canonicalSku' => $entry['sku'] ?? '',
                    'productId' => (string)$productId, 'sourceReadAt' => $snapshot['sourceReadAt'] ?? null];
            }
        }
    }
    return $index;
}
function exactDatabaseItem(array $database, string $sku, ?int $now = null, ?array $index = null, bool $aliasOnly = false): ?array {
    // Ordinary lookup keeps formal precedence. Only mergedAliasItem can opt
    // into alias-only lookup after verifying the formal SKU was retired.
    if (!$aliasOnly && isset($database['items'][hash('sha256', $sku)])) return canonicalDatabaseItem($database, $sku, $now);
    $index ??= aliasIndex($database); $matches = array_values($index[hash('sha256', $sku)] ?? []);
    $matches = array_values(array_filter($matches, fn($r) => $r['alias'] === $sku));
    if (!$matches) return null;
    $now ??= time(); $sourceReadAt = $matches[0]['sourceReadAt'];
    // Invalidate a cached alias with the newest REAL source timestamp involved;
    // never let an older conflicting candidate make legacy clients retain cost.
    $evidenceTimes = [];
    foreach ($matches as $candidate) {
        $at = strtotime($candidate['sourceReadAt'] ?? ''); if ($at !== false) $evidenceTimes[] = $at;
        $at = strtotime($database['items'][hash('sha256', $candidate['canonicalSku'])]['sourceReadAt'] ?? ''); if ($at !== false) $evidenceTimes[] = $at;
    }
    $blockedAt = $evidenceTimes ? gmdate('c', max($evidenceTimes)) : $sourceReadAt;
    $blocked = function(string $reason, string $message) use ($sku, $sourceReadAt, $blockedAt): array {
        // Existing operator versions invalidate old cost on confirmed/null rows.
        return ['status' => 'confirmed', 'imageUrl' => null, 'productName' => null, 'pcl' => null, 'sku' => $sku, 'weightGrams' => null, 'guideCost' => null,
            'currentCost' => null, 'averageCost' => null, 'dataSource' => 'sku_database', 'guideField' => 'goodsGuideCost',
            'sourceReadAt' => $blockedAt, 'readAt' => $blockedAt, 'aliasReadAt' => $sourceReadAt, 'matchBasis' => 'sku_alias',
            'aliasStatus' => $reason, 'message' => $message];
    };
    if (count($matches) !== 1) return $blocked('conflict', 'SKU别名对应多个正式SKU，已停止核算，请管理员核对');
    $match = $matches[0]; $at = is_string($sourceReadAt) ? strtotime($sourceReadAt) : false;
    if ($at === false || $at > $now + 300 || $now - $at > SKU_DATABASE_MAX_AGE)
        return $blocked('stale', 'SKU别名关系过期或日期无效，需管理员重新同步');
    $owner = $database['items'][hash('sha256', $match['canonicalSku'])] ?? null;
    if (!is_array($owner) || ($owner['productId'] ?? null) !== $match['productId'])
        return $blocked('owner_changed', 'SKU别名对应的正式SKU归属变化或缺失，需重新核对');
    $item = canonicalDatabaseItem($database, $match['canonicalSku'], $now);
    if ($item === null) return $blocked('canonical_invalid', 'SKU别名对应的正式SKU资料无效，暂不核算');
    return array_merge($item, ['sku' => $sku, 'canonicalSku' => $match['canonicalSku'], 'matchBasis' => 'sku_alias',
        'aliasReadAt' => $sourceReadAt, 'aliasStatus' => 'matched', 'source' => '通途SKU别名 → 正式SKU · 私有SKU库']);
}
// Ignore English letter case only. Preserve spaces, punctuation, pack suffixes,
// Chinese characters and the original stored SKU; never rewrite database keys.
function skuCaseKey(string $sku): string { return hash('sha256', strtolower($sku)); }
function skuLookupIndex(array $database, ?array $aliases = null): array {
    $index = ['formal' => [], 'aliases' => [], 'confirmedMerges' => readJson(privateDir() . '/confirmed-merged-aliases.json')];
    foreach (($database['items'] ?? []) as $key => $row) {
        $sku = $row['sku'] ?? null;
        if (is_string($sku) && $key === hash('sha256', $sku)) $index['formal'][skuCaseKey($sku)][$key] = $row;
    }
    foreach (($aliases ?? aliasIndex($database)) as $matches) foreach ($matches as $identity => $match) {
        // Different spellings of the same alias/owner are one relationship.
        $index['aliases'][skuCaseKey($match['alias'])][$identity] = $match;
    }
    return $index;
}
function blockedSkuCaseMatch(array $database, string $sku, array $candidates, string $reason, string $message): array {
    $times = [];
    foreach ($candidates as $candidate) {
        $at = strtotime($candidate['sourceReadAt'] ?? ''); if ($at !== false) $times[] = $at;
        if (isset($candidate['canonicalSku'])) {
            $at = strtotime($database['items'][hash('sha256', $candidate['canonicalSku'])]['sourceReadAt'] ?? '');
            if ($at !== false) $times[] = $at;
        }
    }
    $at = $times ? gmdate('c', max($times)) : null;
    return ['status' => 'confirmed', 'imageUrl' => null, 'productName' => null, 'pcl' => null, 'sku' => $sku, 'weightGrams' => null, 'guideCost' => null,
        'currentCost' => null, 'averageCost' => null, 'dataSource' => 'sku_database', 'guideField' => 'goodsGuideCost',
        'sourceReadAt' => $at, 'readAt' => $at, 'matchBasis' => 'sku_case_insensitive',
        'caseMatchStatus' => $reason, 'message' => $message];
}
function databaseItem(array $database, string $sku, ?int $now = null, ?array $aliases = null, ?array $index = null): ?array {
    $aliases ??= aliasIndex($database); $index ??= skuLookupIndex($database, $aliases); $key = skuCaseKey($sku);
    // A current formal SKU wins over aliases in any casing, but ambiguous formal
    // spellings are blocked even when one spelling is an exact string match.
    $formal = array_values($index['formal'][$key] ?? []);
    if (count($formal) > 1) return blockedSkuCaseMatch($database, $sku, $formal, 'conflict', '忽略大小写后对应多个正式SKU，已停止核算，请管理员核对');
    if ($formal) {
        // A retired formal row must not shadow a verified current alias after
        // an ERP merge. Both positive and negative complete snapshots are
        // required; an expired cost alone never authorizes reassignment.
        $merged = mergedAliasItem($database, $sku, $formal[0], $now, $aliases, $index);
        if ($merged !== null) return $merged;
        $canonical = $formal[0]['sku']; $item = canonicalDatabaseItem($database, $canonical, $now);
        if ($canonical === $sku) return $item;
        if ($item === null) return blockedSkuCaseMatch($database, $sku, $formal, 'canonical_invalid', '大小写匹配的正式SKU资料无效，暂不核算');
        return array_merge($item, ['sku' => $sku, 'canonicalSku' => $canonical,
            'matchBasis' => 'sku_case_insensitive', 'caseInsensitiveMatch' => true]);
    }
    $matches = array_values($index['aliases'][$key] ?? []);
    if (!$matches) return null;
    if (count($matches) > 1) return blockedSkuCaseMatch($database, $sku, $matches, 'conflict', '忽略大小写后SKU别名对应多个正式SKU，已停止核算，请管理员核对') + ['aliasStatus' => 'conflict'];
    $match = $matches[0];
    $owners = array_values($index['formal'][skuCaseKey($match['canonicalSku'])] ?? []);
    if (count($owners) > 1) return blockedSkuCaseMatch($database, $sku, $owners, 'conflict', 'SKU别名对应的正式SKU存在大小写归属冲突，已停止核算') + ['aliasStatus' => 'conflict'];
    $item = exactDatabaseItem($database, $match['alias'], $now, $aliases);
    if ($item === null) return blockedSkuCaseMatch($database, $sku, $matches, 'canonical_invalid', 'SKU别名对应的正式SKU资料无效，暂不核算');
    return array_merge($item, ['sku' => $sku, 'matchedSku' => $match['alias'], 'caseInsensitiveMatch' => $match['alias'] !== $sku]);
}
function mergedAliasItem(array $database, string $sku, array $former, ?int $now, array $aliases, array $index): ?array {
    $now ??= time(); $key = skuCaseKey($sku);
    $oldProduct = (string)($former['productId'] ?? '');
    $snapshot = $database['aliasProducts'][$oldProduct] ?? null;
    $oldAt = strtotime($former['sourceReadAt'] ?? ''); $checkedAt = is_array($snapshot) ? strtotime($snapshot['sourceReadAt'] ?? '') : false;
    if ($oldAt === false || $oldAt > $now + 300) return null;
    $retired = is_array($snapshot) && ($snapshot['productId'] ?? null) === $oldProduct && ($snapshot['complete'] ?? false) === true && is_array($snapshot['items'] ?? null)
        && $checkedAt !== false && $checkedAt <= $now + 300 && $now - $checkedAt <= SKU_DATABASE_MAX_AGE && $checkedAt >= $oldAt;
    if ($retired) foreach ($snapshot['items'] as $entry) {
        if (!is_array($entry) || !is_string($entry['sku'] ?? null) || skuCaseKey($entry['sku']) === $key) { $retired = false; break; }
    }
    // Operator-approved merges are CAS-bound to the exact former record and
    // verified target. A later old-SKU import/reactivation cancels approval.
    $approval = $index['confirmedMerges'][$key] ?? null; $approved = false;
    if (is_array($approval) && ($approval['source'] ?? '') === 'user_confirmed_erp_alias' && ($approval['formerProductId'] ?? null) === $oldProduct
        && ($approval['formerSku'] ?? null) === ($former['sku'] ?? null) && ($approval['formerSourceReadAt'] ?? null) === ($former['sourceReadAt'] ?? null)
        && is_string($approval['alias'] ?? null) && skuCaseKey($approval['alias']) === $key) {
        $confirmedAt = strtotime($approval['confirmedAt'] ?? '');
        $approved = $confirmedAt !== false && $confirmedAt >= $oldAt && $confirmedAt <= $now + 300;
    }
    if (!$retired && !$approved) return null;
    $matches = array_values($index['aliases'][$key] ?? []);
    if (!$matches) return null;
    if (count($matches) > 1) return blockedSkuCaseMatch($database, $sku, $matches, 'conflict', '旧SKU已退役但别名对应多个正式SKU，已停止核算') + ['aliasStatus' => 'conflict'];
    $match = $matches[0]; $aliasAt = strtotime($match['sourceReadAt'] ?? '');
    if ($aliasAt === false || $aliasAt > $now + 300 || $now - $aliasAt > SKU_DATABASE_MAX_AGE || $aliasAt < $oldAt) return null;
    $owners = array_values($index['formal'][skuCaseKey($match['canonicalSku'])] ?? []);
    if (count($owners) !== 1 || skuCaseKey($match['canonicalSku']) === $key) return null;
    if (!$retired && (($approval['canonicalSku'] ?? null) !== $match['canonicalSku'] || ($approval['productId'] ?? null) !== $match['productId'])) return null;
    $item = exactDatabaseItem($database, $match['alias'], $now, $aliases, true);
    if ($item === null) return null;
    return array_merge($item, ['sku' => $sku, 'matchedSku' => $match['alias'], 'caseInsensitiveMatch' => $match['alias'] !== $sku,
        'mergeResolved' => true, 'previousFormalSku' => $former['sku'], 'previousProductId' => $oldProduct,
        'retiredFormalEvidenceAt' => $retired ? $snapshot['sourceReadAt'] : $approval['confirmedAt'],
        'mergeEvidence' => $retired ? 'complete_product_snapshot' : 'user_confirmed_erp_alias']);
}
function importAliasSnapshots(array $snapshots, bool $dryRun = false, int $maximumReadAge = 600): array {
    if (count($snapshots) < 1 || count($snapshots) > 100) fail('别名快照数量无效', 400, 'invalid_alias_import');
    $incoming = []; $now = time();
    foreach ($snapshots as $snapshot) {
        $productId = $snapshot['productId'] ?? null; $at = is_string($snapshot['sourceReadAt'] ?? null) ? strtotime($snapshot['sourceReadAt']) : false;
        if (!is_string($productId) || !preg_match('/^\d{10,40}$/D', $productId) || isset($incoming[$productId])
            || ($snapshot['complete'] ?? false) !== true || !is_array($snapshot['items'] ?? null) || count($snapshot['items']) > 5000
            || $at === false || $at > $now + 300 || $at < $now - $maximumReadAge) fail('别名快照未完整核验或已过期', 400, 'invalid_alias_import');
        $entries = []; $aliasCount = 0;
        foreach ($snapshot['items'] as $entry) {
            $sku = $entry['sku'] ?? null; $aliases = $entry['aliases'] ?? null;
            if (!is_string($sku) || $sku !== trim($sku) || $sku === '' || strlen($sku) > 160 || preg_match('/[\x00-\x1f\x7f]/', $sku)
                || !is_array($aliases) || count($aliases) > 200 || isset($entries[hash('sha256', $sku)])) fail('别名所属SKU无效', 400, 'invalid_alias_import');
            $clean = [];
            foreach ($aliases as $alias) {
                if (!is_string($alias) || $alias !== trim($alias) || $alias === '' || strlen($alias) > 160 || preg_match('/[\x00-\x1f\x7f]/', $alias))
                    fail('SKU别名格式无效', 400, 'invalid_alias_import');
                if ($alias !== $sku) $clean[hash('sha256', $alias)] = $alias;
            }
            $aliasCount += count($clean); if ($aliasCount > 10000) fail('单商品别名数量过多', 413, 'invalid_alias_import');
            $entries[hash('sha256', $sku)] = ['sku' => $sku, 'aliases' => array_values($clean)];
        }
        $incoming[$productId] = ['productId' => $productId, 'sourceReadAt' => gmdate('c', $at), 'complete' => true, 'items' => array_values($entries)];
    }
    $lock = fopen(privateDir() . '/database-write.lock', 'c');
    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) fail('数据库正在更新，请稍后重试', 409, 'database_busy');
    try {
        $file = privateDir() . '/sku-database.json'; $db = readJson($file);
        if (($db['schemaVersion'] ?? null) !== 1 || !is_array($db['items'] ?? null)) fail('原数据库无效，未改别名', 503, 'database_invalid');
        $products = $db['aliasProducts'] ?? [];
        foreach ($incoming as $productId => $snapshot) {
            $previous = $products[$productId] ?? null;
            if (is_array($previous) && strtotime($previous['sourceReadAt'] ?? '') > strtotime($snapshot['sourceReadAt']))
                fail('不能用旧别名覆盖新关系', 409, 'older_alias_import');
            foreach ($snapshot['items'] as $entry) {
                $owner = $db['items'][hash('sha256', $entry['sku'])] ?? null;
                if (!is_array($owner) || ($owner['sku'] ?? null) !== $entry['sku'] || ($owner['productId'] ?? null) !== (string)$productId)
                    fail('正式SKU归属尚未确认，未写别名', 409, 'alias_owner_unconfirmed');
                if (strtotime($owner['sourceReadAt'] ?? '') < strtotime($snapshot['sourceReadAt']))
                    fail('正式SKU尚未完成本次资料同步，未写别名', 409, 'alias_owner_stale');
            }
            // Replacement, not append: removed/renamed/deleted aliases disappear.
            $products[$productId] = $snapshot;
        }
        $db['aliasProducts'] = $products; $index = aliasIndex($db); $conflicts = count(array_filter($index, fn($m) => count($m) > 1));
        if ($dryRun) return ['status' => 'preview', 'products' => count($incoming), 'aliases' => count($index), 'conflicts' => $conflicts];
        backupJsonFile($file, privateDir() . '/sku-database-before-alias.json');
        $db['updatedAt'] = gmdate('c'); writeJson($file, $db);
        $queue = readJson(privateDir() . '/admin-pending.json');
        foreach ($index as $key => $matches) if (count($matches) === 1) unset($queue[$key]);
        writeJson(privateDir() . '/admin-pending.json', $queue);
        return ['status' => 'complete', 'products' => count($incoming), 'aliases' => count($index), 'conflicts' => $conflicts, 'updatedAt' => $db['updatedAt']];
    } finally {flock($lock, LOCK_UN); fclose($lock);}
}
function validatedProductPcl(array $entry, int $maximumReadAge = 600): array {
    $id = $entry['productId'] ?? null; $pcl = $entry['pcl'] ?? null;
    $at = is_string($entry['sourceReadAt'] ?? null) ? strtotime($entry['sourceReadAt']) : false;
    if (!is_string($id) || !preg_match('/^\d{10,40}$/D', $id) || !is_string($pcl) || $pcl === '' || $pcl !== trim($pcl)
        || strlen($pcl) > 160 || preg_match('/[\x00-\x1f\x7f]/', $pcl) || $at === false || $at > time() + 300 || $at < time() - $maximumReadAge)
        fail('PCL所属商品或读取时间无效', 400, 'invalid_pcl_import');
    $result = ['productId' => $id, 'pcl' => $pcl, 'sourceReadAt' => gmdate('c', $at)];
    if (array_key_exists('productName', $entry)) {
        $name = $entry['productName'];
        if ($name !== null && (!is_string($name) || strlen($name) > 1500 || $name !== trim($name) || preg_match('/[\x00-\x1f\x7f]/', $name)))
            fail('商品名称格式无效', 400, 'invalid_product_metadata');
        $result['productName'] = $name;
    }
    if (array_key_exists('imageUrl', $entry)) {
        $url = $entry['imageUrl'];
        if ($url !== null && (!is_string($url) || strlen($url) > 1000 || !preg_match('~^https://erp115\.tongtool\.com/file/111799/product/[0-9]{10,40}/[a-zA-Z0-9_.-]+\.(?:png|jpg|jpeg|webp|gif)$~iD', $url)))
            fail('商品图片来源未核实', 400, 'invalid_product_metadata');
        $result['imageUrl'] = $url;
    }
    return $result;
}
function mergeProductPcls(array $existing, array $incoming): array {
    foreach ($incoming as $entry) {
        $id = $entry['productId']; $old = $existing[$id] ?? null;
        if (is_array($old) && strtotime($old['sourceReadAt'] ?? '') > strtotime($entry['sourceReadAt'])) continue;
        if (is_array($old) && ($old['sourceReadAt'] ?? null) === $entry['sourceReadAt'] && ($old['pcl'] ?? null) !== $entry['pcl'])
            fail('同一读取时间的PCL不一致，未写入', 409, 'pcl_conflict');
        // Older clients omit media fields: absence must not erase known data.
        // Explicit null in a fresh complete source snapshot clears removed media.
        $existing[$id] = array_merge(is_array($old) ? $old : [], $entry);
    }
    return $existing;
}
// Metadata-only backfill. It never changes costs, SKU ownership, aliases or
// their source timestamps, and uses the same lock as ordinary synchronization.
function importProductPcls(array $products, bool $dryRun = false): array {
    if (count($products) < 1 || count($products) > 2000) fail('PCL批量数量无效', 400, 'invalid_pcl_import');
    $incoming = [];
    foreach ($products as $entry) {
        if (!is_array($entry)) fail('PCL资料格式无效', 400, 'invalid_pcl_import');
        $p = validatedProductPcl($entry); if (isset($incoming[$p['productId']])) fail('PCL商品重复', 400, 'invalid_pcl_import');
        $incoming[$p['productId']] = $p;
    }
    $lock = fopen(privateDir() . '/database-write.lock', 'c');
    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) fail('数据库正在更新', 409, 'database_busy');
    try {
        $file = privateDir() . '/sku-database.json'; $db = readJson($file);
        if (($db['schemaVersion'] ?? null) !== 1 || !is_array($db['items'] ?? null)) fail('原数据库无效', 503, 'database_invalid');
        $db['productPcls'] = mergeProductPcls($db['productPcls'] ?? [], array_values($incoming));
        if (!$dryRun) {backupJsonFile($file, privateDir() . '/sku-database-before-pcl.json');$db['updatedAt'] = gmdate('c');writeJson($file, $db);}
        return ['status' => $dryRun ? 'preview' : 'complete', 'products' => count($incoming), 'knownProducts' => count($db['productPcls']), 'costRowsChanged' => 0];
    } finally {flock($lock, LOCK_UN); fclose($lock);}
}
function importAdminRows(array $rows, array $rejected = [], bool $dryRun = false, array $reassignments = [], int $maximumReadAge = 600): array {
    if (count($rows) + count($rejected) < 1 || count($rows) + count($rejected) > 5000) fail('每批需1至5000个SKU', 400, 'invalid_import');
    $incoming = []; $pcls = []; $now = time();
    foreach ($rows as $row) {
        if (!is_array($row)) fail('SKU记录格式错误', 400, 'invalid_import');
        if (array_key_exists('pcl', $row)) $pcls[] = validatedProductPcl($row, $maximumReadAge);
        $sku = $row['sku'] ?? null; $guide = $row['guideCost'] ?? null; $weight = $row['weightGrams'] ?? null;
        $productId = $row['productId'] ?? null;
        if (!is_string($sku) || $sku !== trim($sku) || $sku === '' || strlen($sku) > 160 || preg_match('/[\x00-\x1f\x7f]/', $sku)
            || ($row['guideField'] ?? '') !== 'goodsGuideCost' || (!is_int($guide) && !is_float($guide))
            || !is_finite((float)$guide) || $guide < 0 || (!is_int($weight) && !is_float($weight))
            || !is_finite((float)$weight) || $weight <= 0 || !is_string($productId) || !preg_match('/^\d{10,40}$/D', $productId)) {
            fail('SKU、指导成本或重量无效，未写入', 400, 'invalid_import');
        }
        $timestamp = is_string($row['sourceReadAt'] ?? null) ? strtotime($row['sourceReadAt']) : false;
        if ($timestamp === false || $timestamp > $now + 300 || $timestamp < $now - $maximumReadAge) fail('读取时间无效，请重新读取', 400, 'invalid_import_time');
        $key = hash('sha256', $sku);
        if (isset($incoming[$key])) fail('本批SKU重复，未写入', 409, 'duplicate_sku');
        $incoming[$key] = ['sku' => $sku, 'guideCost' => $guide, 'weightGrams' => $weight,
            'guideField' => 'goodsGuideCost', 'sourceReadAt' => gmdate('c', $timestamp),
            'source' => 'tongtool_admin_session', 'productId' => $productId];
        if (in_array($row['productType'] ?? null, ['0', '1', '2'], true)) {
            $incoming[$key]['productType'] = $row['productType'];
        }
    }
    foreach ($rejected as $row) {
        if (!is_array($row)) fail('异常SKU格式错误', 400, 'invalid_import');
        if (array_key_exists('pcl', $row)) $pcls[] = validatedProductPcl($row, $maximumReadAge);
        $sku = $row['sku'] ?? null; $productId = $row['productId'] ?? null;
        $timestamp = is_string($row['sourceReadAt'] ?? null) ? strtotime($row['sourceReadAt']) : false;
        if (!is_string($sku) || $sku !== trim($sku) || $sku === '' || strlen($sku) > 160 || preg_match('/[\x00-\x1f\x7f]/', $sku)
            || !is_string($productId) || !preg_match('/^\d{10,40}$/D', $productId)
            || !in_array($row['reason'] ?? '', ['missing_guide','missing_weight'], true)
            || $timestamp === false || $timestamp > $now + 300 || $timestamp < $now - $maximumReadAge) fail('异常SKU格式错误', 400, 'invalid_import');
        $key = hash('sha256', $sku);
        if (isset($incoming[$key])) fail('本批SKU重复，未写入', 409, 'duplicate_sku');
        $incoming[$key] = ['sku' => $sku, 'guideField' => 'goodsGuideCost', 'sourceReadAt' => gmdate('c', $timestamp),
            'source' => 'tongtool_admin_session', 'productId' => $productId, 'invalid' => true, 'invalidReason' => $row['reason']];
        $guide = $row['guideCost'] ?? null;
        $weight = $row['weightGrams'] ?? null;
        $incoming[$key]['partialGuideCost'] = $row['reason'] === 'missing_weight'
            && (is_int($guide) || is_float($guide)) && is_finite((float)$guide) && $guide >= 0
            && (($row['productType'] ?? null) !== '2' || $guide > 0) ? $guide : null;
        $incoming[$key]['partialWeightGrams'] = $row['reason'] === 'missing_guide'
            && (is_int($weight) || is_float($weight)) && is_finite((float)$weight) && $weight > 0 ? $weight : null;
        if (in_array($row['productType'] ?? null, ['0', '1', '2'], true)) {
            $incoming[$key]['productType'] = $row['productType'];
        }
    }
    // Explicit per-SKU compare-and-swap, never a blanket "force latest" switch.
    // Only a valid, fresh row can move, and the caller must name its old owner.
    foreach ($reassignments as $sku => $expectedOwner) {
        $key = hash('sha256', (string)$sku);
        if (!is_string($sku) || !is_string($expectedOwner) || !preg_match('/^\d{10,40}$/D', $expectedOwner)
            || !isset($incoming[$key]) || ($incoming[$key]['sku'] ?? null) !== $sku || ($incoming[$key]['invalid'] ?? false))
            fail('指定SKU归属更新参数无效', 400, 'invalid_reassignment');
    }
    $lock = fopen(privateDir() . '/database-write.lock', 'c');
    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) fail('其他管理员正在更新，请稍后重试', 409, 'database_busy');
    try {
        $file = privateDir() . '/sku-database.json';
        $database = readJson($file);
        if (is_file($file) && (($database['schemaVersion'] ?? null) !== 1 || !is_array($database['items'] ?? null))) {
            fail('原数据库校验失败，保留原文件', 503, 'database_invalid');
        }
        $items = $database['items'] ?? []; $conflicts = [];
        foreach ($incoming as $key => $row) {
            $previous = $items[$key] ?? null;
            if (is_array($previous)) {
                if (($previous['sku'] ?? null) !== $row['sku']) fail('SKU索引异常', 409, 'database_invalid');
                if (!empty($previous['productId']) && $previous['productId'] !== $row['productId']) {
                    if (($reassignments[$row['sku']] ?? null) !== $previous['productId']) {
                        $conflicts[] = ['sku' => $row['sku'], 'existingProductId' => $previous['productId'], 'incomingProductId' => $row['productId']];
                        continue;
                    }
                    if (strtotime($previous['sourceReadAt'] ?? '') >= strtotime($row['sourceReadAt']))
                        fail('归属更新必须使用更新的通途读取资料', 409, 'older_import');
                }
                if (strtotime($previous['sourceReadAt'] ?? '') > strtotime($row['sourceReadAt'])) fail('不能用旧资料覆盖新资料', 409, 'older_import');
            }
            // Preserve prior values for recovery, but make incomplete fresh reads unusable for audits.
            $items[$key] = ($row['invalid'] ?? false) ? $row + ($previous ?? []) : $row;
        }
        if ($conflicts) respond(409, ['status' => 'unavailable', 'message' => 'SKU归属变化，尚未覆盖',
            'errorCode' => 'duplicate_sku', 'conflicts' => $conflicts, 'items' => new stdClass()]);
        if (count($items) > 100000) fail('SKU库已达到容量上限', 413, 'database_full');
        $productPcls = mergeProductPcls($database['productPcls'] ?? [], $pcls);
        $updatedAt = gmdate('c');
        if ($dryRun) return ['status' => 'preview', 'imported' => count($rows), 'rejected' => count($rejected), 'totalSkus' => count($items)];
        if (is_file($file)) {
            // Keep a daily recovery point and the immediately preceding database.
            $daily = privateDir() . '/sku-database-day-' . gmdate('Ymd') . '.json';
            if (!is_file($daily)) backupJsonFile($file, $daily);
            backupJsonFile($file, privateDir() . '/sku-database-previous.json');
        }
        writeJson($file, array_merge($database, ['schemaVersion' => 1, 'updatedAt' => $updatedAt, 'items' => $items, 'productPcls' => $productPcls]));
        $queue = readJson(privateDir() . '/admin-pending.json');
        foreach ($incoming as $key => $row) unset($queue[$key]);
        writeJson(privateDir() . '/admin-pending.json', $queue);
        writeJson(privateDir() . '/admin-sync-status.json', ['updatedAt' => $updatedAt, 'imported' => count($incoming), 'totalSkus' => count($items)]);
        return ['status' => 'complete', 'imported' => count($rows), 'rejected' => count($rejected), 'totalSkus' => count($items), 'updatedAt' => $updatedAt];
    } finally {flock($lock, LOCK_UN); fclose($lock);}
}
function quarantineAdminSkus(array $skus, bool $dryRun = false): array {
    if (count($skus) < 1 || count($skus) > 100) fail('隔离SKU数量无效', 400, 'invalid_import');
    foreach ($skus as $sku) if (!is_string($sku) || $sku !== trim($sku) || $sku === '' || strlen($sku) > 160
        || preg_match('/[\x00-\x1f\x7f]/', $sku)) fail('隔离SKU无效', 400, 'invalid_import');
    $lock = fopen(privateDir() . '/database-write.lock', 'c');
    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) fail('正在更新，请稍后重试', 409, 'database_busy');
    try {
        $file = privateDir() . '/sku-database.json'; $db = readJson($file);
        if (($db['schemaVersion'] ?? null) !== 1 || !is_array($db['items'] ?? null)) fail('原库校验失败', 503, 'database_invalid');
        $original = $db; $queue = readJson(privateDir() . '/admin-pending.json'); $at = gmdate('c'); $marked = 0;
        foreach (array_unique($skus) as $sku) {
            $key = hash('sha256', $sku);
            if (isset($db['items'][$key])) {
                if (($db['items'][$key]['sku'] ?? null) !== $sku) fail('SKU索引异常', 409, 'database_invalid');
                // Retain actual old values, owner and source date for recovery.
                $db['items'][$key]['invalid'] = true; $db['items'][$key]['invalidReason'] = 'sku_conflict';
                $db['items'][$key]['conflictCheckedAt'] = $at; $marked++;
            }
            if (isset($queue[$key])) $queue[$key]['retryAfter'] = time() + 3600;
        }
        if ($dryRun) return ['status' => 'preview', 'isolated' => count(array_unique($skus)), 'marked' => $marked];
        if ($marked) {
            $backup = privateDir() . '/sku-database-before-conflicts-' . gmdate('Ymd') . '.json';
            if (!is_file($backup)) backupJsonFile($file, $backup);
            $db['updatedAt'] = $at; writeJson($file, $db);
        }
        writeJson(privateDir() . '/admin-pending.json', $queue);
        return ['status' => 'complete', 'isolated' => count(array_unique($skus)), 'marked' => $marked, 'updatedAt' => $at];
    } finally {flock($lock, LOCK_UN); fclose($lock);}
}
function validQueueSku(mixed $sku): bool {
    return is_string($sku) && $sku !== '' && $sku === trim($sku) && strlen($sku) <= 160
        && !preg_match('/[\x00-\x1f\x7f]/', $sku);
}

function queueAdminSkus(array $skus): int {
    $lock = fopen(privateDir() . '/database-write.lock', 'c');
    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) fail('SKU库正在更新', 409, 'database_busy');
    try {
        $database = readJson(privateDir() . '/sku-database.json'); $aliases = aliasIndex($database); $index = skuLookupIndex($database, $aliases);
        $queue = readJson(privateDir() . '/admin-pending.json');
        $queue = array_filter($queue, fn($r) => is_array($r) && ($r['addedAt'] ?? 0) > time() - 604800);
        foreach ($skus as $sku) {
            if (!validQueueSku($sku)) continue;
            $row = databaseItem($database, $sku, null, $aliases, $index);
            $readAt = is_array($row) ? strtotime($row['sourceReadAt'] ?? '') : false;
            if ($readAt !== false && $readAt >= time() - 600) continue;
            // Refresh the stored formal code, not an alias/case spelling that
            // older administrator helpers might reject after reading ERP.
            $queueSku = $row['canonicalSku'] ?? $sku; $key = hash('sha256', $queueSku);
            if (!isset($queue[$key])) $queue[$key] = ['sku' => $queueSku, 'addedAt' => time(), 'retryAfter' => 0];
        }
        $queue = array_slice($queue, -1000, null, true);
        writeJson(privateDir() . '/admin-pending.json', $queue);
        return count($queue);
    } finally {flock($lock, LOCK_UN); fclose($lock);}
}

function deferAdminSku(string $sku): void {
    $lock = fopen(privateDir() . '/database-write.lock', 'c');
    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) fail('SKU库正在更新', 409, 'database_busy');
    try {
        $file = privateDir() . '/admin-pending.json';
        $queue = readJson($file);
        $key = hash('sha256', $sku);
        if (isset($queue[$key])) {
            $queue[$key]['retryAfter'] = time() + 3600;
            writeJson($file, $queue);
        }
    } finally {flock($lock, LOCK_UN); fclose($lock);}
}
function databaseLookup(array $skus): array {
    $database = readJson(privateDir() . '/sku-database.json');
    if (($database['schemaVersion'] ?? null) !== 1 || !is_array($database['items'] ?? null)) {
        fail('SKU数据库未就绪，请联系管理员；未更改原有填写', 503, 'database_unavailable');
    }
    $items = []; $confirmed = 0; $incomplete = 0; $aliases = aliasIndex($database); $index = skuLookupIndex($database, $aliases);
    foreach ($skus as $sku) {
        $item = databaseItem($database, $sku, null, $aliases, $index);
        if ($item === null) {
            $exists = array_key_exists(hash('sha256', $sku), $database['items']);
            $item = ['status' => $exists ? 'unavailable' : 'not_found', 'sku' => $sku,
                'matchBasis' => 'sku_database', 'message' => $exists ? 'SKU库记录无效，请管理员核对，暂不核算'
                : 'SKU数据库未收录：请核对店铺SKU与通途入录，或让管理员更新数据库'];
        } else {
            $confirmed++;
            if ($item['guideCost'] === null || $item['weightGrams'] === null) $incomplete++;
        }
        $items[$sku] = $item;
    }
    return ['status' => $confirmed === count($skus) && $incomplete === 0 ? 'complete' : 'partial',
        'items' => (object)$items, 'readAt' => gmdate('c'), 'source' => '私有SKU数据库',
        'databaseUpdatedAt' => $database['updatedAt'] ?? null, 'databaseCount' => $confirmed,
        'message' => 'SKU数据库已匹配 ' . $confirmed . '/' . count($skus) . ' 个；资料不完整或过期 ' . $incomplete . ' 个',
        'version' => SERVICE_VERSION];
}
