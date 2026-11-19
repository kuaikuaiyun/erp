<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\AssistantDomainStore;
use App\Models\AssistantDomainStoreOwnerHistory;
use App\Models\Department;
use App\Models\LogisticsOrder;
use App\Portals\Assistant\AssistantPortalAuthorizationService;
use App\Portals\Assistant\AssistantStoreOwnerTimelineService;
use App\Portals\Assistant\Http\Controllers\DomainStoreController;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class AssistantDomainStoreReserveOwnerTest extends TestCase
{
    private DomainStoreController $controller;
    private AdminUser $actor;

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:', 'admin.database.connection' => null]);
        app('db')->purge('sqlite');
        app()->setLocale('zh-CN');
        Schema::create('assistant_domain_stores', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('owner_user_id')->nullable();
            $t->unsignedBigInteger('owner_operation_department_id')->nullable();
            $t->unsignedBigInteger('owner_department_id')->nullable();
            $t->timestamps();
        });
        Schema::create('admin_roles', function (Blueprint $t): void {
            $t->id(); $t->string('name'); $t->string('slug');
            $t->unsignedBigInteger('parent_id')->nullable(); $t->integer('sort')->default(0); $t->timestamps();
        });
        Schema::create('admin_users', function (Blueprint $t): void {
            $t->id(); $t->string('name'); $t->string('username'); $t->integer('status')->default(1); $t->timestamps();
        });
        Schema::create('admin_role_users', function (Blueprint $t): void {
            $t->unsignedBigInteger('role_id'); $t->unsignedBigInteger('user_id');
            $t->string('position')->default('member'); $t->string('position_slot')->nullable();
            $t->boolean('is_primary')->default(true); $t->timestamps();
        });
        Schema::create('assistant_dept_operation_scopes', function (Blueprint $t): void {
            $t->id(); $t->unsignedBigInteger('assistant_department_id'); $t->unsignedBigInteger('operation_department_id'); $t->timestamps();
        });
        Schema::create('assistant_domain_store_owner_histories', function (Blueprint $t): void {
            $t->id(); $t->unsignedBigInteger('assistant_domain_store_id');
            $t->unsignedBigInteger('owner_user_id')->nullable(); $t->string('owner_name_snapshot')->nullable();
            $t->unsignedBigInteger('operation_department_id')->nullable(); $t->string('operation_department_name_snapshot')->nullable();
            $t->string('event'); $t->dateTime('effective_from'); $t->dateTime('effective_to')->nullable();
            $t->unsignedBigInteger('actor_id')->nullable(); $t->string('actor_name_snapshot')->nullable(); $t->timestamps();
        });
        Schema::create('logistics_orders', function (Blueprint $t): void {
            $t->id(); $t->unsignedBigInteger('assistant_domain_store_id'); $t->dateTime('order_created_at');
            $t->unsignedBigInteger('assigned_owner_user_id')->nullable(); $t->string('assigned_owner_name')->nullable();
            $t->dateTime('owner_assignment_effective_from')->nullable(); $t->dateTime('owner_assignment_resolved_at')->nullable(); $t->timestamps();
        });
        $authorization = $this->mock(AssistantPortalAuthorizationService::class);
        $authorization->shouldReceive('canAssignStoreOwners')->andReturn(true)->byDefault();
        $authorization->shouldReceive('canManageAllDomainStores')->andReturn(true)->byDefault();
        $this->controller = app(DomainStoreController::class);
        $this->actor = new AdminUser(['name' => 'Assistant', 'username' => 'assistant']);
        $this->actor->id = 100;
    }

    private function invoke(string $method, mixed ...$args): mixed
    {
        return (new \ReflectionMethod($this->controller, $method))->invoke($this->controller, ...$args);
    }

    public function test_explicit_reserve_persists_null_ids_and_null_history_without_changing_access_owner(): void
    {
        $request = Request::create('/', 'POST', ['operation_department_id' => 'reserve']);
        $owner = $this->invoke('ownerAssignmentForRequest', $request, $this->actor);
        $department = $this->invoke('ownerOperationDepartmentIdForRequest', $request, null, $owner);
        $store = AssistantDomainStore::create(['owner_user_id' => $owner, 'owner_operation_department_id' => $department, 'owner_department_id' => 5]);
        $history = app(AssistantStoreOwnerTimelineService::class)->ensureBaseline($store, $this->actor);
        self::assertNull($store->fresh()->owner_user_id);
        self::assertNull($store->fresh()->owner_operation_department_id);
        self::assertSame(5, (int) $store->fresh()->owner_department_id);
        self::assertNull($history->owner_user_id);
        self::assertNull($history->operation_department_id);
    }

    public function test_empty_or_partial_assignment_still_fails_without_selecting_reserve(): void
    {
        foreach ([[], ['operation_department_id' => 22], ['owner_user_id' => 9], ['operation_department_id' => '', 'owner_user_id' => '']] as $input) {
            try {
                $this->invoke('ownerAssignmentForRequest', Request::create('/', 'POST', $input), $this->actor);
                self::fail('An explicit Reserve selection or complete real assignment is required.');
            } catch (ValidationException $e) {
                self::assertNotEmpty($e->errors());
            }
        }
    }

    public function test_reserve_rejects_a_real_owner_in_the_same_request(): void
    {
        $this->expectException(ValidationException::class);
        $this->invoke('ownerAssignmentForRequest', Request::create('/', 'POST', [
            'operation_department_id' => 'reserve', 'owner_user_id' => 9,
        ]), $this->actor);
    }

    public function test_editing_a_reserve_store_refills_reserve_and_does_not_reuse_baseline_time(): void
    {
        $store = AssistantDomainStore::create(['owner_user_id' => null, 'owner_operation_department_id' => null]);
        app(AssistantStoreOwnerTimelineService::class)->ensureBaseline($store, $this->actor, Carbon::parse('2026-01-01'));
        $form = $this->invoke('ownerAssignmentFormData', $this->actor, $store);
        self::assertTrue($form['ownerIsReserve']);
        self::assertNull($form['ownerOperationDepartmentId']);
        self::assertNull($form['ownerUserId']);
        self::assertSame('', $form['ownerEffectiveAt']);
        $new = $this->invoke('ownerAssignmentFormData', $this->actor);
        self::assertFalse($new['ownerIsReserve']);
    }

    public function test_filling_a_real_owner_keeps_earlier_orders_in_reserve_and_allows_return_to_reserve(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-19 10:00:00', 'UTC'));
        try {
            $department = Department::create(['name' => 'Operations', 'slug' => 'dept-000022']);
            $owner = AdminUser::create(['name' => 'Operator', 'username' => 'operator', 'status' => 1]);
            \Illuminate\Support\Facades\DB::table('admin_role_users')->insert(['role_id' => $department->id, 'user_id' => $owner->id]);
            \Illuminate\Support\Facades\DB::table('assistant_dept_operation_scopes')->insert(['assistant_department_id' => 5, 'operation_department_id' => $department->id]);
            $request = Request::create('/', 'POST', ['operation_department_id' => $department->id, 'owner_user_id' => $owner->id]);
            self::assertSame($owner->id, $this->invoke('ownerAssignmentForRequest', $request, $this->actor));
            $store = AssistantDomainStore::create(['owner_user_id' => null, 'owner_operation_department_id' => null]);
            $timeline = app(AssistantStoreOwnerTimelineService::class);
            $timeline->ensureBaseline($store, $this->actor, Carbon::parse('2026-11-19 08:00:00', 'UTC'));
            $early = LogisticsOrder::create(['assistant_domain_store_id' => $store->id, 'order_created_at' => '2026-11-19 09:00:00']);
            $later = LogisticsOrder::create(['assistant_domain_store_id' => $store->id, 'order_created_at' => '2026-11-19 10:30:00']);
            $timeline->assign($store, $owner, $department, now(), $this->actor);
            self::assertNull($early->fresh()->assigned_owner_user_id);
            self::assertSame($owner->id, (int) $later->fresh()->assigned_owner_user_id);
            self::assertSame($owner->id, (int) $store->fresh()->owner_user_id);
            $reserve = Request::create('/', 'POST', ['operation_department_id' => 'reserve']);
            self::assertNull($this->invoke('ownerAssignmentForRequest', $reserve, $this->actor, $store));
            self::assertNull($this->invoke('ownerOperationDepartmentIdForRequest', $reserve, $store, null));
            Carbon::setTestNow(Carbon::parse('2026-11-19 11:00:00', 'UTC'));
            $timeline->assign($store, null, null, now(), $this->actor);
            self::assertNull($store->fresh()->owner_user_id);
            self::assertNull($store->fresh()->owner_operation_department_id);
            self::assertSame($owner->id, (int) $later->fresh()->assigned_owner_user_id);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_reserve_does_not_allow_an_unauthorized_user_to_clear_an_existing_owner(): void
    {
        app(AssistantPortalAuthorizationService::class)->shouldReceive('canAssignStoreOwners')->andReturn(false);
        $store = new AssistantDomainStore(['owner_user_id' => 9, 'owner_operation_department_id' => 22]);
        $request = Request::create('/', 'POST', ['operation_department_id' => 'reserve']);
        self::assertSame(9, $this->invoke('ownerAssignmentForRequest', $request, $this->actor, $store));
        self::assertSame(22, $this->invoke('ownerOperationDepartmentIdForRequest', $request, $store, 9));
    }
}
