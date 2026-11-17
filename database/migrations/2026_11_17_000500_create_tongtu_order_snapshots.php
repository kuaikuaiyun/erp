<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $schema = Schema::connection(config('admin.database.connection') ?: config('database.default'));
        $schema->create('logistics_tongtu_sync_days', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->date('paid_date')->primary();
            $table->dateTime('synced_at');
        });
        $schema->create('logistics_tongtu_order_snapshots', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->date('paid_date');
            $table->string('tongtu_order_number', 191);
            $table->string('domain', 255)->nullable();
            $table->primary(['paid_date', 'tongtu_order_number']);
        });
    }

    public function down(): void
    {
        $schema = Schema::connection(config('admin.database.connection') ?: config('database.default'));
        $schema->dropIfExists('logistics_tongtu_order_snapshots');
        $schema->dropIfExists('logistics_tongtu_sync_days');
    }
};
