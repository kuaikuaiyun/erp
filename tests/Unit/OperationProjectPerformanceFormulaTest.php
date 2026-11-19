<?php

namespace Tests\Unit;

use App\Portals\Fashion\OperationProjectCatalog;
use PHPUnit\Framework\TestCase;

class OperationProjectPerformanceFormulaTest extends TestCase
{
    public function test_the_three_project_formulas_match_the_department_workbook(): void
    {
        self::assertSame([
            'gross_margin_rate' => 0.625,
            'store_fee_rate' => 0.01,
            'usd_to_rmb_rate' => 7.0,
        ], OperationProjectCatalog::performanceFormula('danbao-general'));
        self::assertSame([
            'gross_margin_rate' => 0.50,
            'store_fee_rate' => 0.01,
            'usd_to_rmb_rate' => 7.0,
        ], OperationProjectCatalog::performanceFormula('baokuan-fashion'));
        self::assertSame([
            'gross_margin_rate' => 0.45,
            'store_fee_rate' => 0.015,
            'usd_to_rmb_rate' => 7.0,
        ], OperationProjectCatalog::performanceFormula('baokuan-general'));
        self::assertNull(OperationProjectCatalog::performanceFormula('fuzhuang'));
    }
}
