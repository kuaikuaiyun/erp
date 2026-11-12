<?php

namespace App\Portals\Finance;

use App\Models\FinancePaymentAccount;
use App\Models\FinanceRecord;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date;

class FinanceConfluxSettlement
{
    public const TABLE = 'finance_credit_settlement_entries';
    public const EXCEPTION_COLUMNS = ['ledger_dates' => '账单日期', 'ledger_exception_dates' => '新增异常账单日期', 'ledger_exceptions' => '异常原因'];

    private const SHEETS = [
        '结算统计' => ['summary', '结算日期', '商户号', '结算金额'],
        '成功交易' => ['sale', '流水订单号', '结算货币', '结算金额'],
        '失败交易' => ['failed', '流水订单号', '交易状态'],
        '退款' => ['refund', '支付订单号', '退款订单号', '结算币种', '结算金额'],
        'RDR退款' => ['rdr', '流水订单号', '拒付预警时间', '结算币种', '结算金额'],
        '拒付' => ['chargeback', '流水订单号', '拒付时间', '结算币种', '结算金额'],
        'Ethoca' => ['ethoca', '流水订单号', '拒付预警时间', '币种', '金额'],
        'YY生态代收' => ['yy', '流水订单号', '结算货币', '结算金额'],
        'Shoplazza生态代收' => ['shoplazza', '流水订单号', '结算货币', '结算金额'],
        'Shopline生态代收' => ['shopline', '流水订单号', '结算货币', '结算金额'],
        '释放保证金' => ['release', '流水订单号', '保证金币种', '释放保证金'],
    ];

    private const AMOUNTS = [
        'sale' => ['settlement', '结算货币', '结算金额'],
        'refund' => ['refund', '结算币种', '结算金额'],
        'rdr' => ['rdr_refund', '结算币种', '结算金额'],
        'chargeback' => ['chargeback', '结算币种', '结算金额'],
        'ethoca' => ['ethoca_refund', '币种', '金额'],
        'release' => ['release', '保证金币种', '释放保证金'],
    ];

    private const EVENT_FEES = [
        'refund' => ['refund_fee', '退款处理费', '2.00', '2.00'],
        'chargeback' => ['chargeback_fee', '拒付处理费', '25.00', '19.00'],
        'rdr' => ['rdr_fee', 'RDR处理费', '15.00', '12.00'],
        'ethoca' => ['ethoca_fee', 'Ethoca处理费', '19.00', '15.00'],
    ];

    public function __construct(private readonly FinanceWorkbookService $workbooks) {}

    public function isStatement(UploadedFile $file): bool
    {
        if (strtolower($file->getClientOriginalExtension()) !== 'xlsx') {
            return false;
        }
        $reader = IOFactory::createReader(IOFactory::READER_XLSX);
        if (! $reader->canRead($file->getRealPath())) {
            throw new \PhpOffice\PhpSpreadsheet\Reader\Exception('Invalid XLSX workbook.');
        }

        foreach ($reader->listWorksheetNames($file->getRealPath()) as $name) {
            if (isset(self::SHEETS[explode('-', $name, 2)[0]])) {
                return true;
            }
        }

        return false;
    }

