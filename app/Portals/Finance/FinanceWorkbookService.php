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
use PhpOffice\PhpSpreadsheet\Reader\Exception;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xls;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

class FinanceWorkbookService
{
    public const TIMEZONES = ['UTC', 'Asia/Shanghai', 'America/Los_Angeles', '-08:00', '-07:00', '+08:00'];

    public function database(): Connection
    {
        return DB::connection(config('admin.database.connection') ?: config('database.default'));
    }

    /** Read the same domain, usage name and team fields maintained by the assistant portal. */
    public function domainGroups(): \Illuminate\Support\Collection
    {
        $db = $this->database();
        if (! $db->getSchemaBuilder()->hasTable('assistant_domain_stores')) {
            return $db->table('finance_domain_groups')->orderBy('domain')->get();
        }

        return $db->table('assistant_domain_stores')->select(['id', 'domain', 'user_name', 'team'])
            ->whereNotNull('domain')->where('domain', '!=', '')->orderBy('domain')->get()
            ->map(function ($store) {
                return (object) [
                    'id' => $store->id,
                    'domain' => strtolower(trim($store->domain)),
                    'owner' => $store->user_name ?? '',
                    'team' => FinanceCatalog::teamLabel($store->team),
                ];
            });
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

    public function read(UploadedFile $file, array $headers, ?string $sheetName = null, array $optionalHeaders = []): array
    {
        try {
            $reader = IOFactory::createReaderForFile($file->getRealPath());
            $reader->setReadDataOnly(true);
            $book = $reader->load($file->getRealPath());
        } catch (Exception $e) {
            throw ValidationException::withMessages(['file' => __('finance.errors.file')]);
        }
        try {
            $sheet = $sheetName ? $book->getSheetByName($sheetName) : null;
            if (! $sheet && $sheetName) {
                $legacyName = str_replace(['PayPal', '信用卡'], ['贝宝', '徐总支付'], $sheetName);
                $sheet = $book->getSheetByName($legacyName);
            }
            $sheet ??= $book->getActiveSheet();
            if ($sheet->getHighestDataRow() > 5001 || Coordinate::columnIndexFromString($sheet->getHighestDataColumn()) > 100) {
                throw ValidationException::withMessages(['file' => __('finance.errors.size')]);
            }
            $positions = [];
            for ($column = 1; $column <= Coordinate::columnIndexFromString($sheet->getHighestDataColumn()); $column++) {
                $name = trim(ltrim((string) $sheet->getCell([$column, 1])->getValue(), "\xEF\xBB\xBF"));
                $name = FinanceCatalog::simplified($name);
                if ($name !== '') {
                    if (isset($positions[$name])) {
                        throw ValidationException::withMessages(['file' => __('finance.errors.duplicate_header', ['header' => $name])]);
                    }
                    $positions[$name] = $column;
                }
            }
            foreach ($headers as $header) {
                if (! isset($positions[FinanceCatalog::simplified($header)]) && ! in_array($header, $optionalHeaders, true)) {
                    throw ValidationException::withMessages(['file' => __('finance.errors.header', ['header' => $header])]);
                }
            }
            $rows = [];
            for ($row = 2; $row <= $sheet->getHighestDataRow(); $row++) {
                $values = [];
                foreach ($headers as $header) {
                    if (! isset($positions[FinanceCatalog::simplified($header)])) {
                        $values[$header] = '';
                        continue;
                    }
                    $cell = $sheet->getCell([$positions[FinanceCatalog::simplified($header)], $row]);
                    if ($cell->isFormula()) {
                        throw ValidationException::withMessages(['file' => __('finance.errors.formula', ['row' => $row])]);
                    }
                    $value = $cell->getValue();
                    $values[$header] = is_scalar($value) ? trim((string) $value) : '';
                }
                if (count(array_filter($values, static fn ($value) => $value !== '')) > 0) {
                    $rows[$row] = $values;
                }
            }
            if ($rows === []) {
                throw ValidationException::withMessages(['file' => __('finance.errors.empty')]);
            }

            return $rows;
        } finally {
            $book->disconnectWorksheets();
        }
    }

    public function importRecords(string $dataset, array $rows, string $account, string $timezone, int $actor): int
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
                $occurred = CarbonImmutable::parse($input, $timezone)->utc();
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
            if (mb_strlen($rawType) > 191 || mb_strlen($status) > 100 || mb_strlen($domain) > 191 || ! preg_match('/^[A-Z]{3}$/', $currency)) {
                throw ValidationException::withMessages(['file' => __('finance.errors.fields', ['row' => $row])]);
            }
            $identity = [$dataset, $account, $reference, $occurred->toIso8601String(), $rawType, $currency, $status];
            if ($definition['channel'] !== 'paypal') {
                $identity[] = trim((string) ($payload['商户订单号'] ?? $payload['Shopify订单号'] ?? ''));
            }
            $records[] = [
                'dataset' => $dataset, 'account' => $account, 'reference' => $reference,
                'occurred_at' => $occurred->format('Y-m-d H:i:s'), 'source_timezone' => $timezone,
                'domain' => $domain ?: null, 'raw_type' => $rawType ?: null,
                'currency' => $currency, 'status' => $status ?: null,
                // Distinguish currency conversion, refund and failed-attempt rows sharing a reference.
                'fingerprint' => hash('sha256', json_encode($identity, JSON_UNESCAPED_UNICODE)),
                'payload' => $payload, 'imported_by' => $actor,
            ];
        }

