<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::connection(config('admin.database.connection') ?: config('database.default'))
            ->table('fashion_ad_spend_entries', function (Blueprint $table): void {
                $table->unsignedBigInteger('assistant_domain_store_id')->nullable()->index()->after('account_number');
                $table->string('store_domain', 255)->nullable()->after('assistant_domain_store_id');
            });
    }
    public function down(): void
    {
        Schema::connection(config('admin.database.connection') ?: config('database.default'))
            ->table('fashion_ad_spend_entries', fn (Blueprint $table) => $table->dropColumn(['assistant_domain_store_id', 'store_domain']));
    }
};
