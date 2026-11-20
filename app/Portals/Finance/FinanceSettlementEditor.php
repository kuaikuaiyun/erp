<?php

namespace App\Portals\Finance;

use App\Models\FinancePaymentAccount;
use App\Models\FinanceRecord;
use Carbon\CarbonImmutable;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class FinanceSettlementEditor
{
    public function __construct(private readonly FinanceSettlementService $settlements, private readonly FinanceWorkbookService $workbooks) {}

    public function fields(string $channel, ?string $cardChannel = null): array
    {
        if ($channel === 'credit_card' && $cardChannel !== null) {
            return array_diff_key(FinanceCreditSettlementLayout::displayColumns($cardChannel), array_flip([
                'calendar_date', 'operation_region', 'worldpay_exception', 'payable', 'logistics', 'purchase',
            ]));
        }
        return array_diff_key($this->settlements->columns($channel), array_flip($channel === 'paypal'
            ? ['id', 'team', 'operation_region', 'logistics', 'purchase'] : ['id', 'logistics', 'purchase']));
    }

    public function numericFields(string $channel, ?string $cardChannel = null): array
    {
        return array_unique([...array_keys($this->settlements->moneyFields($channel)), 'settlement', 'gross', 'source_settlement_usd', 'paypal_7', 'paypal_8', 'paypal_9',
            ...FinanceCreditSettlementLayout::numericFields($cardChannel)]);
    }

    public function save(FinanceRecord $record, array $input, string $channel, int $actor, bool $addImportedCosts = false, ?string $cardChannel = null): void
    {
        $columns = $this->fields($channel, $cardChannel);
        if ($addImportedCosts) $columns += ['logistics' => '物流-元', 'purchase' => '采购成本-元'];
        $rules = array_fill_keys(array_keys($columns), 'sometimes|nullable|string|max:191');
        foreach (array_intersect($this->numericFields($channel, $cardChannel), array_keys($columns)) as $field) {
            $rules[$field] = ['sometimes', 'nullable', 'numeric', 'regex:/^-?\d{1,18}(?:\.\d{1,8})?$/'];
            if (! in_array($field, ['paypal_7', 'paypal_8', 'paypal_9', 'gross'], true)) {
                $rules[$field][] = 'gte:0';
            }
        }
        $rules['usd_rate'] = ['sometimes', 'nullable', 'numeric', 'regex:/^\d{1,10}(?:\.\d{1,8})?$/', 'gt:0'];
        $rules['note'] = 'sometimes|nullable|string|max:2000';
        $rules['reference'] = 'sometimes|required|string|max:100';
        $rules['account'] = 'sometimes|required|string|max:191';
        $rules['month'] = 'sometimes|required|date_format:Y-m';
        $rules['timezone'] = ['sometimes', 'required', Rule::in(FinanceWorkbookService::TIMEZONES)];
        if ($channel === 'paypal') {
            $rules['paypal_4'] = 'sometimes|required|string|max:191';
            $rules['paypal_5'] = 'sometimes|required|string|max:100';
            $rules['paypal_0'] = 'sometimes|required|date_format:Y-m-d';
            $rules['paypal_1'] = 'sometimes|required|date_format:H:i:s';
            $rules['paypal_2'] = ['sometimes', 'required', Rule::in(FinanceWorkbookService::TIMEZONES)];
            $rules['paypal_6'] = 'sometimes|required|regex:/^[A-Z]{3}$/';
        } else {
            $rules['status'] = 'sometimes|required|string|max:100';
            $rules['currency'] = 'sometimes|required|regex:/^[A-Z]{3}$/';
        }
        if (! $addImportedCosts) {
            unset($rules['logistics'], $rules['purchase']);
        }
        if ($cardChannel !== null) $rules = array_intersect_key($rules, $columns + ['timezone' => true]);
        if ($cardChannel === 'ye') $rules['initiated_at'] = 'sometimes|required|date_format:Y-m-d H:i:s';
        if (isset($columns['completed_at'])) $rules['completed_at'] = 'sometimes|nullable|date_format:Y-m-d H:i:s';
        if (isset($columns['refund_count'])) $rules['refund_count'] = 'sometimes|nullable|integer|min:0';
        if (isset($columns['statement_rate'])) $rules['statement_rate'] = 'sometimes|nullable|numeric|gt:0';
        // Excel and gateway imports can represent whole counts as "0.0" or "2.000".
        // Preserve the integer rule while accepting the unchanged value shown by the editor.
        if (isset($columns['refund_count'], $input['refund_count']) && is_scalar($input['refund_count'])
            && preg_match('/^\d{1,18}(?:\.0{1,8})?$/D', (string) $input['refund_count'])) {
            $input['refund_count'] = bcadd((string) $input['refund_count'], '0', 0);
        }
        $values = validator($input, $rules, [], $columns + ['timezone' => '时区', 'usd_rate' => '汇率'])->validate();
        if ($cardChannel === 'ye' && isset($values['initiated_at'])) $values['date'] = $values['initiated_at'];
        $db = $this->workbooks->database();
        $db->transaction(function () use ($db, $record, $values, $channel, $actor, $addImportedCosts, $cardChannel): void {
            $record = FinanceRecord::query()->whereKey($record->id)->lockForUpdate()->firstOrFail();
            $accounting = app(FinanceAccountingService::class);
            $accounting->guardUnlocked($record->occurred_at->setTimezone('Asia/Shanghai')->format('Y-m'));
            $scope = ['timezone' => $values['timezone'] ?? 'Asia/Shanghai'];
            if ($cardChannel !== null) $scope['card_channel'] = $cardChannel;
            $current = $this->settlements->rows($channel, $scope, new \Illuminate\Database\Eloquent\Collection([$record]))->sole();
            if ($cardChannel !== null) $current = array_replace($current, $current['card_layout'] ?? []);
            $accounting->guardUnlocked($current['month']);
            if ($addImportedCosts) {
                foreach ($values as $field => $amount) {
                    $prior = $current[$field] ?? null;
                    $values[$field] = bcadd(is_numeric($prior) ? (string) $prior : '0', (string) $amount, 8);
                }
            }
            $numericFields = $this->numericFields($channel, $cardChannel);
            $changed = array_filter($values, function ($value, $field) use ($current, $numericFields): bool {
                $prior = $current[$field] ?? null;
                if (in_array($field, $numericFields, true) && is_numeric($value) && is_numeric($prior)) {
                    return bccomp((string) $value, (string) $prior, 8) !== 0;
                }
                return (string) ($value ?? '') !== (string) ($prior ?? '');
            }, ARRAY_FILTER_USE_BOTH);
            if ($changed === []) {
                return;
            }
            if (array_key_exists('date', $changed) && blank($changed['date'])) {
                throw ValidationException::withMessages(['date' => '已有交易时间不能为空。']);
            }
            $payload = $record->payload;
            $attributes = [];
            $account = null;
            if (array_key_exists('account', $changed)) {
                $accounts = FinancePaymentAccount::query()->where('account_type', $channel)
                    ->where(fn ($q) => $q->where('account_name', $changed['account'])->orWhere('activity_account', $changed['account']))->lockForUpdate()->get();
                if ($accounts->count() !== 1) {
                    throw ValidationException::withMessages(['account' => '请选择有效且唯一的支付账户。']);
                }
                $account = $accounts->sole();
                $attributes['account'] = $account->activity_account;
            }
            if (isset($changed['reference'])) {
                $attributes['reference'] = $changed['reference'];
                $definition = FinanceCatalog::dataset($record->dataset);
                $payload[$definition['headers'][$definition['reference']]] = $changed['reference'];
            }
            $sourceFields = ['account', 'reference', 'date', 'timezone'];
            if ($channel === 'paypal') {
                $headers = FinanceCatalog::dataset('paypal-orders')['headers'];
                foreach (array_slice($headers, 0, 15) as $index => $header) {
                    $key = 'paypal_'.$index;
                    $sourceFields[] = $key;
                    if (array_key_exists($key, $changed)) {
                        $payload[$header] = $changed[$key] ?? '';
                    }
                }
                if (isset($changed['paypal_4'])) {
                    $type = FinancePayPalEventTypes::label($changed['paypal_4']);
                    $code = collect(FinancePayPalEventTypes::TYPES)->search(fn ($definition) => $definition[0] === $type && $definition[1] === '结账付款');
                    if ($code === false) {
                        throw ValidationException::withMessages(['paypal_4' => '请选择结账付款交易类型。']);
                    }
                    $this->workbooks->registerType($code);
                    if ($db->table('finance_transaction_types')->where('raw_type', $code)->value('category') !== '结账付款') {
                        throw ValidationException::withMessages(['paypal_4' => '该交易类型当前未归类为结账付款。']);
                    }
                    $attributes['raw_type'] = $payload['類型'] = $code;
                }
                if (isset($changed['paypal_5'])) {
                    $attributes['status'] = $payload['狀態'] = $this->status($changed['paypal_5'], $channel);
                }
                if (isset($changed['paypal_6'])) {
                    $attributes['currency'] = $changed['paypal_6'];
                }
            } else {
                $sourceFields = [...$sourceFields, 'status', 'currency', 'gross'];
                if ($cardChannel === 'ye') $sourceFields[] = 'initiated_at';
                if ($cardChannel !== null) {
                    $sourceColumns = FinanceCreditSettlementLayout::columns($cardChannel);
                    foreach (['reference', 'currency', 'gross', 'status'] as $field) {
                        if (array_key_exists($field, $changed)) {
                            $payload[$sourceColumns[$field]] = $field === 'status'
                                ? $this->status($changed[$field], $channel) : ($changed[$field] ?? '');
                        }
                    }
                }
                if ($cardChannel !== null && array_key_exists('merchant_order', $changed)) {
                    $payload['商户订单号'] = $changed['merchant_order'] ?? '';
                }
                foreach (['status' => '支付状态', 'currency' => '交易币种', 'gross' => '交易金额'] as $field => $header) {
                    if (array_key_exists($field, $changed)) {
                        $value = $field === 'status' ? $this->status($changed[$field], $channel) : $changed[$field];
                        $payload[$header] = $value ?? '';
                        if ($field !== 'gross') {
                            $attributes[$field] = $value;
                        }
                    }
                }
            }
            $zone = $changed['timezone'] ?? $current['timezone'];
            if (isset($changed['timezone'])) {
                $payload['_report_timezone'] = $zone;
            }
            if (isset($changed['date']) && $changed['date'] !== '') {
                $attributes['occurred_at'] = $this->date($changed['date'], $zone);
                if ($channel === 'paypal' && array_intersect_key($changed, array_flip(['paypal_0', 'paypal_1', 'paypal_2']))
                    && ! $attributes['occurred_at']->equalTo($this->date($payload['日期'].' '.$payload['時間'], $payload['時區']))) {
                    throw ValidationException::withMessages(['date' => '时间点与原日期、时间及时区不一致，请核对后保存。']);
                }
                unset($payload['_manual_import_month']);
            } elseif ($channel === 'paypal' && array_intersect_key($changed, array_flip(['paypal_0', 'paypal_1', 'paypal_2']))) {
                $attributes['occurred_at'] = $this->date($payload['日期'].' '.$payload['時間'], $payload['時區']);
            }
            if (isset($attributes['occurred_at'])) {
                $accounting->guardUnlocked($attributes['occurred_at']->setTimezone('Asia/Shanghai')->format('Y-m'));
                if ($channel === 'paypal') {
                    $sourceZone = $changed['paypal_2'] ?? $record->source_timezone ?? 'UTC';
                    $sourceDate = $attributes['occurred_at']->setTimezone($sourceZone);
                    $payload['日期'] = $sourceDate->format('Y-m-d');
                    $payload['時間'] = $sourceDate->format('H:i:s');
                    $payload['時區'] = $sourceZone;
                    $attributes['source_timezone'] = $sourceZone;
                } else {
                    $payload['创建时间'] = $attributes['occurred_at']->setTimezone($record->source_timezone ?? 'Asia/Shanghai')->format('Y-m-d H:i:s');
                }
            }
            if (isset($changed['month'])) {
                $accounting->guardUnlocked($changed['month']);
            }
            if (! $addImportedCosts) $payload = FinanceSourceEdits::remember($record, $attributes, $payload);
            $candidate = clone $record;
            $candidate->forceFill([...$attributes, 'payload' => $payload]);
            if (array_intersect_key($attributes, array_flip(['account', 'reference', 'occurred_at', 'currency', 'status', 'raw_type']))) {
                $this->guardUnique($candidate, $channel);
            }
            if ($attributes !== [] || $payload !== $record->payload) {
                $link = $db->table('finance_payment_transactions')->where('finance_record_id', $record->id)->first();
                if ($link) {
                    $ownerId = $account?->id ?? $link->payment_account_id;
                    $event = $candidate->raw_type ?? '';
                    if ($db->table('finance_payment_transactions')->where('payment_account_id', $ownerId)->where('transaction_id', $candidate->reference)->where('event_code', $event)->where('finance_record_id', '!=', $record->id)->exists()) {
                        throw ValidationException::withMessages(['reference' => '交易ID已存在，不能重复。']);
                    }
                    $db->table('finance_payment_transactions')->where('finance_record_id', $record->id)->update(['payment_account_id' => $ownerId, 'transaction_id' => $candidate->reference, 'event_code' => $event, 'updated_at' => now()]);
                }
                // Source identity is independent of editable display values in every channel.
                $candidate->fingerprint = $record->fingerprint;
                if (FinanceRecord::query()->where('fingerprint', $candidate->fingerprint)->whereKeyNot($record->id)->exists()) {
                    throw ValidationException::withMessages(['reference' => '该交易记录已存在，不能重复。']);
                }
                $candidate->save();
            }
            $saved = $db->table('finance_settlement_supplements')->where('finance_record_id', $record->id)->value('payload');
            $saved = $saved ? json_decode($saved, true, 512, JSON_THROW_ON_ERROR) : [];
            $overrides = $saved['_edited_fields'] ?? [];
            $supplements = array_diff_key($changed, array_flip($sourceFields));
            if (array_key_exists('paypal_12', $changed)) {
                $supplements['paypal_12'] = $changed['paypal_12'];
            }
            if ($cardChannel !== null && filled($supplements['completed_at'] ?? null)) {
                $supplements['completed_at'] = $this->date($supplements['completed_at'], $zone)
                    ->setTimezone($record->source_timezone ?: 'Asia/Shanghai')->toDateTimeString();
            }
            if ($addImportedCosts) {
                $this->settlements->save($candidate, $supplements, $actor);
            } else {
                $supplements = array_map(fn ($value) => $value ?? '', $supplements);
                $overrides = array_replace($overrides, $supplements);
                $this->settlements->save($candidate, [...$supplements, '_edited_fields' => $overrides], $actor);
            }
        });
    }

    private function status(string $value, string $channel): string
    {
        return match ($value) {
            '已完成', '已付款', '成功' => $channel === 'paypal' ? 'S' : 'SUCCESS',
            '已失败', '失败' => $channel === 'paypal' ? 'D' : 'FAILED',
            '待处理' => 'PENDING', '已撤销' => 'VOIDED', default => $value,
        };
    }

    private function date(string $value, string $zone): CarbonImmutable
    {
        try {
            if (! preg_match('/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2}$/', $value)) {
                throw new \InvalidArgumentException;
            }
            $date = CarbonImmutable::parse($value, $zone)->utc();
            $errors = CarbonImmutable::getLastErrors();
            if ($errors && ($errors['warning_count'] || $errors['error_count'])) {
                throw new \InvalidArgumentException;
            }

            return $date;
        } catch (\Throwable) {
            throw ValidationException::withMessages(['date' => '请填写有效日期时间，格式为 YYYY-MM-DD HH:mm:ss。']);
        }
    }

    private function guardUnique(FinanceRecord $record, string $channel): void
    {
        $matches = FinanceRecord::query()->whereKeyNot($record->id)->where('reference', $record->reference);
        if ($channel === 'paypal') {
            $matches->where('dataset', 'paypal-orders')->whereIn('raw_type', $this->workbooks->database()->table('finance_transaction_types')->where('category', '结账付款')->select('raw_type'));
        } else {
            $merchant = $record->payload['商户订单号'] ?? $record->payload['Shopify订单号'] ?? '';
            $matches->whereIn('dataset', ['xuzong2-orders', 'xuzong2-details', 'xuzong1-details'])->where('account', $record->account)
                ->where('occurred_at', $record->occurred_at)->where('currency', $record->currency)->where('status', $record->status)
                ->where(fn ($q) => $q->where('payload->商户订单号', $merchant)->orWhere('payload->Shopify订单号', $merchant));
            if (isset($record->payload['_card_channel'])) {
                $matches->where(fn ($q) => $q->whereNull('payload->_card_channel')
                    ->orWhere('payload->_card_channel', $record->payload['_card_channel']));
            }
        }
        if ($matches->exists()) {
            throw ValidationException::withMessages(['reference' => '交易ID或对应支付记录已存在，不能重复。']);
        }
    }
}
