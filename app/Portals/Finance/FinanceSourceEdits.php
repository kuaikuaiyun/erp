<?php

namespace App\Portals\Finance;

use App\Models\FinanceRecord;
use DateTimeInterface;

class FinanceSourceEdits
{
    public const KEY = '_finance_source_edits';

    // Called only by the detail editor, never by synchronization or imports.
    public static function remember(FinanceRecord $record, array $attributes, array $payload): array
    {
        $original = $record->payload;
        $edits = $original[self::KEY] ?? ['attributes' => [], 'payload' => []];
        foreach ($attributes as $field => $value) {
            $prior = $record->getAttribute($field);
            $comparable = static fn ($item) => $item instanceof DateTimeInterface ? $item->format('Y-m-d H:i:s') : (string) $item;
            if ($comparable($prior) !== $comparable($value)) {
                $edits['attributes'][$field] = $value instanceof DateTimeInterface ? $value->format('Y-m-d H:i:s') : $value;
            }
        }
        foreach ($payload as $field => $value) {
            if ($field !== self::KEY && (! array_key_exists($field, $original) || $original[$field] !== $value)) {
                $edits['payload'][$field] = $value;
            }
        }
        $edits['removed'] = array_values(array_unique([...($edits['removed'] ?? []), ...array_keys(array_diff_key($original, $payload))]));
        $edits['removed'] = array_values(array_diff($edits['removed'], array_keys($edits['payload']), [self::KEY]));
        if ($edits['attributes'] !== [] || $edits['payload'] !== [] || $edits['removed'] !== []) {
            $edits['identity'] ??= ['reference' => $record->reference, 'account' => $record->account,
                'gateway' => $original['网关接入号'] ?? '', 'merchant_order' => $original['商户订单号'] ?? ''];
            $payload[self::KEY] = $edits;
        }
        return $payload;
    }

    public static function apply(FinanceRecord $record, array $incoming): array
    {
        $original = $record->payload;
        $edits = $original[self::KEY] ?? null;
        $incoming = array_replace($incoming, $edits['attributes'] ?? []);
        if (isset($incoming['payload'])) {
            $incoming['payload'] = array_replace($incoming['payload'], $edits['payload'] ?? [],
                array_intersect_key($original, array_flip([self::KEY, '_edited_report_fields', '_edited_platform_order_id'])));
            foreach ($edits['removed'] ?? [] as $field) unset($incoming['payload'][$field]);
        }
        return $incoming;
    }
}
