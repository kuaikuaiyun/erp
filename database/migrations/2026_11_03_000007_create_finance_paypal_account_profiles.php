<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $schema = Schema::connection(config('admin.database.connection') ?: config('database.default'));
        if (! $schema->hasTable('finance_paypal_account_profiles')) {
            $schema->create('finance_paypal_account_profiles', function (Blueprint $table): void {
                $table->string('account_key', 64)->primary();
                $table->string('display_name', 100)->unique();
                $table->text('remark')->nullable();
                $table->unsignedInteger('updated_by')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        // Preserve saved account names and remarks.
    }
};
