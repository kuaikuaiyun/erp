<?php

namespace App\Portals\Payment;

class PaymentPayPalMonthlyCalculator
{
    /** @param array<string,int|float> $metrics @return array<string,int|float|null> */
    public function calculate(array $metrics, bool $includeActualPayout): array
    {
        $orderCount = max(0, (int) ($metrics['payment_order_count'] ?? 0));
        $receipts = round((float) ($metrics['received_amount'] ?? 0), 2);
        $refundCount = max(0, (int) ($metrics['refund_order_count'] ?? 0));
        $refunds = round((float) ($metrics['refund_amount'] ?? 0), 2);
        $disputeCount = max(0, (int) ($metrics['dispute_order_count'] ?? 0));
        $disputes = round((float) ($metrics['dispute_amount'] ?? 0), 2);
        $disputeFees = round((float) ($metrics['dispute_fee_amount'] ?? 0), 2);
        $chargebackFees = round((float) ($metrics['chargeback_fee_amount'] ?? 0), 2);
        $realisedDisputeLoss = round((float) ($metrics['realised_dispute_loss'] ?? 0), 2);
        $actualPayout = $includeActualPayout
            ? round($receipts - $refunds - $realisedDisputeLoss - $disputeFees - $chargebackFees, 2)
            : null;

        return [
            'payment_order_count' => $orderCount,
            'received_amount' => $receipts,
            'refund_order_count' => $refundCount,
            'refund_amount' => $refunds,
            'refund_amount_rate' => $this->percentage($refunds, $receipts),
            'dispute_order_count' => $disputeCount,
            'dispute_amount' => $disputes,
            'dispute_order_rate' => $orderCount > 0 ? round($disputeCount / $orderCount * 100, 2) : 0.0,
            'dispute_amount_rate' => $this->percentage($disputes, $receipts),
            'dispute_fee_amount' => $disputeFees,
            'chargeback_fee_amount' => $chargebackFees,
            'dispute_fee_rate' => $this->percentage($disputeFees, $receipts),
            'realised_dispute_loss' => $realisedDisputeLoss,
            'actual_payout_amount' => $actualPayout,
            'actual_payout_rate' => $actualPayout === null ? null : $this->percentage($actualPayout, $receipts),
        ];
    }

    private function percentage(float $numerator, float $denominator): float
    {
        return $denominator > 0 ? round($numerator / $denominator * 100, 2) : 0.0;
    }
}
