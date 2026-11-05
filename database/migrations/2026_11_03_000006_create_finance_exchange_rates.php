<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $schema = Schema::connection(config('admin.database.connection') ?: config('database.default'));
        if (! $schema->hasTable('finance_exchange_rates')) {
            $schema->create('finance_exchange_rates', function (Blueprint $table): void {
                $table->string('currency', 3)->primary();
                $table->decimal('units_per_usd', 24, 10);
                $table->unsignedInteger('updated_by')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        // Retain manually configured financial exchange rates.
    }
};
