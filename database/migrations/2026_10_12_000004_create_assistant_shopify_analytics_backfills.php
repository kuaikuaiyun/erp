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

        if (!$schema->hasTable('assistant_domain_stores')
            || $schema->hasTable('assistant_shopify_analytics_backfills')) {
            return;
        }

        $schema->create('assistant_shopify_analytics_backfills', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('assistant_domain_store_id');
            $table->unique('assistant_domain_store_id', 'as_shopify_backfill_store_unique');
            $table->string('shop_timezone', 100);
            $table->date('from_date');
            $table->date('to_date');
            $table->date('next_date')->nullable()->index();
            $table->date('last_finalized_date')->nullable()->index();
            $table->string('status', 20)->default('pending')->index();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->string('last_error', 1000)->nullable();
            $table->dateTime('next_attempt_at')->nullable()->index();
            $table->dateTime('started_at')->nullable();
            $table->dateTime('last_processed_at')->nullable();
            $table->dateTime('completed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        // Backfill progress is operational data and is intentionally retained on rollback.
    }
};
