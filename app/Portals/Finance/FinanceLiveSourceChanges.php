<?php

namespace App\Portals\Finance;

use App\Models\AdminUser;
use Illuminate\Database\Connection;

/** Invalidate financial source rows when another portal changes their inputs. */
class FinanceLiveSourceChanges
{
    public const ATTRIBUTION_SOURCES = ['attribution_user', 'attribution_membership', 'attribution_department'];

    public static function install(Connection $db, bool $legacy = false): void
    {
        if (! in_array($db->getDriverName(), ['mysql', 'mariadb'], true)) return;
        $sources = [
            'logistics_orders' => ['order',
                ['id', 'bill_number', 'transaction_id', 'payment_gateway_order_id', 'shopify_order_number', 'assistant_domain_store_id', 'source_shop_domain', 'subscription_type', 'is_add_on', 'tongtu_order_number', 'order_created_at', 'payment_method', 'assigned_owner_name', 'assigned_owner_user_id', 'operator_name', 'group_names'],
                ['owner_assignment_resolved_at', 'owner_assignment_effective_from', 'paid_amount_usd', 'paid_at', 'payment_status', 'payment_gateway', 'collection_account', 'collection_account_email', 'shopify_payload', 'buyer_email', 'tongtu_product_name', 'shopify_order_id', 'store_name', 'total_shipping_usd'],
                ['id', 'bill_number', 'transaction_id', 'payment_gateway_order_id', 'shopify_order_number']],
            'logistics_order_items' => ['item',
                ['logistics_order_id', 'quantity', 'sale_weight_gram', 'tongtu_goods_weight_gram', 'tongtu_sku_mapping_id'],
                ['product_title', 'variant_title', 'tongtu_product_name', 'shopify_sku', 'tongtu_sku', 'tongtu_product_code'],
                ['logistics_order_id']],
            'assistant_domain_stores' => ['store',
                ['id', 'domain', 'shop_name', 'owner_user_id', 'user_name', 'team', 'shop_status'],
                ['site_builder', 'project'],
                ['id', 'domain', 'shop_name']],
            'fashion_logistics_orders' => ['fashion',
                ['transaction_id', 'assistant_domain_store_id', 'source_domain', 'order_created_at', 'order_created_at_utc'],
                ['currency', 'total_amount'],
                ['transaction_id']],
        ];
        foreach ($sources as $table => [$source, $old, $extra, $keys]) {
            if (! $db->getSchemaBuilder()->hasTable($table)) continue;
            $columns = $db->getSchemaBuilder()->getColumnListing($table);
            $watched = array_values(array_intersect($legacy ? $old : [...$old, ...$extra], $columns));
            $payload = array_values(array_intersect($legacy ? $old : $keys, $columns));
            if ($watched === [] || $payload === []) continue;
            $name = 'finance_summary_dependency_'.$source.'_update';
            $changed = self::changed($watched);
            $writes = [];
            foreach (['OLD', 'NEW'] as $image) {
                $pairs = implode(', ', array_map(fn ($column) => "'{$column}', {$image}.`{$column}`", $payload));
                $writes[] = "INSERT INTO finance_summary_changes (source, payload) VALUES ('{$source}', JSON_OBJECT({$pairs}))";
            }
            $db->unprepared("DROP TRIGGER IF EXISTS `{$name}`");
            FinanceSummaryTriggers::install($db, "CREATE TRIGGER `{$name}` AFTER UPDATE ON `{$table}`
                FOR EACH ROW BEGIN IF {$changed} THEN ".implode('; ', $writes).'; END IF; END');
        }
        $attribution = [
            (new AdminUser)->getTable() => ['attribution_user', ['name', 'username']],
            config('admin.database.role_users_table') => ['attribution_membership', ['user_id', 'role_id', 'is_primary']],
            config('admin.database.roles_table') => ['attribution_department', ['name', 'slug', 'parent_id']],
        ];
        foreach ($attribution as $table => [$source, $fields]) {
            if (! $table || ! $db->getSchemaBuilder()->hasTable($table)) continue;
            $columns = array_values(array_intersect($fields, $db->getSchemaBuilder()->getColumnListing($table)));
            foreach (['insert', 'update', 'delete'] as $event) {
                $name = 'finance_summary_'.$source.'_'.$event;
                $db->unprepared("DROP TRIGGER IF EXISTS `{$name}`");
                if ($legacy || $columns === []) continue;
                $changed = $event === 'update' ? self::changed($columns) : '1';
                FinanceSummaryTriggers::install($db, "CREATE TRIGGER `{$name}` AFTER {$event} ON `{$table}`
                    FOR EACH ROW BEGIN IF {$changed} THEN UPDATE finance_summary_periods SET revision = revision + 1; END IF; END");
            }
        }
        // Existing publications may already contain an update missed by the old triggers.
        $db->table('finance_summary_periods')->increment('revision');
    }

    private static function changed(array $columns): string
    {
        return 'NOT ('.implode(' AND ', array_map(fn ($column) => "BINARY OLD.`{$column}` <=> BINARY NEW.`{$column}`", $columns)).')';
    }
}
