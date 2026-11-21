<?php

namespace Tests\Unit;

use App\Portals\Finance\FinanceFilterSelection;
use App\Portals\Finance\FinanceSettlementService;
use PHPUnit\Framework\TestCase;

class FinanceRegionTest extends TestCase
{
    public function test_subscription_labels_do_not_become_part_of_a_region(): void
    {
        foreach ([
            '深圳外部shopify订阅' => '深圳', '订阅雨果团队' => '雨果', '蒋总订阅部门' => '蒋总',
            '订阅/雨果团队' => '雨果', '雨果訂閱团队' => '雨果', 'TK订阅团队' => '王总',
            '订阅' => '', '订阅部门' => '', '订阅刷单' => '刷单',
            '厦门订阅/深圳订阅' => '', 'AI开品部门' => 'AI开品',
        ] as $department => $region) {
            $this->assertSame($region, FinanceSettlementService::operationRegion($department), $department);
        }
    }

    public function test_saved_region_filters_use_the_clean_label_and_keep_the_explicit_empty_option(): void
    {
        $filters = FinanceFilterSelection::availableRegions([
            'region' => '雨果订阅', 'regions' => ['雨果订阅', '雨果', '__empty__', '订阅'],
        ], ['雨果']);
        $this->assertSame('雨果', $filters['region']);
        $this->assertSame(['雨果', '__empty__'], $filters['regions']);
        $this->assertSame('华南/华东', FinanceSettlementService::normaliseRegionLabel('华南/华东'));
    }
}
