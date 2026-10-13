<?php

namespace App\Portals\Logistics;

use App\Models\LogisticsOrder;

class LogisticsProfitCalculator
{
    public function recalculate(LogisticsOrder $order): void
    {
        $paid = $this->number($order->paid_amount_usd);
        if ($paid === null) {
            $order->fill($this->emptyProfitFields());

            return;
        }

        $handlingFee = $this->round($paid * (float) config('logistics.profit.handling_fee_rate', 0.06));
        $proportionalRefund = $this->round($paid * (float) config('logistics.profit.proportional_refund_rate', 0.08));
        $productCost = $this->number($order->product_cost_usd);
        $shipping = $this->number($order->total_shipping_usd) ?? 0.0;

        $order->handling_fee_usd = $handlingFee;
        $order->proportional_refund_usd = $proportionalRefund;

        if ($productCost === null) {
            $order->fill([
                'proportional_profit_usd' => null,
                'proportional_profit_rate' => null,
                'financial_profit_usd' => null,
                'financial_profit_rate' => null,
                'operation_profit_usd' => null,
                'operation_profit_rate' => null,
            ]);

            return;
        }

        $proportionalProfit = $this->round($paid - $productCost - $handlingFee - $shipping - $proportionalRefund);
        $financialProfit = $this->round($paid - $productCost - $handlingFee - $shipping);

        $order->fill([
            'proportional_profit_usd' => $proportionalProfit,
            'proportional_profit_rate' => $this->rate($proportionalProfit, $paid),
            'financial_profit_usd' => $financialProfit,
            'financial_profit_rate' => $this->rate($financialProfit, $paid),
            'operation_profit_usd' => $financialProfit,
            'operation_profit_rate' => $this->rate($financialProfit, $paid),
        ]);
    }

    /** @return array<string, null> */
    private function emptyProfitFields(): array
    {
        return array_fill_keys([
            'handling_fee_usd',
            'proportional_refund_usd',
            'proportional_profit_usd',
            'proportional_profit_rate',
            'financial_profit_usd',
            'financial_profit_rate',
            'operation_profit_usd',
            'operation_profit_rate',
        ], null);
    }

    private function number(mixed $value): ?float
    {
        return $value === null || $value === '' || !is_numeric($value) ? null : (float) $value;
    }

    private function round(float $value): string
    {
        return number_format(round($value, 4), 4, '.', '');
    }

    private function rate(float $profit, float $paid): ?string
    {
        return $paid == 0.0 ? null : $this->round(($profit / $paid) * 100);
    }
}
