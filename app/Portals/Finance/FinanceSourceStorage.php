<?php

namespace App\Portals\Finance;

use Illuminate\Database\Query\Expression;

/** Ordinary source columns and separate event rows; no serialized business documents. */
class FinanceSourceStorage
{
    public const SOURCES = ['record' => ['finance_records', 'payload'], 'payment' => ['finance_payment_transactions', 'raw_payload'],
        'statement' => ['finance_credit_settlement_entries', 'payload']];
    private const NULL = 1; private const STRING = 2; private const INTEGER = 3; private const FLOAT = 4;
    private const FALSE = 5; private const TRUE = 6; private const ARRAY = 7;
    private array $accountPlatforms = [];

    public function __construct(private readonly FinanceWorkbookService $workbooks) {}

    public static function definitions(): array
    {
        static $definitions;
        return $definitions ??= require database_path('data/finance-relational-fields.php');
    }

    public function installed(): bool
    {
        $db = $this->workbooks->database();
        $key = 'finance.source-storage:'.$db->getName().':'.$db->getDatabaseName();
        $attributes = app('request')->attributes;
        if (! $attributes->has($key)) $attributes->set($key, $db->getSchemaBuilder()->hasTable('finance_source_storage'));
        return $attributes->get($key);
    }

    public function active(string $kind): bool
    {
        if (! $this->installed()) return false;
        $db = $this->workbooks->database();
        $key = 'finance.source-active:'.$db->getName().':'.$db->getDatabaseName().':'.$kind;
        $attributes = app('request')->attributes;
        if (! $attributes->has($key)) $attributes->set($key, $db->table('finance_source_storage')->where('kind', $kind)->value('state') === 'active');
        return $attributes->get($key);
    }

    public function setState(string $kind, string $state): void
    {
        $db = $this->workbooks->database();
        $db->table('finance_source_storage')->where('kind', $kind)->update(['state' => $state]);
        app('request')->attributes->remove('finance.source-active:'.$db->getName().':'.$db->getDatabaseName().':'.$kind);
    }

    public function hydrate(string $kind, \Illuminate\Support\Collection $rows, string $id = 'id'): \Illuminate\Support\Collection
    {
        if (! $this->active($kind) || $rows->isEmpty()) return $rows;
        $documents = $this->load($kind, $rows->pluck($id)->all());
        foreach ($rows as $row) {
            if (! array_key_exists($row->$id, $documents)) throw new \RuntimeException('财务来源明细缺失，停止读取。');
            $row->source_fields = $documents[$row->$id];
        }
        return $rows;
    }

    public function stream(string $kind, iterable $rows): \Illuminate\Support\LazyCollection
    {
        return \Illuminate\Support\LazyCollection::make(function () use ($rows): iterable { yield from $rows; })->chunk(500)
            ->flatMap(fn ($batch) => $this->hydrate($kind, $batch->collect()));
    }

    public function values(string $kind, object $row): array
    {
        if (isset($row->source_fields)) return $row->source_fields;
        $field = self::SOURCES[$kind][1];
        if (isset($row->$field)) return json_decode($row->$field, true, 512, JSON_THROW_ON_ERROR);
        if ($this->active($kind)) return $this->load($kind, [$row->id])[$row->id] ?? throw new \RuntimeException('财务来源明细缺失。');
        throw new \RuntimeException('财务来源字段缺失。');
    }

    public function forgetAccounts(): void { $this->accountPlatforms = []; }

    public static function money(mixed $value): ?string
    {
        return $value === null ? null : bcadd((string) $value, '0', 8);
    }