        return $this->database()->transaction(function () use ($records): int {
            foreach ($records as $record) {
                FinanceRecord::query()->updateOrCreate(['fingerprint' => $record['fingerprint']], $record);
                if ($record['raw_type']) {
                    $this->registerType($record['raw_type']);
                }
            }

            return count($records);
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

    public function download(array $headers, iterable $rows, string $filename, string $format = 'xlsx'): StreamedResponse
    {
        return response()->streamDownload(function () use ($headers, $rows, $format): void {
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
            $book->getDefaultStyle()->getFont()->setName('Microsoft YaHei')->setSize(10);
            $sheet = $book->getActiveSheet();
            $headers = array_map(FinanceCatalog::simplified(...), $headers);
            $sheet->fromArray($headers, null, 'A1');
            $numericColumns = [];
            foreach ($headers as $index => $header) {
                $numericColumns[$index] = ! preg_match('/ID|编号|号码|账号|单号|影响|名称|类型|状态|日期|时间|时区|地址/iu', $header)
                    && preg_match('/金额|总额|净额|费用|手续费|余额|汇率|数量|条数|订单数|人民币|结算|应收|退款|冻结|解冻|保证金|成本|货款|运费|税额|保险|拒付|物流|采购|损耗/u', $header);
            }
            $rowIndex = 2;
            foreach ($rows as $row) {
                foreach (array_values($row) as $column => $value) {
                    // Financial references and literal text must survive export without formula execution.
                    $value = FinanceCatalog::simplified((string) ($value ?? ''));
                    $number = str_replace(',', '', $value);
                    $numeric = ($numericColumns[$column] ?? false)
                        && preg_match('/^-?\d+(?:\.\d+)?$/D', $number)
                        && strlen(ltrim(str_replace(['-', '.'], '', $number), '0')) <= 15;
                    $sheet->setCellValueExplicit([$column + 1, $rowIndex], $numeric ? (float) $number : $value, $numeric ? DataType::TYPE_NUMERIC : DataType::TYPE_STRING);
                }
                // Long item descriptions must not expand every order to hundreds of pixels.
                $sheet->getRowDimension($rowIndex)->setRowHeight(36);
                $rowIndex++;
            }
            $sheet->freezePane('A2');
            $sheet->getStyle($sheet->calculateWorksheetDimension())->getAlignment()->setWrapText(true);
            $sheet->getStyle($sheet->calculateWorksheetDimension())->getAlignment()->setVertical('center');
            $sheet->getStyle($sheet->calculateWorksheetDimension())->getBorders()->getAllBorders()->setBorderStyle('thin')->getColor()->setARGB('FFDCE6EF');
            $sheet->setAutoFilter($sheet->calculateWorksheetDimension());
            $sheet->getStyle('1:1')->getFont()->setBold(true);
            $lastColumn = Coordinate::stringFromColumnIndex(count($headers));
            $sheet->getRowDimension(1)->setRowHeight(30);
            $sheet->getStyle('A1:'.$lastColumn.'1')->getFill()->setFillType('solid')->getStartColor()->setARGB('FFE8F1F8');
            $sheet->getStyle('A1:'.$lastColumn.'1')->getAlignment()->setHorizontal('center');
            foreach ($headers as $index => $header) {
                $column = Coordinate::stringFromColumnIndex($index + 1);
                $width = match (true) {
                    (bool) preg_match('/地址|标题|物品|商品|备注/u', $header) => 44,
                    (bool) preg_match('/ID|编号|号码|单号/iu', $header) => 30,
                    (bool) preg_match('/类型|姓名|名称/u', $header) => 28,
                    (bool) preg_match('/日期|时间|时区/u', $header) => 15,
                    (bool) preg_match('/货币|币种|数量|条数|状态/u', $header) => 12,
                    default => 22,
                };
                $sheet->getColumnDimension($column)->setWidth($width);
                if ($numericColumns[$index] && $rowIndex > 2) {
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
                    ini_set('memory_limit', $previousLimit);
                }
            }
        }, $filename, ['Content-Type' => $format === 'xls' ? 'application/vnd.ms-excel' : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }

    public function downloadDelimited(array $headers, iterable $rows, string $filename, string $delimiter = ','): StreamedResponse
    {
        return response()->streamDownload(function () use ($headers, $rows, $delimiter): void {
            $stream = fopen('php://output', 'wb');
            fwrite($stream, "\xEF\xBB\xBF");
            fputcsv($stream, array_map(FinanceCatalog::simplified(...), $headers), $delimiter, '"', '');
            foreach ($rows as $row) {
                $values = array_map(static function ($value): string {
                    $value = FinanceCatalog::simplified((string) ($value ?? ''));

                    return ! is_numeric($value) && preg_match('/^[=+\-@\t\r]/', $value) ? "'".$value : $value;
                }, array_values($row));
                fputcsv($stream, $values, $delimiter, '"', '');
            }
            fclose($stream);
        }, $filename, ['Content-Type' => $delimiter === "\t" ? 'text/tab-separated-values; charset=UTF-8' : 'text/csv; charset=UTF-8']);
    }
}
