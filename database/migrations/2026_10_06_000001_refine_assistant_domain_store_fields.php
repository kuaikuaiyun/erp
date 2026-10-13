<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $connection = config('admin.database.connection') ?: config('database.default');
        $schema = Schema::connection($connection);

        if (!$schema->hasTable('assistant_domain_stores')) {
            return;
        }

        $missingColumns = [
            'credit_card' => 100,
            'wallet' => 100,
            'paypal' => 100,
            'other_payment' => 100,
        ];

        $columnsToAdd = array_filter(
            $missingColumns,
            fn (int $length, string $column): bool => !$schema->hasColumn('assistant_domain_stores', $column),
            ARRAY_FILTER_USE_BOTH,
        );

        if ($columnsToAdd !== []) {
            $schema->table('assistant_domain_stores', function (Blueprint $table) use ($columnsToAdd): void {
                foreach ($columnsToAdd as $column => $length) {
                    $table->string($column, $length)->nullable();
                }
            });
        }

        $database = DB::connection($connection);

        if ($schema->hasColumn('assistant_domain_stores', 'payment_type')
            && $schema->hasColumn('assistant_domain_stores', 'payment_note')) {
            $legacyPaymentMappings = [
                'credit_card' => [
                    'legacy_type' => 'credit_card',
                    'accounts' => ['F支付', '叶总厦门单包', '叶总深圳单包', '叶总郑州单包', '叶总西安单包', '叶总pin单包', '叶总郑州普货', '叶总深圳极客', '徐州信用卡', '酷迈普货', '酷迈百货', '酷迈英国服装'],
                ],
                'wallet' => [
                    'legacy_type' => 'wallet',
                    'accounts' => ['F支付', '叶总厦门单包', '叶总深圳单包', '叶总郑州单包', '叶总西安单包', '叶总郑州普货', '叶总深圳极客'],
                ],
                'paypal' => [
                    'legacy_type' => 'paypal',
                    'accounts' => ['1-尚雲科技', '2-立鉑', '3-嘉匯環球貿易', '4-啟盛亞洲商貿', '5-晟達恆通商貿'],
                ],
                'other_payment' => [
                    'legacy_type' => 'other',
                    'accounts' => ['payments'],
                ],
            ];

            foreach ($legacyPaymentMappings as $targetColumn => $mapping) {
                $database->table('assistant_domain_stores')
                    ->where('payment_type', $mapping['legacy_type'])
                    ->whereIn('payment_note', $mapping['accounts'])
                    ->update([$targetColumn => DB::raw('payment_note')]);
            }
        }

        $database->table('assistant_domain_stores')
            ->where('domain_check_status', 'normal')
            ->update(['domain_check_status' => 'no']);
        $database->table('assistant_domain_stores')
            ->where('domain_check_status', 'blocked')
            ->update(['domain_check_status' => 'yes']);
        $database->table('assistant_domain_stores')
            ->where('shop_status', 'enabled')
            ->update(['shop_status' => 'in_use']);
        $database->table('assistant_domain_stores')
            ->whereIn('shop_status', ['closed', 'idle'])
            ->update(['shop_status' => 'abandoned']);
        $database->table('assistant_domain_stores')
            ->where('shop_status', 'banned')
            ->update(['shop_status' => 'stopped']);

        $schema->table('assistant_domain_stores', function (Blueprint $table): void {
            $table->string('domain_check_status', 20)->default('no')->change();
            $table->string('shop_status', 20)->default('in_use')->change();
            $table->string('team', 100)->nullable()->change();
        });
    }

    public function down(): void
    {
        // Intentionally non-destructive: rolling back must not discard newly
        // recorded payment accounts or revert users' corrected status values.
    }
};
