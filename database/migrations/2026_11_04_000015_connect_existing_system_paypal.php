<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $db = DB::connection(config('admin.database.connection') ?: config('database.default'));
        $schema = $db->getSchemaBuilder();
        $schema->table('finance_payment_accounts', function (Blueprint $table): void {
            $table->unsignedBigInteger('system_paypal_account_id')->nullable()->unique();
            $table->dateTime('system_paypal_synced_at')->nullable();
        });
        // Keep legacy columns for existing installations; never bind or copy system data.
    }

    public function down(): void {}
};
