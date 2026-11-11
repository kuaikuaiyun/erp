<?php

namespace App\Portals\Finance;

use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

class FinanceXuzongOrderImport
{
    private const REFERENCES = [
        '交易流水号' => 'bill_number',
        '平台订单号' => 'payment_gateway_order_id',
        '商户订单号' => 'transaction_id',
        '通途订单' => 'tongtu_order_number',
    ];

    public function __construct(
        private readonly FinanceWorkbookService $workbooks,
        private readonly FinanceLogisticsCreditOrders $creditOrders,
    ) {}

    public function fileType(UploadedFile $file): ?string
    {
        if (in_array(strtolower($file->getClientOriginalExtension()), ['csv', 'tsv', 'txt'], true)) {
            $stream = fopen($file->getPathname(), 'rb');
            try {
                $headers = $this->headers($stream, $this->delimiter($file));
            } finally {
                fclose($stream);
            }
        } else {
            try {
                $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReaderForFile($file->getPathname());
                $reader->setReadDataOnly(true);
                $reader->setReadFilter(new class implements \PhpOffice\PhpSpreadsheet\Reader\IReadFilter {
                    public function readCell(string $columnAddress, int $row, string $worksheetName = ''): bool
                    {
                        return $row === 1;
                    }
                });
                $book = $reader->load($file->getPathname());
                $headers = array_map(fn ($header) => FinanceCatalog::simplified(trim((string) $header)),
                    $book->getActiveSheet()->toArray()[0] ?? []);
                $book->disconnectWorksheets();
            } catch (\PhpOffice\PhpSpreadsheet\Exception | \ValueError) {
                throw ValidationException::withMessages(['file' => __('finance.errors.file')]);
            }
        }

        return match (true) {
            in_array('退款单号', $headers, true) => 'refunds',
            in_array('结算批次号', $headers, true) => 'batches',
            in_array('平台返回code', $headers, true) || in_array('平台返回message', $headers, true) => 'orders',
            array_intersect(['平台订单号', '交易流水号', '交易 ID', '商户订单号'], $headers)
                && array_intersect(['支付状态', '状态'], $headers)
                && ! array_intersect(['结算金额', '保证金', '资金归属', ...array_keys(FinanceXuzongCreditMatches::STATEMENT_HEADERS)], $headers) => 'orders',
            default => null,
        };
    }

    public static function accountForRow(array $row): ?string
    {
        foreach (['交易流水号', '平台订单号', '交易单号', '商户订单号'] as $field) {
            if (str_starts_with(strtoupper($row[$field] ?? ''), 'TXN')) {
                return 'SHANGYUN-v2';
            }
        }
        if (FinanceXuzongCreditMatches::isLegacyOrder($row['平台订单号'] ?? '')
            || FinanceXuzongCreditMatches::isLegacyOrder($row['商户订单号'] ?? '')) {
            return 'SHANGYUN';
        }

        return match (strtolower(trim($row['商户名称'] ?? ''))) {
            'shangyun', '徐总1' => 'SHANGYUN',
            'shangyun-v2', '徐总2' => 'SHANGYUN-v2',
            default => null,
        };
    }

    public function import(UploadedFile $file, ?string $account, string $timezone, int $actor): array
    {
        $headers = [...array_values(FinanceXuzongCreditReport::headers()), ...array_keys(FinanceXuzongCreditReport::DISPLAY_HEADERS), '是否 3D', '交易单号', '交易时间',
            'Source（域名）', '发行国', '邮箱', ...FinanceXuzongCreditMatches::EXCHANGE_HEADERS];
        $batches = in_array(strtolower($file->getClientOriginalExtension()), ['csv', 'tsv', 'txt'], true)
            ? $this->csvBatches($file, $headers)
            : array_chunk($this->workbooks->read($file, $headers, optionalHeaders: $headers), 300, true);

        $total = $matched = $saved = 0;
        foreach ($batches as $rows) {
            $total += count($rows);
            foreach ($rows as &$row) {
                foreach (FinanceXuzongCreditReport::DISPLAY_HEADERS as $field => $label) {
                    $row[$field] = filled($row[$field] ?? null) ? $row[$field] : ($row[$label] ?? '');
                    unset($row[$label]);
                }
                foreach (['创建时间' => '交易时间', '域名' => 'Source（域名）', '是否3D' => '是否 3D', '发卡国家' => '发行国', '消费者邮箱' => '邮箱'] as $field => $alias) {
                    $row[$field] = ($row[$field] ?? '') ?: ($row[$alias] ?? '');
                    unset($row[$alias]);
                }
                if (str_starts_with(strtoupper($row['平台订单号'] ?? ''), 'TXN')) {
                    $row['交易流水号'] = ($row['交易流水号'] ?? '') ?: $row['平台订单号'];
                    $row['平台订单号'] = '';
                }
                if (FinanceSettlementService::transactionStatusCategory($row['支付状态'] ?? null) === 'failed') {
                    $row['支付状态'] = '失败';
                }
                $row['卡号'] = preg_match('/(\d{4})$/D', $row['卡号'] ?? '', $lastFour) ? '****'.$lastFour[1] : '';
            }
            unset($row);

            $linked = $this->matches($rows, $account);
            $byAccount = [];
            foreach ($rows as $line => &$row) {
                $order = $linked[$line] ?? null;
                if ($order !== null) {
                    $matched++;
                    $row['_linked_logistics_id'] = $order->id;
                    $row['_linked_platform_order_id'] = $order->reference;
                    if (FinanceSettlementService::isSuccessfulTransaction($row['支付状态'] ?? null)
                        && FinanceSettlementService::isSuccessfulTransaction($order->status)) {
                        $row['_replaced_logistics_id'] = $order->id;
                    }
                    $row['域名'] = $order->domain ?: ($row['域名'] ?? '');
                    $row['通途订单'] = $order->tongtu_order_number ?: ($row['通途订单'] ?? '');
                }
                if (blank($row['平台订单号'] ?? null)) {
                    $row['_platform_missing'] = true;
                    $row['平台订单号'] = ($row['交易流水号'] ?? '') ?: (($row['商户订单号'] ?? '') ?: ($row['交易单号'] ?? ''));
                }
                if (filled($row['完成时间'] ?? null)) {
                    try {
                        $row['完成时间'] = \Carbon\CarbonImmutable::parse($row['完成时间'], $timezone)->utc()->toDateTimeString();
                    } catch (\Exception) {
                        throw ValidationException::withMessages(['file' => '第'.$line.'行完成时间无效。']);
                    }
                }
                $reportAccount = $account ?? self::accountForRow($row) ?? $order?->account;
                if ($reportAccount === null) {
                    throw ValidationException::withMessages(['file' => '第'.$line.'行无法识别徐总信用卡账户，请核对商户名称或交易流水号。']);
                }
                $byAccount[$reportAccount][$line] = $row;
            }
            unset($row);

            foreach ($byAccount as $reportAccount => $accountRows) {
                $saved += $this->workbooks->importRecords(FinanceLogisticsCreditOrders::IMPORTED_DATASET,
                    $accountRows, $reportAccount, $timezone, $actor, createOnly: true, fillMissing: true);
            }
        }

        if ($total === 0) {
            throw ValidationException::withMessages(['file' => '订单文件没有可导入的记录。']);
        }

        return compact('total', 'matched', 'saved');
    }

