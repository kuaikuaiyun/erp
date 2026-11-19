<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $connection = config('admin.database.connection') ?: config('database.default');
        $schema = Schema::connection($connection);
        foreach (['webhook', 'webhook_secret'] as $column) {
            if (! $schema->hasColumn('asset_reminder_groups', $column)) {
                $schema->table('asset_reminder_groups', fn (Blueprint $t) => $t->text($column)->nullable());
            }
        }
        if (! $schema->hasColumn('asset_reminder_groups', 'webhook_hash')) {
            $schema->table('asset_reminder_groups', fn (Blueprint $t) => $t->string('webhook_hash', 64)->nullable());
        }
        if (! $schema->hasIndex('asset_reminder_groups', 'ar_group_webhook')) {
            $schema->table('asset_reminder_groups', fn (Blueprint $t) => $t->unique('webhook_hash', 'ar_group_webhook'));
        }
        // Retain legacy columns for recovery; the application no longer reads or writes them.
        if ($schema->hasIndex('asset_reminder_groups', 'ar_group_target')) {
            $schema->table('asset_reminder_groups', fn (Blueprint $t) => $t->dropUnique('ar_group_target'));
        }
        $schema->table('asset_reminder_groups', function (Blueprint $t) {
            $t->string('robot_code', 190)->nullable()->change();
            $t->string('conversation_id', 255)->nullable()->change();
        });
        // Legacy destinations cannot be converted to Webhooks. Preserve rules and history.
        DB::connection($connection)->table('asset_reminder_groups')->whereNull('webhook')->where('enabled', true)
            ->update(['enabled' => false, 'version' => DB::raw('version + 1'), 'updated_at' => now('UTC')]);
    }

    public function down(): void
    {
        // Preserve encrypted destinations and history; never silently restore the old transport.
    }
};
