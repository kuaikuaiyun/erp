<?php

namespace Tests\Unit;

use App\Portals\Finance\FinanceSummaryProjection;
use Mockery;
use Tests\TestCase;

class FinanceLossProjectionTest extends TestCase
{
    public function test_losses_reuse_usd_variants_instead_of_native_settlement_amounts(): void
    {
        $projection = Mockery::mock(FinanceSummaryProjection::class)->makePartial();
        $filters = ['timezone' => 'Asia/Shanghai'];
        $projection->shouldReceive('stream')->once()->with('credit_card', $filters, true, null, ['xuzong1', 'xuzong2'])
            ->andReturn([['payment_channel' => 'xuzong1', 'settlement' => '20', 'logistics' => '7']]);
        $projection->shouldReceive('stream')->once()->with('credit_card', $filters, false, null, ['ye', 'worldpay'])
            ->andReturn([['payment_channel' => 'ye', 'settlement' => '110', 'purchase' => '14']]);
        $rows = iterator_to_array($projection->lossStream('credit_card', []), false);
        $this->assertSame(['20', '110'], array_column($rows, 'settlement'));
        $this->assertSame('7', $rows[0]['logistics']);
        $this->assertSame('14', $rows[1]['purchase']);
    }

    public function test_a_stale_variant_falls_back_instead_of_returning_a_partial_loss_total(): void
    {
        $projection = Mockery::mock(FinanceSummaryProjection::class)->makePartial();
        $projection->shouldReceive('stream')->with('credit_card', ['timezone' => 'Asia/Shanghai'], true, null, ['xuzong1', 'xuzong2'])
            ->andReturn([['settlement' => '20']]);
        $projection->shouldReceive('stream')->with('credit_card', ['timezone' => 'Asia/Shanghai'], false, null, ['ye', 'worldpay'])
            ->andReturnNull();
        $this->assertNull($projection->lossStream('credit_card', []));
    }

    public function test_a_selected_loss_channel_does_not_load_other_platforms(): void
    {
        $projection = Mockery::mock(FinanceSummaryProjection::class)->makePartial();
        $projection->shouldReceive('stream')->once()->with('credit_card', ['timezone' => 'Asia/Shanghai'], false, null, ['worldpay'])
            ->andReturn([['payment_channel' => 'worldpay', 'settlement' => '25']]);
        $this->assertSame(['25'], array_column(iterator_to_array($projection->lossStream('credit_card', [], ['worldpay']), false), 'settlement'));
    }
}
