<?php

namespace Tests\Unit;

use App\Portals\Finance\FinanceTransactionSummary;
use PHPUnit\Framework\TestCase;

class FinanceTransactionSummaryTest extends TestCase
{
    public function test_missing_summary_respects_channel_dimensions_and_keeps_currencies_separate(): void
    {
        $summary = new FinanceTransactionSummary;
        $items = [
            ['team' => '', 'subscription' => '订阅', 'currency' => 'USD', 'orders' => 2, 'amount' => '10', 'settlement' => '11', 'purchase' => '5'],
            ['team' => '深圳', 'subscription' => '', 'currency' => 'USD', 'orders' => 3, 'amount' => '20', 'settlement' => '20'],
            ['team' => '深圳', 'subscription' => '订阅', 'currency' => 'USD', 'orders' => 4, 'amount' => '100'],
        ];
        foreach (array_keys(FinanceTransactionSummary::VIEWS) as $tab) {
            $row = $summary->missingSummary($items, $tab, '2026-11');
            $requiresSubscription = FinanceTransactionSummary::requiresSubscription($tab);
            $this->assertSame($requiresSubscription ? 5 : 2, $row['orders']);
            $this->assertSame('5.00000000', $row['purchase']);
            $this->assertSame($requiresSubscription ? '31.00000000' : '11.00000000', $row['settlement']);
            $this->assertSame('缺失数据汇总', $row['account']);
        }
        $this->assertSame('30.00000000', $summary->missingSummary($items, 'combined', '2026-11')['amount']);
        $this->assertSame('USD', $summary->missingSummary($items, 'combined', '2026-11')['currency']);
        $this->assertSame(0, $summary->missingSummary([], 'paypal', '2026-11')['orders']);
    }

    public function test_usd_summary_uses_converted_settlement_and_converts_cny_costs_once(): void
    {
        $row = ['channel' => 'credit_card', 'account' => 'A', 'currency' => 'EUR', 'team' => '深圳',
            'subscription' => '订阅', 'month' => '2026-11', 'success' => true, 'failed' => false,
            'gross' => '100', 'settlement' => '108', 'fee' => '2', 'logistics' => '70', 'purchase' => '140'];
        $summary = new FinanceTransactionSummary;
        foreach (['credit_card', 'ye', 'worldpay', 'combined'] as $tab) {
            $items = $summary->summarize([$row, [...$row, 'currency' => 'GBP', 'gross' => '200', 'settlement' => '250']], $tab, usd: true, cnyPerUsd: '7');
            $this->assertCount(1, $items);
            $this->assertSame('USD', $items[0]['currency']);
            $this->assertSame('358.00000000', $items[0]['amount']);
            $this->assertSame('354.00000000', $items[0]['payable']);
            $this->assertSame('20.00000000', $items[0]['logistics']);
            $this->assertSame('40.00000000', $items[0]['purchase']);
        }
        $missing = $summary->summarize([$row, [...$row, 'settlement' => null]], 'credit_card', usd: true);
        $this->assertNull($missing[0]['amount']);
        $this->assertNull($missing[0]['settlement']);
        $this->assertNull($missing[0]['payable']);
        $this->assertNull($missing[0]['logistics']);
    }

    public function test_transaction_summary_keeps_actual_settlement_currencies_and_uses_each_channel_rate(): void
    {
        $base = ['channel' => 'credit_card', 'account' => 'A', 'team' => '深圳', 'subscription' => '',
            'month' => '2026-11', 'success' => true, 'failed' => false, 'gross' => '100'];
        $rows = [
            [...$base, 'payment_channel' => 'ye', 'currency' => 'USD', '_summary_settlement_currency' => 'GBP',
                'settlement' => '80', 'logistics' => '80', 'purchase' => '160'],
            [...$base, 'payment_channel' => 'worldpay', 'currency' => 'USD', '_summary_settlement_currency' => 'EUR',
                'settlement' => '90', 'logistics' => '75', 'purchase' => '150'],
        ];

        $items = (new FinanceTransactionSummary)->summarize($rows, 'combined', usd: true, cnyPerUsdByChannel: [
            'ye' => ['GBP' => '8'], 'worldpay' => ['EUR' => '7.5'],
        ]);
        $items = collect($items)->keyBy('currency');

        $this->assertSame(['EUR', 'GBP'], $items->keys()->sort()->values()->all());
        $this->assertSame('80.00000000', $items['GBP']['amount']);
        $this->assertSame('10.00000000', $items['GBP']['logistics']);
        $this->assertSame('20.00000000', $items['EUR']['purchase']);
    }
}
