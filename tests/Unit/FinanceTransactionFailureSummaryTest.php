<?php

namespace Tests\Unit;

use App\Portals\Finance\FinanceTransactionSummary;
use PHPUnit\Framework\TestCase;

class FinanceTransactionFailureSummaryTest extends TestCase
{
    public function test_single_scan_failure_summary_matches_separate_tab_summaries(): void
    {
        $rows = [];
        foreach (['paypal', 'xuzong1', 'xuzong2', 'ye', 'worldpay'] as $platform) {
            $channel = $platform === 'paypal' ? 'paypal' : 'credit_card';
            $rows[] = $this->row($channel, $platform, $platform.'-A', 'A', '订阅', success: true);
            $rows[] = $this->row($channel, $platform, $platform.'-B', 'B', '订阅', success: true);
            $rows[] = $this->row($channel, $platform, $platform.'-F', '', '', failed: true);
        }

        $summary = new FinanceTransactionSummary;
        $singleScan = $summary->summarizeFailuresByTab($rows);
        $platforms = [
            'paypal' => ['paypal'],
            'credit_card' => ['xuzong1', 'xuzong2'],
            'ye' => ['ye'],
            'worldpay' => ['worldpay'],
        ];
        foreach ($platforms as $tab => $selected) {
            $separate = $summary->summarize(array_filter($rows,
                fn ($row) => in_array($row['payment_channel'], $selected, true)), $tab);
            $expected = array_column($separate, 'failed_orders', 'account_group');
            $actual = array_column(array_filter($singleScan,
                fn ($row) => $row['_failure_tab'] === $tab), 'failed_orders', 'account_group');
            ksort($expected);
            ksort($actual);
            $this->assertSame($expected, $actual, $tab);
        }
    }

    private function row(string $channel, string $platform, string $account, string $team, string $subscription,
        bool $success = false, bool $failed = false): array
    {
        return [
            'channel' => $channel, 'payment_channel' => $platform, 'account' => $account, 'account_key' => $account,
            'currency' => 'USD', 'team' => $team, 'subscription' => $subscription, 'month' => '2026-11',
            'success' => $success, 'failed' => $failed, 'gross' => '10', 'settlement' => '10',
        ];
    }
}
