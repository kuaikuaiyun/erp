<?php

namespace App\Portals\Finance;

use App\Models\FinanceRecord;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

class FinanceWorldpayImport
{
    public const SOURCE_ACCOUNT = 'worldpay-direct';

    public const MAX_UPLOAD_FILES = 20;

    private const GENERAL = ['网关接入号', '流水订单号', '商户订单号', '币种', '交易金额', '结单币种', '结单金额',
        '结单汇率', '交易时间', '交易状态', '支付方式', 'HPP支付方式', '卡种', '交易网址', '卡号', '邮箱', '收货人国家'];

    private const POSTED = ['网关接入号', '流水订单号', '商户订单号', '交易时间', '记账时间', '结算批次号', '结算类型',
        '交易币种', '交易金额', '结单币种', '成功结单金额', '交易手续费', '单笔手续费', '保证金扣取金额', '应结算正交易金额', '来源网址'];

    private const EVENT = ['网关接入号', '流水订单号', '商户订单号', '交易时间', '记账时间', '结算批次号', '结算类型',
        '结单币种', '异常结单金额', '处理费', '应结算异常结单金额', 'Case ID'];

    public function __construct(private readonly FinanceWorkbookService $workbooks) {}

    public function read(UploadedFile $file): ?array
    {
        if (strtolower($file->getClientOriginalExtension()) !== 'csv') {
            return null;
        }
        $contents = file_get_contents($file->getRealPath());
        if (! mb_check_encoding($contents, 'UTF-8')) {
            $contents = mb_convert_encoding($contents, 'UTF-8', 'GB18030');
        }
        $stream = fopen('php://temp', 'w+');
        try {
            fwrite($stream, preg_replace('/^(?:\xEF\xBB\xBF)+/', '', $contents));
            rewind($stream);
            $headers = array_map('trim', fgetcsv($stream, escape: '') ?: []);
            [$kind, $required] = match (true) {
                in_array('应结算异常结单金额', $headers, true) => ['event', self::EVENT],
                in_array('成功结单金额', $headers, true) && in_array('结算类型', $headers, true) => ['posted', self::POSTED],
                in_array('划款状态', $headers, true) && in_array('流水订单号', $headers, true) => ['general', self::GENERAL],
                default => [null, []],
            };
            if ($kind === null) {
                return null;
            }
            if (array_diff($required, $headers) || count($headers) !== count(array_unique($headers))) {
                throw ValidationException::withMessages(['file' => 'Worldpay 文件表头不完整或有重复。']);
            }
            $rows = [];
            while (($values = fgetcsv($stream, escape: '')) !== false) {
                if ($values === [null]) {
                    continue;
                }
                if (count($rows) >= 10000 || count($values) !== count($headers)) {
                    throw ValidationException::withMessages(['file' => 'Worldpay 文件列数不一致或超过 10,000 行。']);
                }
                $rows[count($rows) + 2] = array_combine($headers, array_map('trim', $values));
            }
            return [$kind, $rows];
        } finally {
            fclose($stream);
        }
    }

    public function import(string $kind, array $rows, string $account, string $timezone, int $actor): int
    {
        $count = 0;
        foreach ($rows as $line => $row) {
            $reference = $row['流水订单号'];
            $gateway = $row['网关接入号'];
            if ($reference === '' || $gateway === '' || ($kind !== 'general' && $row['结算批次号'] === '')
                || ($kind === 'event' && $row['Case ID'] === '')) {
                throw ValidationException::withMessages(['file' => '第 '.$line.' 行缺少 Worldpay 订单、网关或账单标识。']);
            }
            $matches = FinanceRecord::query()->where('dataset', 'xuzong2-details')
                ->when($account !== self::SOURCE_ACCOUNT, fn ($query) => $query->where('account', $account))
                ->where('reference', $reference)->where('payload->_card_channel', 'worldpay')
                ->where('payload->网关接入号', $gateway)->lockForUpdate()->limit(2)->get();
            if ($matches->count() > 1) {
                throw ValidationException::withMessages(['file' => '第 '.$line.' 行网关和流水号对应多条已有明细。']);
            }
            $record = $matches->first();
            $payload = $record?->payload ?? [
                '_card_channel' => 'worldpay', '网关接入号' => $gateway, '流水订单号' => $reference,
                '平台订单号' => $reference, '商户订单号' => $row['商户订单号'], '创建时间' => $row['交易时间'],
                '交易币种' => $row['币种'] ?? $row['交易币种'] ?? $row['结单币种'], '支付状态' => '', '域名' => '',
            ];
            if ($kind === 'general') {
                $payload = array_replace($payload, array_intersect_key($row, array_flip(self::GENERAL)), [
                    '交易币种' => $row['币种'], '支付状态' => $row['交易状态'], '创建时间' => $row['交易时间'],
                    '_worldpay_general' => true, '划款状态' => $row['划款状态'],
                ]);
            } elseif ($kind === 'posted') {
                if (empty($payload['_worldpay_general'])) {
                    $payload = array_replace($payload, [
                        '支付状态' => $row['结算类型'], '交易金额' => $row['交易金额'], '交易币种' => $row['交易币种'],
                        '结单金额' => $row['成功结单金额'], '结单币种' => $row['结单币种'], '交易网址' => $row['来源网址'],
                        '支付方式' => $row['支付方式'] ?? '', '卡种' => $row['卡种'] ?? '',
                    ]);
                }
                $payload['_worldpay_posted'][$row['结算批次号']] = $row;
            } else {
                $payload['_worldpay_events'][$row['结算批次号'].'|'.$row['Case ID'].'|'.$row['结算类型']] = $row;
            }
            if ($kind !== 'general') {
                $month = substr($row['记账时间'], 0, 7);
                if (! preg_match('/^\d{4}-(?:0[1-9]|1[0-2])$/D', $month)) {
                    throw ValidationException::withMessages(['file' => '第 '.$line.' 行 Worldpay 记账时间无效。']);
                }
                if ($record?->payload !== $payload) {
                    app(FinanceAccountingService::class)->guardUnlocked($month);
                }
            }
            if ($record === null) {
                $count += $this->workbooks->importRecords('xuzong2-details', [$line => $payload], $account, $timezone,
                    $actor, createOnly: true, guardSettlementMonth: true);
                continue;
            }
            $record->fill(['payload' => $payload, 'status' => $payload['支付状态'] ?: null, 'currency' => $payload['交易币种']]);
            if ($record->isDirty()) {
                app(FinanceAccountingService::class)->guardUnlocked($record->occurred_at->setTimezone('Asia/Shanghai')->format('Y-m'));
                $record->save();
                $count++;
            }
        }
        return $count;
    }

