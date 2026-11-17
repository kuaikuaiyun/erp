<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $schema = Schema::connection(config('admin.database.connection') ?: config('database.default'));
        if ($schema->hasTable('fashion_ad_spend_entries') && ! $schema->hasColumn('fashion_ad_spend_entries', 'misc_fee_rmb')) {
            $schema->table('fashion_ad_spend_entries', function (Blueprint $table): void {
                $table->decimal('misc_fee_rmb', 18, 2)->default(0)->after('actual_spend_usd');
            });
        }
    }

    public function down(): void
    {
        $schema = Schema::connection(config('admin.database.connection') ?: config('database.default'));
        if ($schema->hasTable('fashion_ad_spend_entries') && $schema->hasColumn('fashion_ad_spend_entries', 'misc_fee_rmb')) {
            $schema->table('fashion_ad_spend_entries', fn (Blueprint $table) => $table->dropColumn('misc_fee_rmb'));
        }
    }
};
