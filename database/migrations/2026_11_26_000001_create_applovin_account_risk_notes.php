<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection(config('admin.database.connection') ?: config('database.default'))
            ->create('applovin_account_risk_notes', function (Blueprint $table): void {
                $table->id();
                $table->string('account_number', 191)->unique();
                $table->text('risk_factors')->nullable();
                $table->unsignedBigInteger('updated_by')->nullable()->index();
                $table->timestamps();
            });
    }

    public function down(): void
    {
        Schema::connection(config('admin.database.connection') ?: config('database.default'))
            ->dropIfExists('applovin_account_risk_notes');
    }
};
