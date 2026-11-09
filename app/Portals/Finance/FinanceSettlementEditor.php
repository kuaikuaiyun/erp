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

    public function numericFields(string $channel): array
    {
        return array_unique([...array_keys($this->settlements->moneyFields($channel)), 'settlement', 'gross', 'source_settlement_usd', 'paypal_7', 'paypal_8', 'paypal_9']);
    }

    public function save(FinanceRecord $record, array $input, string $channel, int $actor): void
    {
        $columns = array_diff_key($this->settlements->columns($channel), ['id' => true]);
        $rules = array_fill_keys(array_keys($columns), 'sometimes|nullable|string|max:191');
        foreach (array_intersect($this->numericFields($channel), array_keys($columns)) as $field) {
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
        $values = validator($input, $rules)->validate();
        $db = $this->workbooks->database();
        $db->transaction(function () use ($db, $record, $values, $channel, $actor): void {
            $record = FinanceRecord::query()->whereKey($record->id)->lockForUpdate()->firstOrFail();
            $accounting = app(FinanceAccountingService::class);
            $accounting->guardUnlocked($record->occurred_at->setTimezone('Asia/Shanghai')->format('Y-m'));
            $current = $this->settlements->rows($channel, [], new \Illuminate\Database\Eloquent\Collection([$record]))->sole();
            $accounting->guardUnlocked($current['month']);
            $changed = array_filter($values, fn ($value, $field) => (string) ($value ?? '') !== (string) ($current[$field] ?? ''), ARRAY_FILTER_USE_BOTH);
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
            $candidate = clone $record;
            $candidate->forceFill([...$attributes, 'payload' => $payload]);
            if (array_intersect_key($attributes, array_flip(['account', 'reference', 'occurred_at', 'currency', 'status', 'raw_type']))) {
                $this->guardUnique($candidate, $channel);
            }
            if ($attributes !== [] || $payload !== $record->payload) {
                $identity = [$record->dataset, $candidate->account, $candidate->reference, $candidate->occurred_at->toIso8601String(), $candidate->raw_type ?? '', $candidate->currency, $candidate->status ?? ''];
                if ($channel === 'credit_card') {
                    $identity[] = trim((string) ($payload['商户订单号'] ?? $payload['Shopify订单号'] ?? ''));
                    if (isset($payload['_card_channel'])) {
                        $identity[] = $payload['_card_channel'];
                    }
                }
                $link = $db->table('finance_payment_transactions')->where('finance_record_id', $record->id)->first();
                if ($link) {
                    $ownerId = $account?->id ?? $link->payment_account_id;
                    $event = $candidate->raw_type ?? '';
                    $identity = ['relay', $ownerId, $candidate->reference, $event];
                    if ($db->table('finance_payment_transactions')->where('payment_account_id', $ownerId)->where('transaction_id', $candidate->reference)->where('event_code', $event)->where('finance_record_id', '!=', $record->id)->exists()) {
                        throw ValidationException::withMessages(['reference' => '交易ID已存在，不能重复。']);
                    }
                    $db->table('finance_payment_transactions')->where('finance_record_id', $record->id)->update(['payment_account_id' => $ownerId, 'transaction_id' => $candidate->reference, 'event_code' => $event, 'updated_at' => now()]);
                }
                $candidate->fingerprint = hash('sha256', json_encode($identity, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
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
            foreach ($supplements as $field => $value) {
                if ($value === null && in_array($field, $this->numericFields($channel), true)) {
                    unset($overrides[$field]);
                } else {
                    $overrides[$field] = $value ?? '';
                }
            }
            $this->settlements->save($candidate, [...$supplements, '_edited_fields' => $overrides], $actor);
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
