<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        $schema = Schema::connection(config('admin.database.connection') ?: config('database.default'));
        if (! $schema->hasColumn('asset_reminder_rules', 'deleted_at')) {
            $schema->table('asset_reminder_rules', function (Blueprint $t) {
                $t->timestamp('deleted_at')->nullable();
                $t->unsignedBigInteger('deletion_key')->default(0);
                $t->json('submission_types')->nullable();
            });
        }
        if ($schema->hasIndex('asset_reminder_rules', 'ar_rule_dept_type')) {
            $schema->table('asset_reminder_rules', fn (Blueprint $t) => $t->dropUnique('ar_rule_dept_type'));
        }
        if (! $schema->hasIndex('asset_reminder_rules', 'ar_rule_active')) {
            $schema->table('asset_reminder_rules', fn (Blueprint $t) => $t->unique(['department_id', 'type', 'deletion_key'], 'ar_rule_active'));
        }
    }
    public function down(): void { /* Preserve configuration and audit records. */ }
};
