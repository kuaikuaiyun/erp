<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $schema = Schema::connection(config('admin.database.connection') ?: config('database.default'));
        if ($schema->hasTable('payment_paypal_account_risk_snapshots')) {
            return;
        }
        $schema->create('payment_paypal_account_risk_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('logistics_paypal_account_id');
            $table->date('snapshot_date');
            $table->dateTime('window_start');
            $table->dateTime('window_end');
            $table->unsignedInteger('order_count')->default(0);
            $table->unsignedInteger('dispute_order_count')->default(0);
            $table->decimal('dispute_rate', 8, 4)->default(0);
            $table->timestamps();
            $table->unique(['logistics_paypal_account_id', 'snapshot_date'], 'pay_pp_risk_account_week_uq');
            $table->index('snapshot_date', 'pay_pp_risk_week_idx');
        });
    }

    public function down(): void
    {
        Schema::connection(config('admin.database.connection') ?: config('database.default'))
            ->dropIfExists('payment_paypal_account_risk_snapshots');
    }
};
