<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $schema = Schema::connection(config('admin.database.connection') ?: config('database.default'));

        $schema->table('logistics_orders', function (Blueprint $table) use ($schema): void {
            if (!$schema->hasColumn('logistics_orders', 'assigned_owner_user_id')) {
                $table->unsignedInteger('assigned_owner_user_id')->nullable()->after('operator_name');
                $table->string('assigned_owner_name', 100)->nullable()->after('assigned_owner_user_id');
                $table->dateTime('owner_assignment_effective_from')->nullable()->after('assigned_owner_name');
                $table->dateTime('owner_assignment_resolved_at')->nullable()->after('owner_assignment_effective_from');
                $table->index('assigned_owner_user_id', 'lo_owner_snapshot_idx');
            }
        });
    }

    public function down(): void
    {
        $schema = Schema::connection(config('admin.database.connection') ?: config('database.default'));
        if (!$schema->hasColumn('logistics_orders', 'assigned_owner_user_id')) return;

        $schema->table('logistics_orders', function (Blueprint $table): void {
            $table->dropIndex('lo_owner_snapshot_idx');
            $table->dropColumn(['assigned_owner_user_id', 'assigned_owner_name', 'owner_assignment_effective_from', 'owner_assignment_resolved_at']);
        });
    }
};
