<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $schema = Schema::connection(config('admin.database.connection') ?: config('database.default'));
        if (! $schema->hasTable('asset_reminder_calendars')) {
            $schema->create('asset_reminder_calendars', function (Blueprint $t) {
                $t->id(); $t->unsignedBigInteger('company_id'); $t->string('month', 7);
                $t->json('days'); $t->unsignedInteger('version')->default(1);
                $t->unsignedBigInteger('updated_by'); $t->timestamps();
                $t->unique(['company_id', 'month'], 'ar_calendar_company_month');
            });
        }
        if (! $schema->hasTable('asset_reminder_calendar_logs')) {
            $schema->create('asset_reminder_calendar_logs', function (Blueprint $t) {
                $t->id(); $t->unsignedBigInteger('calendar_id'); $t->unsignedBigInteger('actor_id');
                $t->json('before_days')->nullable(); $t->json('after_days');
                $t->unsignedInteger('version'); $t->timestamp('created_at');
                $t->index(['calendar_id', 'id'], 'ar_calendar_log');
            });
        }
        if (! $schema->hasColumn('asset_reminder_rules', 'calendar_company_id')) {
            $schema->table('asset_reminder_rules', fn (Blueprint $t) => $t->unsignedBigInteger('calendar_company_id')->nullable());
        }
    }

    public function down(): void
    {
        // Keep calendar configuration, rule links and audit history for recovery.
    }
};
