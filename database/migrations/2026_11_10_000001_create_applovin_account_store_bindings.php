<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection(config('admin.database.connection') ?: config('database.default'))
            ->create('applovin_account_store_bindings', function (Blueprint $table): void {
                $table->id();
                $table->string('account_key', 64)->unique();
                $table->unsignedBigInteger('assistant_domain_store_id')->index();
                $table->unsignedBigInteger('bound_by');
                $table->timestamps();
            });
    }

    public function down(): void
    {
        Schema::connection(config('admin.database.connection') ?: config('database.default'))
            ->dropIfExists('applovin_account_store_bindings');
    }
};
