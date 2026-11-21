<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\AssistantDomainStore;
use App\Models\AssetResource;
use App\Models\AssetCodexRenewal;
use App\Models\AssetIpfoxyAccount;
use App\Models\Department;
use App\Portals\Assistant\AssistantPortalAuthorizationService;
use App\Portals\Assistant\Http\Controllers\DomainStoreController;
use App\Portals\Assistant\Http\Controllers\ShopifyAnalyticsController;
use App\Portals\AssetManager\AssetManagerDepartmentScope;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class AssistantFullDataAccessTest extends TestCase
{
    private AdminUser $user;
    private AssistantPortalAuthorizationService $authorization;

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:', 'admin.database.connection' => null]);
        DB::purge('sqlite');
        Schema::create('admin_roles', function (Blueprint $t): void {
            $t->id(); $t->string('name'); $t->string('slug'); $t->unsignedBigInteger('parent_id')->nullable();
            $t->integer('sort')->default(0); $t->timestamps();
        });
        Schema::create('admin_users', function (Blueprint $t): void {
            $t->id(); $t->string('name'); $t->string('username'); $t->timestamps();
        });
        Schema::create('admin_role_users', function (Blueprint $t): void {
            $t->unsignedBigInteger('role_id'); $t->unsignedBigInteger('user_id'); $t->string('position');
            $t->string('position_slot')->nullable(); $t->boolean('is_primary')->default(false); $t->timestamps();
        });
        Schema::create('assistant_domain_stores', function (Blueprint $t): void {
            $t->id(); $t->string('site_builder')->default('shopify'); $t->timestamps();
        });
        Schema::create('assistant_domain_store_department_accesses', function (Blueprint $t): void {
            $t->unsignedBigInteger('domain_store_id'); $t->unsignedBigInteger('department_id'); $t->timestamps();
        });
        foreach (['asset_resources', 'asset_codex_renewals', 'asset_ipfoxy_accounts'] as $table) {
            Schema::create($table, function (Blueprint $t): void {
                $t->id(); $t->unsignedBigInteger('owner_department_id')->nullable(); $t->timestamps();
            });
        }
        foreach ([
            [4, 'Assistant', 'dept-000004', null],
            [35, 'Team A', 'dept-000035', 4],
            [13, 'Team B', 'dept-000013', 4],
            [99, 'Other root', 'outside-root', null],
        ] as [$id, $name, $slug, $parent]) {
            DB::table('admin_roles')->insert(['id' => $id, 'name' => $name, 'slug' => $slug, 'parent_id' => $parent]);
        }
        $this->user = new class extends AdminUser {
            public bool $systemAdministrator = false;
            public function isAdministrator(): bool { return $this->systemAdministrator; }
        };
        $this->user->forceFill(['name' => 'Tester', 'username' => 'tester'])->save();
        $this->authorization = app(AssistantPortalAuthorizationService::class);
        foreach ([1, 2, 3, 4] as $id) DB::table('assistant_domain_stores')->insert(['id' => $id, 'site_builder' => 'shopify']);
        DB::table('assistant_domain_store_department_accesses')->insert([
            ['domain_store_id' => 1, 'department_id' => 35],
            ['domain_store_id' => 2, 'department_id' => 13],
            ['domain_store_id' => 3, 'department_id' => 35],
            ['domain_store_id' => 3, 'department_id' => 13],
        ]);
    }

    private function memberships(array $memberships): void
    {
        DB::table('admin_role_users')->where('user_id', $this->user->id)->delete();
        foreach ($memberships as [$department, $position]) {
            DB::table('admin_role_users')->insert(['user_id' => $this->user->id, 'role_id' => $department, 'position' => $position]);
        }
        $this->user->unsetRelation('roles');
    }

    private function storeIds(string $controller, string $method): array
    {
        $instance = app($controller);
        $query = (new \ReflectionMethod($instance, $method))->invoke($instance, $this->user);

        return $query->orderBy('assistant_domain_stores.id')->pluck('assistant_domain_stores.id')->all();
    }

    public function test_child_managers_and_deputies_are_scoped_across_domain_stores_shopify_and_assets(): void
    {
        foreach (['manager', 'deputy'] as $position) {
            $this->memberships([[35, $position]]);
            self::assertFalse($this->authorization->canManageAllDomainStores($this->user));
            self::assertTrue($this->authorization->canManageQuickOptions($this->user));
            self::assertTrue($this->authorization->canManageDomainStores($this->user));
            self::assertSame([1, 3], $this->storeIds(DomainStoreController::class, 'scopedStoreQuery'));
            self::assertSame([1, 3], $this->storeIds(ShopifyAnalyticsController::class, 'visibleShopifyStores'));
            $scope = app(AssetManagerDepartmentScope::class);
            self::assertSame([35], array_keys($scope->departments($this->user)));
            foreach ([AssetResource::class, AssetCodexRenewal::class, AssetIpfoxyAccount::class] as $model) {
                $model::query()->delete();
                $table = (new $model)->getTable();
                $mine = DB::table($table)->insertGetId(['owner_department_id' => 35]);
                $other = DB::table($table)->insertGetId(['owner_department_id' => 13]);
                DB::table($table)->insert(['owner_department_id' => null]);
                self::assertSame([$mine], $scope->apply($model::query(), $this->user)->pluck('id')->all());
                self::assertFalse($scope->apply($model::query(), $this->user)->whereKey($other)->exists());
            }
            self::assertSame(35, $scope->owner($this->user, []));
            try {
                $scope->owner($this->user, ['owner_department_id' => 13]);
                self::fail('A child manager cannot write another department.');
            } catch (ValidationException $e) {
                self::assertArrayHasKey('owner_department_id', $e->errors());
            }
        }
    }

    public function test_root_managers_deputies_and_system_admin_keep_full_access(): void
    {
        foreach (['manager', 'deputy'] as $position) {
            $this->memberships([[4, $position]]);
            self::assertTrue($this->authorization->canManageAllDomainStores($this->user));
            self::assertSame([1, 2, 3, 4], $this->storeIds(DomainStoreController::class, 'scopedStoreQuery'));
            self::assertSame([1, 2, 3, 4], $this->storeIds(ShopifyAnalyticsController::class, 'visibleShopifyStores'));
            self::assertTrue(app(AssetManagerDepartmentScope::class)->all($this->user));
        }
        $this->memberships([]);
        $this->user->systemAdministrator = true;
        self::assertTrue($this->authorization->canManageAllDomainStores($this->user));
        self::assertSame([1, 2, 3, 4], $this->storeIds(ShopifyAnalyticsController::class, 'visibleShopifyStores'));
    }

    public function test_root_member_plus_child_manager_does_not_gain_full_access(): void
    {
        $this->memberships([[4, 'member'], [35, 'manager']]);
        self::assertFalse($this->authorization->canManageAllDomainStores($this->user));
        self::assertTrue($this->authorization->canManageQuickOptions($this->user));
        self::assertSame([1, 3], $this->storeIds(ShopifyAnalyticsController::class, 'visibleShopifyStores'));
        $this->memberships([[35, 'member']]);
        self::assertFalse($this->authorization->canManageQuickOptions($this->user));
        self::assertSame([1, 3], $this->storeIds(ShopifyAnalyticsController::class, 'visibleShopifyStores'));
    }

    public function test_other_root_managers_and_ambiguous_child_memberships_do_not_gain_full_access(): void
    {
        $this->memberships([[99, 'manager']]);
        self::assertFalse($this->authorization->canManageAllDomainStores($this->user));
        self::assertSame([], $this->storeIds(ShopifyAnalyticsController::class, 'visibleShopifyStores'));
        $this->memberships([[35, 'manager'], [13, 'deputy']]);
        self::assertFalse($this->authorization->canManageAllDomainStores($this->user));
        self::assertSame([], $this->storeIds(ShopifyAnalyticsController::class, 'visibleShopifyStores'));
    }
}
