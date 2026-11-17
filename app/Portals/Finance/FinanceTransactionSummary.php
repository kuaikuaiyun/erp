<?php

namespace App\Portals\Finance;

class FinanceTransactionSummary
{
    public const VIEWS = ['paypal' => 'PayPal', 'credit_card' => '徐总信用卡', 'ye' => '叶总支付', 'worldpay' => 'Worldpay', 'combined' => 'Shopify'];

    private const COMMON = ['channel' => '通道', 'account' => '支付账户', 'currency' => '货币', 'team' => '区域', 'subscription' => '订阅类型',
        'month' => '年月', 'orders' => '订单数', 'amount' => '金额', 'settlement' => '结算金额'];

    private const PAYPAL = ['fee' => FinanceSettlementService::MONEY['fee'], 'hold' => '冻结金额', 'dispute_hold' => '争议冻结金额', 'refund' => '退款金额',
        'refund_fee' => '退款费用', 'dispute_refund' => '争议退款', 'dispute_fee' => '争议费', 'fee_return' => '返还手续费',
        'dispute_release' => '返还争议冻结', 'release' => '返还冻结'];

    private const CARD = ['fee' => FinanceSettlementService::MONEY['fee'], 'per_order_fee' => '单笔费用', 'refund' => '退款金额', 'refund_fee' => '退款费用',
        'chargeback' => '拒付金额', 'chargeback_fee' => '拒付费用', 'ethoca_refund' => 'ethoca-预警退款', 'ethoca_fee' => '拒付预警ethoca',
        'rdr_refund' => '拒付RDR金额', 'rdr_fee' => '拒付RDR费用', 'release' => '保证金返还'];

    public function columns(string $tab): array
    {
        return [...self::COMMON, ...match ($tab) {
            'paypal' => self::PAYPAL,
            'credit_card', 'ye', 'worldpay' => ['hold' => '保证金', ...self::CARD],
            'combined' => ['hold' => '保证金', 'dispute_hold' => '争议冻结金额', ...self::CARD],
        }, 'payable' => '应结算', 'logistics' => '物流-元', 'purchase' => '采购成本-元', 'failed_orders' => '失败订单数'];
    }

    public function summarize(iterable $rows, string $tab): array
    {
        $groups = [];
        $failedByMonth = [];
        foreach ($rows as $row) {
            $dimensions = ['account' => $row['account'], 'currency' => $row['currency'], 'team' => $row['team'],
                'subscription' => $row['subscription'], 'month' => $row['month']];
            $key = json_encode([$row['payment_channel'] ?? $row['channel'], $row['account_key'] ?? $row['account'], $dimensions], JSON_THROW_ON_ERROR);
            $group = &$groups[$key];
            $group ??= [...$dimensions, 'channel' => self::VIEWS[$tab], 'orders' => 0, 'account_group' => $key];
            if (! empty($row['_cost_adjustment'])) {
                foreach (array_intersect_key($row, ['purchase' => true, 'logistics' => true]) as $field => $value) {
                    $group[$field] = bcadd($group[$field] ?? '0', $value, 8);
                }
                unset($group);
                continue;
            }
            $group['orders'] += (int) $row['success'];
            if ($row['failed'] && ! $row['success']) {
                $failedByMonth[$row['month']] = ($failedByMonth[$row['month']] ?? 0) + 1;
            }
            $paypal = $row['channel'] === 'paypal';
            $values = array_intersect_key($row, [...self::PAYPAL, ...self::CARD, 'logistics' => true, 'purchase' => true]);
            $values['refund'] = FinanceSettlementService::refundIncludingSplit($row);
            $values['settlement'] = $row['failed'] ? null : $row['settlement'];
            $values['amount'] = $paypal ? $values['settlement'] : $row['gross'];
            if ($tab === 'combined' && $paypal) {
                $values['fee'] = $this->difference($row['fee'], $row['fee_return']);
                $values['dispute_hold'] = $this->difference($row['dispute_hold'], $row['dispute_release']);
                $values['ethoca_refund'] = $row['dispute_refund'];
                $values['ethoca_fee'] = self::shopifyPayPalDisputeFee($row['dispute_fee'], $row['month']);
            }
            foreach ($values as $field => $value) {
                if ($value !== null) {
                    $group[$field] = bcadd($group[$field] ?? '0', $value, 8);
                }
            }
            unset($group);
        }
        $deductions = match ($tab) {
            'paypal' => ['fee', 'hold', 'dispute_hold', 'refund', 'refund_fee', 'dispute_refund', 'dispute_fee'],
            'credit_card', 'ye', 'worldpay' => ['hold', ...array_diff(array_keys(self::CARD), ['release'])],
            'combined' => ['hold', 'dispute_hold', ...array_diff(array_keys(self::CARD), ['release'])],
        };
        foreach ($groups as &$group) {
            $payable = $group['settlement'] ?? null;
            if ($payable !== null) {
                foreach ($deductions as $field) {
                    $payable = bcsub($payable, $group[$field] ?? '0', 8);
                }
                foreach ($tab === 'paypal' ? ['fee_return', 'dispute_release', 'release'] : ['release'] as $field) {
                    $payable = bcadd($payable, $group[$field] ?? '0', 8);
                }
            }
            $group['payable'] = $payable;
            // The PayPal amount is USD in the template; do not add it to non-USD card amounts.
            if ($tab === 'combined' && $group['currency'] !== 'USD') {
                $group['amount'] = null;
            }
        }
        unset($group);

        return $this->allocateFailures($groups, $failedByMonth, $tab);
    }

