<?php

namespace App\Portals\Finance;

class FinanceSettlementOverviewGroups
{
    public static function mergeMonths(array $months): array
    {
        $result = [];
        foreach ($months as $groups) {
            foreach ($groups as $group) {
                $key = FinanceExportSelection::key($group);
                if (! isset($result[$key])) {
                    $result[$key] = $group;
                    continue;
                }
                foreach (['count', 'success_count', 'failed_count'] as $field) {
                    $result[$key][$field] = ($result[$key][$field] ?? 0) + ($group[$field] ?? 0);
                }
                $result[$key]['settlement'] = $result[$key]['settlement'] === null || $group['settlement'] === null
                    ? null : bcadd($result[$key]['settlement'], $group['settlement'], 8);
            }
        }
        return array_values($result);
    }

    public static function mergeYeAccounts(array $groups): array
    {
        $result = [];
        $yeIndex = null;
        foreach ($groups as $group) {
            if ($group['channel'] !== 'ye') {
                $result[] = $group;
                continue;
            }
            if ($yeIndex === null) {
                $yeIndex = count($result);
                $group['name'] = '叶总';
                unset($group['account_group']);
                $result[] = $group;
                continue;
            }
            foreach (['count', 'success_count', 'failed_count'] as $field) {
                $result[$yeIndex][$field] = ($result[$yeIndex][$field] ?? 0) + ($group[$field] ?? 0);
            }
            // A missing constituent amount must not become a partial total.
            $result[$yeIndex]['settlement'] = $result[$yeIndex]['settlement'] === null || $group['settlement'] === null
                ? null : bcadd($result[$yeIndex]['settlement'], $group['settlement'], 8);
        }
        return $result;
    }
}
