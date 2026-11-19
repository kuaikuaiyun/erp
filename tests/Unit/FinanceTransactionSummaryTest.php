<?php

namespace Tests\Unit;

use App\Portals\Finance\FinanceTransactionSummary;
use PHPUnit\Framework\TestCase;

class FinanceTransactionSummaryTest extends TestCase
{
    public function test_combined_summary_reads_source_once_and_matches_each_channels_missing_total(): void
    {
        $base = array_fill_keys(array_keys(\App\Portals\Finance\FinanceSettlementService::MONEY), null) + [
            'currency' => 'USD', 'month' => '2026-11', 'team' => '深圳', 'subscription' => '订阅',
            'success' => true, 'failed' => false, 'settlement' => '100', 'gross' => '100',
        ];
        $rows = [];
        foreach (['paypal', 'xuzong1', 'xuzong2', 'ye', 'worldpay'] as $platform) {
            $row = ['channel' => $platform === 'paypal' ? 'paypal' : 'credit_card', 'payment_channel' => $platform,
                'account' => $platform, 'account_key' => $platform] + $base;
            array_push($rows, $row, [...$row, 'team' => '', 'subscription' => '', 'refund' => '5'],
                [...$row, 'success' => false, 'failed' => true, 'settlement' => null],
                [...$row, 'settlement' => null], [...$row, '_cost_adjustment' => true, 'purchase' => '7']);
        }
        $summary = new FinanceTransactionSummary;
        $expected = $summary->summarize($rows, 'combined', usd: true);
        $missing = [];
        foreach (['paypal', 'credit_card', 'ye', 'worldpay'] as $tab) {
            $sourceRows = array_filter($rows, fn ($row) => $tab === 'credit_card'
                ? in_array($row['payment_channel'], ['xuzong1', 'xuzong2'], true) : $row['payment_channel'] === $tab);
            foreach ($summary->summarize($sourceRows, $tab, usd: true) as $item) {
                if (! filled($item['team']) || ($summary::requiresSubscription($tab) && ! filled($item['subscription']))) $missing[] = $item;
            }
        }
        $expected[] = ['_combined_missing_summary_groups' => $missing];
        $reads = 0;
        $source = (function () use ($rows, &$reads) {
            foreach ($rows as $row) { $reads++; yield $row; }
        })();
        $this->assertSame($expected, $summary->summarizeCombined($source));
        $this->assertSame(count($rows), $reads);
    }

    public function test_database_group_counts_keep_orders_failure_allocation_and_amounts_equal(): void
    {
        $base = array_fill_keys(array_keys(\App\Portals\Finance\FinanceSettlementService::MONEY), null) + [
            'channel' => 'credit_card', 'payment_channel' => 'xuzong2', 'account' => 'A',
            'currency' => 'USD', 'month' => '2026-11', 'team' => '深圳', 'subscription' => '订阅',
            'success' => true, 'failed' => false, 'settlement' => '100', 'gross' => '100',
        ];
        $failed = [...$base, 'success' => false, 'failed' => true, 'settlement' => null];
        $summary = new FinanceTransactionSummary;
        $rows = [$base, $base, $base, $failed, $failed];
        $grouped = [[...$base, '_row_count' => 3, 'settlement' => '300', 'gross' => '300'],
            [...$failed, '_row_count' => 2, 'gross' => '200']];
        $this->assertSame($summary->summarize($rows, 'credit_card', usd: true), $summary->summarize($grouped, 'credit_card', usd: true));
        $this->assertSame($summary->summarizeFailuresByTab($rows), $summary->summarizeFailuresByTab($grouped));
    }

    public function test_transaction_account_labels_and_combined_ye_rollup_apply_to_saved_rows(): void
    {
        $summary = new FinanceTransactionSummary;
        $base = ['currency' => 'USD', 'month' => '2026-11', 'orders' => 2, 'failed_orders' => 1,
            'team' => '深圳', 'subscription' => '订阅', 'amount' => '10.1', 'settlement' => '9.9', 'fee' => '0.2'];
        $items = [
            $base + ['account' => 'SHANGYUN-v2', '_payment_channel' => 'xuzong2', 'channel' => '徐总信用卡', 'account_group' => 'card'],
            $base + ['account' => 'xadanbao', '_payment_channel' => 'ye', 'channel' => '叶总支付', 'account_group' => 'ye1'],
            $base + ['account' => 'Worldpay', '_payment_channel' => 'worldpay', 'channel' => 'Worldpay', 'account_group' => 'worldpay'],
            $base + ['account' => '郑州单包', '_payment_channel' => 'ye', 'channel' => '叶总支付', 'account_group' => 'ye2'],
        ];
        $combined = $summary->displayAccounts($items, 'combined');
        $this->assertCount(3, $combined);
        $this->assertSame('徐总2', $combined[0]['account']);
        $this->assertSame('叶总', $combined[1]['account']);
        $this->assertSame(4, $combined[1]['orders']);
        $this->assertSame(2, $combined[1]['failed_orders']);
        $this->assertSame('20.20000000', $combined[1]['amount']);
        $this->assertSame('0.40000000', $combined[1]['fee']);
        $this->assertSame('Worldpay', $combined[2]['account']);
        $detail = $summary->displayAccounts([$items[1], $items[3]], 'ye');
        $this->assertCount(2, $detail);
        $this->assertSame('叶总', $detail[0]['account']);
        $this->assertSame('ye1', $detail[0]['account_group']);
        $this->assertSame('深圳', $detail[0]['team']);
        $differentCurrency = $items[3];
        $differentCurrency['currency'] = 'EUR';
        $this->assertCount(2, $summary->displayAccounts([$items[1], $differentCurrency], 'combined'));
        $items[3]['_incomplete_usd_fields'] = ['settlement'];
        $this->assertNull($summary->displayAccounts([$items[1], $items[3]], 'combined')[0]['settlement']);
    }

    public function test_missing_summary_respects_channel_dimensions_and_keeps_currencies_separate(): void
    {
        $summary = new FinanceTransactionSummary;
        $items = [
            ['team' => '', 'subscription' => '订阅', 'currency' => 'USD', 'orders' => 2, 'amount' => '10', 'settlement' => '11', 'purchase' => '5'],
            ['team' => '深圳', 'subscription' => '', 'currency' => 'USD', 'orders' => 3, 'amount' => '20', 'settlement' => '20'],
            ['team' => '深圳', 'subscription' => '订阅', 'currency' => 'USD', 'orders' => 4, 'amount' => '100'],
        ];
        foreach (array_keys(FinanceTransactionSummary::VIEWS) as $tab) {
            // Combined receives the source tabs' missing groups, since its
            // account rows no longer contain team/subscription dimensions.
            $missingItems = $tab === 'combined' ? array_slice($items, 0, 2) : $items;
            $row = $summary->missingSummary($missingItems, $tab, '2026-11');
            $requiresSubscription = FinanceTransactionSummary::requiresSubscription($tab);
            $this->assertSame($requiresSubscription ? 5 : 2, $row['orders']);
            $this->assertSame('5.00000000', $row['purchase']);
            $this->assertSame($requiresSubscription ? '31.00000000' : '11.00000000', $row['settlement']);
            $this->assertSame('缺失数据汇总', $row['account']);
        }
        $this->assertSame('30.00000000', $summary->missingSummary(array_slice($items, 0, 2), 'combined', '2026-11')['amount']);
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