    private function allocateFailures(array $groups, array $failedByMonth, string $tab): array
    {
        $teams = [];
        foreach ($groups as $key => &$group) {
            $group['failed_orders'] = 0;
            if ($group['orders'] > 0 && filled($group['team']) && filled($group['subscription'])) {
                $teams[$group['month']][$group['team']][$key] = $group['orders'];
            }
        }
        unset($group);

        foreach ($failedByMonth as $month => $failed) {
            $teamGroups = $teams[$month] ?? [];
            if ($teamGroups === []) {
                $key = json_encode([$tab, $month, 'unallocated'], JSON_THROW_ON_ERROR);
                $groups[$key] = ['account_group' => $key, 'channel' => self::VIEWS[$tab], 'account' => '', 'currency' => '',
                    'team' => '未分配', 'subscription' => '未分配', 'month' => $month, 'orders' => 0, 'failed_orders' => $failed];
                continue;
            }
            foreach ($this->proportionalShares(array_map(array_sum(...), $teamGroups), $failed) as $team => $share) {
                foreach ($this->proportionalShares($teamGroups[$team], $share) as $key => $count) {
                    $groups[$key]['failed_orders'] = $count;
                }
            }
        }

        return array_values($groups);
    }

    private function proportionalShares(array $weights, int $total): array
    {
        ksort($weights, SORT_STRING);
        arsort($weights, SORT_NUMERIC);
        $sum = array_sum($weights);
        $shares = array_map(fn (int $weight) => intdiv($weight * $total, $sum), $weights);
        $remainder = $total - array_sum($shares);
        foreach (array_slice(array_keys($shares), 0, $remainder) as $key) {
            $shares[$key]++;
        }

        return $shares;
    }

    public static function shopifyPayPalDisputeFee(?string $fee, string $month): ?string
    {
        // Finance workbook, 再汇总 R16:R18: August subtracts PayPal dispute fees.
        return $fee !== null && $month === '2026-08' ? bcsub('0', $fee, 8) : $fee;
    }

    private function difference(?string $amount, ?string $return): ?string
    {
        return $amount === null && $return === null ? null : bcsub($amount ?? '0', $return ?? '0', 8);
    }
}
