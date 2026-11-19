<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Portals\Assistant\AssistantStoreOwnerService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AssistantStoreOwnershipDepartmentScopeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
            'admin.database.connection' => null,
        ]);
        app('db')->purge('sqlite');

        Schema::create('admin_roles', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('name');
            $table->string('slug')->unique();
            $table->unsignedBigInteger('parent_id')->nullable();
            $table->integer('sort')->default(0);
            $table->timestamps();
        });
    }

    public function test_store_owner_scope_accepts_a_root_department_when_it_has_no_children(): void
    {
        $this->department(22, '运营部门', 'dept-000022');
        $this->department(23, '郑州运营部门', 'dept-000023', 22);
        $this->department(37, '服装运营部门', 'dept-000037');
        $this->department(38, '厦门服装组', 'dept-000038', 37);
        $this->department(29, '新增一级运营部门', 'dept-000029');

        $selectableIds = app(AssistantStoreOwnerService::class)
            ->storeOwnershipSelectableOperationDepartments()
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        $this->assertContains(23, $selectableIds);
        $this->assertContains(38, $selectableIds);
        $this->assertContains(29, $selectableIds);
        $this->assertNotContains(22, $selectableIds);
        $this->assertNotContains(37, $selectableIds);
    }

    public function test_configuration_reads_all_business_roots_and_second_level_departments(): void
    {
        $this->department(1, 'System', 'administrator');
        $this->department(4, 'Assistant', 'dept-000004');
        $this->department(5, 'Assistant team', 'assistant-team', 4);
        $this->department(50, 'New business root', 'new-business-root');
        $this->department(51, 'New business team', 'new-business-team', 50);
        $this->department(52, 'Third level', 'third-level', 51);
        $this->department(60, 'Standalone root', 'standalone-root');
        $this->department(70, 'Orphan', 'orphan', 999);

        $service = app(AssistantStoreOwnerService::class);
        $tree = $service->scopeConfigurationDepartments();
        $this->assertSame([4, 5, 50, 51, 60], $tree->pluck('id')->all());
        $this->assertSame([0, 1, 0, 1, 0], $tree->pluck('tree_depth')->all());
        $this->assertSame([5, 51, 60], $service->selectableScopeConfigurationDepartments()->pluck('id')->all());
    }

    public function test_new_departments_are_read_without_changing_codes_or_restarting_service(): void
    {
        $service = app(AssistantStoreOwnerService::class);
        $this->assertCount(0, $service->selectableScopeConfigurationDepartments());
        $this->department(50, 'Root', 'arbitrary-code');
        $this->assertSame([50], $service->selectableScopeConfigurationDepartments()->pluck('id')->all());
        $this->department(51, 'Child', 'another-arbitrary-code', 50);
        $this->assertSame([51], $service->selectableScopeConfigurationDepartments()->pluck('id')->all());
    }

    public function test_configuration_expansion_does_not_expand_legacy_reporting_and_finance_candidates(): void
    {
        $this->department(22, 'Operations', 'dept-000022');
        $this->department(23, 'Operations team', 'dept-000023', 22);
        $this->department(37, 'Fashion operations', 'dept-000037');
        $this->department(29, 'Other approved operations', 'dept-000029');
        $this->department(50, 'New business root', 'arbitrary-root');
        $this->department(51, 'New business team', 'arbitrary-team', 50);
        $service = app(AssistantStoreOwnerService::class);

        $this->assertContains(51, $service->selectableScopeConfigurationDepartments()->pluck('id')->all());
        $this->assertSame([22, 23], $service->operationDepartments()->pluck('id')->all());
        $this->assertSame([22, 23, 29, 37], $service->storeOwnershipOperationDepartments()->pluck('id')->all());
        $this->assertSame([23, 29, 37], $service->storeOwnershipSelectableOperationDepartments()->pluck('id')->all());
    }

    public function test_new_business_candidates_are_assignable_only_after_explicit_binding(): void
    {
        Schema::create('assistant_dept_operation_scopes', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('assistant_department_id');
            $table->unsignedBigInteger('operation_department_id');
            $table->unsignedBigInteger('last_operator_id')->nullable();
            $table->timestamps();
        });
        $this->department(4, 'Assistant', 'dept-000004');
        $this->department(5, 'Assistant team', 'assistant-team', 4);
        $this->department(50, 'New business root', 'arbitrary-root');
        $this->department(51, 'Bound team', 'arbitrary-team', 50);
        $this->department(60, 'Unbound root', 'unbound-root');
        $actor = new \App\Models\AdminUser();
        $actor->id = 123;
        $this->mock(\App\Portals\Assistant\AssistantPortalAuthorizationService::class)
            ->shouldReceive('canManageAllDomainStores')->andReturn(true);
        $controller = app(\App\Portals\Assistant\Http\Controllers\DomainStoreController::class);
        $method = new \ReflectionMethod($controller, 'assignableOperationDepartments');
        $this->assertSame([], $method->invoke($controller, $actor)->pluck('id')->all());

        $service = app(AssistantStoreOwnerService::class);
        $service->replaceScopes([5 => [51]], $actor);
        $this->assertSame([5 => [51]], $service->scopeMap());
        $this->assertSame([51], $method->invoke($controller, $actor)->pluck('id')->all());
        $this->assertSame(123, (int) \App\Models\AssistantDepartmentOperationScope::first()->last_operator_id);
    }

    private function department(int $id, string $name, string $slug, ?int $parentId = null): void
    {
        $department = new Department([
            'name' => $name,
            'slug' => $slug,
            'parent_id' => $parentId,
            'sort' => $id,
        ]);
        $department->id = $id;
        $department->save();
    }
}
