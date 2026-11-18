<?php

namespace App\Portals\Finance;

use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

class FinanceYeBillAdjustments
{
    protected const CHANNEL = 'ye';

    protected const FIELDS = [
        'refund' => '账单退款金额', 'refund_fee' => '退款费用', 'chargeback' => '拒付金额',
        'chargeback_fee' => '拒付费用', 'ethoca_refund' => 'ethoca-预警退款', 'ethoca_fee' => '拒付预警ethoca',
        'rdr_refund' => '拒付RDR金额', 'rdr_fee' => '拒付RDR费用', 'release' => '保证金返还',
        'logistics' => '物流-元', 'purchase' => '采购成本-元', 'nbc_fee' => 'NBC费用', 'store_fee' => 'Shopline+店匠费用',
    ];

    public function read(UploadedFile $file): ?array
    {
        $headers = ['交易 ID', '流水订单号', '平台订单号', '商户订单号', '网站名称', '网关接入号', ...array_values(static::FIELDS)];
        $rows = app(FinanceWorkbookService::class)->read($file, $headers, optionalHeaders: $headers, maxRows: 10000);
        return collect($rows)->contains(fn ($row) => collect(static::FIELDS)->contains(fn ($label) => filled($row[$label] ?? null)))
            ? $rows : null;
    }

    public function import(array $rows, string $settlementType, int $actor, ?string $account = null): int
    {
        $service = app(FinanceSettlementService::class);
        $groups = [];
        foreach ($rows as $line => $row) {
            $reference = trim((string) (($row['交易 ID'] ?? '') ?: ($row['流水订单号'] ?? '') ?: ($row['平台订单号'] ?? '')));
            $merchant = trim((string) ($row['商户订单号'] ?? ''));
            if ($reference === '' && $merchant === '') {
                throw ValidationException::withMessages(['file' => '第'.$line.'行请填写订单号。']);
            }
            $websiteField = static::CHANNEL === 'worldpay' ? '网关接入号' : '网站名称';
            $website = trim((string) ($row[$websiteField] ?? ''));
            $key = json_encode([$reference, $merchant, $website], JSON_THROW_ON_ERROR);
            $group = $groups[$key] ?? compact('reference', 'merchant', 'website', 'line') + ['values' => []];
            $hasAmount = false;
            foreach (static::FIELDS as $field => $label) {
                if (blank($row[$label] ?? null)) {
                    continue;
                }
                $hasAmount = true;
                $value = $service->number($row[$label]);
                if ($value === null) {
                    throw ValidationException::withMessages(['file' => '第'.$line.'行「'.$label.'」金额无效。']);
                }
                $group['values'][$field] = bcadd($group['values'][$field] ?? '0', $value, 8);
            }
            if (! $hasAmount) {
                throw ValidationException::withMessages(['file' => '第'.$line.'行请填写需要变动的金额。']);
            }
            $groups[$key] = $group;
        }

        return app(FinanceWorkbookService::class)->database()->transaction(function () use ($groups, $service, $settlementType, $actor, $account): int {
            $saved = 0;
            foreach ($groups as $group) {
                $query = $service->query('credit_card', ['card_channel' => static::CHANNEL]);
                if ($account !== null) {
                    $query->where('account', $account);
                }
                if ($group['reference'] !== '') {
                    $query->where('reference', $group['reference']);
                }
                if ($group['merchant'] !== '') {
                    $query->where('payload->商户订单号', $group['merchant']);
                }
                if ($group['website'] !== '') {
                    $query->where('payload->'.(static::CHANNEL === 'worldpay' ? '网关接入号' : '网站名称'), $group['website']);
                }
                $matches = $query->lockForUpdate()->get();
                if ($matches->count() !== 1) {
                    throw ValidationException::withMessages(['file' => '第'.$group['line'].'行无法匹配唯一订单，请核对订单号和网站名称或网关。']);
                }
                $record = $matches->sole();
                $db = app(FinanceWorkbookService::class)->database();
                $stored = $db->table('finance_settlement_supplements')->where('finance_record_id', $record->id)->value('payload');
                $data = json_decode($stored ?? '{}', true, 512, JSON_THROW_ON_ERROR);
                $deltaKey = '_'.static::CHANNEL.'_bill_deltas';
                $deltas = $data[$deltaKey] ?? [];
                foreach ($group['values'] as $field => $delta) {
                    $deltas[$field] = bcadd($deltas[$field] ?? '0', $delta, 8);
                }
                $values = [$deltaKey => $deltas];
                if (static::CHANNEL === 'ye') {
                    $values['_ye_settlement_type'] = $settlementType;
                }
                $service->save($record, $values, $actor);
                $saved++;
            }
            return $saved;
        });
    }
}
