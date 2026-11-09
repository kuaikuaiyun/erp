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
