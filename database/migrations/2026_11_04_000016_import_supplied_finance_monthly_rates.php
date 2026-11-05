<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $db = DB::connection(config('admin.database.connection') ?: config('database.default'));
        foreach (['2026-08' => '6.7865', '2026-09' => '6.7628', '2026-10' => '6.7628'] as $month => $rate) {
            if ($db->table('finance_period_locks')->where('month', $month)->whereNotNull('report_snapshot')->exists()) {
                continue;
            }
            $db->table('finance_monthly_exchange_rates')->insertOrIgnore([
                'month' => $month, 'currency' => 'USD', 'cny_rate' => $rate,
                'source' => '用户提供：汇总损耗表.xlsx／损耗表 B2:D2',
                'updated_by' => 0, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void {}
};
