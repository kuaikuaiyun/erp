<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $schema = Schema::connection(config('admin.database.connection') ?: config('database.default'));
        $schema->create('finance_credit_usd_rates', function (Blueprint $table): void {
            $table->id();
            $table->char('currency', 3);
            $table->string('scope', 5);
            $table->date('starts_on');
            $table->date('ends_on');
            $table->decimal('usd_rate', 20, 8);
            $table->unsignedBigInteger('updated_by');
            $table->timestamps();
            $table->unique(['currency', 'scope', 'starts_on', 'ends_on'], 'finance_credit_usd_rate_period');
        });
    }

    public function down(): void
    {
        Schema::connection(config('admin.database.connection') ?: config('database.default'))
            ->dropIfExists('finance_credit_usd_rates');
    }
};
