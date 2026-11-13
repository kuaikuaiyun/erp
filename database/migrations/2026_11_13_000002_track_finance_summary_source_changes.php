<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const SOURCES = [
        'logistics_orders' => 'order',
        'logistics_order_payment_references' => 'reference',
        'logistics_order_items' => 'item',
        'logistics_tongtu_sku_mappings' => 'sku',
        'fashion_logistics_orders' => 'fashion',
        'fashion_logistics_order_items' => 'fashion_item',
        'assistant_domain_stores' => 'store',
        'assistant_domain_store_owner_histories' => 'owner',
    ];

    public function up(): void
    {
        $db = DB::connection(config('admin.database.connection') ?: config('database.default'));
        if (! in_array($db->getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }
        if (! $db->getSchemaBuilder()->hasTable('finance_summary_changes')) $db->getSchemaBuilder()->create('finance_summary_changes', function (Blueprint $table): void {
            $table->id();
            $table->string('source', 20);
            $table->json('payload');
        });
        foreach (self::SOURCES as $table => $source) {
            if (! $db->getSchemaBuilder()->hasTable($table)) {
                continue;
            }
            foreach (['insert' => ['NEW'], 'update' => ['OLD', 'NEW'], 'delete' => ['OLD']] as $event => $images) {
                $columns = match ($source) {
                    'order' => ['id', 'bill_number', 'transaction_id', 'payment_gateway_order_id', 'shopify_order_number', 'assistant_domain_store_id', 'source_shop_domain', 'subscription_type', 'is_add_on', 'tongtu_order_number', 'order_created_at', 'payment_method', 'assigned_owner_name', 'assigned_owner_user_id', 'operator_name', 'group_names'],
                    'reference' => ['logistics_order_id', 'reference_number', 'reference_type'],
                    'item' => ['logistics_order_id', 'quantity', 'sale_weight_gram', 'tongtu_goods_weight_gram', 'tongtu_sku_mapping_id'],
                    'sku' => ['id', 'product_guide_cost_cny', 'goods_weight_gram'],
                    'fashion' => ['transaction_id', 'assistant_domain_store_id', 'source_domain', 'order_created_at', 'order_created_at_utc'],
                    'store' => ['id', 'domain', 'shop_name', 'owner_user_id', 'user_name', 'team', 'shop_status'],
                    'fashion_item' => ['fashion_logistics_order_id'],
                    'owner' => ['assistant_domain_store_id'],
                };
                $columns = array_intersect($columns, $db->getSchemaBuilder()->getColumnListing($table));
                $changed = $event === 'update' ? 'NOT ('.implode(' AND ', array_map(
                    fn ($column) => "OLD.`{$column}` <=> NEW.`{$column}`", $columns
                )).')' : '1';
                if (in_array($source, ['owner', 'fashion_item'], true)) {
                    $changed = '1';
                }
                $writes = [];
                foreach ($images as $image) {
                    $pairs = implode(', ', array_map(fn ($column) => "'{$column}', {$image}.`{$column}`", $columns));
                    $writes[] = "INSERT INTO finance_summary_changes (source, payload) VALUES ('{$source}', JSON_OBJECT({$pairs}))";
                }
                \App\Portals\Finance\FinanceSummaryTriggers::install($db, "CREATE TRIGGER finance_summary_dependency_{$source}_{$event} AFTER {$event} ON {$table}
                    FOR EACH ROW BEGIN IF {$changed} THEN ".implode('; ', $writes)."; END IF; END");
            }
        }
        $db->table('finance_summary_periods')->increment('revision');
    }

    public function down(): void
    {
        $db = DB::connection(config('admin.database.connection') ?: config('database.default'));
        if (in_array($db->getDriverName(), ['mysql', 'mariadb'], true)) {
            foreach (self::SOURCES as $source) {
                foreach (['insert', 'update', 'delete'] as $event) {
                    $db->unprepared("DROP TRIGGER IF EXISTS finance_summary_dependency_{$source}_{$event}");
                }
            }
            $db->getSchemaBuilder()->dropIfExists('finance_summary_changes');
        }
    }
};
