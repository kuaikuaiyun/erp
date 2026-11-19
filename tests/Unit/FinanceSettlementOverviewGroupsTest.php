<?php

namespace Tests\Unit;

use App\Portals\Finance\FinanceCatalog;
use App\Portals\Finance\FinanceExportSelection;
use App\Portals\Finance\FinanceSettlementOverviewGroups;
use PHPUnit\Framework\TestCase;

class FinanceSettlementOverviewGroupsTest extends TestCase
{
    public function test_five_ye_accounts_merge_without_worldpay_or_xuzong(): void
    {
        $groups = [];
        foreach (['SHANGYUN-v2' => 'xuzong2', '徐总旧账户' => 'xuzong1', 'xadanbao' => 'ye',
            'Worldpay' => 'worldpay', 'xmdanbao' => 'ye', 'szdanbao' => 'ye', 'zzdanbao' => 'ye', 'pindanbao' => 'ye',
            '郑州单包' => 'ye', 'kuaiy' => 'ye', '新增叶总账户' => 'ye'] as $name => $channel) {
            $groups[] = compact('name', 'channel') + ['success_count' => 2, 'failed_count' => 3, 'settlement' => '0.12345678'];
        }
        $merged = FinanceSettlementOverviewGroups::mergeYeAccounts($groups);
        $this->assertCount(4, $merged);
        $this->assertSame('叶总', $merged[2]['name']);
        $this->assertSame(16, $merged[2]['success_count']);
        $this->assertSame(24, $merged[2]['failed_count']);
        $this->assertSame('0.98765424', $merged[2]['settlement']);
        $this->assertSame('Worldpay', $merged[3]['name']);
        $this->assertSame('徐总1', FinanceCatalog::settlementProjectName($merged[1]['name'], $merged[1]['channel']));
        $this->assertSame('徐总2', FinanceCatalog::settlementProjectName($merged[0]['name'], $merged[0]['channel']));
        $this->assertCount(4, array_unique(array_map([FinanceExportSelection::class, 'key'], $merged)));
    }

    public function test_missing_amount_stays_missing_and_filtered_subset_merges(): void
    {
        $row = ['channel' => 'ye', 'success_count' => 1, 'failed_count' => 0];
        $merged = FinanceSettlementOverviewGroups::mergeYeAccounts([
            $row + ['name' => 'xadanbao', 'settlement' => null],
            $row + ['name' => 'xmdanbao', 'settlement' => '10.00'],
        ]);
        $this->assertCount(1, $merged);
        $this->assertNull($merged[0]['settlement']);
        $this->assertSame(2, $merged[0]['success_count']);
    }

    public function test_selected_months_accumulate_by_group_and_preserve_unknown_amounts(): void
    {
        $row = ['name' => '叶总', 'channel' => 'ye', 'success_count' => 2, 'failed_count' => 1, 'settlement' => '10.12345678'];
        $result = FinanceSettlementOverviewGroups::mergeMonths(['2026-09' => [$row], '2026-11' => [$row]]);
        $this->assertCount(1, $result);
        $this->assertSame(4, $result[0]['success_count']);
        $this->assertSame('20.24691356', $result[0]['settlement']);
        $row['settlement'] = null;
        $this->assertNull(FinanceSettlementOverviewGroups::mergeMonths([[$row], $result])[0]['settlement']);
    }
}