    public function read(UploadedFile $file, ?string $settlementType = null): array
    {
        try {
            $reader = IOFactory::createReader(IOFactory::READER_XLSX);
            $reader->setReadDataOnly(true);
            $book = $reader->load($file->getRealPath());
        } catch (\PhpOffice\PhpSpreadsheet\Exception $e) {
            throw ValidationException::withMessages(['file' => '请上传 ConfluxAPI 导出的 XLSX 结算报表。']);
        }
        $dates = [];
        $merchants = [];
        $entries = [];
        try {
            foreach ($book->getWorksheetIterator() as $sheet) {
                $title = $sheet->getTitle();
                [$prefix, $merchant] = array_pad(explode('-', $title, 2), 2, '');
                ['row' => $lastRow, 'column' => $lastColumn] = $sheet->getHighestRowAndColumn();
                if (! isset(self::SHEETS[$prefix])) {
                    if ($lastRow > 1) {
                        throw ValidationException::withMessages(['file' => '无法识别结算工作表：'.$title]);
                    }
                    continue;
                }
                [$kind, $referenceHeader] = self::SHEETS[$prefix];
                if ($kind === 'release' && $sheet->getHighestDataRow() === 1) {
                    continue;
                }
                if ($merchant !== '') {
                    $merchants[mb_strtolower($merchant)] = true;
                }
                $width = Coordinate::columnIndexFromString($lastColumn);
                if ($lastRow > 10001 || $width > 100) {
                    throw ValidationException::withMessages(['file' => '结算报表每张工作表最多 10,000 行、100 列。']);
                }
                $headers = [];
                for ($column = 1; $column <= $width; $column++) {
                    $header = trim((string) $sheet->getCell([$column, 1])->getValue());
                    if ($header !== '') {
                        if (in_array($header, $headers, true)) {
                            throw ValidationException::withMessages(['file' => $title.' 中存在重复表头：'.$header]);
                        }
                        $headers[$column] = $header;
                    }
                }
                foreach (array_slice(self::SHEETS[$prefix], 1) as $header) {
                    if (! in_array($header, $headers, true)) {
                        throw ValidationException::withMessages(['file' => $title.' 缺少表头：'.$header]);
                    }
                }
                for ($row = 2; $row <= $lastRow; $row++) {
                    $payload = [];
                    foreach ($headers as $column => $header) {
                        $cell = $sheet->getCell([$column, $row]);
                        if ($cell->isFormula()) {
                            throw ValidationException::withMessages(['file' => $title.' '.trans('finance.errors.formula', ['row' => $row])]);
                        }
                        $value = trim((string) $cell->getValue());
                        if ($value !== '') {
                            $payload[$header] = $value;
                        }
                    }
                    if ($payload === []) {
                        continue;
                    }
                    $reference = $payload[$referenceHeader] ?? '';
                    if ($kind === 'summary') {
                        if (in_array($reference, ['合计', '总计'], true)) {
                            continue;
                        }
                        $date = $this->date($reference);
                        if ($date === null) {
                            throw ValidationException::withMessages(['file' => $title.' 第 '.$row.' 行结算日期无效。']);
                        }
                        $dates[$date] = true;
                        $payload[$referenceHeader] = $date;
                        $reference = null;
                    } elseif ($reference === '' || mb_strlen($reference) > 191) {
                        throw ValidationException::withMessages(['file' => $title.' 第 '.$row.' 行缺少有效的原交易单号。']);
                    }
                    if (isset(self::AMOUNTS[$kind])) {
                        [, $currency, $amount] = self::AMOUNTS[$kind];
                        if (! preg_match('/^[A-Z]{3}$/iD', $payload[$currency] ?? '')
                            || ! preg_match('/^-?\d{1,18}(?:\.\d{1,8})?$/D', $payload[$amount] ?? '')) {
                            throw ValidationException::withMessages(['file' => $title.' 第 '.$row.' 行结算金额或币种无效。']);
                        }
                    }
                    ksort($payload);
                    $entries[] = ['reference' => $reference, 'kind' => $kind, 'source_sheet' => $title, 'source_row' => $row,
                        'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)];
                }
            }
        } finally {
            $book->disconnectWorksheets();
        }
        if (count($dates) !== 1 || count($merchants) !== 1
            || ! collect($entries)->contains(fn ($entry) => $entry['reference'] !== null)) {
            throw ValidationException::withMessages(['file' => '请上传包含一个商户、一个结算日期及交易明细的完整 ConfluxAPI 结算报表。']);
        }
        $hashes = array_map(fn ($entry) => hash('sha256', json_encode([
            $entry['kind'], $entry['source_sheet'], $entry['payload'],
        ], JSON_THROW_ON_ERROR)), $entries);
        sort($hashes, SORT_STRING);


        return ['date' => array_key_first($dates), 'merchant' => (string) array_key_first($merchants),
            'hash' => hash('sha256', implode('', $hashes)), 'entries' => $entries, 'settlement_type' => $settlementType];
    }

    public function findOrCreateStatementAccount(Collection $accounts, array $statement, int $actor): FinancePaymentAccount
    {
        $key = $statement['merchant'];
        if (! preg_match('/^[a-z0-9._-]{1,100}$/D', $key)) {
            throw ValidationException::withMessages(['file' => '账单工作表中的商户标识无效。']);
        }

        if ($accounts->contains(fn (FinancePaymentAccount $account) => strcasecmp((string) $account->card_merchant_key, $key) === 0)) {
            return $this->resolveAccount($accounts, $key);
        }

        $currencies = collect($statement['entries'])->map(function (array $entry): ?string {
            $columns = self::AMOUNTS[$entry['kind']] ?? null;
            $payload = json_decode($entry['payload'], true, 512, JSON_THROW_ON_ERROR);

            return $columns === null ? null : strtoupper($payload[$columns[1]] ?? '');
        })->filter()->unique()->values();

        return $this->createAccount($key, $currencies->count() === 1 ? $currencies->first() : 'XXX', $actor);
    }

    private function createAccount(string $key, string $currency, int $actor): FinancePaymentAccount
    {
        $name = FinancePaymentAccount::query()->withTrashed()->where('account_type', 'credit_card')
            ->where(fn ($query) => $query->where('account_name', $key)->orWhere('activity_account', $key));
        if ($name->exists()) {
            throw ValidationException::withMessages(['file' => '商户「'.$key.'」与已有账户重名，请核对账户的商户标识。']);
        }

        return FinancePaymentAccount::query()->create([
            'account_code' => 'FA-'.\Illuminate\Support\Str::ulid(),
            'account_name' => $key, 'activity_account' => $key, 'account_type' => 'credit_card',
            'card_platform' => 'ye', 'card_merchant_key' => $key,
            'main_currency' => $currency,
            'status' => 'enabled', 'created_by' => $actor, 'updated_by' => $actor,
        ]);
    }

    public function resolveAccount(Collection $accounts, string $identifier, bool $website = false, ?int $actor = null): FinancePaymentAccount
    {
        $value = mb_strtolower(trim($identifier));
        $matches = $accounts->filter(function (FinancePaymentAccount $account) use ($value, $website): bool {
            $key = mb_strtolower(trim((string) $account->card_merchant_key));

            return $key !== '' && ($value === $key
                || ($website && preg_match('/(?:^|-)'.preg_quote($key, '/').'(?:-|$)/u', $value) === 1));
        });
        if ($matches->isEmpty() && $website) {
            $matches = $accounts->filter(function (FinancePaymentAccount $account) use ($value): bool {
                $key = mb_strtolower(trim((string) $account->card_merchant_key));

                return $key !== '' && preg_match('/(?:^|-)'.preg_quote($key, '/').'[0-9]+(?:-|$)/u', $value) === 1;
            });
        }
        if ($matches->isEmpty() && $website && $actor !== null
            && preg_match('/^[^-]+-([a-z0-9._]{3,100})$/uD', $value, $parts) === 1) {
            $account = $this->createAccount($parts[1], 'XXX', $actor);
            $accounts->push($account);

            return $account;
        }
        if ($matches->count() !== 1) {
            $field = $website ? '网站名称' : '商户标识';
            throw ValidationException::withMessages(['file' => $field.'「'.($identifier ?: '空白').'」无法唯一匹配 Conflux 账户，网站名称无法确认归属时，可先单独导入对应的结算账单。']);
        }

        return $matches->first();
    }

    public function import(array $report, string $accountKey, int $actor, ?int $groupId = null): array
    {
        $db = $this->workbooks->database();

        return $db->transaction(function () use ($db, $report, $accountKey, $actor, $groupId): array {
            $account = FinancePaymentAccount::query()->where('account_type', 'credit_card')
                ->where('activity_account', $accountKey)->lockForUpdate()->first();
            if (! $account || $account->card_platform !== 'ye') {
                throw ValidationException::withMessages(['account' => '请选择 ConfluxAPI 支付账户。']);
            }
            if ($groupId !== null && (int) $account->card_group_id !== $groupId) {
                throw ValidationException::withMessages(['account' => '所选账户不属于当前归属主体。']);
            }
            if (blank($account->card_merchant_key) || mb_strtolower($account->card_merchant_key) !== $report['merchant']) {
                throw ValidationException::withMessages(['account' => '所选商户的标识与报表不一致。请在财务工作台核对 ConfluxAPI 商户标识。']);
            }
            $batch = ['payment_account_id' => $account->id, 'platform' => 'ye', 'settlement_date' => $report['date']];
            $query = $db->table(self::TABLE)->where($batch);
            $sameReport = (clone $query)->where('report_hash', $report['hash']);
            $alreadyImported = $sameReport->exists();
            if ($alreadyImported && isset($report['settlement_type'])) {
                $summary = (clone $sameReport)->where('kind', 'summary')->value('payload');
                $previousType = json_decode($summary ?? '{}', true, 512, JSON_THROW_ON_ERROR)['_settlement_type'] ?? null;
                if ($previousType !== null && $previousType !== $report['settlement_type']) {
                    throw ValidationException::withMessages(['file' => '这份账单已按「'.$previousType.'」导入，请核对所选结算类型。']);
                }
            }
            if (! $alreadyImported) {
                $existingSummaries = (clone $query)->where('kind', 'summary')->pluck('payload')
                    ->map(fn ($payload) => json_decode($payload, true, 512, JSON_THROW_ON_ERROR));
                $incomingSummaries = collect($report['entries'])->where('kind', 'summary')->pluck('payload')
                    ->map(fn ($payload) => json_decode($payload, true, 512, JSON_THROW_ON_ERROR));
                $descriptors = static fn (Collection $summaries) => $summaries
                    ->map(fn ($summary) => mb_strtolower(trim((string) ($summary['账单描述符'] ?? ''))));
                if ($existingSummaries->isNotEmpty()) {
                    $existing = $descriptors($existingSummaries);
                    $incoming = $descriptors($incomingSummaries);
                    if ($existing->contains('') || $incoming->isEmpty() || $incoming->contains('')
                        || $existing->intersect($incoming)->isNotEmpty()) {
                        throw ValidationException::withMessages(['file' => '此账户在该结算日期已有内容不同的报表，请核对账单描述符及原账单后再导入。']);
                    }
                }
                app(FinanceAccountingService::class)->guardUnlocked(substr($report['date'], 0, 7));
                $stamp = now();
                foreach (array_chunk($report['entries'], 80) as $entries) {
                    $db->table(self::TABLE)->insert(array_map(function (array $entry) use ($batch, $report, $actor, $stamp): array {
                        if (isset($report['settlement_type'])) {
                            $payload = json_decode($entry['payload'], true, 512, JSON_THROW_ON_ERROR);
                            $payload['_settlement_type'] = $report['settlement_type'];
                            $entry['payload'] = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
                        }

                        return [...$batch, ...$entry, 'report_hash' => $report['hash'], 'imported_by' => $actor, 'created_at' => $stamp];
                    }, $entries));
                }
            }
            $references = $sameReport->whereNotNull('reference')->distinct()->pluck('reference');
            $matched = 0;
            foreach ($references->chunk(500) as $chunk) {
                $matched += $this->orders($accountKey)->whereIn('reference', $chunk)
                    ->groupBy('reference')->havingRaw('COUNT(*) = 1')->pluck('reference')->count();
            }

            return ['inserted' => $alreadyImported ? 0 : count($report['entries']),
                'unmatched' => $references->count() - $matched];
        });
    }

    public function entriesFor(Collection $records, Collection $accounts): Collection
    {
        $entries = collect();
        foreach ($records->where('payload._card_channel', 'ye')->groupBy('account') as $key => $orders) {
            $account = $accounts->get($key);
            if (! $account) {
                continue;
            }
            $byReference = $orders->groupBy('reference');
            foreach ($byReference->keys()->map(fn ($reference) => (string) $reference)->chunk(500) as $references) {
                $unique = $this->orders($key)->whereIn('reference', $references)->select('reference')
                    ->groupBy('reference')->havingRaw('COUNT(*) = 1');
                $items = $this->workbooks->database()->table(self::TABLE)
                    ->where('platform', 'ye')->where('payment_account_id', $account->id)
                    ->whereIn('reference', $unique)->orderBy('settlement_date')->orderBy('id')->get()->groupBy('reference');
                foreach ($items as $reference => $item) {
                    $entries->put($byReference->get($reference)->first()->id, $item);
                }
            }
        }

        return $entries;
    }

    public function filterOrderMatch(\Illuminate\Database\Eloquent\Builder $query, bool $matched): void
    {
        $uniqueOrder = $this->orders(table: 'matched_order')->selectRaw('COUNT(*)')
            ->whereColumn('matched_order.account', 'finance_records.account')
            ->whereColumn('matched_order.reference', 'finance_records.reference');
        $ledger = $this->workbooks->database()->table('finance_payment_accounts as matched_account')
            ->where('matched_account.account_type', 'credit_card')->whereNull('matched_account.deleted_at')
            ->whereColumn('matched_account.activity_account', 'finance_records.account')
            ->where('finance_records.dataset', 'xuzong2-details')->where('finance_records.payload->_card_channel', 'ye')
            ->whereExists(function ($entries): void {
                $entries->selectRaw('1')->from(self::TABLE.' as matched_entry')
                    ->where('matched_entry.platform', 'ye')
                    ->whereColumn('matched_entry.payment_account_id', 'matched_account.id')
                    ->whereColumn('matched_entry.reference', 'finance_records.reference');
            })
            ->where($uniqueOrder->toBase(), '=', 1);
        $query->whereExists($ledger, not: ! $matched);
    }

    public function feesFor(Collection $matchedEntries, ?\ArrayObject $reportFees = null): array
    {
        $fees = [];
        $batches = $matchedEntries->flatten(1)->groupBy(fn ($entry) => $entry->payment_account_id.'|'.$entry->report_hash);
        $reportFees ??= new \ArrayObject;
        foreach ($batches as $key => $entries) {
            $report = $entries->first();
            $wanted = $entries->pluck('id')->flip()->all();
            if (! $reportFees->offsetExists($key)) {
                $bill = $this->workbooks->database()->table(self::TABLE)->where([
                    'payment_account_id' => $report->payment_account_id, 'settlement_date' => $report->settlement_date,
                    'platform' => 'ye', 'report_hash' => $report->report_hash,
                ])->whereIn('kind', ['summary', 'sale', 'failed', ...array_keys(self::EVENT_FEES)])->get(['id', 'kind', 'payload']);
                if ($reportFees->count() >= 8) {
                    unset($reportFees[array_key_first($reportFees->getArrayCopy())]);
                }
                $reportFees[$key] = $this->statementFees($bill, $report->settlement_date);
            }
            $fees += array_intersect_key($reportFees[$key], $wanted);
        }

        return $fees;
    }

    private function statementFees(Collection $bill, string $settlementDate): array
    {
        $entries = $bill->map(function ($entry) {
            $payload = json_decode($entry->payload, true, 512, JSON_THROW_ON_ERROR);
            $merchant = trim($payload['商户号'] ?? '');
            $descriptor = trim($payload['账单描述符'] ?? '');
            $entry->merchant_descriptor = $merchant !== '' && $descriptor !== '' ? $merchant.'|'.$descriptor : null;
            $tradeTime = $payload[$entry->kind === 'summary' ? '交易日期' : '交易时间'] ?? '';
            $entry->trade_date = $this->date(is_numeric($tradeTime) ? $tradeTime : substr($tradeTime, 0, 10));
            $entry->data = $payload;

            return $entry;
        });
        $sales = $entries->where('kind', 'sale');
        $currencies = $sales->map(fn ($entry) => strtoupper($entry->data['结算货币']))->unique();
        $currency = $currencies->count() === 1 ? $currencies->first() : null;
        if (! in_array($currency, ['USD', 'GBP'], true)) {
            return [];
        }
        $fees = [];
        foreach ($sales as $entry) {
            $amount = $entry->data['结算金额'];
            $fees[$entry->id] = [
                'hold' => $this->roundMoney(bcmul($amount, '0.10', 10)),
                'fee' => $this->roundMoney(bcmul($amount, $currency === 'USD' ? '0.05' : '0.06', 10)),
            ];
        }
        foreach ($entries->whereNotNull('merchant_descriptor')->groupBy('merchant_descriptor') as $merchantEntries) {
            $summaries = $merchantEntries->where('kind', 'summary');
            foreach ($merchantEntries->whereNotNull('trade_date')->groupBy('trade_date') as $dayEntries) {
                $summary = $dayEntries->where('kind', 'summary');
                if ($summary->count() !== 1) {
                    continue;
                }
                $isWallet = stripos($summary->first()->data['账单描述符'], 'wallet') !== false;
                $gatewayFee = $currency === 'USD' && $settlementDate >= '2026-10-13' && $isWallet ? '0.35' : '0.30';
                foreach (['sale' => '成功网关费', 'failed' => '失败网关费'] as $kind => $column) {
                    $this->addVerifiedFees($fees, $dayEntries->where('kind', $kind), $summary, 'per_order_fee', $column, $gatewayFee);
                }
            }
            foreach (self::EVENT_FEES as $kind => [$field, $column, $usdRate, $gbpRate]) {
                // Event fees are booked in the statement period, even for older transactions.
                $this->addVerifiedFees($fees, $merchantEntries->where('kind', $kind), $summaries, $field, $column,
                    $currency === 'USD' ? $usdRate : $gbpRate);
            }
        }
        foreach ($fees as &$values) {
            $values['currency'] = $currency;
        }
        unset($values);

        return $fees;
    }

    private function roundMoney(string $amount): string
    {
        return bcadd($amount, bccomp($amount, '0', 10) < 0 ? '-0.005' : '0.005', 2);
    }

    private function addVerifiedFees(array &$fees, Collection $entries, Collection $summaries, string $field, string $column, string $rate): void
    {
        if ($entries->isEmpty() || $summaries->isEmpty()) {
            return;
        }
        $total = '0';
        foreach ($summaries as $summary) {
            $amount = $summary->data[$column] ?? '';
            if (! preg_match('/^-?\d{1,18}(?:\.\d{1,8})?$/D', $amount) || bccomp($amount, '0', 8) > 0) {
                return;
            }
            $total = bcsub($total, $amount, 8);
        }
        if (bccomp($total, bcmul((string) $entries->count(), $rate, 8), 8) === 0) {
            foreach ($entries as $entry) {
                $fees[$entry->id][$field] = $rate;
            }
        }
    }

    public function details(Collection $entries, array $fees = []): array
    {
        $byType = $entries->groupBy('kind');
        $values = [];
        $currencies = [];
        $currencyDates = [];
        $amounts = [];
        foreach (self::AMOUNTS as $kind => [$field, $currencyField, $amountField]) {
            foreach ($byType->get($kind, []) as $entry) {
                $payload = json_decode($entry->payload, true, 512, JSON_THROW_ON_ERROR);
                $currency = strtoupper($payload[$currencyField] ?? '');
                $amount = $payload[$amountField] ?? '';
                $currencies[$currency] = true;
                $currencyDates[$currency][] = $entry->settlement_date;
                $amounts[$field] = bcadd($amounts[$field] ?? '0', $amount, 8);
            }
        }
        foreach ($entries as $entry) {
            $entryFees = $fees[$entry->id] ?? [];
            if ($entryFees !== []) {
                $currencies[$entryFees['currency']] = true;
                $currencyDates[$entryFees['currency']][] = $entry->settlement_date;
                unset($entryFees['currency']);
            }
            foreach ($entryFees as $field => $amount) {
                $amounts[$field] = bcadd($amounts[$field] ?? '0', $amount, 8);
            }
        }
        if (count($currencies) === 1) {
            $values = ['settlement_currency' => array_key_first($currencies), ...$amounts];
        }
        $reasons = [];
        $exceptionDates = collect();
        foreach (['sale' => '结算入账', 'refund' => '退款', 'rdr' => 'RDR', 'chargeback' => '拒付', 'ethoca' => 'Ethoca'] as $kind => $label) {
            if (($byType->get($kind)?->count() ?? 0) > 1) {
                $reasons[] = '多笔'.$label;
                $exceptionDates = $exceptionDates->concat($byType->get($kind)->pluck('settlement_date')->sort()->skip(1));
                if ($kind === 'sale') {
                    unset($values['settlement']);
                }
            }
        }
        if ($byType->has('rdr') && ($byType->has('refund') || $byType->has('chargeback'))) {
            $reasons[] = $byType->has('refund') && $byType->has('chargeback') ? '退款、拒付与 RDR 同时存在'
                : ($byType->has('refund') ? '退款与 RDR 同时存在' : '拒付与 RDR 同时存在');
            $firstRdrDate = $byType->get('rdr')->min('settlement_date');
            foreach (['refund', 'chargeback'] as $kind) {
                if ($byType->has($kind)) {
                    $exceptionDates->push(max($firstRdrDate, $byType->get($kind)->min('settlement_date')));
                }
            }
        }
        if (count($currencies) > 1) {
            $reasons[] = '结算币种不一致';
            $exceptionDates = $exceptionDates->concat(collect($currencyDates)->map(fn (array $dates) => min($dates))->sort()->skip(1));
        }

        return ['values' => $values, 'reasons' => $reasons,
            'exception_dates' => $exceptionDates->unique()->sort()->implode('、'),
            'dates' => $entries->pluck('settlement_date')->unique()->implode('、'),
            'settlement_types' => $entries->map(function ($entry): string {
                $payload = json_decode($entry->payload, true, 512, JSON_THROW_ON_ERROR);

                return $payload['_settlement_type'] ?? '未标注';
            })->unique()->implode('、')];
    }

    public function payable(Collection $entries, array $values): ?string
    {
        $required = [];
        $kinds = $entries->pluck('kind')->unique();
        // An Ethoca alert can describe the same refund or have a different currency.
        if ($kinds->contains('ethoca')) {
            return null;
        }
        foreach ($kinds as $kind) {
            if (isset(self::AMOUNTS[$kind])) {
                $required[] = self::AMOUNTS[$kind][0];
            }
            $required = array_merge($required, match ($kind) {
                'sale' => ['hold', 'fee', 'per_order_fee'],
                'failed' => ['per_order_fee'],
                default => isset(self::EVENT_FEES[$kind]) ? [self::EVENT_FEES[$kind][0]] : [],
            });
        }
        if ($required === [] || ! in_array($values['settlement_currency'] ?? null, ['USD', 'GBP'], true)) {
            return null;
        }
        foreach ($required as $field) {
            if (! isset($values[$field])) {
                return null;
            }
        }

        $principal = $kinds->contains('sale') ? $values['settlement'] : '0';
        $payable = bcadd($principal, (string) ($values['release'] ?? '0'), 8);
        foreach (['hold', 'fee', 'per_order_fee', 'refund', 'refund_fee', 'chargeback', 'chargeback_fee',
            'ethoca_refund', 'ethoca_fee', 'rdr_refund', 'rdr_fee'] as $field) {
            if (isset($values[$field])) {
                $payable = bcsub($payable, (string) $values[$field], 8);
            }
        }

        return $payable;
    }

    public function storeFees(array $layout, Collection $entries, array $manual = []): array
    {
        $rules = [
            'nbc_fee' => [['yy'], '0.004'],
            'store_fee' => [['shoplazza', 'shopline'], '0.003'],
        ];
        $settlementCurrency = strtoupper(trim((string) ($layout['settlement_currency'] ?? '')));
        foreach ($rules as $field => [$kinds, $rate]) {
            if (array_key_exists($field, $manual) || filled($layout[$field] ?? null)) {
                continue;
            }
            $lines = $entries->whereIn('kind', $kinds);
            if ($lines->isEmpty()) {
                continue;
            }

            $total = '0';
            $currency = $settlementCurrency;
            foreach ($lines as $line) {
                $payload = json_decode($line->payload, true, 512, JSON_THROW_ON_ERROR);
                $lineCurrency = strtoupper(trim((string) ($payload['结算货币'] ?? '')));
                $amount = trim((string) ($payload['结算金额'] ?? ''));
                if (! preg_match('/^[A-Z]{3}$/D', $lineCurrency)
                    || ! preg_match('/^-?\d{1,18}(?:\.\d{1,8})?$/D', $amount)
                    || ($currency !== '' && $currency !== $lineCurrency)) {
                    continue 2;
                }
                $currency = $lineCurrency;
                $total = bcadd($total, $amount, 8);
            }

            $fee = bcmul($total, $rate, 12);
            $layout[$field] = bcadd($fee, bccomp($fee, '0', 12) < 0 ? '-0.0005' : '0.0005', 3);
        }

        return $layout;
    }

    public function isWallet(mixed $website, Collection $entries): bool
    {
        if (stripos((string) $website, 'wallet') !== false) {
            return true;
        }

        return $entries->contains(function ($entry): bool {
            $payload = json_decode($entry->payload, true, 512, JSON_THROW_ON_ERROR);

            return stripos((string) ($payload['账单描述符'] ?? ''), 'wallet') !== false;
        });
    }

    public function exceptionColumns(array $layout, Collection $entries, bool $wallet): array
    {
        $kinds = $entries->pluck('kind');
        if ($kinds->contains('refund') && $kinds->contains('chargeback') && ! $kinds->contains('release')
            && blank($layout['release'] ?? null)) {
            return ['ledger_exception_dates' => '', 'ledger_exceptions' => ''];
        }

        $reason = $layout['ledger_exceptions'] ?? '';
        $dates = $layout['ledger_exception_dates'] ?? '';
        if ($wallet) {
            $reason = $reason === '' ? 'Wallet 交易' : 'Wallet 交易；'.$reason;
            $dates = $dates ?: ($entries->min('settlement_date') ?? '');
        }

        return ['ledger_exception_dates' => $dates, 'ledger_exceptions' => $reason];
    }

    private function unmatchedQuery(array $filters): ?\Illuminate\Database\Query\Builder
    {
        if (($filters['card_channel'] ?? null) !== 'ye' || ($filters['card_match'] ?? '') === 'matched'
            || filled($filters['team'] ?? null) || filled($filters['domain'] ?? null)
            || filled($filters['subscription'] ?? null) || filled($filters['card_transaction_status'] ?? null)
            || filled($filters['source'] ?? null)) {
            return null;
        }

        $matches = $this->orders()->selectRaw('COUNT(*)')
            ->whereColumn('finance_records.account', 'merchant.activity_account')
            ->whereColumn('finance_records.reference', 'entry.reference');
        return $this->workbooks->database()->table(self::TABLE.' as entry')
            ->join('finance_payment_accounts as merchant', 'merchant.id', '=', 'entry.payment_account_id')
            ->where('entry.platform', 'ye')->whereNull('merchant.deleted_at')->whereNotNull('entry.reference')
            ->where($matches->toBase(), '!=', 1)
            ->when(filled($filters['account'] ?? null), fn ($query) => $query->where('merchant.activity_account', $filters['account']))
            ->when(filled($filters['card_group'] ?? null), fn ($query) => $query->where('merchant.card_group_id', $filters['card_group']))
            ->when(filled($filters['reference'] ?? null), fn ($query) => $query->where('entry.reference', $filters['reference']))
            ->when(filled($filters['from'] ?? null), fn ($query) => $query->where('entry.settlement_date', '>=', $filters['from']))
            ->when(filled($filters['to'] ?? null), fn ($query) => $query->where('entry.settlement_date', '<=', $filters['to']));
    }

    public function unmatchedPage(array $filters, int $offset, int $limit): array
    {
        $query = $this->unmatchedQuery($filters);
        if ($query === null) {
            return [0, collect()];
        }

        $keys = (clone $query)->select(['entry.payment_account_id', 'entry.reference'])
            ->groupBy('entry.payment_account_id', 'entry.reference');
        $total = $this->workbooks->database()->query()->fromSub(clone $keys, 'statement_orders')->count();
        if ($limit <= 0 || $offset >= $total) {
            return [$total, collect()];
        }

        $keys->orderBy('entry.payment_account_id')->orderBy('entry.reference')->offset($offset)->limit($limit);
        $entries = $query->joinSub($keys, 'page_orders', function ($join): void {
            $join->on('page_orders.payment_account_id', '=', 'entry.payment_account_id')
                ->on('page_orders.reference', '=', 'entry.reference');
        })->select('entry.*');

        return [$total, collect($this->statementRows($entries, $filters))];
    }

    public function unmatchedRows(array $filters): iterable
    {
        $query = $this->unmatchedQuery($filters);
        if ($query !== null) {
            yield from $this->statementRows($query->select('entry.*'), $filters);
        }
    }

    private function statementRows(\Illuminate\Database\Query\Builder $query, array $filters): iterable
    {
        $entries = $query->orderBy('entry.payment_account_id')->orderBy('entry.reference')
            ->orderByDesc('entry.settlement_date')->orderByDesc('entry.id')->lazy(500);
        $service = app(FinanceSettlementService::class);
        $groups = $entries->chunkWhile(fn ($entry, $key, $group) =>
            $entry->payment_account_id === $group->first()->payment_account_id && $entry->reference === $group->first()->reference);
        foreach ($groups as $group) {
            $entry = $group->first();
            $group = $group->collect();
            $details = $this->details($group);

            $filterType = $filters['ledger_settlement_type'] ?? '';
            if (filled($filterType) && $filterType !== 'waiting'
                && ! in_array($filterType, explode('、', $details['settlement_types']), true)) {
                continue;
            }
            $layout = [
                'ledger_status' => '未匹配订单', 'ledger_dates' => $details['dates'],
                'ledger_settlement_type' => $details['settlement_types'], 'reference' => $entry->reference,
                'ledger_exception_dates' => $details['exception_dates'],
                'ledger_exceptions' => implode('；', $details['reasons']),
                ...$details['values'],
            ];
            foreach ($group as $item) {
                $payload = json_decode($item->payload, true, 512, JSON_THROW_ON_ERROR);
                $layout['website_name'] ??= $payload['网站名称'] ?? $payload['账单描述符'] ?? null;
                $layout['merchant_order'] ??= $payload['商户订单号'] ?? $payload['商户支付订单号'] ?? null;
                $layout['status'] ??= $payload['交易状态'] ?? $payload['退款状态'] ?? null;
                $layout['currency'] ??= $payload['交易币种'] ?? $payload['币种'] ?? null;
                $layout['gross'] ??= $payload['交易金额'] ?? $payload['订单金额'] ?? null;
                foreach ([
                    'three_ds' => '3DS', 'three_ds_status' => '3DS状态', 'card_type' => '卡种', 'email' => '邮箱',
                    'payer_account' => '付款人账号', 'cardholder_phone' => '持卡人电话', 'recipient_phone' => '收货人电话',
                    'country' => '国家', 'refund_status' => '退款状态', 'source_page' => '来源页面',
                    'completed_at' => '完成时间',
                ] as $field => $header) {
                    $layout[$field] ??= $payload[$header] ?? null;
                }
                $layout['failure_reason'] ??= $item->kind === 'failed' ? ($payload['交易返回信息'] ?? null) : null;
                $time = $payload['发起时间'] ?? $payload['交易时间'] ?? null;
                if ($time !== null) {
                    $layout['initiated_at'] ??= is_numeric($time)
                        ? Date::excelToDateTimeObject((float) $time)->format('Y-m-d H:i:s') : $time;
                }
            }
            foreach (['initiated_at', 'completed_at'] as $field) {
                $layout[$field] = FinanceCreditSettlementLayout::reportTimestamp(
                    $layout[$field] ?? null, 'Asia/Shanghai', $filters['timezone'] ?? 'Asia/Shanghai',
                );
            }
            $layout = $this->storeFees($layout, $group);
            $wallet = $this->isWallet($layout['website_name'] ?? null, $group);
            $layout = array_replace($layout, $this->exceptionColumns($layout, $group, $wallet));
            if ((empty($filters['card_wallet']) || $wallet)
                && (empty($filters['card_exceptions']) || filled($layout['ledger_exceptions']))
                && $service->matches($layout, $filters['conditions'] ?? [])) {
                yield ['statement_only' => true, 'card_layout' => $layout];
            }
        }
    }

    private function orders(?string $account = null, string $table = 'finance_records')
    {
        return FinanceRecord::query()->from($table === 'finance_records' ? $table : 'finance_records as '.$table)
            ->where($table.'.dataset', 'xuzong2-details')->when($account !== null, fn ($query) => $query->where($table.'.account', $account))
            ->where($table.'.payload->_card_channel', 'ye')->whereNotExists(function ($deleted) use ($table): void {
                $deleted->selectRaw('1')->from('finance_settlement_supplements')
                    ->whereColumn('finance_record_id', $table.'.id')->whereNotNull('payload->_deleted_at');
            });
    }

    private function date(string $value): ?string
    {
        if (is_numeric($value)) {
            $value = Date::excelToDateTimeObject((float) $value)->format('Y-m-d');
        }
        try {
            $date = CarbonImmutable::createFromFormat('!Y-m-d', $value, 'Asia/Shanghai');
        } catch (\InvalidArgumentException) {
            return null;
        }

        return $date && $date->format('Y-m-d') === $value ? $value : null;
    }
}
