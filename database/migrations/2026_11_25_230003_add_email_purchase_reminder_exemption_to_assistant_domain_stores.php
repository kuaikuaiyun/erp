<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $connection = config('admin.database.connection') ?: config('database.default');
        $schema = Schema::connection($connection);

        if (! $schema->hasTable('assistant_domain_stores')
            || $schema->hasColumn('assistant_domain_stores', 'email_purchase_reminder_exempt')) {
            return;
        }

        $schema->table('assistant_domain_stores', function (Blueprint $table): void {
            $table->boolean('email_purchase_reminder_exempt')->default(false)->after('email_purchased');
            $table->string('email_purchase_reminder_exempt_reason', 255)->nullable()->after('email_purchase_reminder_exempt');
            $table->unsignedInteger('email_purchase_reminder_exempted_by')->nullable()->after('email_purchase_reminder_exempt_reason');
            $table->timestamp('email_purchase_reminder_exempted_at')->nullable()->after('email_purchase_reminder_exempted_by');
            $table->index('email_purchase_reminder_exempt', 'assistant_store_email_reminder_exempt_index');
        });
    }

    public function down(): void
    {
        $connection = config('admin.database.connection') ?: config('database.default');
        $schema = Schema::connection($connection);

        if (! $schema->hasTable('assistant_domain_stores')
            || ! $schema->hasColumn('assistant_domain_stores', 'email_purchase_reminder_exempt')) {
            return;
        }

        $schema->table('assistant_domain_stores', function (Blueprint $table): void {
            $table->dropIndex('assistant_store_email_reminder_exempt_index');
            $table->dropColumn([
                'email_purchase_reminder_exempt',
                'email_purchase_reminder_exempt_reason',
                'email_purchase_reminder_exempted_by',
                'email_purchase_reminder_exempted_at',
            ]);
        });
    }
};
