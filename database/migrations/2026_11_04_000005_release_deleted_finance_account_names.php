<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $schema = Schema::connection(config('admin.database.connection') ?: config('database.default'));
        $mysql = $schema->getConnection()->getDriverName() === 'mysql';
        $hasActiveIndex = $schema->hasIndex('finance_payment_accounts', 'finance_payment_accounts_active_account_name_unique');
        $activeColumn = collect($schema->getColumns('finance_payment_accounts'))->firstWhere('name', 'active_account_name');
        // Recover a partially installed virtual column on older MyISAM databases.
        if ($mysql && $activeColumn && ! $hasActiveIndex && ($activeColumn['generation']['type'] ?? null) === 'virtual') {
            $schema->table('finance_payment_accounts', fn (Blueprint $table) => $table->dropColumn('active_account_name'));
        }
        if (! $schema->hasColumn('finance_payment_accounts', 'active_account_name')) {
            $schema->table('finance_payment_accounts', function (Blueprint $table) use ($mysql): void {
                $column = $table->string('active_account_name', 100)->nullable();
                $expression = 'CASE WHEN deleted_at IS NULL THEN account_name ELSE NULL END';
                $mysql ? $column->storedAs($expression) : $column->virtualAs($expression);
            });
        }
        if (! $schema->hasIndex('finance_payment_accounts', 'finance_payment_accounts_active_account_name_unique')) {
            $schema->table('finance_payment_accounts', function (Blueprint $table): void {
                $table->unique('active_account_name');
            });
        }
        // Install the live-account constraint before removing the old global constraint.
        if ($schema->hasIndex('finance_payment_accounts', 'finance_payment_accounts_account_name_unique')) {
            $schema->table('finance_payment_accounts', function (Blueprint $table): void {
                $table->dropUnique(['account_name']);
            });
        }
    }

    public function down(): void
    {
        // Keep reused names and deleted-account history; the old constraint may no longer be valid.
    }
};