    public function prepare(string $kind, array $attributes, array $document): array
    {
        $attributes['source_hash'] = self::hash($document);
        if ($kind === 'record') {
            $account = $attributes['account'] ?? '';
            if (! isset($this->accountPlatforms[$account])) {
                $this->accountPlatforms[$account] = \App\Models\FinancePaymentAccount::query()->where('activity_account', $account)
                    ->where('account_type', 'credit_card')->get()->map(FinanceCreditSettlementLayout::accountPlatform(...))->all();
            }
            $flags = FinanceTransactions::flags($attributes['dataset'], $document, $this->accountPlatforms[$account]);
            $attributes['source_channel'] = match (true) {
                (bool) ($flags & FinanceTransactions::PAYPAL) => 'paypal', (bool) ($flags & FinanceTransactions::WORLDPAY) => 'worldpay',
                (bool) ($flags & FinanceTransactions::YE) => 'ye', (bool) ($flags & FinanceTransactions::XUZONG1) => 'xuzong1', default => 'xuzong2',
            };
            foreach (['source_bill' => '帳單號碼', 'source_merchant' => '商户订单号', 'source_shopify' => 'Shopify订单号',
                'source_parent' => '偏好設定 Txn ID', 'source_reference' => '交易 ID'] as $field => $key) $attributes[$field] = $document[$key] ?? null;
            $attributes['amount'] = app(FinanceSettlementService::class)->number($document[str_starts_with($attributes['dataset'], 'paypal-') ? '總額' : '交易金额'] ?? null);
            $attributes['source_legacy'] = $document['_legacy_order_id'] ?? null;
            $attributes['source_notice'] = isset($document['_xuzong_statement_import']['notice']);
            $attributes['source_conflux_order'] = $attributes['dataset'] === 'xuzong2-details' && ($document['_card_channel'] ?? null) === 'ye';
        } elseif ($kind === 'payment') {
            $info = $document['transaction_info'] ?? [];
            foreach (['related_id' => 'paypal_reference_id', 'related_type' => 'paypal_reference_id_type', 'invoice_id' => 'invoice_id',
                'transaction_status' => 'transaction_status', 'transaction_updated_date' => 'transaction_updated_date'] as $field => $key) $attributes[$field] = $info[$key] ?? null;
            $attributes['amount_currency'] = $info['transaction_amount']['currency_code'] ?? null;
            $attributes['amount'] = app(FinanceSettlementService::class)->number($info['transaction_amount']['value'] ?? null);
            $attributes['fee_amount'] = app(FinanceSettlementService::class)->number($info['fee_amount']['value'] ?? null);
        }
        if ($this->active($kind)) unset($attributes[self::SOURCES[$kind][1]]);
        return $attributes;
    }

