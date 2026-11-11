<?php

namespace App\Portals\Finance;

use App\Models\FinanceRecord;
use Carbon\CarbonImmutable;
use Illuminate\Database\Connection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\Csv;
use PhpOffice\PhpSpreadsheet\Reader\Exception;
use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xls;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

class FinanceWorkbookService
{
    public const NAMED_TIMEZONES = ['UTC', 'Asia/Shanghai', 'America/Los_Angeles', 'America/New_York'];
    public const FIXED_TIMEZONES = [
        '-12:00', '-11:00', '-10:00', '-09:30', '-09:00', '-08:00', '-07:00', '-06:00',
        '-05:00', '-04:00', '-03:30', '-03:00', '-02:30', '-02:00', '-01:00', '+00:00',
        '+01:00', '+02:00', '+03:00', '+03:30', '+04:00', '+04:30', '+05:00', '+05:30',
        '+05:45', '+06:00', '+06:30', '+07:00', '+08:00', '+08:45', '+09:00', '+09:30',
        '+10:00', '+10:30', '+11:00', '+12:00', '+12:45', '+13:00', '+13:45', '+14:00',
    ];
    public const TIMEZONES = [...self::NAMED_TIMEZONES, ...self::FIXED_TIMEZONES];

    public function database(): Connection
    {
        return DB::connection(config('admin.database.connection') ?: config('database.default'));
    }

    /** Read the same domain, usage name and team fields maintained by the assistant portal. */
    public function domainGroups(bool $withAssignedOwner = false, ?int $perPage = null): \Illuminate\Support\Collection|\Illuminate\Pagination\LengthAwarePaginator
    {
        $db = $this->database();
        if (! $db->getSchemaBuilder()->hasTable('assistant_domain_stores')) {
            $groups = $db->table('finance_domain_groups')->orderBy('domain')->orderBy('id');

            return $perPage === null ? $groups->get() : $groups->paginate($perPage)->withQueryString();
        }

        $columns = ['id', 'domain', 'user_name', 'team'];
        if ($db->getSchemaBuilder()->hasColumn('assistant_domain_stores', 'shop_name')) {
            $columns[] = 'shop_name';
        }
        $withAssignedOwner = $withAssignedOwner && $db->getSchemaBuilder()->hasColumn('assistant_domain_stores', 'owner_user_id');
        if ($withAssignedOwner) {
            $columns[] = 'owner_user_id';
        }
        if ($withAssignedOwner && $db->getSchemaBuilder()->hasColumn('assistant_domain_stores', 'shop_status')) {
            $columns[] = 'shop_status';
        }

        $query = $db->table('assistant_domain_stores')->select($columns)
            ->whereNotNull('domain')->where('domain', '!=', '')->orderBy('domain')->orderBy('id');
        $page = $perPage === null ? null : $query->paginate($perPage)->withQueryString();
        $stores = $page?->getCollection() ?? $query->get();
        $ownerIds = $stores->pluck('owner_user_id')->filter()->unique();
        $owners = $ownerIds->isEmpty() ? collect() : \App\Models\AdminUser::query()
            ->whereKey($ownerIds->all())->get(['id', 'name', 'username'])->keyBy('id');

        $groups = $stores->map(function ($store) use ($owners, $withAssignedOwner) {
                $assignedOwner = $owners->get($store->owner_user_id ?? null);
                return (object) [
                    'id' => $store->id,
                    'domain' => strtolower(trim($store->domain)),
                    'owner' => $store->user_name ?? '',
                    'team' => FinanceCatalog::teamLabel($store->team),
                    'shop_name' => $store->shop_name ?? '',
                    'assigned_owner' => $withAssignedOwner ? ($assignedOwner?->name ?: ($assignedOwner?->username ?? '')) : null,
                    'assigned_owner_user_id' => $assignedOwner?->getKey(),
                    'shop_status' => $store->shop_status ?? null,
                ];
            });

        return $page ? $page->setCollection($groups) : $groups;
    }

    /** Keep source keys for historical lookups, but show one row per logical type. */
    public function typeSettings(): \Illuminate\Support\Collection
    {
        return $this->database()->table('finance_transaction_types')->orderBy('id')->get()
            ->groupBy(fn ($row) => FinancePayPalEventTypes::label(trim($row->raw_type)))
            ->map(function ($aliases) {
                $row = clone ($aliases->filter(fn ($row) => filled($row->category))
                    ->sortByDesc('updated_at')->first() ?? $aliases->first());
                $row->aliases = $aliases->pluck('raw_type')->all();

                return $row;
            })->sortBy(fn ($row) => FinancePayPalEventTypes::label($row->raw_type))->values();
    }

