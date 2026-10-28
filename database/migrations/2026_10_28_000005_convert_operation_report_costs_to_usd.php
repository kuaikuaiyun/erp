<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const CNY_PER_USD = 6.5;

    public function up(): void
    {
        $connection = config('admin.database.connection') ?: config('database.default');
        if (! Schema::connection($connection)->hasTable('operation_reports')) return;

        DB::connection($connection)->table('operation_reports')->orderBy('id')->chunkById(100, function ($reports) use ($connection): void {
            foreach ($reports as $report) {
                $rows = json_decode((string) $report->metric_rows, true) ?: [];
                $firstRevenue = 0.0;
                foreach ($rows as &$row) {
                    $row['first_purchase_cost'] = round((float) ($row['first_purchase_cost'] ?? 0) / self::CNY_PER_USD, 4);
                    $row['first_shipping_cost'] = round((float) ($row['first_shipping_cost'] ?? 0) / self::CNY_PER_USD, 4);
                    $firstRevenue += (float) ($row['first_total_revenue'] ?? 0);
                }
                unset($row);

                $purchaseCost = round((float) $report->first_purchase_cost / self::CNY_PER_USD, 4);
                $shippingCost = round((float) $report->first_shipping_cost / self::CNY_PER_USD, 4);
                $firstProfit = round(
                    $firstRevenue - $purchaseCost - $shippingCost
                    - (float) $report->first_return_fee - (float) $report->actual_ad_spend,
                    4,
                );

                DB::connection($connection)->table('operation_reports')->where('id', $report->id)->update([
                    'metric_rows' => json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'first_purchase_cost' => $purchaseCost,
                    'first_shipping_cost' => $shippingCost,
                    'first_profit' => $firstProfit,
                    'overall_profit' => round($firstProfit + (float) $report->renewal_net_revenue, 4),
                    'updated_at' => now(),
                ]);
            }
        });
    }

    public function down(): void
    {
        // 历史快照无法安全区分人工修正值，不执行反向放大。
    }
};
