<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $schema = Schema::connection(config('admin.database.connection') ?: config('database.default'));

        if ($schema->hasTable('assistant_domain_store_owner_histories')) return;

        $schema->create('assistant_domain_store_owner_histories', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedInteger('assistant_domain_store_id');
            $table->unsignedInteger('owner_user_id')->nullable();
            $table->string('owner_name_snapshot', 100)->nullable();
            $table->string('event', 32);
            $table->dateTime('effective_from');
            $table->dateTime('effective_to')->nullable();
            $table->unsignedInteger('actor_id')->nullable();
            $table->string('actor_name_snapshot', 100)->nullable();
            $table->timestamps();
            $table->index(['assistant_domain_store_id', 'effective_from'], 'ads_own_hist_store_time_idx');
            $table->index('owner_user_id', 'ads_own_hist_owner_idx');
        });
    }

    public function down(): void
    {
        Schema::connection(config('admin.database.connection') ?: config('database.default'))
            ->dropIfExists('assistant_domain_store_owner_histories');
    }
};
