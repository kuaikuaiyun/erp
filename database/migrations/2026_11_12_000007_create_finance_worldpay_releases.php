<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection(config('admin.database.connection') ?: config('database.default'))
            ->create('finance_worldpay_releases', function (Blueprint $table): void {
                $table->id();
                $table->string('merchant', 64);
                $table->char('currency', 3);
                $table->string('hold_batch', 64);
                $table->date('hold_date');
                $table->string('release_batch', 64);
                $table->date('release_date')->index();
                $table->decimal('amount', 20, 2);
                $table->unique(['merchant', 'currency', 'hold_batch', 'hold_date', 'release_batch', 'release_date'], 'worldpay_release_identity');
            });
    }

    public function down(): void
    {
        Schema::connection(config('admin.database.connection') ?: config('database.default'))->dropIfExists('finance_worldpay_releases');
    }
};