    public function matches(array $rows, ?string $account = null): array
    {
        if ($rows === []) {
            return [];
        }
        $db = $this->workbooks->database();
        if (! $db->getSchemaBuilder()->hasTable('logistics_orders')) {
            return [];
        }
        $columns = array_intersect(self::REFERENCES, $db->getSchemaBuilder()->getColumnListing('logistics_orders'));
        if ($columns === []) {
            return [];
        }
        $candidates = $db->table('logistics_orders')->where(function ($query) use ($rows, $columns): void {
            foreach ($columns as $header => $column) {
                $query->orWhereIn($column, collect($rows)->pluck($header)->filter()->unique()->values());
            }
        })->get(['id', ...array_values($columns)])->keyBy('id');
        if ($candidates->isEmpty()) {
            return [];
        }
        $eligible = $this->creditOrders->query(withPayload: false, xuzongReport: true, withPayments: false, ids: $candidates->keys()->all(), includeReplaced: true)
            ->when($account !== null, fn ($query) => $query->where('account', $account))->get()->keyBy('id');
        $byField = [];
        foreach ($columns as $header => $column) {
            $byField[$header] = $candidates->filter(fn ($order) => $eligible->has($order->id))->groupBy($column);
        }

        $matches = [];
        foreach ($rows as $line => $row) {
            $rowAccount = $account ?? self::accountForRow($row);
            $ids = collect($byField)->flatMap(fn ($orders, $header) => filled($row[$header] ?? null)
                ? $orders->get($row[$header], collect())->pluck('id') : [])->unique()
                ->filter(fn ($id) => $rowAccount === null || $eligible->get($id)->account === $rowAccount)->values();
            if ($ids->count() === 1) {
                $id = $ids->first();
                $matches[$line] = $eligible->get($id);
                $matches[$line]->tongtu_order_number = $candidates->get($id)->tongtu_order_number ?? null;
            }
        }

        return $matches;
    }

    private function csvBatches(UploadedFile $file, array $allowed): \Generator
    {
        $stream = fopen($file->getPathname(), 'rb');
        $delimiter = $this->delimiter($file);
        try {
            $headers = $this->headers($stream, $delimiter);
            if (count($headers) !== count(array_unique($headers))) {
                throw ValidationException::withMessages(['file' => '订单文件存在重复表头。']);
            }
            $columns = array_intersect($headers, $allowed);
            $batch = [];
            for ($line = 2; ($cells = fgetcsv($stream, 0, $delimiter, '"', '')) !== false; $line++) {
                if ($cells === [null]) {
                    continue;
                }
                if (count($cells) !== count($headers)) {
                    throw ValidationException::withMessages(['file' => '第'.$line.'行的列数和表头不一致。']);
                }
                $row = [];
                foreach ($columns as $index => $name) {
                    $row[$name] = trim((string) $cells[$index]);
                }
                $batch[$line] = $row;
                if (count($batch) === 300) {
                    yield $batch;
                    $batch = [];
                }
            }
            if ($batch !== []) {
                yield $batch;
            }
        } finally {
            fclose($stream);
        }
    }

    private function headers($stream, string $delimiter): array
    {
        return array_map(fn ($header) => FinanceCatalog::simplified(trim(ltrim((string) $header, "\xEF\xBB\xBF"))),
            fgetcsv($stream, 0, $delimiter, '"', '') ?: []);
    }

    private function delimiter(UploadedFile $file): string
    {
        return strtolower($file->getClientOriginalExtension()) === 'tsv' ? "\t" : ',';
    }
}
