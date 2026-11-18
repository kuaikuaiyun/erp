<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private array $tables = ['asset_resources', 'asset_codex_renewals', 'asset_ipfoxy_accounts'];
    private array $kinds = ['resources', 'codex-renewals', 'ipfoxy'];

    public function up(): void
    {
        $connection = config('admin.database.connection') ?: config('database.default');
        $db = DB::connection($connection);
        $schema = Schema::connection($connection);
        $assistant = $db->table('portals')->where('code', 'zhuli')->value('id');
        if (! $assistant) {
            throw new RuntimeException(__('asset_manager.assistant_required', [], 'zh-CN'));
        }
        foreach ($this->tables as $table) {
            if (! $schema->hasTable($table)) {
                throw new RuntimeException(__('asset_manager.install_required', [], 'zh-CN'));
            }
        }
        foreach ($this->tables as $table) {
            if (! $schema->hasColumn($table, 'owner_department_id')) {
                $schema->table($table, function (Blueprint $t) {
                    $t->unsignedBigInteger('owner_department_id')->nullable()->index();
                });
            }
        }
        $db->transaction(function () use ($db, $assistant) {
            $old = $db->table('portals')->where('code', 'asset-manager')->value('id');
            $sort = (int) $db->table('portal_menus')->where('portal_id', $assistant)->max('sort');
            foreach ($this->kinds as $i => $kind) {
                $code = 'asset_manager_'.$kind;
                $values = ['parent_id' => 0, 'title' => null, 'title_key' => 'asset_manager.titles.'.$kind,
                    'path' => '/assistant/assets/'.$kind, 'permission_slug' => 'asset-manager.'.$kind,
                    'icon' => 'fa-cubes', 'sort' => $sort + ($i + 1) * 10, 'updated_at' => now()];
                $target = $db->table('portal_menus')->where('portal_id', $assistant)->where('code', $code)->first();
                $source = $old ? $db->table('portal_menus')->where('portal_id', $old)->where('code', $code)->first() : null;
                if ($target) {
                    $values['sort'] = $target->sort;
                    $db->table('portal_menus')->where('id', $target->id)->update($values);
                    if ($source) {
                        $db->table('portal_menus')->where('id', $source->id)->update(['is_enabled' => false, 'updated_at' => now()]);
                    }
                } elseif ($source) {
                    // Preserve menu identity and its existing enabled state.
                    $db->table('portal_menus')->where('id', $source->id)->update($values + ['portal_id' => $assistant]);
                } else {
                    $db->table('portal_menus')->insert($values + ['portal_id' => $assistant, 'code' => $code, 'is_enabled' => true, 'created_at' => now()]);
                }
            }
        });
    }

    public function down(): void
    {
        $db = DB::connection(config('admin.database.connection') ?: config('database.default'));
        $assistant = $db->table('portals')->where('code', 'zhuli')->value('id');
        $old = $db->table('portals')->where('code', 'asset-manager')->value('id');
        foreach ($this->kinds as $kind) {
            $query = $db->table('portal_menus')->where('portal_id', $assistant)->where('code', 'asset_manager_'.$kind);
            if ($old) {
                $existing = $db->table('portal_menus')->where('portal_id', $old)->where('code', 'asset_manager_'.$kind)->exists();
                if (! $existing) {
                    $query->update(['portal_id' => $old, 'path' => '/asset-manager/'.$kind, 'updated_at' => now()]);
                } else {
                    $query->update(['is_enabled' => false, 'updated_at' => now()]);
                    $db->table('portal_menus')->where('portal_id', $old)->where('code', 'asset_manager_'.$kind)->update(['is_enabled' => true, 'updated_at' => now()]);
                }
            } else {
                $query->update(['is_enabled' => false, 'updated_at' => now()]);
            }
        }
        // Keep department ownership data when rolling back; restoring code is a separate step.
    }
};
