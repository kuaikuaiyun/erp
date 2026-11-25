<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\Department;
use App\Models\Portal;
use App\Portal\Services\PortalMenuAuthorizationService;
use App\Services\DepartmentAuthorizationService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PortalMenuDepartmentScopeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
            'admin.database.connection' => null,
        ]);
        DB::purge('sqlite');

        Schema::create('admin_users', function (Blueprint $table): void {
            $table->id();
            $table->string('username');
            $table->string('password')->nullable();
            $table->string('name');
            $table->integer('status')->default(1);
            $table->timestamps();
        });
        Schema::create('admin_roles', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('slug');
            $table->unsignedBigInteger('parent_id')->nullable();
            $table->integer('sort')->default(0);
            $table->timestamps();
        });
        Schema::create('admin_role_users', function (Blueprint $table): void {
            $table->unsignedBigInteger('role_id');
            $table->unsignedBigInteger('user_id');
            $table->string('position')->default('member');
            $table->string('position_slot')->nullable();
            $table->boolean('is_primary')->default(false);
            $table->timestamps();
        });
        Schema::create('admin_permissions', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('slug');
            $table->string('http_method')->nullable();
            $table->text('http_path')->nullable();
            $table->timestamps();
        });
        Schema::create('admin_role_permissions', function (Blueprint $table): void {
            $table->unsignedBigInteger('role_id');
            $table->unsignedBigInteger('permission_id');
        });
        Schema::create('admin_user_permissions', function (Blueprint $table): void {
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('permission_id');
        });
        Schema::create('admin_department_permission_scopes', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('role_id');
            $table->unsignedBigInteger('permission_id');
            $table->string('scope');
            $table->timestamps();
        });
        Schema::create('admin_user_permission_overrides', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('permission_id');
            $table->string('effect');
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
        });
        Schema::create('portals', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('code');
            $table->string('remark')->nullable();
            $table->string('status');
            $table->timestamps();
        });
        Schema::create('portal_departments', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('portal_id');
            $table->unsignedBigInteger('department_id');
            $table->boolean('includes_descendants')->default(true);
            $table->unsignedBigInteger('granted_by')->nullable();
            $table->timestamp('granted_at')->nullable();
            $table->timestamps();
        });
        Schema::create('portal_menus', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('portal_id');
            $table->unsignedBigInteger('parent_id')->default(0);
            $table->string('code');
            $table->string('title')->nullable();
            $table->string('title_key')->nullable();
            $table->string('path');
            $table->string('permission_slug')->nullable();
            $table->string('icon')->nullable();
            $table->integer('sort')->default(0);
            $table->boolean('is_enabled')->default(true);
            $table->timestamps();
        });
    }

    public function test_portal_menus_only_use_permissions_from_departments_authorized_for_that_portal(): void
    {
        $rootA = Department::create(['name' => 'Portal A root', 'slug' => 'root-a']);
        $rootB = Department::create(['name' => 'Portal B root', 'slug' => 'root-b']);
        $teamA = Department::create(['name' => 'Portal A team', 'slug' => 'team-a', 'parent_id' => $rootA->id]);
        $teamB = Department::create(['name' => 'Portal B team', 'slug' => 'team-b', 'parent_id' => $rootB->id]);

        DB::table('admin_users')->insert(['id' => 10, 'username' => 'multi', 'name' => 'Multi department']);
        foreach ([$teamA->id, $teamB->id] as $roleId) {
            DB::table('admin_role_users')->insert(['role_id' => $roleId, 'user_id' => 10]);
        }

        $allowedPermission = DB::table('admin_permissions')->insertGetId([
            'name' => 'Allowed page', 'slug' => 'portal.a.allowed',
        ]);
        $foreignPermission = DB::table('admin_permissions')->insertGetId([
            'name' => 'Foreign page', 'slug' => 'portal.a.foreign',
        ]);
        DB::table('admin_role_permissions')->insert([
            ['role_id' => $teamA->id, 'permission_id' => $allowedPermission],
            ['role_id' => $teamB->id, 'permission_id' => $foreignPermission],
        ]);

        $portal = Portal::create(['name' => 'Portal A', 'code' => 'a', 'status' => Portal::STATUS_ENABLED]);
        DB::table('portal_departments')->insert([
            'portal_id' => $portal->id,
            'department_id' => $rootA->id,
            'includes_descendants' => true,
        ]);
        $portal->menus()->createMany([
            ['code' => 'home', 'path' => '/', 'sort' => 0, 'permission_slug' => null],
            ['code' => 'allowed', 'path' => '/allowed', 'sort' => 1, 'permission_slug' => 'portal.a.allowed'],
            ['code' => 'foreign', 'path' => '/foreign', 'sort' => 2, 'permission_slug' => 'portal.a.foreign'],
        ]);
        $foreignGroup = $portal->menus()->create([
            'code' => 'foreign_group', 'path' => '#', 'sort' => 3, 'permission_slug' => null,
        ]);
        $portal->menus()->create([
            'code' => 'foreign_group_page',
            'parent_id' => $foreignGroup->id,
            'path' => '/foreign-group-page',
            'sort' => 4,
            'permission_slug' => 'portal.a.foreign',
        ]);

        $user = AdminUser::query()->findOrFail(10);
        $permissions = app(DepartmentAuthorizationService::class)->permissionsForPortal($user, $portal);
        self::assertSame(['portal.a.allowed'], $permissions->pluck('slug')->values()->all());

        $portal->load('menus');
        $visibleCodes = app(PortalMenuAuthorizationService::class)
            ->visibleMenus($portal, $user)
            ->pluck('code')
            ->values()
            ->all();

        self::assertSame(['home', 'allowed'], $visibleCodes);
        self::assertNotContains('foreign', $visibleCodes);
        self::assertNotContains('foreign_group', $visibleCodes);
    }
}
