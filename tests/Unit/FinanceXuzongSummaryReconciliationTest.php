<?php

namespace Tests\Unit;

use App\Portals\Finance\FinanceSettlementService;
use App\Portals\Finance\FinanceTransactionSummary;
use PHPUnit\Framework\TestCase;

class FinanceXuzongSummaryReconciliationTest extends TestCase
{
    private function row(array $values = []): array
    {
        return array_replace(array_fill_keys(array_keys(FinanceSettlementService::MONEY), null), [
            'channel' => 'credit_card', 'payment_channel' => 'xuzong2', 'account' => 'SHANGYUN-v2',
            'currency' => 'USD', 'team' => '郑州', 'subscription' => '订阅', 'month' => '2026-10',
            'success' => true, 'failed' => false, 'gross' => '100', 'settlement' => '100',
        ], $values);
    }

    public function test_failed_transaction_fees_are_summed_once_in_card_and_combined_views(): void
    {
        $rows = [$this->row(['per_order_fee' => '0.30']),
            $this->row(['success' => false, 'failed' => true, 'settlement' => null, 'per_order_fee' => '0.30'])];
        foreach (['credit_card', 'combined'] as $tab) {
            $items = (new FinanceTransactionSummary)->summarize($rows, $tab, usd: true);
            $this->assertSame('0.60000000', $items[0]['per_order_fee'], $tab);
            $this->assertSame('99.40000000', $items[0]['payable'], $tab);
            $this->assertSame(1, $items[0]['failed_orders']);
        }
    }

    public function test_cdrn_and_chargeback_returns_participate_in_both_summary_views(): void
    {
        foreach (['credit_card', 'combined'] as $tab) {
            $items = (new FinanceTransactionSummary)->summarize([
                $this->row(['cdrn_fee' => '20', 'chargeback_return' => '3']),
            ], $tab, usd: true);
            $this->assertSame('20.00000000', $items[0]['cdrn_fee'] ?? null, $tab);
            $this->assertSame('3.00000000', $items[0]['chargeback_return'] ?? null, $tab);
            $this->assertSame('83.00000000', $items[0]['payable'], $tab);
        }
    }

    public function test_xuzong_summary_preserves_each_details_rounded_net_amount(): void
    {
        $rows = [$this->row(['settlement' => '10.00', '_summary_xuzong_detail' => true, '_summary_payable' => '8.51']),
            $this->row(['settlement' => '20.00', '_summary_xuzong_detail' => true, '_summary_payable' => '16.52']),
            $this->row(['success' => false, 'failed' => true, 'settlement' => null,
                'per_order_fee' => '0.30', '_summary_xuzong_detail' => true, '_summary_payable' => '-0.30'])];
        foreach (['credit_card', 'combined'] as $tab) {
            $items = (new FinanceTransactionSummary)->summarize($rows, $tab, usd: true);
            $this->assertSame('24.73000000', $items[0]['payable'], $tab);
        }
    }

    public function test_missing_detail_net_cannot_be_hidden_by_other_rows_or_the_total(): void
    {
        $summary = new FinanceTransactionSummary;
        foreach (['credit_card', 'combined'] as $tab) {
            $items = $summary->summarize([
                $this->row(['_summary_xuzong_detail' => true, '_summary_payable' => null]),
                $this->row(['_summary_xuzong_detail' => true, '_summary_payable' => '90', 'team' => '深圳']),
                $this->row(),
            ], $tab, usd: true);
            $this->assertNull($items[0]['payable']);
            $this->assertContains('payable', $items[0]['_incomplete_usd_fields']);
            $this->assertNull($summary->totalSummary($items, $tab, '2026-10')['payable']);
        }
    }
}