    /** Insert metadata in bulk, then associate source rows with their actual generated IDs. */
    public function insert(string $kind, array $rows): void
    {
        if ($rows === []) return;
        $db = $this->workbooks->database(); [$table, $field] = self::SOURCES[$kind];
        $db->transaction(function () use ($db, $kind, $rows, $table, $field): void {
            $metadata = $documents = [];
            foreach ($rows as $row) {
                $document = is_array($row[$field]) ? $row[$field] : json_decode($row[$field], true, 512, JSON_THROW_ON_ERROR);
                $row[$field] = json_encode($document, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
                $metadata[] = $this->prepare($kind, $row, $document); $documents[] = $document;
            }
            $last = (int) $db->table($table)->max('id');
            $db->table($table)->insert($metadata);
            $query = $db->table($table)->where('id', '>', $last);
            if ($kind === 'record') $query->whereIn('fingerprint', array_column($metadata, 'fingerprint'));
            elseif ($kind === 'statement') $query->where('payment_account_id', $metadata[0]['payment_account_id'])->where('report_hash', $metadata[0]['report_hash']);
            $ids = $query->orderBy('id')->pluck('id')->all();
            if (count($ids) !== count($rows)) throw new \RuntimeException('来源数据与生成 ID 数量不一致，已回滚导入。');
            $sources = [];
            foreach ($ids as $index => $id) $sources[$id] = ['document' => $documents[$index], 'dataset' => $metadata[$index]['dataset'] ?? null];
            $this->write($kind, $sources);
        }, 3);
    }

    public function delete(string $kind, array $ids): void
    {
        foreach (array_chunk($ids, 250) as $batch) {
            foreach (self::definitions() as $definition) {
                if ($definition['kind'] !== $kind) continue;
                $this->workbooks->database()->table($definition['table'])->whereIntegerInRaw('entity_id', $batch)->delete();
                foreach ($definition['collections'] as $collection) $this->workbooks->database()->table($collection['table'])->whereIntegerInRaw('entity_id', $batch)->delete();
            }
            $this->workbooks->database()->table('finance_source_extensions')->where('kind', $kind)->whereIntegerInRaw('entity_id', $batch)->delete();
        }
    }

    public function sweepOrphans(string $kind): void
    {
        $db = $this->workbooks->database(); $master = self::SOURCES[$kind][0];
        foreach (self::definitions() as $definition) {
            if ($definition['kind'] !== $kind) continue;
            foreach ([$definition['table'], ...array_column($definition['collections'], 'table')] as $table) {
                $db->table($table)->whereNotExists(fn ($query) => $query->selectRaw('1')->from($master)->whereColumn($master.'.id', $table.'.entity_id'))->delete();
            }
        }
        $db->table('finance_source_extensions')->where('kind', $kind)->whereNotExists(fn ($query) => $query->selectRaw('1')->from($master)
            ->whereColumn($master.'.id', 'finance_source_extensions.entity_id'))->delete();
        if ($kind === 'record' && app(FinanceTransactions::class)->installed()) {
            foreach (['finance_transactions' => 'id', 'finance_card_transaction_details' => 'record_id'] as $table => $key) {
                $db->table($table)->whereNotExists(fn ($query) => $query->selectRaw('1')->from($master)->whereColumn($master.'.id', $table.'.'.$key))->delete();
            }
        }
    }

    public static function hash(array $value): string
    {
        $canonical = function (array $items) use (&$canonical): array {
            foreach ($items as &$item) if (is_array($item)) $item = $canonical($item);
            unset($item);
            if (! array_is_list($items)) ksort($items);
            return $items;
        };
        return hash('sha256', json_encode($canonical($value), JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR));
    }

    private static function type(mixed $value): int
    {
        return match (true) { $value === null => self::NULL, is_string($value) => self::STRING, is_int($value) => self::INTEGER,
            is_float($value) => self::FLOAT, $value === false => self::FALSE, $value === true => self::TRUE,
            is_array($value) => self::ARRAY, default => throw new \InvalidArgumentException('财务来源字段包含不支持的类型。') };
    }

    private static function scalar(mixed $value): ?string
    {
        return is_scalar($value) ? (string) $value : null;
    }

    private static function value(int $type, mixed $value): mixed
    {
        return match ($type) { self::NULL => null, self::STRING => (string) $value, self::INTEGER => (int) $value,
            self::FLOAT => (float) $value, self::FALSE => false, self::TRUE => true, self::ARRAY => [],
            default => throw new \RuntimeException('财务来源字段类型损坏。') };
    }

    private static function pointer(array $segments): string
    {
        return '/'.implode('/', array_map(fn ($part) => str_replace(['~', '/'], ['~0', '~1'], (string) $part), $segments));
    }

    private static function segments(string $path): array
    {
        return array_map(fn ($part) => str_replace(['~1', '~0'], ['/', '~'], $part), explode('/', substr($path, 1)));
    }

    private static function set(array &$document, array $path, mixed $value): void
    {
        $target = &$document;
        foreach ($path as $part) $target = &$target[$part];
        $target = $value;
    }

    private function group(string $kind, array $document, ?string $dataset): string
    {
        if ($kind !== 'record') return $kind;
        if (str_starts_with($dataset ?? '', 'paypal-')) return 'record_paypal';
        if (str_starts_with($dataset ?? '', 'xuzong-credit-')) return 'record_credit';
        if (($document['_card_channel'] ?? null) === 'worldpay' || filled($document['网关接入号'] ?? null)
            || isset($document['_worldpay_events']) || isset($document['_worldpay_posted'])) return 'record_worldpay';
        if (($document['_card_channel'] ?? null) === 'ye' || filled($document['网站名称'] ?? null)) return 'record_ye';
        return 'record_credit';
    }

    /** @param array<int, array{document:array, dataset?:string}> $documents */
    public function write(string $kind, array $documents): void
    {
        if ($documents === []) return;
        $definitions = self::definitions(); $tables = []; $extras = [];
        foreach ($documents as $id => $source) {
            $group = $this->group($kind, $source['document'], $source['dataset'] ?? null);
            $definition = $definitions[$group];
            $children = []; $ordinal = 0;
            $row = $this->encode($kind, (int) $id, $source['document'], $definition, [], $extras, $children, $ordinal);
            $tables[$definition['table']][] = ['entity_id' => $id, ...$row];
            foreach ($children as $table => $rows) $tables[$table] = [...($tables[$table] ?? []), ...$rows];
        }
        $db = $this->workbooks->database();
        $db->transaction(function () use ($db, $kind, $documents, $definitions, $tables, $extras): void {
            foreach (array_chunk(array_keys($documents), 250) as $ids) {
                foreach ($definitions as $definition) {
                    if ($definition['kind'] !== $kind) continue;
                    $db->table($definition['table'])->whereIntegerInRaw('entity_id', $ids)->delete();
                    foreach ($definition['collections'] as $collection) $db->table($collection['table'])->whereIntegerInRaw('entity_id', $ids)->delete();
                }
                $db->table('finance_source_extensions')->where('kind', $kind)->whereIntegerInRaw('entity_id', $ids)->delete();
            }
            foreach ($tables as $table => $rows) foreach (array_chunk($rows, 100) as $batch) $db->table($table)->insert($batch);
            foreach (array_chunk($extras, 250) as $batch) $db->table('finance_source_extensions')->insert($batch);
        }, 3);
    }

    private function encode(string $kind, int $id, array $document, array $definition, array $prefix, array &$extras, array &$children, int &$ordinal): array
    {
        $fields = $definition['paths']; $indexes = array_flip(array_keys($fields));
        $types = str_repeat("\0", count($fields)); $values = array_fill_keys(array_values($fields), null);
        $walk = function (array $items, array $path) use (&$walk, $kind, $id, $definition, $prefix, $fields, $indexes, &$types, &$values, &$extras, &$children, &$ordinal): void {
            foreach ($items as $key => $value) {
                $segments = [...$path, $key]; $name = implode('.', $segments); $type = self::type($value);
                if (array_key_exists($name, $fields)) {
                    $types[$indexes[$name]] = chr($type); $values[$fields[$name]] = self::scalar($value);
                } else {
                    $pointer = self::pointer([...$prefix, ...$segments]);
                    $extras[] = ['kind' => $kind, 'entity_id' => $id, 'path' => $pointer, 'path_hash' => hash('sha256', $pointer),
                        'value_type' => $type, 'value' => self::scalar($value), 'position' => ++$ordinal];
                }
                if (isset($definition['collections'][$name]) && is_array($value)) {
                    $collection = $definition['collections'][$name]; $position = 0;
                    foreach ($value as $itemKey => $item) {
                        if (! is_array($item)) {
                            $pointer = self::pointer([...$prefix, ...$segments, $itemKey]);
                            $extras[] = ['kind' => $kind, 'entity_id' => $id, 'path' => $pointer, 'path_hash' => hash('sha256', $pointer),
                                'value_type' => self::type($item), 'value' => self::scalar($item), 'position' => ++$ordinal];
                            continue;
                        }
                        $encoded = $this->encode($kind, $id, $item, [...$collection, 'collections' => []],
                            [...$prefix, ...$segments, $itemKey], $extras, $children, $ordinal);
                        $children[$collection['table']][] = ['entity_id' => $id, 'item_key' => (string) $itemKey,
                            'key_type' => is_int($itemKey) ? 1 : 0, 'position' => ++$position, ...$encoded];
                    }
                } elseif (is_array($value)) $walk($value, $segments);
            }
        };
        $walk($document, []);
        return ['field_types' => $types, ...$values];
    }

    /** Load bounded ID batches; event collections never share the parent row. */
    public function load(string $kind, array $ids): array
    {
        if ($ids === []) return [];
        $db = $this->workbooks->database(); $documents = [];
        foreach (self::definitions() as $definition) {
            if ($definition['kind'] !== $kind) continue;
            $rows = $db->table($definition['table'])->whereIntegerInRaw('entity_id', $ids)->get();
            if ($rows->isEmpty()) continue;
            foreach ($rows as $row) $documents[$row->entity_id] = $this->decode($row, $definition['paths']);
            foreach ($definition['collections'] as $path => $collection) {
                $items = $db->table($collection['table'])->whereIntegerInRaw('entity_id', $rows->pluck('entity_id')->all())->orderBy('position')->get();
                foreach ($items as $item) {
                    $key = $item->key_type ? (int) $item->item_key : $item->item_key;
                    self::set($documents[$item->entity_id], [...explode('.', $path), $key], $this->decode($item, $collection['paths']));
                }
            }
        }
        foreach ($db->table('finance_source_extensions')->where('kind', $kind)->whereIntegerInRaw('entity_id', $ids)->orderBy('position')->get() as $extra) {
            if (! isset($documents[$extra->entity_id])) throw new \RuntimeException('财务扩展字段缺少所属记录。');
            self::set($documents[$extra->entity_id], self::segments($extra->path), self::value($extra->value_type, $extra->value));
        }
        return $documents;
    }

    private function decode(object $row, array $fields): array
    {
        if (strlen($row->field_types) !== count($fields)) throw new \RuntimeException('财务来源字段映射版本不一致。');
        $document = []; $index = 0;
        foreach ($fields as $path => $column) {
            $type = ord($row->field_types[$index++]);
            if ($type !== 0) self::set($document, explode('.', $path), self::value($type, $row->$column));
        }
        return $document;
    }

    /** A JSON-selector compatibility expression over normal columns, without JSON SQL. */
    public function expression(string $kind, string $alias, array $path): Expression
    {
        $db = $this->workbooks->database(); $grammar = $db->getQueryGrammar();
        $name = implode('.', $path); $owner = $grammar->wrap(($alias !== '' ? $alias.'.' : '').'id');
        if ($kind === 'record') $owner = 'ABS('.$owner.')';
        $groups = array_keys(array_filter(self::definitions(), fn ($definition) => $definition['kind'] === $kind)); $variants = [];
        foreach ($groups as $group) {
            $definition = self::definitions()[$group];
            if (! array_key_exists($name, $definition['paths'])) continue;
            $index = array_search($name, array_keys($definition['paths']), true) + 1;
            $column = $grammar->wrap($definition['paths'][$name]); $table = $grammar->wrapTable($definition['table']);
            $array = $db->getDriverName() === 'sqlite' ? "HEX(SUBSTR(CAST(field_types AS BLOB), ".$index.", 1)) = '07'" : 'ORD(SUBSTRING(field_types, '.$index.', 1)) = 7';
            $variants[] = '(SELECT CASE WHEN '.$array.' THEN \'[]\' ELSE '.$column.' END FROM '.$table.' WHERE entity_id = '.$owner.')';
        }
        $hash = hash('sha256', self::pointer($path));
        $variants[] = "(SELECT CASE WHEN value_type = 7 THEN '[]' ELSE value END FROM finance_source_extensions WHERE kind = ".$db->getPdo()->quote($kind)
            .' AND entity_id = '.$owner.' AND path_hash = '.$db->getPdo()->quote($hash).')';
        return new Expression(count($variants) === 1 ? $variants[0] : 'COALESCE('.implode(', ', $variants).')');
    }
}
