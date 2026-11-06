<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $schema = Schema::connection(config('admin.database.connection') ?: config('database.default'));
        if (! $schema->hasIndex('finance_payment_accounts', 'finance_account_type_live_name_unique')) {
            $schema->table('finance_payment_accounts', function (Blueprint $table): void {
                $table->unique(['account_type', 'active_account_name'], 'finance_account_type_live_name_unique');
            });
        }
        foreach (['finance_payment_accounts_active_account_name_unique', 'finance_payment_accounts_account_name_unique'] as $index) {
            if ($schema->hasIndex('finance_payment_accounts', $index)) {
                $schema->table('finance_payment_accounts', fn (Blueprint $table) => $table->dropUnique($index));
            }
        }
    }

    public function down(): void
    {
        // Keep same-name accounts in different channels valid.
    }
};
