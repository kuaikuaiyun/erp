<?php

namespace App\Portals\Finance;

/** Resumable, bounded migration. Every document is read back before its source column can be retired. */
class FinanceSourceMigration
{
    public function __construct(private readonly FinanceWorkbookService $workbooks, private readonly FinanceSourceStorage $storage) {}

    public function migrate(int $batch = 150, ?callable $progress = null): array
    {
        $db = $this->workbooks->database(); $schema = $db->getSchemaBuilder(); $counts = [];
        if (! $this->storage->installed()) throw new \RuntimeException('请先创建财务普通字段及事件明细表。');
        foreach (FinanceSourceStorage::SOURCES as $kind => [$table, $field]) {
            if (! $schema->hasColumn($table, $field)) {
                $this->storage->setState($kind, 'active');
                $counts[$kind] = $this->verify($kind, $batch);
                continue;
            }
            $columns = array_column(array_filter($schema->getColumns($table), fn ($column) => empty($column['generation'])), 'name');
            $last = 0; $count = 0;
            // Recheck earlier rows on resume too: writes may have arrived since the last batch.
            do {
                $processed = $db->transaction(function () use ($db, $kind, $table, $field, $columns, $batch, &$last): int {
                    $rows = $db->table($table)->where('id', '>', $last)->orderBy('id')->limit($batch)->lockForUpdate()->get($columns);
                    if ($rows->isEmpty()) return 0;
                    $documents = $metadata = [];
                    foreach ($rows as $row) {
                        $document = json_decode($row->$field, true, 512, JSON_THROW_ON_ERROR);
                        $documents[$row->id] = ['document' => $document, 'dataset' => $row->dataset ?? null];
                        $metadata[] = $this->storage->prepare($kind, (array) $row, $document);
                    }
                    $existing = $this->storage->load($kind, $rows->pluck('id')->all()); $changed = [];
                    foreach ($documents as $id => $source) {
                        if (! isset($existing[$id]) || FinanceSourceStorage::hash($existing[$id]) !== FinanceSourceStorage::hash($source['document'])) $changed[$id] = $source;
                    }
                    $this->storage->write($kind, $changed);
                    $readback = $this->storage->load($kind, $rows->pluck('id')->all());
                    foreach ($documents as $id => $source) {
                        if (! isset($readback[$id]) || FinanceSourceStorage::hash($source['document']) !== FinanceSourceStorage::hash($readback[$id])) {
                            throw new \RuntimeException('来源逐字段校验失败：'.$kind.' #'.$id.'；原字段未删除。');
                        }
                    }
                    $auxiliary = $kind === 'record'
                        ? ['source_hash', 'source_channel', 'source_bill', 'source_merchant', 'source_shopify', 'source_parent', 'source_reference', 'source_legacy', 'source_notice', 'source_conflux_order', 'amount']
                        : ($kind === 'payment' ? ['source_hash', 'related_id', 'related_type', 'invoice_id', 'amount_currency', 'transaction_status', 'transaction_updated_date', 'amount', 'fee_amount'] : ['source_hash']);
                    $db->table($table)->upsert($metadata, ['id'], $auxiliary);
                    $last = $rows->last()->id;
                    $db->table('finance_source_storage')->where('kind', $kind)->update(['last_id' => $last]);
                    return $rows->count();
                }, 3);
                $count += $processed;
                if ($processed && $progress) $progress($kind, $count);
            } while ($processed > 0);
            $this->storage->sweepOrphans($kind);
            $this->storage->setState($kind, 'ready'); $counts[$kind] = $count;
        }
        return $counts;
    }

    public function verify(string $kind, int $batch = 150): int
    {
        $db = $this->workbooks->database(); [$table, $field] = FinanceSourceStorage::SOURCES[$kind];
        $legacy = $db->getSchemaBuilder()->hasColumn($table, $field); $count = 0;
        $db->table($table)->select(['id', 'source_hash', ...($legacy ? [$field] : [])])->orderBy('id')
            ->chunkById($batch, function ($rows) use ($kind, $field, $legacy, &$count): void {
                $documents = $this->storage->load($kind, $rows->pluck('id')->all());
                foreach ($rows as $row) {
                    $expected = $legacy ? FinanceSourceStorage::hash(json_decode($row->$field, true, 512, JSON_THROW_ON_ERROR)) : $row->source_hash;
                    if (! isset($documents[$row->id]) || ! is_string($expected) || ! is_string($row->source_hash)
                        || ! hash_equals($expected, $row->source_hash) || ! hash_equals($expected, FinanceSourceStorage::hash($documents[$row->id]))) {
                        throw new \RuntimeException('来源覆盖或内容校验失败：'.$kind.' #'.$row->id.'；停止切换。');
                    }
                    $count++;
                }
            });
        return $count;
    }
}
