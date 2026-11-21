<?php

namespace App\Portals\Finance;

class FinanceFilterSelection
{
    public const EMPTY = '__empty__';

    public static function availableRegions(array $filters, array $available): array
    {
        if (isset($filters['region'])) $filters['region'] = FinanceSettlementService::normaliseRegionLabel($filters['region']);
        if (isset($filters['regions'])) $filters['regions'] = array_values(array_unique(array_filter(array_map(
            FinanceSettlementService::normaliseRegionLabel(...), $filters['regions'],
        ), fn ($region) => $region !== '')));
        // Keep an explicit selection even if no current order has that region.
        // Dropping it silently broadens a no-match query into all regions.
        return $filters;
    }

    public static function matches(mixed $value, array $selected): bool
    {
        if ($selected === []) return true;
        return blank($value) ? in_array(self::EMPTY, $selected, true) : in_array((string) $value, $selected, true);
    }

    public static function applyQuery($query, string $column, array $selected): void
    {
        if ($selected === []) return;
        $values = array_values(array_diff($selected, [self::EMPTY]));
        $query->where(function ($scope) use ($column, $selected, $values): void {
            $scope->whereIn($column, $values);
            if (in_array(self::EMPTY, $selected, true)) {
                $scope->orWhereNull($column)->orWhere($column, '');
            }
        });
    }

    public static function rowMatches(array $row, array $filters, bool $paypalPayment = false): bool
    {
        $team = $paypalPayment ? ($row['operation_department'] ?? '') : ($row['card_layout']['team'] ?? $row['team'] ?? '');
        $region = $paypalPayment ? FinanceSettlementService::operationRegion($team)
            : ($row['card_layout']['operation_region'] ?? $row['operation_region'] ?? FinanceSettlementService::operationRegion($team));
        $subscription = $row['subscription'] ?? '';
        if ($subscription === '未匹配') $subscription = '';
        $subscriptions = array_map(fn ($value) => $value === '未匹配' ? self::EMPTY : FinanceSettlementService::subscriptionLabel($value), $filters['subscriptions'] ?? []);
        return self::matches($row['account_key'] ?? $row['unlinked_account'] ?? '', $filters['accounts'] ?? [])
            && self::matches($team, $filters['teams'] ?? [])
            && self::matches($region, $filters['regions'] ?? [])
            && self::matches(FinanceSettlementService::subscriptionLabel($subscription), $subscriptions);
    }
}
