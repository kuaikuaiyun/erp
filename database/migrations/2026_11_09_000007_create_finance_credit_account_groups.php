<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $schema = Schema::connection(config('admin.database.connection') ?: config('database.default'));
        $schema->create('finance_credit_account_groups', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 100)->unique();
            $table->timestamps();
        });
        $schema->table('finance_payment_accounts', function (Blueprint $table): void {
            $table->foreignId('card_group_id')->nullable()->constrained('finance_credit_account_groups')->nullOnDelete();
        });
    }

    public function down(): void
    {
        $schema = Schema::connection(config('admin.database.connection') ?: config('database.default'));
        $schema->table('finance_payment_accounts', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('card_group_id');
        });
        $schema->dropIfExists('finance_credit_account_groups');
    }
};
