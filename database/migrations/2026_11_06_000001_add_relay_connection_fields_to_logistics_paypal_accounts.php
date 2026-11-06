<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $schema = Schema::connection(config('admin.database.connection') ?: config('database.default'));

        if (! $schema->hasTable('logistics_paypal_accounts')) {
            return;
        }

        $schema->table('logistics_paypal_accounts', function (Blueprint $table) use ($schema): void {
            if (! $schema->hasColumn('logistics_paypal_accounts', 'relay_url')) {
                $table->string('relay_url', 500)->nullable();
            }
            if (! $schema->hasColumn('logistics_paypal_accounts', 'relay_certificate_thumbprint_sha1')) {
                $table->char('relay_certificate_thumbprint_sha1', 40)->nullable();
            }
            if (! $schema->hasColumn('logistics_paypal_accounts', 'relay_hmac_key_id')) {
                $table->string('relay_hmac_key_id', 64)->default('erp-main');
            }
            if (! $schema->hasColumn('logistics_paypal_accounts', 'relay_hmac_secret')) {
                $table->text('relay_hmac_secret')->nullable();
            }
        });
    }

    public function down(): void
    {
        // Relay configuration contains credentials. Keep it when rolling back unrelated features.
    }
};
