<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $schema = Schema::connection(config('admin.database.connection') ?: config('database.default'));
        if (! $schema->hasColumn('finance_payment_accounts', 'legacy_card_key')) {
            $schema->table('finance_payment_accounts', function (Blueprint $table): void {
                $table->string('legacy_card_key', 64)->nullable()->unique();
            });
        }
    }

    public function down(): void
    {
        // Preserve the mapping between payment accounts and historical balances.
    }
};
