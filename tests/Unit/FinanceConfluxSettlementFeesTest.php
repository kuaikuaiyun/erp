<?php

namespace Tests\Unit;

use App\Portals\Finance\FinanceConfluxSettlement;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

class FinanceConfluxSettlementFeesTest extends TestCase
{
    public function test_statement_fees_streaming_aggregation_preserves_verified_fees(): void
    {
        $summary = [
            '商户号' => 'merchant-1', '账单描述符' => 'store-wallet', '交易日期' => '2026-11-06',
            '成功网关费' => '-0.70', '失败网关费' => '-0.35', '退款处理费' => '-2.00',
            '拒付处理费' => '0', 'RDR处理费' => '0', 'Ethoca处理费' => '0',
        ];
        $entries = [
            $this->entry(10, 'summary', $summary),
            $this->entry(1, 'sale', ['商户号' => 'merchant-1', '账单描述符' => 'store-wallet', '交易时间' => '2026-11-06 01:00:00', '结算货币' => 'USD', '结算金额' => '10']),
            $this->entry(2, 'sale', ['商户号' => 'merchant-1', '账单描述符' => 'store-wallet', '交易时间' => '2026-11-06 02:00:00', '结算货币' => 'USD', '结算金额' => '20']),
            $this->entry(3, 'failed', ['商户号' => 'merchant-1', '账单描述符' => 'store-wallet', '交易时间' => '2026-11-06 03:00:00']),
            $this->entry(4, 'refund', ['商户号' => 'merchant-1', '账单描述符' => 'store-wallet', '交易时间' => '2026-11-05 03:00:00']),
        ];
        $service = (new ReflectionClass(FinanceConfluxSettlement::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod($service, 'statementFees');
        $fees = $method->invoke($service, $entries, '2026-11-09');

        $this->assertSame(['hold' => '1.00', 'fee' => '0.50', 'per_order_fee' => '0.35', 'currency' => 'USD'], $fees[1]);
        $this->assertSame(['hold' => '2.00', 'fee' => '1.00', 'per_order_fee' => '0.35', 'currency' => 'USD'], $fees[2]);
        $this->assertSame(['per_order_fee' => '0.35', 'currency' => 'USD'], $fees[3]);
        $this->assertSame(['refund_fee' => '2.00', 'currency' => 'USD'], $fees[4]);
    }

    private function entry(int $id, string $kind, array $payload): object
    {
        return (object) ['id' => $id, 'kind' => $kind, 'payload' => json_encode($payload, JSON_THROW_ON_ERROR)];
    }
}
