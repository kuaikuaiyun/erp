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
        if (! $schema->hasTable('abnormal_order_records')) {
            $schema->create('abnormal_order_records', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('logistics_order_id')->unique();
                $table->string('status', 20)->default('pending')->index();
                $table->unsignedInteger('processed_by_user_id')->nullable();
                $table->string('processed_by_name', 100)->nullable();
                $table->timestamp('processed_at')->nullable();
                $table->unsignedInteger('last_notified_by_user_id')->nullable();
                $table->string('last_notified_by_name', 100)->nullable();
                $table->timestamp('last_notified_at')->nullable();
                $table->timestamps();
            });
        }
        if (! $schema->hasTable('portals') || ! $schema->hasTable('portal_menus')) return;
        $db = DB::connection($connection);
        $definitions = [
            'yunying' => ['code' => 'operation_abnormal_orders', 'permission' => 'portal.yunying.abnormal_orders', 'name' => '[运营系统] 异常订单', 'sort' => 67],
            'caigou' => ['code' => 'caigou_abnormal_orders', 'permission' => 'portal.caigou.abnormal_orders', 'name' => '[入录审核系统] 异常订单', 'sort' => 51],
        ];
        foreach ($definitions as $portalCode => $definition) {
            $portalId = $db->table('portals')->where('code', $portalCode)->value('id');
            if (! $portalId) continue;
            $menu = [
                'parent_id' => 0, 'title' => '异常订单', 'title_key' => 'abnormal_orders.menu',
                'path' => '/abnormal-orders', 'icon' => 'fa-exclamation-circle', 'sort' => $definition['sort'],
                'is_enabled' => true, 'created_at' => now(), 'updated_at' => now(),
            ];
            if ($schema->hasColumn('portal_menus', 'permission_slug')) $menu['permission_slug'] = $definition['permission'];
            $db->table('portal_menus')->updateOrInsert(['portal_id' => $portalId, 'code' => $definition['code']], $menu);
            if (! $schema->hasTable(config('admin.database.permissions_table'))) continue;
            $permissionId = $db->table(config('admin.database.permissions_table'))->updateOrInsert(
                ['slug' => $definition['permission']],
                ['name' => $definition['name'], 'http_method' => '', 'http_path' => '', 'created_at' => now(), 'updated_at' => now()],
            );
            $permissionId = $db->table(config('admin.database.permissions_table'))->where('slug', $definition['permission'])->value('id');
            foreach ($db->table(config('admin.database.roles_table'))->where('slug', '!=', 'administrator')->pluck('id') as $roleId) {
                $db->table(config('admin.database.role_permissions_table'))->updateOrInsert(
                    ['role_id' => $roleId, 'permission_id' => $permissionId], ['created_at' => now(), 'updated_at' => now()]
                );
            }
        }
    }

    public function down(): void
    {
        $connection = config('admin.database.connection') ?: config('database.default');
        $db = DB::connection($connection);
        if (Schema::connection($connection)->hasTable('portal_menus')) {
            $db->table('portal_menus')->whereIn('code', ['operation_abnormal_orders', 'caigou_abnormal_orders'])->delete();
        }
        Schema::connection($connection)->dropIfExists('abnormal_order_records');
    }
};