    public static function settlementValues(array $values, array $payload, array $saved, bool $success, bool $failed): array
    {
        $posted = $payload['_worldpay_posted'] ?? [];
        $events = $payload['_worldpay_events'] ?? [];
        $actual = [];
        foreach ($posted as $bill) {
            foreach (['settlement' => '成功结单金额', 'hold' => '保证金扣取金额', 'fee' => '交易手续费',
                'per_order_fee' => '单笔手续费'] as $field => $column) {
                $actual[$field] = bcadd($actual[$field] ?? '0', self::amount($bill[$column]), 2);
            }
        }
        foreach ($events as $event) {
            $fields = match (strtolower($event['结算类型'])) {
                '退款' => ['refund', 'refund_fee'],
                '拒付' => ['chargeback', 'chargeback_fee'],
                'rdr' => ['rdr_refund', 'rdr_fee'],
                'cdrn' => [null, 'rdr_fee'],
                'ethoca' => [null, 'ethoca_fee'],
                '预仲裁', '拒付预仲裁' => ['pre_arbitration', 'pre_arbitration_fee'],
                'rdr申诉成功' => ['rdr_appeal', null],
                '申诉成功' => ['appeal', null],
                default => [null, null],
            };
            foreach (array_combine(['异常结单金额', '处理费'], $fields) as $column => $field) {
                if ($field !== null) {
                    $actual[$field] = bcadd($actual[$field] ?? '0', self::amount($event[$column]), 2);
                }
            }
        }
        $values = array_replace($values, array_diff_key($actual, $saved));
        if (! array_key_exists('settlement', $saved) && blank($values['settlement'])
            && is_numeric($values['statement_amount']) && ($success || $failed)) {
            $values['settlement'] = $success ? $values['statement_amount'] : '0.00';
        }
        if (is_numeric($values['settlement'])) {
            foreach (['hold' => '0.10', 'fee' => '0.045'] as $field => $rate) {
                if (! array_key_exists($field, $saved) && blank($values[$field])) {
                    $amount = bcmul((string) $values['settlement'], $rate, 8);
                    $values[$field] = bcadd($amount, str_starts_with($amount, '-') ? '-0.005' : '0.005', 2);
                }
            }
        }
        if (! array_key_exists('per_order_fee', $saved) && blank($values['per_order_fee'])
            && ! empty($payload['_worldpay_general']) && ($success || $failed)) {
            $values['per_order_fee'] = '0.30';
        }
        if (! array_key_exists('payable', $saved) && blank($values['payable'])
            && (is_numeric($values['settlement']) || $events !== [])) {
            $net = self::amount($values['settlement']);
            foreach (['hold', 'fee', 'per_order_fee', 'refund', 'refund_fee', 'chargeback', 'chargeback_fee',
                'rdr_refund', 'rdr_fee', 'ethoca_fee', 'pre_arbitration', 'pre_arbitration_fee'] as $field) {
                $net = bcsub($net, self::amount($values[$field]), 2);
            }
            foreach (['release', 'rdr_appeal', 'appeal'] as $field) {
                $net = bcadd($net, self::amount($values[$field]), 2);
            }
            $values['payable'] = $net;
        }
        return $values;
    }

    private static function amount(mixed $value): string
    {
        $value = str_replace(',', '', (string) $value);
        return preg_match('/^[+-]?(?:\d+(?:\.\d*)?|\.\d+)$/D', $value) ? $value : '0';
    }
}
