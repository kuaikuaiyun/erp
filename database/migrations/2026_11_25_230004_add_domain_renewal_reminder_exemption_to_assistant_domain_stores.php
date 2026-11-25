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
            || $schema->hasColumn('assistant_domain_stores', 'domain_renewal_reminder_exempt')) {
            return;
        }

        $schema->table('assistant_domain_stores', function (Blueprint $table): void {
            $table->boolean('domain_renewal_reminder_exempt')->default(false)->after('domain_expires_at');
            $table->string('domain_renewal_reminder_exempt_reason', 255)->nullable()->after('domain_renewal_reminder_exempt');
            $table->unsignedInteger('domain_renewal_reminder_exempted_by')->nullable()->after('domain_renewal_reminder_exempt_reason');
            $table->timestamp('domain_renewal_reminder_exempted_at')->nullable()->after('domain_renewal_reminder_exempted_by');
            $table->index('domain_renewal_reminder_exempt', 'assistant_store_domain_reminder_exempt_index');
        });
    }

    public function down(): void
    {
        $connection = config('admin.database.connection') ?: config('database.default');
        $schema = Schema::connection($connection);

        if (! $schema->hasTable('assistant_domain_stores')
            || ! $schema->hasColumn('assistant_domain_stores', 'domain_renewal_reminder_exempt')) {
            return;
        }

        $schema->table('assistant_domain_stores', function (Blueprint $table): void {
            $table->dropIndex('assistant_store_domain_reminder_exempt_index');
            $table->dropColumn([
                'domain_renewal_reminder_exempt',
                'domain_renewal_reminder_exempt_reason',
                'domain_renewal_reminder_exempted_by',
                'domain_renewal_reminder_exempted_at',
            ]);
        });
    }
};
