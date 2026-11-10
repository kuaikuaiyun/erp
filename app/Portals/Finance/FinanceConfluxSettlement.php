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
    public const EXCEPTION_COLUMNS = ['ledger_dates' => '账单日期', 'ledger_exceptions' => '异常原因'];

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

    public function read(UploadedFile $file): array
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
                if ($merchant !== '') {
                    $merchants[mb_strtolower($merchant)] = true;
                }
                $width = Coordinate::columnIndexFromString($lastColumn);
                if ($lastRow > 10001 || $width > 100) {
                    throw ValidationException::withMessages(['file' => '结算报表每张工作表最多 10,000 行、100 列。']);
                }
                [$kind, $referenceHeader] = self::SHEETS[$prefix];
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
            'hash' => hash('sha256', implode('', $hashes)), 'entries' => $entries];
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
            $previous = (clone $query)->value('report_hash');
            if ($previous !== null && $previous !== $report['hash']) {
                throw ValidationException::withMessages(['file' => '此账户在该结算日期已有内容不同的报表，请核对原账单后再导入。']);
            }
            if ($previous === null) {
                app(FinanceAccountingService::class)->guardUnlocked(substr($report['date'], 0, 7));
                $stamp = now();
                foreach (array_chunk($report['entries'], 80) as $entries) {
                    $db->table(self::TABLE)->insert(array_map(fn ($entry) => [...$batch, ...$entry,
                        'report_hash' => $report['hash'], 'imported_by' => $actor, 'created_at' => $stamp], $entries));
                }
            }
            $references = (clone $query)->whereNotNull('reference')->distinct()->pluck('reference');
            $matched = 0;
            foreach ($references->chunk(500) as $chunk) {
                $matched += $this->orders($accountKey)->whereIn('reference', $chunk)
                    ->groupBy('reference')->havingRaw('COUNT(*) = 1')->pluck('reference')->count();
            }

            return ['inserted' => $previous === null ? count($report['entries']) : 0,
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

    public function feesFor(Collection $matchedEntries): array
    {
        $fees = [];
        $batches = $matchedEntries->flatten(1)->groupBy(['payment_account_id', 'settlement_date']);
        foreach ($batches as $accountId => $dates) {
            foreach ($dates as $date => $entries) {
                $wanted = $entries->pluck('id')->flip()->all();
                $bill = $this->workbooks->database()->table(self::TABLE)->where([
                    'payment_account_id' => $accountId, 'settlement_date' => $date, 'platform' => 'ye',
                ])->whereIn('kind', ['summary', 'sale', 'failed', ...array_keys(self::EVENT_FEES)])->get(['id', 'kind', 'payload']);
                $fees += array_intersect_key($this->statementFees($bill, $date), $wanted);
            }
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
        $amounts = [];
        foreach (self::AMOUNTS as $kind => [$field, $currencyField, $amountField]) {
            foreach ($byType->get($kind, []) as $entry) {
                $payload = json_decode($entry->payload, true, 512, JSON_THROW_ON_ERROR);
                $currency = strtoupper($payload[$currencyField] ?? '');
                $amount = $payload[$amountField] ?? '';
                $currencies[$currency] = true;
                $amounts[$field] = bcadd($amounts[$field] ?? '0', $amount, 8);
            }
        }
        foreach ($entries as $entry) {
            $entryFees = $fees[$entry->id] ?? [];
            if ($entryFees !== []) {
                $currencies[$entryFees['currency']] = true;
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
        foreach (['sale' => '结算入账', 'refund' => '退款', 'rdr' => 'RDR', 'chargeback' => '拒付', 'ethoca' => 'Ethoca'] as $kind => $label) {
            if (($byType->get($kind)?->count() ?? 0) > 1) {
                $reasons[] = '多笔'.$label;
                if ($kind === 'sale') {
                    unset($values['settlement']);
                }
            }
        }
        if ($byType->has('rdr') && ($byType->has('refund') || $byType->has('chargeback'))) {
            $reasons[] = $byType->has('refund') && $byType->has('chargeback') ? '退款、拒付与 RDR 同时存在'
                : ($byType->has('refund') ? '退款与 RDR 同时存在' : '拒付与 RDR 同时存在');
        }
        if (count($currencies) > 1) {
            $reasons[] = '结算币种不一致';
        }

        return ['values' => $values, 'reasons' => $reasons,
            'dates' => $entries->pluck('settlement_date')->unique()->implode('、')];
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

    private function orders(string $account)
    {
        return FinanceRecord::query()->where('dataset', 'xuzong2-details')->where('account', $account)
            ->where('payload->_card_channel', 'ye')->whereNotExists(function ($deleted): void {
                $deleted->selectRaw('1')->from('finance_settlement_supplements')
                    ->whereColumn('finance_record_id', 'finance_records.id')->whereNotNull('payload->_deleted_at');
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
