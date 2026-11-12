<?php

namespace App\Portals\Finance;

use App\Models\FinanceRecord;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

class FinanceXuzongOrderImport
{
    private const LEGACY_ORDER_SHEET = '徐总1数据';

    private const STATEMENT_AMOUNTS = [
        '交易本金' => '结算金额', '退款本金' => '退款金额', '拒付本金' => '拒付金额',
        '拒付处理费' => '拒付费用', '保证金扣除' => '保证金', '保证金返还' => '保证金返还',
        '拒付返还' => '拒付返还', '交易处理费' => '单笔费用', '交易手续费' => '交易手续费',
        '退款处理费' => '退款费用', '争议处理费' => '争议处理费',
        'Ethoca' => '拒付预警ethoca', 'RDR' => 'RDR费用', 'CDRN' => 'CDRN费用',
    ];

    private const STATEMENT_COLUMNS = ['平台订单号', '商户订单号', '结算币种', '适用汇率', '折算后结算贡献', '结算时间'];

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
                $legacySheet = $book->getSheetByName(self::LEGACY_ORDER_SHEET);
                $headers = array_map(fn ($header) => FinanceCatalog::simplified(trim((string) $header)),
                    ($legacySheet ?? $book->getActiveSheet())->rangeToArray('A1:CV1')[0]);
                $book->disconnectWorksheets();
                $legacyRequired = ['商户订单号', '创建时间', '交易币种', '交易金额', '支付状态'];
                $legacyOptional = ['消费者邮箱', '物流-元', '采购成本-元', '备注'];
                $legacyFormat = ! array_diff($legacyRequired, $headers)
                    && ! array_diff(array_filter($headers), [...$legacyRequired, ...$legacyOptional]);
                if ($legacyFormat || ($legacySheet !== null && ! array_diff($legacyRequired, $headers))) {
                    return 'legacy_orders';
                }
            } catch (\PhpOffice\PhpSpreadsheet\Exception | \ValueError) {
                throw ValidationException::withMessages(['file' => __('finance.errors.file')]);
            }
        }

        return match (true) {
            in_array('退款单号', $headers, true) => 'refunds',
            in_array('结算批次号', $headers, true) => 'batches',
            ! array_diff(['平台订单号', '折算后结算贡献', '适用汇率'], $headers) => 'official_statement',
            array_intersect([FinanceXuzongCreditReport::PLATFORM_CODE, FinanceXuzongCreditReport::PLATFORM_MESSAGE,
                FinanceXuzongCreditReport::PLATFORM_RESULT], $headers) => 'orders',
            array_intersect(['平台订单号', '交易流水号', '交易 ID', '商户订单号'], $headers)
                && array_intersect(['支付状态', '状态'], $headers)
                && ! array_intersect(['结算金额', '保证金', '资金归属', ...array_keys(FinanceXuzongCreditMatches::STATEMENT_HEADERS)], $headers) => 'orders',
            default => null,
        };
    }

    public function importOfficialStatement(UploadedFile $file, ?string $account): array
    {
        $account ??= 'SHANGYUN-v2';
        $headers = [...self::STATEMENT_COLUMNS, ...array_keys(self::STATEMENT_AMOUNTS)];
        $batches = in_array(strtolower($file->getClientOriginalExtension()), ['csv', 'tsv', 'txt'], true)
            ? $this->csvBatches($file, $headers)
            : [$this->workbooks->read($file, $headers)];
        $groups = [];
        $total = 0;
        $numbers = app(FinanceSettlementService::class);
        foreach ($batches as $rows) {
            foreach ($rows as $line => $row) {
                $reference = trim((string) ($row['平台订单号'] ?? ''));
                $merchant = trim((string) ($row['商户订单号'] ?? ''));
                $rate = $numbers->number($row['适用汇率'] ?? null);
                $net = $numbers->number($row['折算后结算贡献'] ?? null);
                $time = trim((string) ($row['结算时间'] ?? ''));
                if ($reference === '' || $merchant === '' || ($row['结算币种'] ?? '') !== 'USD'
                    || $rate === null || bccomp($rate, '0', 8) <= 0 || $net === null
                    || ! preg_match('/^\d{4}-(?:0[1-9]|1[0-2])-\d{2}\s\d{2}:\d{2}:\d{2}$/D', $time)) {
                    throw ValidationException::withMessages(['file' => '第'.$line.'行的订单号、结算币种、汇率、净额或结算时间无效。']);
                }
                $group = $groups[$reference] ?? ['merchant' => $merchant, 'events' => [], 'line' => $line];
                if ($group['merchant'] !== $merchant) {
                    throw ValidationException::withMessages(['file' => '第'.$line.'行同一平台订单号对应多个商户订单号。']);
                }
                $amounts = [];
                foreach (self::STATEMENT_AMOUNTS as $source => $field) {
                    $value = $numbers->number($row[$source] ?? null);
                    if ($value === null) {
                        throw ValidationException::withMessages(['file' => '第'.$line.'行的「'.$source.'」金额无效。']);
                    }
                    $sign = in_array($field, ['结算金额', '保证金返还', '拒付返还'], true) ? '1' : '-1';
                    $value = bcmul(bcmul($value, $rate, 12), $sign, 8);
                    $amounts[$field] = $value;
                }
                $amounts['应结算'] = bcadd($net, '0', 2);
                $event = ['time' => $time, 'amounts' => $amounts];
                $eventKey = $this->statementEventKey($event);
                $group['events'][$eventKey] = $event;
                $groups[$reference] = $group;
                $total++;
            }
        }
        if ($groups === []) {
            throw ValidationException::withMessages(['file' => '账单中没有可导入的流水。']);
        }

        $hash = hash_file('sha256', $file->getPathname());
        $saved = $this->workbooks->database()->transaction(function () use ($groups, $account, $hash): int {
            $saved = 0;
            foreach (array_chunk($groups, 300, true) as $batch) {
                $orders = FinanceRecord::query()->where('dataset', FinanceLogisticsCreditOrders::IMPORTED_DATASET)
                    ->where('account', $account)->whereIn('reference', array_keys($batch))
                    ->lockForUpdate()->get()->groupBy('reference');
                foreach ($batch as $reference => $group) {
                    $matches = $orders->get($reference, collect())->filter(fn ($order) =>
                        trim((string) ($order->payload['商户订单号'] ?? '')) === $group['merchant']);
                    if ($matches->count() !== 1) {
                        throw ValidationException::withMessages(['file' => '第'.$group['line'].'行无法匹配唯一订单，请先导入对应账户的逐笔订单。']);
                    }
                    $order = $matches->sole();
                    $payload = $order->payload;
                    $existing = $payload['_xuzong_statement_import'] ?? [];
                    $fields = array_fill_keys(self::statementAmountFields(), true);
                    if (! $existing && (filled($payload['结算金额'] ?? null) || filled($payload['应结算'] ?? null))) {
                        throw ValidationException::withMessages(['file' => '第'.$group['line'].'行已有未标记来源的结算数据，请先核对原账单。']);
                    }
                    $events = $existing['events'] ?? [];
                    $legacy = $this->legacyStatement($payload, $existing);
                    $incoming = array_diff_key($group['events'], $events, $existing['suppressed'] ?? []);
                    $restoringLegacy = $legacy !== null && $legacy['sha256'] === $hash;
                    if (! $restoringLegacy && $legacy !== null
                        && array_intersect($legacy['times'], array_column($incoming, 'time')) !== []) {
                        throw ValidationException::withMessages(['file' => '第'.$group['line'].'行与旧账单时间重叠，请先重传已入库的原账单以补齐逐笔明细。']);
                    }
                    if ($incoming === [] && ! $restoringLegacy) {
                        continue;
                    }
                    if (! $restoringLegacy && ($events !== [] || $legacy !== null || count($incoming) > 1)) {
                        $existing['notice'] = (string) \Illuminate\Support\Str::uuid();
                    }
                    $next = $this->statementPayload($payload, $existing, $events + $incoming, $restoringLegacy ? null : $legacy);
                    if (! $restoringLegacy || array_intersect_key($next, $fields) != array_intersect_key($payload, $fields)) {
                        $this->guardStatementDates($order, array_column($incoming, 'time'));
                    }
                    $order->update(['payload' => $next]);
                    $saved++;
                }
            }

            return $saved;
        });

        return ['total' => $total, 'matched' => count($groups), 'saved' => $saved];
    }

    public static function statementAmountFields(): array
    {
        return [...array_values(self::STATEMENT_AMOUNTS), '应结算'];
    }

    public const EDITABLE_ORDER_FIELDS = [
        '商户名称', '商户订单号', '平台订单号', '交易流水号', '订单币种', '订单金额', '交易币种',
        '交易金额', '支付状态', '支付方式', '是否3D', '发卡国家', '卡号', '消费者邮箱', '创建时间', '完成时间',
    ];

    public function manageStatement(int $recordId, array $data): void
    {
        $this->workbooks->database()->transaction(function () use ($recordId, $data): void {
            $order = FinanceRecord::query()->where('dataset', FinanceLogisticsCreditOrders::IMPORTED_DATASET)
                ->lockForUpdate()->findOrFail($recordId);
            $payload = $order->payload;
            $existing = $payload['_xuzong_statement_import'] ?? [];
            if ($data['action'] === 'edit_order') {
                $fields = $data['order_fields'];
                $this->guardStatementDates($order, []);
                $created = \Carbon\CarbonImmutable::parse($fields['创建时间'], 'UTC');
                app(FinanceAccountingService::class)->guardUnlocked($created->setTimezone('Asia/Shanghai')->format('Y-m'));
                $fields['卡号'] = preg_match('/(\d{4})$/D', $fields['卡号'] ?? '', $lastFour) ? '****'.$lastFour[1] : '';
                $platform = $fields['平台订单号'] ?? '';
                unset($fields['平台订单号']);
                $payload = array_replace($payload, array_map(fn ($value) => $value ?? '', $fields));
                $payload['_edited_platform_order_id'] = $platform;
                if (! empty($data['report_fields'])) {
                    $current = app(FinanceXuzongCreditReport::class)->orderValues($order->id);
                    foreach ($data['report_fields'] as $field => $value) {
                        if ((string) $value !== (string) ($current[$field] ?? '')) {
                            $payload['_edited_report_fields'][$field] = $value ?? '';
                        }
                    }
                }
                $order->update([
                    'payload' => $payload, 'occurred_at' => $created,
                    'currency' => strtoupper($fields['交易币种']), 'status' => $fields['支付状态'],
                ]);

                return;
            }
            if ($data['action'] === 'handled') {
                if (($existing['notice'] ?? null) !== $data['notice']) {
                    throw ValidationException::withMessages(['statement' => '结算明细刚有更新，请刷新后再确认。']);
                }
                unset($existing['notice']);
                $payload['_xuzong_statement_import'] = $existing;
                $order->update(['payload' => $payload]);

                return;
            }

            if (! $existing && (filled($payload['结算金额'] ?? null) || filled($payload['应结算'] ?? null))) {
                throw ValidationException::withMessages(['statement' => '该订单已有未标记来源的结算数据，请先核对原账单。']);
            }
            $events = $existing['events'] ?? [];
            $legacy = $this->legacyStatement($payload, $existing);
            $key = $data['event'] ?? null;
            $original = $key === null ? null : ($events[$key] ?? null);
            if (in_array($data['action'], ['update', 'delete'], true) && $original === null) {
                throw ValidationException::withMessages(['statement' => '该结算明细已变化，请刷新后重试。']);
            }

            $replacement = null;
            if ($data['action'] !== 'delete') {
                $amounts = [];
                foreach (self::statementAmountFields() as $field) {
                    $amounts[$field] = bcadd((string) ($data['amounts'][$field] ?? '0'), '0', $field === '应结算' ? 2 : 8);
                }
                if (in_array($data['time'], $legacy['times'] ?? [], true)) {
                    throw ValidationException::withMessages(['statement' => '该结算时间已有历史汇总，请先重新导入原结算文件。']);
                }
                $replacement = ['time' => $data['time'], 'amounts' => $amounts];
            }
            $dates = array_filter([$original['time'] ?? null, $replacement['time'] ?? null]);
            $this->guardStatementDates($order, $dates);
            if ($original !== null) {
                unset($events[$key]);
                $existing['suppressed'][$this->statementEventKey($original)] = true;
            }
            if ($replacement !== null) {
                $newKey = $this->statementEventKey($replacement);
                if (isset($events[$newKey])) {
                    throw ValidationException::withMessages(['statement' => '已有相同的逐笔结算明细。']);
                }
                $events[$newKey] = $replacement;
                unset($existing['suppressed'][$newKey]);
            }

            $existing['notice'] = (string) \Illuminate\Support\Str::uuid();
            $order->update(['payload' => $this->statementPayload($payload, $existing, $events, $legacy)]);
        });
    }

    private function statementEventKey(array $event): string
    {
        return hash('sha256', json_encode($event, JSON_THROW_ON_ERROR));
    }

    private function legacyStatement(array $payload, array $existing): ?array
    {
        if ($existing === [] || array_key_exists('events', $existing)) {
            return $existing['legacy'] ?? null;
        }

        return [
            'sha256' => $existing['sha256'], 'times' => $existing['settlement_times'],
            'lines' => $existing['lines'], 'amounts' => array_intersect_key($payload, array_fill_keys(self::statementAmountFields(), true)),
        ];
    }

    private function statementPayload(array $payload, array $existing, array $events, ?array $legacy): array
    {
        $amounts = $legacy['amounts'] ?? [];
        foreach ($events as $event) {
            foreach ($event['amounts'] as $field => $value) {
                $amounts[$field] = bcadd($amounts[$field] ?? '0', $value, $field === '应结算' ? 2 : 8);
            }
        }
        $priorRefund = $existing['prior_refund'] ?? array_intersect_key($payload, array_flip(['退款金额', '退款费用']));
        if ($amounts === []) {
            $payload = array_diff_key($payload, array_fill_keys([...self::statementAmountFields(), '结算币种'], true));
            $amounts = $priorRefund;
        } else {
            $payload['结算币种'] = 'USD';
        }

        return [...$payload, ...$amounts, '_xuzong_statement_import' => [
            ...array_intersect_key($existing, array_flip(['notice', 'suppressed'])),
            'events' => $events, 'legacy' => $legacy, 'prior_refund' => $priorRefund,
        ]];
    }

    private function guardStatementDates(FinanceRecord $order, array $dates): void
    {
        $accounting = app(FinanceAccountingService::class);
        $months = [$order->occurred_at->setTimezone('Asia/Shanghai')->format('Y-m')];
        foreach ($dates as $time) {
            $months[] = substr($time, 0, 7);
        }
        foreach (array_unique($months) as $month) {
            $accounting->guardUnlocked($month);
        }
    }

    public function importRefunds(UploadedFile $file, ?string $account): array
    {
        $account ??= 'SHANGYUN-v2';
        $headers = ['退款单号', '平台订单号', '商户订单号', '退款金额', '退款币种', '退款处理费', '退款完成时间', '状态'];
        $batches = in_array(strtolower($file->getClientOriginalExtension()), ['csv', 'tsv', 'txt'], true)
            ? $this->csvBatches($file, $headers) : [$this->workbooks->read($file, $headers)];
        $numbers = app(FinanceSettlementService::class);
        $refunds = [];
        $total = 0;
        foreach ($batches as $rows) {
            foreach ($rows as $line => $row) {
                $total++;
                if (strtoupper((string) ($row['状态'] ?? '')) !== 'SUCCESS') {
                    continue;
                }
                $id = (string) ($row['退款单号'] ?? '');
                $reference = (string) ($row['平台订单号'] ?? '');
                $merchant = (string) ($row['商户订单号'] ?? '');
                $amount = $numbers->number($row['退款金额'] ?? null);
                $fee = $numbers->number($row['退款处理费'] ?? null);
                $currency = (string) ($row['退款币种'] ?? '');
                if ($id === '' || $reference === '' || $merchant === '' || $currency !== 'USD'
                    || $amount === null || $fee === null || bccomp($amount, '0', 8) < 0 || bccomp($fee, '0', 8) < 0) {
                    throw ValidationException::withMessages(['file' => '第'.$line.'行的退款单号、订单号、币种或金额无效。']);
                }
                $refund = ['refund_id' => $id, 'currency' => $currency, 'completed_at' => $row['退款完成时间'] ?? '',
                    'amount' => bcadd($amount, '0', 8), 'fee' => bcadd($fee, '0', 8)];
                $entry = compact('reference', 'merchant', 'refund');
                if (isset($refunds[$id]) && $refunds[$id] !== $entry) {
                    throw ValidationException::withMessages(['file' => '第'.$line.'行与同退款单号的记录不一致。']);
                }
                $refunds[$id] = $entry;
            }
        }
        $groups = collect($refunds)->groupBy('reference');
        $saved = $this->workbooks->database()->transaction(function () use ($groups, $account): int {
            $accounting = app(FinanceAccountingService::class);
            $saved = 0;
            foreach ($groups->chunk(300) as $batch) {
                $orders = FinanceRecord::query()->where('dataset', FinanceLogisticsCreditOrders::IMPORTED_DATASET)
                    ->where('account', $account)->whereIn('reference', $batch->keys()->all())
                    ->lockForUpdate()->get()->groupBy('reference');
                foreach ($batch as $reference => $entries) {
                    $matches = $orders->get($reference, collect())->filter(fn ($order) => $entries->every(fn ($entry) =>
                        (string) ($order->payload['商户订单号'] ?? '') === $entry['merchant']));
                    if ($matches->count() !== 1) {
                        throw ValidationException::withMessages(['file' => '退款订单 '.$reference.' 无法唯一匹配，请先导入对应账户的逐笔订单。']);
                    }
                    $order = $matches->sole();
                    $payload = $order->payload;
                    $stored = $payload['_xuzong_refunds'] ?? [];
                    $legacy = $payload['_xuzong_refund_import']['refund_id'] ?? null;
                    foreach ($entries as $entry) {
                        $refund = $entry['refund'];
                        $id = $refund['refund_id'];
                        if (isset($stored[$id]) && $stored[$id] != $refund) {
                            throw ValidationException::withMessages(['file' => '退款单 '.$id.' 与已导入的记录不一致。']);
                        }
                        $stored[$id] = $refund;
                    }
                    if ($legacy !== null && ! isset($stored[$legacy])) {
                        throw ValidationException::withMessages(['file' => '订单 '.$reference.' 请同时提供先前导入的退款记录。']);
                    }
                    if ($stored == ($payload['_xuzong_refunds'] ?? [])) {
                        continue;
                    }
                    $accounting->guardUnlocked($order->occurred_at->setTimezone('Asia/Shanghai')->format('Y-m'));
                    $amount = $fee = '0';
                    foreach ($stored as $refund) {
                        $amount = bcadd($amount, $refund['amount'], 8);
                        $fee = bcadd($fee, $refund['fee'], 8);
                    }
                    $payload['_xuzong_refunds'] = $stored;
                    $statement = $payload['_xuzong_statement_import'] ?? [];
                    if (array_key_exists('events', $statement)) {
                        $payload['_xuzong_statement_import']['prior_refund'] = ['退款金额' => $amount, '退款费用' => $fee];
                    }
                    if (blank($payload['结算币种'] ?? null)
                        && blank($payload['结算金额'] ?? null) && blank($payload['应结算'] ?? null)) {
                        $payload['退款金额'] = $amount;
                        $payload['退款费用'] = $fee;
                    }
                    $order->update(['payload' => $payload]);
                    $saved++;
                }
            }

            return $saved;
        });

        return ['total' => $total, 'matched' => $groups->count(), 'saved' => $saved];
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

    public function import(UploadedFile $file, ?string $account, string $timezone, int $actor, bool $legacySheet = false): array
    {
        if (! $legacySheet) {
            return $this->importOrderSheet($file, $account, $timezone, $actor);
        }

        $result = $this->importOrderSheet($file, 'SHANGYUN', $timezone, $actor, self::LEGACY_ORDER_SHEET);
        $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReaderForFile($file->getPathname());
        foreach ($reader->listWorksheetNames($file->getPathname()) as $sheet) {
            if (! in_array(trim($sheet), ['徐总2失败订单', '徐总2数据'], true)) {
                continue;
            }

            $second = $this->importOrderSheet($file, 'SHANGYUN-v2', $timezone, $actor, $sheet);
            foreach ($result as $key => $count) {
                $result[$key] = $count + $second[$key];
            }
        }

        return $result;
    }

    private function importOrderSheet(UploadedFile $file, ?string $account, string $timezone, int $actor, ?string $sheet = null): array
    {
        $headers = [...array_values(FinanceXuzongCreditReport::headers()), ...array_keys(FinanceXuzongCreditReport::DISPLAY_HEADERS),
            FinanceXuzongCreditReport::PLATFORM_CODE, FinanceXuzongCreditReport::PLATFORM_MESSAGE, '是否 3D', '交易单号', '交易时间',
            'Source（域名）', '发行国', '邮箱', '退款', ...FinanceXuzongCreditMatches::EXCHANGE_HEADERS];
        $batches = in_array(strtolower($file->getClientOriginalExtension()), ['csv', 'tsv', 'txt'], true)
            ? $this->csvBatches($file, $headers)
            : array_chunk($this->workbooks->read($file, $headers, $sheet, $headers), 300, true);

        $total = $matched = $saved = 0;
        foreach ($batches as $rows) {
            $total += count($rows);
            foreach ($rows as &$row) {
                if (filled($row['原始支付状态'] ?? null)) {
                    $row['支付状态'] = $row['原始支付状态'];
                }
                unset($row['原始支付状态']);
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
                $row['_original_payment_status'] = $row['支付状态'] ?? '';
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
        $candidates = collect();
        foreach ($columns as $header => $column) {
            $references = array_values(array_unique(array_filter(array_column($rows, $header))));
            if ($references !== []) {
                $candidates = $candidates->union($db->table('logistics_orders')->whereIn($column, $references)
                    ->get(['id', ...array_values($columns)])->keyBy('id'));
            }
        }
        if ($candidates->isEmpty()) {
            return [];
        }
        $eligible = $this->creditOrders->query(withPayload: false, xuzongReport: true, withPayments: false, ids: $candidates->keys()->all(), includeReplaced: true, includeSystemOrders: true)
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
