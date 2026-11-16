<?php

namespace App\Portals\Finance;

use App\Models\FinanceRecord;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class FinanceXuzongCostEligibility
{
    public const FIELDS = ['物流-元', '采购成本-元'];
    public const LOCKED_LABEL = '不可填写';

    public function editable(Collection $records, ?Collection $linkedOrders = null): Collection
    {
        $numbers = $records->map(self::merchantNumber(...))->filter(fn ($number) => $number !== '')->unique();
        $siblings = $records;
        foreach ($numbers->chunk(300) as $batch) {
            $siblings = $siblings->concat(app(FinanceLogisticsCreditOrders::class)->query(xuzongReport: true)
                ->where(fn ($query) => $query->whereIn('payload->商户订单号', $batch->all())
                    ->orWhereIn('payload->_legacy_order_id', $batch->all()))
                ->get());
        }
        $siblings = $siblings->keyBy('id');
        $successful = $siblings->filter(fn ($record) => FinanceSettlementService::isSuccessfulTransaction($record->status));
        $linked = ($linkedOrders ?? collect())->union(app(FinanceXuzongCreditReport::class)->linkedOrders(
            $linkedOrders === null ? $successful : $successful->except($records->pluck('id')->all()),
        ));
        $winners = $successful->filter(function ($record) use ($linked) {
            $order = $linked->get($record->id);
            $payload = $record->payload;
            $addOn = filled($payload['是否加购'] ?? null) ? $payload['是否加购'] : ($order->is_add_on ?? '');

            return ! self::isAddOn($addOn) && ! self::isAddOn(data_get($payload, '_edited_report_fields.是否加购'));
        })->sortBy([
            fn ($a, $b) => self::hasPlatformNumber($b) <=> self::hasPlatformNumber($a),
            fn ($a, $b) => abs($a->id) <=> abs($b->id),
        ])->unique(self::groupKey(...))->pluck('id')->flip();

        return $records->mapWithKeys(fn ($record) => [$record->id => $winners->has($record->id)]);
    }

    public function guard(Collection $records, string $field): void
    {
        if ($records->isEmpty()) {
            return;
        }
        $editable = $this->editable($records);
        foreach ($records as $record) {
            if (! $editable->get($record->id)) {
                $reference = self::merchantNumber($record) ?: $record->reference;
                throw ValidationException::withMessages([$field => '订单 '.$reference.'（'.($record->payload['交易流水号'] ?? $record->reference).'）不可填写物流或采购成本。']);
            }
        }
    }

    private static function merchantNumber(FinanceRecord $record): string
    {
        $number = trim((string) ($record->payload['商户订单号'] ?? ''));

        return $number === '' ? trim((string) ($record->payload['_legacy_order_id'] ?? '')) : $number;
    }

    private static function groupKey(FinanceRecord $record): string
    {
        $number = self::merchantNumber($record);

        return $number === '' ? 'id:'.$record->id : 'merchant:'.$number;
    }

    private static function hasPlatformNumber(FinanceRecord $record): bool
    {
        return ! ($record->payload['_platform_missing'] ?? false) && filled($record->reference);
    }

    private static function isAddOn(mixed $value): bool
    {
        return in_array(strtolower(FinanceCatalog::simplified(trim((string) $value))), ['1', 'true', 'yes', '是', '加购', '已加购'], true);
    }
}
