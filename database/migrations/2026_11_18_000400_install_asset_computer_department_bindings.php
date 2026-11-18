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
        $db = DB::connection($connection);
        $schema = Schema::connection($connection);
        $portalId = $db->table('portals')->where('code', 'asset-manager')->value('id');
        if (! $portalId) {
            throw new RuntimeException(__('asset_manager.bindings.install_required', [], 'zh-CN'));
        }
        if (! $schema->hasTable('asset_computer_department_bindings')) {
            $schema->create('asset_computer_department_bindings', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('source_department_id');
                $t->unsignedBigInteger('target_department_id')->index('acdb_target_idx');
                $t->unsignedBigInteger('updated_by');
                $t->timestamps();
                $t->unique(['source_department_id', 'target_department_id'], 'asset_computer_department_binding_unique');
            });
        }
        if (! $schema->hasTable('asset_computer_department_binding_logs')) {
            $schema->create('asset_computer_department_binding_logs', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('source_department_id')->index('acdbl_source_idx');
                $t->string('source_name');
                foreach (['before_ids', 'after_ids', 'before_labels', 'after_labels'] as $column) {
                    $t->text($column);
                }
                $t->unsignedBigInteger('actor_id');
                $t->string('actor_name');
                $t->timestamp('created_at');
            });
        }
        // MySQL may retain a created table after a later ADD INDEX statement fails.
        // Match by columns so successful older installations keep their existing names.
        foreach ([
            ['asset_computer_department_bindings', ['source_department_id', 'target_department_id'], 'asset_computer_department_binding_unique', true],
            ['asset_computer_department_bindings', ['target_department_id'], 'acdb_target_idx', false],
            ['asset_computer_department_binding_logs', ['source_department_id'], 'acdbl_source_idx', false],
        ] as [$table, $columns, $name, $unique]) {
            if (! $schema->hasIndex($table, $columns, $unique ? 'unique' : null)) {
                $schema->table($table, function (Blueprint $t) use ($columns, $name, $unique) {
                    $unique ? $t->unique($columns, $name) : $t->index($columns, $name);
                });
            }
        }
        if (! $db->table(config('admin.database.permissions_table'))->where('slug', 'asset-manager.computer-department-bindings')->exists()) {
            $db->table(config('admin.database.permissions_table'))->insert([
                'name' => __('asset_manager.bindings.title', [], 'zh-CN'), 'slug' => 'asset-manager.computer-department-bindings',
                'http_method' => '', 'http_path' => '', 'created_at' => now('UTC'), 'updated_at' => now('UTC'),
            ]);
        }
        if (! $db->table('portal_menus')->where('portal_id', $portalId)->where('code', 'asset_computer_department_bindings')->exists()) {
            $db->table('portal_menus')->insert([
                'portal_id' => $portalId, 'parent_id' => 0, 'code' => 'asset_computer_department_bindings', 'title' => null,
                'title_key' => 'asset_manager.bindings.title', 'path' => '/asset-manager/computer-department-bindings',
                'permission_slug' => 'asset-manager.computer-department-bindings', 'icon' => 'fa-link', 'sort' => 60,
                'is_enabled' => true, 'created_at' => now('UTC'), 'updated_at' => now('UTC'),
            ]);
        }
        // No default bindings: non-administrators start with an empty equipment scope.
    }

    public function down(): void
    {
        $db = DB::connection(config('admin.database.connection') ?: config('database.default'));
        $portalId = $db->table('portals')->where('code', 'asset-manager')->value('id');
        if ($portalId) {
            $db->table('portal_menus')->where('portal_id', $portalId)->where('code', 'asset_computer_department_bindings')->update(['is_enabled' => false, 'updated_at' => now('UTC')]);
        }
        // Preserve mappings and audit history when reverting application code.
    }
};