    public function registerType(string $rawType): void
    {
        $rawType = trim($rawType);
        if ($rawType === '') {
            return;
        }
        $category = $this->typeSettings()->first(fn ($row) =>
            FinancePayPalEventTypes::label($row->raw_type) === FinancePayPalEventTypes::label($rawType))?->category;
        $this->database()->table('finance_transaction_types')->insertOrIgnore([
            'raw_type' => $rawType, 'category' => $category, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function read(UploadedFile $file, array $headers, ?string $sheetName = null, array $optionalHeaders = [], bool $keyedHeaders = false, int $maxRows = 5000, ?array $readerTypes = null): array
    {
        try {
            $reader = IOFactory::createReaderForFile($file->getRealPath(), $readerTypes);
            $reader->setReadDataOnly(true);
            $book = $reader->load($file->getRealPath());
        } catch (Exception $e) {
            throw ValidationException::withMessages(['file' => __('finance.errors.file')]);
        }
        try {
            $sheet = $this->importSheet($book, $sheetName);
            ['row' => $lastRow, 'column' => $lastColumn] = $sheet->getHighestRowAndColumn();
            $width = Coordinate::columnIndexFromString($lastColumn);
            if ($lastRow > $maxRows + 1 || $width > 100) {
                throw ValidationException::withMessages(['file' => __('finance.errors.size', ['rows' => number_format($maxRows)])]);
            }
            $rows = $this->readRows($sheet, $headers, $optionalHeaders, $keyedHeaders, $width, 2, $lastRow);
            if ($rows === []) {
                throw ValidationException::withMessages(['file' => __('finance.errors.empty')]);
            }

            return $rows;
        } finally {
            $book->disconnectWorksheets();
        }
    }

    public function readConfluxOrderBatches(UploadedFile $file, array $headers, string $sheetName, array $optionalHeaders): \Generator
    {
        $maxRows = 20000;
        $batchSize = 5000;
        $reader = IOFactory::createReader(IOFactory::READER_XLSX);
        $reader->setReadDataOnly(true);
        $path = $file->getRealPath();
        try {
            $info = array_column($reader->listWorksheetInfo($path), null, 'worksheetName');
            $selected = $info[$sheetName] ?? (count($info) === 1 ? reset($info) : null);
            $hasRows = false;
            for ($first = 2; $first === 2 || $first <= $selected['totalRows']; $first += $batchSize) {
                $last = $first + $batchSize - 1;
                if ($selected) {
                    $reader->setLoadSheetsOnly($selected['worksheetName']);
                }
                $reader->setReadFilter(new class($first, $last) implements IReadFilter {
                    public function __construct(private int $first, private int $last) {}

                    public function readCell(string $columnAddress, int $row, string $worksheetName = ''): bool
                    {
                        return ($row === 1 || ($row >= $this->first && $row <= $this->last))
                            && Coordinate::columnIndexFromString($columnAddress) <= 100;
                    }
                });
                $book = $reader->load($path);
                try {
                    $sheet = $this->importSheet($book, $selected['worksheetName'] ?? $sheetName);
                    $selected = $info[$sheet->getTitle()];
                    if ($selected['totalRows'] > $maxRows + 1 || $selected['totalColumns'] > 100) {
                        throw ValidationException::withMessages(['file' => __('finance.errors.size', ['rows' => number_format($maxRows)])]);
                    }
                    $rows = $this->readRows($sheet, $headers, $optionalHeaders, true, $selected['totalColumns'],
                        $first, min($last, $selected['totalRows']));
                } finally {
                    $book->disconnectWorksheets();
                }
                if ($rows !== []) {
                    $hasRows = true;
                    yield $rows;
                }
            }
            if (! $hasRows) {
                throw ValidationException::withMessages(['file' => __('finance.errors.empty')]);
            }
        } catch (Exception $e) {
            throw ValidationException::withMessages(['file' => __('finance.errors.file')]);
        }
    }

    private function importSheet(Spreadsheet $book, ?string $sheetName): Worksheet
    {
        if ($sheetName === null) {
            return $book->getActiveSheet();
        }
        $legacyName = str_replace(['PayPal', '信用卡'], ['贝宝', '徐总支付'], $sheetName);

        return $book->getSheetByName($sheetName) ?? $book->getSheetByName($legacyName) ?? $book->getActiveSheet();
    }

    private function readRows(Worksheet $sheet, array $headers, array $optionalHeaders, bool $keyedHeaders, int $width, int $firstRow, int $lastRow): array
    {
        $positions = [];
        $expected = array_count_values(array_map(FinanceCatalog::simplified(...), $headers));
        for ($column = 1; $column <= $width; $column++) {
            $name = trim(ltrim((string) $sheet->getCell([$column, 1])->getValue(), "\xEF\xBB\xBF"));
            $name = FinanceCatalog::simplified($name);
            if ($name !== '') {
                if (count($positions[$name] ?? []) >= ($keyedHeaders ? ($expected[$name] ?? 1) : 1)) {
                    throw ValidationException::withMessages(['file' => __('finance.errors.duplicate_header', ['header' => $name])]);
                }
                $positions[$name][] = $column;
            }
        }
        $columns = [];
        $occurrences = [];
        foreach ($headers as $key => $header) {
            $name = FinanceCatalog::simplified($header);
            $occurrence = $keyedHeaders ? ($occurrences[$name] ?? 0) : 0;
            $column = $positions[$name][$occurrence] ?? null;
            if ($column === null && ! in_array($keyedHeaders ? $key : $header, $optionalHeaders, true)) {
                throw ValidationException::withMessages(['file' => __('finance.errors.header', ['header' => $header])]);
            }
            $occurrences[$name] = $occurrence + 1;
            $columns[$keyedHeaders ? $key : $header] = $column;
        }
        $rows = [];
        for ($row = $firstRow; $row <= $lastRow; $row++) {
            $values = [];
            foreach ($columns as $key => $column) {
                if ($column === null) {
                    $values[$key] = '';
                    continue;
                }
                $cell = $sheet->getCell([$column, $row]);
                if ($cell->isFormula()) {
                    throw ValidationException::withMessages(['file' => __('finance.errors.formula', ['row' => $row])]);
                }
                $value = $cell->getValue();
                $values[$key] = is_scalar($value) ? trim((string) $value) : '';
            }
            if (count(array_filter($values, static fn ($value) => $value !== '')) > 0) {
                $rows[$row] = $values;
            }
        }
        return $rows;
    }

    public function readExportTemplate(UploadedFile $file, int $headerRow, array $columns, array $legacyColumns = []): array
    {
        $path = $file->getRealPath();
        try {
            $reader = IOFactory::createReaderForFile($path, [IOFactory::READER_XLSX, IOFactory::READER_XLS, IOFactory::READER_CSV]);
            if ($reader instanceof Csv) {
                $reader->setInputEncoding(Csv::guessEncoding($path, 'GB18030'));
            } else {
                $sheetName = $reader->listWorksheetNames($path)[0] ?? null;
                if ($sheetName === null) {
                    throw new Exception('Empty workbook');
                }
                $reader->setLoadSheetsOnly($sheetName);
            }
            $reader->setReadDataOnly(true);
            $reader->setReadFilter(new class($headerRow) implements IReadFilter {
                public function __construct(private readonly int $headerRow) {}

                public function readCell(string $columnAddress, int $row, string $worksheetName = ''): bool
                {
                    return $row === $this->headerRow && Coordinate::columnIndexFromString($columnAddress) <= 129;
                }
            });
            $book = $reader->load($path);
        } catch (Exception | \ValueError) {
            throw ValidationException::withMessages(['file' => '无法读取模板，请确认文件是有效的 XLSX、XLS 或 CSV。']);
        }

        try {
            $sheet = $book->getActiveSheet();
            $width = Coordinate::columnIndexFromString($sheet->getHighestDataColumn($headerRow));
            if ($width > 128) {
                throw ValidationException::withMessages(['file' => '模板表头最多支持 128 列。']);
            }
            $normalize = static fn (string $name): string => mb_strtolower(preg_replace(
                '/[\s\x{FEFF}()（）_\-－]+/u', '', FinanceCatalog::simplified($name),
            ) ?? '');
            $known = [];
            foreach ($columns as $field => $label) {
                $known[$normalize($label)][] = $field;
            }
            foreach ($legacyColumns as $field => $label) {
                $name = $normalize($label);
                if (! in_array($field, $known[$name] ?? [], true)) {
                    $known[$name][] = $field;
                }
            }
            foreach ([
                '交易号' => 'reference', '交易单号' => 'reference', '订单状态' => 'order_status',
                '商品名称' => 'paypal_18', '物品标题' => 'paypal_18', '配货名称' => 'paypal_18',
                '账单号码' => 'paypal_13', '账单号' => 'paypal_13', '应结算' => 'payable',
                '结算金额-美元' => 'settlement', '自定义编号' => 'paypal_27', '联系电话号码' => 'paypal_37',
            ] as $alias => $field) {
                if (isset($columns[$field])) {
                    $known[$normalize($alias)] ??= [$field];
                }
            }
            $selected = $unmatched = $duplicates = [];
            for ($column = 1; $column <= $width; $column++) {
                $cell = $sheet->getCell([$column, $headerRow]);
                $value = $cell->getValue();
                $label = is_scalar($value) ? mb_substr(trim((string) $value), 0, 120) : '';
                if ($label === '') {
                    continue;
                }
                $candidates = $cell->isFormula() ? [] : ($known[$normalize($label)] ?? []);
                $remaining = array_diff_key(array_fill_keys($candidates, true), $selected);
                if ($candidates === []) {
                    $unmatched[] = $label;
                } elseif ($remaining === []) {
                    $duplicates[] = $label;
                } else {
                    $selected[array_key_first($remaining)] = true;
                }
            }
            return ['fields' => array_keys($selected), 'unmatched' => $unmatched, 'duplicates' => $duplicates];
        } finally {
            $book->disconnectWorksheets();
        }
    }

    public function importRecords(string $dataset, array $rows, string $account, string $timezone, int $actor, bool $createOnly = false, bool $guardSettlementMonth = false, bool $fillMissing = false): int
    {
        if (! in_array($timezone, self::TIMEZONES, true)) {
            throw ValidationException::withMessages(['source_timezone' => __('finance.errors.timezone')]);
        }
        $definition = FinanceCatalog::dataset($dataset);
        $headers = $definition['headers'];
        $records = [];
        foreach ($rows as $row => $payload) {
            $value = static fn (string $key) => isset($definition[$key]) ? trim((string) ($payload[$headers[$definition[$key]]] ?? '')) : '';
            $reference = $value('reference');
            if ($reference === '' || mb_strlen($reference) > 191) {
                throw ValidationException::withMessages(['file' => __('finance.errors.reference', ['row' => $row])]);
            }
            $date = $value('date');
            $time = $value('time');
            $rowTimezone = $timezone;
            $point = trim((string) ($payload['时间点'] ?? ''));
            if ($point === '') {
                $point = trim((string) ($payload['（-8）时间点'] ?? ''));
            }
            if ($point !== '') {
                $zone = trim((string) ($payload['导出时区'] ?? ''));
                if ($zone === '' && filled($payload['（-8）时间点'] ?? null)) {
                    $zone = '-08:00';
                }
                $zone = preg_replace('/^UTC(?=[+-])/', '', $zone);
                if (preg_match('/^([+-]?)(\d{1,2})$/D', $zone, $parts)) {
                    $zone = ($parts[1] === '-' ? '-' : '+').str_pad($parts[2], 2, '0', STR_PAD_LEFT).':00';
                }
                $rowTimezone = $zone === '' ? $timezone : $zone;
                if (! in_array($rowTimezone, self::TIMEZONES, true)) {
                    throw ValidationException::withMessages(['file' => '第'.$row.'行导出时区无效。']);
                }
                $date = $point;
                $time = '';
                $payload['_report_timezone'] = $rowTimezone;
            }
            if (is_numeric($date)) {
                $date = Date::excelToDateTimeObject((float) $date)->format('Y-m-d H:i:s');
                if ($time !== '') {
                    $date = substr($date, 0, 10);
                }
            }
            if ($time !== '' && is_numeric($time)) {
                $time = Date::excelToDateTimeObject((float) $time)->format('H:i:s');
            }
            try {
                if (! preg_match('/^\d{4}[-\/]\d{1,2}[-\/]\d{1,2}(?:[ T]\d{1,2}:\d{2}(?::\d{2})?)?$/', $date)) {
                    throw new \InvalidArgumentException;
                }
                if ($time !== '' && ! preg_match('/^\d{1,2}:\d{2}(?::\d{2})?$/', $time)) {
                    throw new \InvalidArgumentException;
                }
                $input = trim($date.' '.$time);
                $occurred = CarbonImmutable::parse($input, $rowTimezone)->utc();
                $errors = CarbonImmutable::getLastErrors();
                if ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) {
                    throw new \InvalidArgumentException;
                }
            } catch (\Throwable $e) {
                throw ValidationException::withMessages(['file' => __('finance.errors.date', ['row' => $row])]);
            }
            $rawType = $value('type');
            $currency = strtoupper($value('currency'));
            $status = $value('status');
            $domain = strtolower($value('domain'));
            $currencyMissingOnFailedBill = $currency === '' && in_array($dataset, [...FinanceCatalog::XUZONG_REPORT_IMPORTS, FinanceLogisticsCreditOrders::IMPORTED_DATASET], true)
                && FinanceSettlementService::transactionStatusCategory($status) === 'failed';
            if (mb_strlen($rawType) > 191 || mb_strlen($status) > 100 || mb_strlen($domain) > 191
                || (! $currencyMissingOnFailedBill && ! preg_match('/^[A-Z]{3}$/', $currency))) {
                throw ValidationException::withMessages(['file' => __('finance.errors.fields', ['row' => $row])]);
            }
            $identity = [$dataset, $account, $reference, $occurred->toIso8601String(), $rawType, $currency, $status];
            if ($definition['channel'] !== 'paypal') {
                $identity[] = trim((string) ($payload['商户订单号'] ?? $payload['Shopify订单号'] ?? ''));
                if (isset($payload['_card_channel'])) {
                    $identity[] = $payload['_card_channel'];
                }
            }
            $records[] = [
                'dataset' => $dataset, 'account' => $account, 'reference' => $reference,
                'occurred_at' => $occurred->format('Y-m-d H:i:s'), 'source_timezone' => $rowTimezone,
                'domain' => $domain ?: null, 'raw_type' => $rawType ?: null,
                'currency' => $currency, 'status' => $status ?: null,
                // Distinguish currency conversion, refund and failed-attempt rows sharing a reference.
                'fingerprint' => hash('sha256', json_encode($identity, JSON_UNESCAPED_UNICODE)),
                'payload' => $payload, 'imported_by' => $actor,
            ];
        }

        if ($dataset === FinanceLogisticsCreditOrders::IMPORTED_DATASET && $createOnly && $fillMissing && ! $guardSettlementMonth) {
            return $this->saveImportedCreditOrders($records);
        }

        return $this->database()->transaction(function () use ($records, $createOnly, $guardSettlementMonth, $fillMissing): int {
            $affected = 0;
            $checkedMonths = [];
            foreach ($records as $record) {
                $model = $createOnly
                    ? FinanceRecord::query()->firstOrCreate(['fingerprint' => $record['fingerprint']], $record)
                    : FinanceRecord::query()->updateOrCreate(['fingerprint' => $record['fingerprint']], $record);
                $created = $model->wasRecentlyCreated;
                if ($fillMissing && ! $created) {
                    $model = FinanceRecord::query()->whereKey($model->id)->lockForUpdate()->firstOrFail();
                    $payload = $model->payload;
                    foreach ($record['payload'] as $key => $value) {
                        if (blank($payload[$key] ?? null) && filled($value)) {
                            $payload[$key] = $value;
                        }
                    }
                    $model->payload = $payload;
                    if (blank($model->domain) && filled($record['domain'])) {
                        $model->domain = $record['domain'];
                    }
                }
                $updated = $fillMissing && ! $created && $model->isDirty();
                if ($guardSettlementMonth && ($created || $updated)) {
                    $month = $model->occurred_at->setTimezone('Asia/Shanghai')->format('Y-m');
                    if (! isset($checkedMonths[$month])) {
                        app(FinanceAccountingService::class)->guardUnlocked($month);
                        $checkedMonths[$month] = true;
                    }
                }
                if ($updated) {
                    $model->save();
                }
                $affected += (int) ($created || $updated);
                if ($record['raw_type']) {
                    $this->registerType($record['raw_type']);
                }
            }

            return $createOnly ? $affected : count($records);
        });
    }

    private function saveImportedCreditOrders(array $records): int
    {
        return $this->database()->transaction(function () use ($records): int {
            $existing = FinanceRecord::query()->whereIn('fingerprint', array_column($records, 'fingerprint'))
                ->lockForUpdate()->get()->keyBy('fingerprint');
            $inserts = [];
            $updated = 0;
            $now = now();
            foreach ($records as $record) {
                $fingerprint = $record['fingerprint'];
                $model = $existing->get($fingerprint);
                if ($model === null) {
                    $inserts[$fingerprint] ??= [
                        ...$record, 'payload' => json_encode($record['payload'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                        'created_at' => $now, 'updated_at' => $now,
                    ];
                    continue;
                }
                $payload = $model->payload;
                foreach ($record['payload'] as $key => $value) {
                    if (blank($payload[$key] ?? null) && filled($value)) {
                        $payload[$key] = $value;
                    }
                }
                $model->payload = $payload;
                $model->domain = $model->domain ?: $record['domain'];
                if ($model->isDirty()) {
                    $model->save();
                    $updated++;
                }
            }
            // Keep the SQL parameter count within the limits of each database driver.
            $batchSize = $this->database()->getDriverName() === 'mysql' ? 300 : 75;
            foreach (array_chunk($inserts, $batchSize) as $batch) {
                $this->database()->table('finance_records')->insert($batch);
            }

            return count($inserts) + $updated;
        });
    }

    public function importSettings(string $kind, array $rows): int
    {
        abort_unless(in_array($kind, ['types', 'domains'], true), 404);
        $keys = $kind === 'types' ? ['raw_type', 'category'] : ['domain', 'owner', 'team'];
        $prepared = [];
        foreach ($rows as $number => $row) {
            $values = array_values($row);
            $data = array_combine($keys, $values);
            if ($kind === 'types') {
                $data = array_map(fn ($value) => is_string($value) ? trim($value) : $value, $data);
            }
            if ($kind === 'domains') {
                $data['domain'] = strtolower($data['domain']);
            }
            $rules = $kind === 'types'
                ? ['raw_type' => 'required|string|max:191', 'category' => 'required|string|max:100']
                : ['domain' => ['required', 'max:191', 'regex:/^(?:[a-z0-9](?:[a-z0-9-]*[a-z0-9])?\.)+[a-z]{2,}$/i'], 'owner' => 'required|string|max:100', 'team' => 'required|string|max:100'];
            $validator = validator($data, $rules);
            if ($validator->fails()) {
                throw ValidationException::withMessages(['file' => __('finance.errors.settings', ['row' => $number])]);
            }
            $prepared[] = $data;
        }

        return $this->database()->transaction(function () use ($kind, $prepared, $keys): int {
            foreach ($prepared as $data) {
                $query = $this->database()->table($kind === 'types' ? 'finance_transaction_types' : 'finance_domain_groups');
                if ($kind === 'types') {
                    $type = $this->typeSettings()->first(fn ($row) =>
                        FinancePayPalEventTypes::label($row->raw_type) === FinancePayPalEventTypes::label($data['raw_type']));
                    if ($type) {
                        $query->whereIn('raw_type', $type->aliases)->update(['category' => $data['category'], 'updated_at' => now()]);
                        $this->registerType($data['raw_type']);
                        continue;
                    }
                }
                $existing = (clone $query)->where($keys[0], $data[$keys[0]])->exists();
                $query->updateOrInsert([$keys[0] => $data[$keys[0]]], [
                    ...$data, 'updated_at' => now(), ...($existing ? [] : ['created_at' => now()]),
                ]);
            }

            return count($prepared);
        });
    }

    public function download(array $headers, iterable $rows, string $filename, string $format = 'xlsx', bool $plain = false, bool $simplifyText = true): StreamedResponse
    {
        if ($format === 'xlsx') {
            return $this->downloadStreamingXlsx($headers, $rows, $filename, $plain, $simplifyText);
        }
        return response()->streamDownload(function () use ($headers, $rows, $format, $plain, $simplifyText): void {
            // Spreadsheet cells and ZIP compression coexist during export.
            // Keep the higher budget local to this download, not the whole PHP service.
            $previousLimit = ini_get('memory_limit');
            $bytes = (int) $previousLimit;
            $unit = strtolower(substr(trim($previousLimit), -1));
            $bytes *= match ($unit) { 'g' => 1024 ** 3, 'm' => 1024 ** 2, 'k' => 1024, default => 1 };
            $raisedLimit = $bytes > 0 && $bytes < 512 * 1024 ** 2;
            if ($raisedLimit) {
                ini_set('memory_limit', '512M');
            }
            $book = new Spreadsheet;
            if (! $plain) {
                $book->getDefaultStyle()->getFont()->setName('Microsoft YaHei')->setSize(10);
            }
            $sheet = $book->getActiveSheet();
            if ($simplifyText) {
                $headers = array_map(FinanceCatalog::simplified(...), $headers);
            }
            $sheet->fromArray($headers, null, 'A1');
            $numericColumns = [];
            foreach ($headers as $index => $header) {
                $numericColumns[$index] = ! preg_match('/ID|编号|号码|账号|单号|影响|名称|类型|状态|日期|时间|时区|地址/iu', $header)
                    && preg_match('/金额|总额|净额|费用|手续费|余额|汇率|数量|条数|订单数|人民币|结算|应收|退款|冻结|解冻|保证金|成本|货款|运费|税额|保险|拒付|物流|采购|损耗|转换美元|争议费/u', $header);
            }
            $rowIndex = 2;
            foreach ($rows as $row) {
                foreach (array_values($row) as $column => $value) {
                    // Financial references and literal text must survive export without formula execution.
                    $value = (string) ($value ?? '');
                    if ($simplifyText) {
                        $value = FinanceCatalog::simplified($value);
                    }
                    $number = str_replace(',', '', $value);
                    $numeric = ($numericColumns[$column] ?? false)
                        && preg_match('/^-?\d+(?:\.\d+)?$/D', $number)
                        && strlen(ltrim(str_replace(['-', '.'], '', $number), '0')) <= 15;
                    $sheet->setCellValueExplicit([$column + 1, $rowIndex], $numeric ? (float) $number : $value, $numeric ? DataType::TYPE_NUMERIC : DataType::TYPE_STRING);
                }
                // Long item descriptions must not expand every order to hundreds of pixels.
                if (! $plain) {
                    $sheet->getRowDimension($rowIndex)->setRowHeight(36);
                }
                $rowIndex++;
            }
            $dimension = $sheet->calculateWorksheetDimension();
            $sheet->setAutoFilter($dimension);
            if (! $plain) {
                $sheet->freezePane('A2');
                $sheet->getStyle($dimension)->getAlignment()->setWrapText(true);
                $sheet->getStyle($dimension)->getAlignment()->setVertical('center');
                $sheet->getStyle($dimension)->getBorders()->getAllBorders()->setBorderStyle('thin')->getColor()->setARGB('FFDCE6EF');
                $sheet->getStyle('1:1')->getFont()->setBold(true);
                $lastColumn = Coordinate::stringFromColumnIndex(count($headers));
                $sheet->getRowDimension(1)->setRowHeight(30);
                $sheet->getStyle('A1:'.$lastColumn.'1')->getFill()->setFillType('solid')->getStartColor()->setARGB('FFE8F1F8');
                $sheet->getStyle('A1:'.$lastColumn.'1')->getAlignment()->setHorizontal('center');
            }
            foreach ($headers as $index => $header) {
                $column = Coordinate::stringFromColumnIndex($index + 1);
                $sheet->getColumnDimension($column)->setWidth($this->downloadColumnWidth($header, $plain));
                if (! $plain && $numericColumns[$index] && $rowIndex > 2) {
                    $range = $column.'2:'.$column.($rowIndex - 1);
                    $sheet->getStyle($range)->getNumberFormat()->setFormatCode(preg_match('/数量|条数/u', $header) ? '#,##0.########' : '#,##0.00########');
                    $sheet->getStyle($range)->getAlignment()->setHorizontal('right');
                }
            }
            try {
                $writer = $format === 'xls' ? new Xls($book) : new Xlsx($book);
                $writer->setUseDiskCaching(true, sys_get_temp_dir());
                $writer->setPreCalculateFormulas(false);
                $writer->save('php://output');
            } finally {
                $book->disconnectWorksheets();
                unset($writer, $sheet, $book);
                gc_collect_cycles();
                if ($raisedLimit) {
                    // PHP may retain freed pages; lowering below current usage emits an export-breaking warning.
                    if ($bytes >= memory_get_usage(true)) ini_set('memory_limit', $previousLimit);
                }
            }
        }, $filename, ['Content-Type' => $format === 'xls' ? 'application/vnd.ms-excel' : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }

    private function downloadStreamingXlsx(array $headers, iterable $rows, string $filename, bool $plain, bool $simplifyText): StreamedResponse
    {
        if ($simplifyText) {
            $headers = array_map(FinanceCatalog::simplified(...), $headers);
        }
        $numeric = [];
        $columns = [];
        foreach ($headers as $index => $header) {
            $numeric[$index] = ! preg_match('/ID|编号|号码|账号|单号|影响|名称|类型|状态|日期|时间|时区|地址/iu', $header)
                && preg_match('/金额|总额|净额|费用|手续费|余额|汇率|数量|条数|订单数|人民币|结算|应收|退款|冻结|解冻|保证金|成本|货款|运费|税额|保险|拒付|物流|采购|损耗|转换美元|争议费/u', $header);
            $columns[] = ['label' => $header, 'width' => $this->downloadColumnWidth($header, $plain)];
        }
        return app(\App\Portals\Logistics\StreamedXlsxWriter::class)->response($filename, 'Sheet1', $columns,
            function () use ($headers, $numeric, $rows, $simplifyText): iterable {
                foreach ($rows as $row) {
                    $cells = [];
                    foreach (array_values($row) as $column => $raw) {
                        $value = (string) ($raw ?? '');
                        if ($simplifyText) {
                            $value = FinanceCatalog::simplified($value);
                        }
                        $number = str_replace(',', '', $value);
                        $isNumeric = ($numeric[$column] ?? false) && preg_match('/^-?\d+(?:\.\d+)?$/D', $number)
                            && strlen(ltrim(str_replace(['-', '.'], '', $number), '0')) <= 15;
                        $cells[] = ['value' => $isNumeric ? (float) $number : $value,
                            'type' => $isNumeric ? (preg_match('/数量|条数/u', $headers[$column]) ? 'quantity' : 'decimal') : 'text', 'wrap' => true];
                    }
                    yield ['height' => 36, 'cells' => $cells];
                }
            }, plain: $plain);
    }

    private function downloadColumnWidth(string $header, bool $plain): int
    {
        $width = match (true) {
            (bool) preg_match('/地址|标题|物品|商品|备注/u', $header) => 44,
            (bool) preg_match('/ID|编号|号码|单号/iu', $header) => 30,
            (bool) preg_match('/类型|姓名|名称/u', $header) => 28,
            (bool) preg_match('/日期|时间|时区/u', $header) => 15,
            (bool) preg_match('/货币|币种|数量|条数|状态/u', $header) => 12,
            default => 22,
        };
        if (! $plain) {
            return $width;
        }
        return max(mb_strwidth($header) + 4, match ($header) {
            '当前订单状态' => 46,
            '核对说明' => 55,
            '交易 ID', '偏好设定 Txn ID', '通途订单' => 40,
            '支付账户', '域名' => 28,
            '时间点', '导出时区' => 23,
            default => $width,
        });
    }

    public function downloadDelimited(array $headers, iterable $rows, string $filename, string $delimiter = ',', bool $simplifyText = true): StreamedResponse
    {
        return response()->streamDownload(function () use ($headers, $rows, $delimiter, $simplifyText): void {
            $stream = fopen('php://output', 'wb');
            fwrite($stream, "\xEF\xBB\xBF");
            fputcsv($stream, $simplifyText ? array_map(FinanceCatalog::simplified(...), $headers) : $headers, $delimiter, '"', '');
            foreach ($rows as $row) {
                $values = array_map(static function ($value) use ($simplifyText): string {
                    $value = (string) ($value ?? '');
                    if ($simplifyText) {
                        $value = FinanceCatalog::simplified($value);
                    }

                    return ! is_numeric($value) && preg_match('/^[=+\-@\t\r]/', $value) ? "'".$value : $value;
                }, array_values($row));
                fputcsv($stream, $values, $delimiter, '"', '');
            }
            fclose($stream);
        }, $filename, ['Content-Type' => $delimiter === "\t" ? 'text/tab-separated-values; charset=UTF-8' : 'text/csv; charset=UTF-8']);
    }
}
