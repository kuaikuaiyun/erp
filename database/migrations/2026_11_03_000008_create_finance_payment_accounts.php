<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $schema = Schema::connection(config('admin.database.connection') ?: config('database.default'));
        if (! $schema->hasTable('finance_payment_accounts')) {
            $schema->create('finance_payment_accounts', function (Blueprint $table): void {
                $table->id();
                $table->string('account_code', 40)->unique();
                $table->string('account_name', 100)->unique();
                $table->string('activity_account', 100);
                $table->string('account_type', 20)->index();
                $table->char('main_currency', 3);
                $table->string('status', 20)->index();
                $table->text('remark')->nullable();
                $table->string('relay_url', 2048)->nullable();
                $table->char('relay_certificate_sha1', 40)->nullable();
                $table->string('relay_hmac_key_id', 100)->nullable();
                $table->text('relay_hmac_secret')->nullable();
                $table->string('relay_server_remark', 255)->nullable();
                $table->unsignedInteger('created_by')->nullable();
                $table->unsignedInteger('updated_by')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        // Preserve payment account configuration and encrypted credentials.
    }
};
