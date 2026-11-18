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
        $portal = $db->table('portals')->where('code', 'asset-manager')->first();
        if (! $portal) {
            throw new RuntimeException(__('discipline.install_required', [], 'zh-CN'));
        }
        $schema = Schema::connection($connection);
        if (! $schema->hasTable('discipline_records')) {
            $schema->create('discipline_records', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('member_id');
                $table->string('member_name');
                $table->unsignedBigInteger('department_id');
                $table->string('department_name');
                $table->date('record_date');
                $table->text('remarks');
                $table->string('version', 36);
                $table->unsignedBigInteger('created_by');
                $table->unsignedBigInteger('updated_by');
                $table->timestamps();
                $table->softDeletes();
                $table->index(['member_id', 'record_date', 'deleted_at'], 'discipline_member_date_idx');
                $table->index(['department_id', 'record_date', 'deleted_at'], 'discipline_department_date_idx');
            });
        }
        if (! $schema->hasTable('discipline_record_logs')) {
            $schema->create('discipline_record_logs', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('record_id')->index();
                $table->string('action', 16);
                $table->unsignedBigInteger('actor_id');
                $table->string('actor_name');
                $table->json('before_data')->nullable();
                $table->json('after_data')->nullable();
                $table->timestamp('created_at');
            });
        }
        $db->transaction(function () use ($db, $portal) {
            foreach (['asset-manager.discipline' => 'permission_view', 'asset-manager.discipline.manage' => 'permission_manage'] as $slug => $label) {
                if ($db->table(config('admin.database.permissions_table'))->where('slug', $slug)->exists()) {
                    continue;
                }
                $db->table(config('admin.database.permissions_table'))->insert([
                    'slug' => $slug, 'name' => __('discipline.'.$label, [], 'zh-CN'),
                    'http_method' => '', 'http_path' => '', 'created_at' => now('UTC'), 'updated_at' => now('UTC'),
                ]);
            }
            if (! $db->table('portal_menus')->where('portal_id', $portal->id)->where('code', 'asset_manager_discipline')->exists()) {
                $db->table('portal_menus')->insert([
                    'portal_id' => $portal->id, 'parent_id' => 0, 'code' => 'asset_manager_discipline',
                    'title' => null, 'title_key' => 'discipline.title', 'path' => '/asset-manager/discipline',
                    'permission_slug' => 'asset-manager.discipline', 'icon' => 'fa-calendar', 'sort' => 50, 'is_enabled' => true,
                    'created_at' => now('UTC'), 'updated_at' => now('UTC'),
                ]);
            }
        });
    }

    // Deliberately retain records, audit history, and assigned permissions on rollback.
    public function down(): void {}
};
