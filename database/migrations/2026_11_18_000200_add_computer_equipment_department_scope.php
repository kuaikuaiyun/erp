<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $schema = Schema::connection(config('admin.database.connection') ?: config('database.default'));
        if (! $schema->hasTable('asset_computer_equipment')) {
            throw new RuntimeException(__('asset_manager.computer_install_required', [], 'zh-CN'));
        }
        if (! $schema->hasColumn('asset_computer_equipment', 'owner_department_id')) {
            $schema->table('asset_computer_equipment', function (Blueprint $table) {
                $table->unsignedBigInteger('owner_department_id')->nullable()->index('asset_computer_owner_department_index');
            });
        }
        // Historical records deliberately remain unassigned; only administrators may assign them.
    }

    public function down(): void // Preserve department ownership and historical business data on rollback.
    {}
};
