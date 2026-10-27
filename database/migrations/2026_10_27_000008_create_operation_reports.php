<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $connection = config('admin.database.connection') ?: config('database.default');
        Schema::connection($connection)->create('operation_reports', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->date('report_date');
            $table->unsignedInteger('operator_user_id');
            $table->string('operator_name', 100);
            $table->json('metric_rows');
            $table->json('advertising_rows')->nullable();
            $table->decimal('first_purchase_cost', 18, 4)->default(0);
            $table->decimal('first_shipping_cost', 18, 4)->default(0);
            $table->decimal('first_return_fee', 18, 4)->default(0);
            $table->decimal('actual_ad_spend', 18, 4)->default(0);
            $table->decimal('first_profit', 18, 4)->default(0);
            $table->decimal('renewal_net_revenue', 18, 4)->default(0);
            $table->decimal('overall_profit', 18, 4)->default(0);
            $table->string('status', 20)->default('pending');
            $table->unsignedInteger('submitted_by');
            $table->timestamp('submitted_at')->nullable();
            $table->unsignedInteger('reviewed_by')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
            $table->unique(['report_date', 'operator_user_id'], 'operation_report_date_operator_unique');
            $table->index(['status', 'report_date'], 'operation_report_status_date_index');
        });
    }

    public function down(): void
    {
        $connection = config('admin.database.connection') ?: config('database.default');
        Schema::connection($connection)->dropIfExists('operation_reports');
    }
};
