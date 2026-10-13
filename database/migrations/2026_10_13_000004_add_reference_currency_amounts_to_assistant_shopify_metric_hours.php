<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $connection = config('admin.database.connection') ?: config('database.default');
        $schema = Schema::connection($connection);

        if (!$schema->hasTable('assistant_shopify_store_metric_hours')) {
            return;
        }

        $missingColumns = [];
        foreach (['original_refund_amount', 'original_net_sales'] as $column) {
            if (!$schema->hasColumn('assistant_shopify_store_metric_hours', $column)) {
                $missingColumns[] = $column;
            }
        }

        if ($missingColumns === []) {
            return;
        }

        $schema->table('assistant_shopify_store_metric_hours', function (Blueprint $table) use ($missingColumns): void {
            if (in_array('original_refund_amount', $missingColumns, true)) {
                $table->decimal('original_refund_amount', 20, 4)
                    ->nullable()
                    ->after('original_total_sales');
            }

            if (in_array('original_net_sales', $missingColumns, true)) {
                $table->decimal('original_net_sales', 20, 4)
                    ->nullable()
                    ->after('original_total_sales');
            }
        });
    }

    public function down(): void
    {
        // Preserve captured source amounts on rollback.
    }
};
