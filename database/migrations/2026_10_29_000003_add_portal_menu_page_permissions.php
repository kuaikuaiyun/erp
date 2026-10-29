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

        if (!$schema->hasColumn('portal_menus', 'permission_slug')) {
            $schema->table('portal_menus', function (Blueprint $table): void {
                $table->string('permission_slug', 150)->nullable()->after('path')->index();
            });
        }

        if (!$schema->hasTable('admin_user_permission_overrides')) {
            $schema->create('admin_user_permission_overrides', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('user_id');
                $table->unsignedBigInteger('permission_id');
                $table->enum('effect', ['allow', 'deny']);
                $table->unsignedBigInteger('updated_by')->nullable();
                $table->timestamps();
                $table->unique(['user_id', 'permission_id']);
                $table->index(['permission_id', 'effect']);
            });
        }

        $database = DB::connection($connection);
        $permissionsTable = config('admin.database.permissions_table');
        $rolePermissionsTable = config('admin.database.role_permissions_table');
        $rolesTable = config('admin.database.roles_table');
        $timestamp = now();
        $systemCodes = ['home', 'backend_management', 'menu_configuration'];

        $portals = $database->table('portals')->get(['id', 'code', 'name'])->keyBy('id');
        $menus = $database->table('portal_menus')
            ->whereNotIn('code', $systemCodes)
            ->where('path', '!=', '#')
            ->get(['id', 'portal_id', 'code', 'title', 'title_key']);

        foreach ($menus as $menu) {
            $portal = $portals->get($menu->portal_id);
            $portalCode = (string) ($portal->code ?? 'portal');
            $slug = 'portal.'.preg_replace('/[^a-z0-9_\-]+/i', '-', $portalCode).'.'.preg_replace('/[^a-z0-9_\-]+/i', '-', (string) $menu->code);
            $database->table('portal_menus')->where('id', $menu->id)->update(['permission_slug' => $slug]);

            $permissionId = $database->table($permissionsTable)->where('slug', $slug)->value('id');
            if (!$permissionId) {
                $permissionId = $database->table($permissionsTable)->insertGetId([
                    'name' => '['.(string) ($portal->name ?? $portalCode).'] '.(string) ($menu->title ?: $menu->title_key ?: $menu->code),
                    'slug' => $slug,
                    'http_method' => '',
                    'http_path' => '',
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ]);
            }

            // Preserve existing behaviour on deployment. Administrators can
            // subsequently remove a page from a department in the UI.
            $roleIds = $database->table($rolesTable)->where('slug', '!=', 'administrator')->pluck('id');
            foreach ($roleIds as $roleId) {
                $database->table($rolePermissionsTable)->updateOrInsert([
                    'role_id' => $roleId,
                    'permission_id' => $permissionId,
                ], [
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ]);
            }
        }
    }

    public function down(): void
    {
        $connection = config('admin.database.connection') ?: config('database.default');
        $schema = Schema::connection($connection);
        $database = DB::connection($connection);
        if ($schema->hasColumn('portal_menus', 'permission_slug')) {
            $permissionIds = $database->table(config('admin.database.permissions_table'))
                ->whereIn('slug', $database->table('portal_menus')->whereNotNull('permission_slug')->pluck('permission_slug'))
                ->pluck('id');
            if ($permissionIds->isNotEmpty()) {
                $database->table(config('admin.database.role_permissions_table'))->whereIn('permission_id', $permissionIds)->delete();
                $database->table(config('admin.database.user_permissions_table'))->whereIn('permission_id', $permissionIds)->delete();
                $database->table(config('admin.database.permissions_table'))->whereIn('id', $permissionIds)->delete();
            }
        }
        if ($schema->hasTable('admin_user_permission_overrides')) {
            $schema->drop('admin_user_permission_overrides');
        }
        if ($schema->hasColumn('portal_menus', 'permission_slug')) {
            $schema->table('portal_menus', fn (Blueprint $table) => $table->dropColumn('permission_slug'));
        }
    }
};
