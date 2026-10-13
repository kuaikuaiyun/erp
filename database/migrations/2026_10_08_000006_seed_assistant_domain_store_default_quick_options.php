<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $connection = config('admin.database.connection') ?: config('database.default');

        if (!Schema::connection($connection)->hasTable('assistant_domain_store_quick_options')) {
            return;
        }

        $defaults = [
            'site_builder' => [
                'xshopy', 'nbc', 'shopyy', 'shopline', 'super_store', 'shoplazza', 'shopify',
            ],
            'credit_card' => [
                'f_payment', 'ye_xiamen_single', 'ye_shenzhen_single', 'ye_zhengzhou_single',
                'ye_xian_single', 'ye_pin_single', 'ye_zhengzhou_general', 'ye_shenzhen_geek',
                'xuzhou_card', 'kumai_general', 'kumai_department', 'kumai_uk_clothing',
            ],
            'wallet' => [
                'f_payment', 'ye_xiamen_single', 'ye_shenzhen_single', 'ye_zhengzhou_single',
                'ye_xian_single', 'ye_zhengzhou_general', 'ye_shenzhen_geek',
            ],
            'paypal' => [
                'shangyun', 'libo', 'jiahui', 'qisheng', 'shengda',
            ],
            'other_payment' => ['payments'],
        ];
        $database = DB::connection($connection);
        $timestamp = now();

        foreach ($defaults as $field => $values) {
            foreach ($values as $value) {
                if (!$database->table('assistant_domain_store_quick_options')
                    ->where('field', $field)
                    ->where('value', $value)
                    ->exists()) {
                    $database->table('assistant_domain_store_quick_options')->insert([
                        'field' => $field,
                        'value' => $value,
                        'created_at' => $timestamp,
                        'updated_at' => $timestamp,
                    ]);
                }
            }
        }
    }

    public function down(): void
    {
        // Keep option records intact on rollback to avoid removing user-managed values.
    }
};
