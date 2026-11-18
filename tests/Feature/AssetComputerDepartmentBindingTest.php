<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\AssetComputerEquipment;
use App\Models\Department;
use App\Models\Portal;
use App\Portal\Http\Controllers\PortalEntryController;
use App\Portals\AssetManager\AssetComputerDepartmentBindingService;
use App\Portals\AssetManager\AssetManagerSpreadsheetService;
use App\Services\AdminWatermarkService;
use App\Services\SystemSettingService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

class AssetComputerDepartmentBindingTest extends TestCase
{
    private AdminUser $actor;

    private Portal $portal;

    private Department $root;

    private Department $a;

    private Department $b;

    private Department $solo;

    private Department $outside;

    private string $configUrl = '/asset-manager/computer-department-bindings';

    private string $computerUrl = '/asset-manager/computers';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('x', 32)), 'app.locale' => 'zh-CN', 'database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:', 'admin.database.connection' => null, 'cache.default' => 'array']);
        DB::purge('sqlite');
        Cache::flush();
        app()->setLocale('zh-CN');
        Schema::create('portals', function (Blueprint $t) {
            $t->id();
            $t->string('code');
            $t->string('name');
            $t->string('status');
            $t->timestamps();
        });
        Schema::create('portal_menus', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('portal_id');
            $t->integer('parent_id');
            foreach (['code', 'title', 'title_key', 'path', 'permission_slug', 'icon'] as $column) {
                $t->string($column)->nullable();
            }
            $t->integer('sort');
            $t->boolean('is_enabled');
            $t->timestamps();
        });
        Schema::create('admin_permissions', function (Blueprint $t) {
            $t->id();
            $t->string('slug')->unique();
            $t->string('name');
            $t->string('http_method');
            $t->string('http_path');
            $t->timestamps();
        });
        Schema::create('admin_roles', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('slug');
            $t->unsignedBigInteger('parent_id')->nullable();
            $t->integer('sort')->default(0);
            $t->timestamps();
        });
        Schema::create('admin_users', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('username');
            $t->integer('status')->default(1);
            $t->timestamps();
        });
        Schema::create('admin_role_users', function (Blueprint $t) {
            $t->unsignedBigInteger('role_id');
            $t->unsignedBigInteger('user_id');
            $t->string('position')->default('member');
            $t->string('position_slot')->nullable();
            $t->boolean('is_primary')->default(false);
            $t->timestamps();
        });
        Schema::create('portal_departments', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('portal_id');
            $t->unsignedBigInteger('department_id');
        });
        $this->portal = Portal::create(['code' => 'asset-manager', 'name' => 'Assets', 'status' => 'enabled']);
        (require base_path('database/migrations/2026_11_17_000200_install_asset_manager.php'))->up();
        (require base_path('database/migrations/2026_11_18_000200_add_computer_equipment_department_scope.php'))->up();
        (require base_path('database/migrations/2026_11_18_000400_install_asset_computer_department_bindings.php'))->up();
        $this->root = Department::create(['name' => 'Root', 'slug' => 'root']);
        $this->a = Department::create(['name' => 'A', 'slug' => 'a', 'parent_id' => $this->root->id]);
        $this->b = Department::create(['name' => 'B', 'slug' => 'b', 'parent_id' => $this->root->id]);
        $this->solo = Department::create(['name' => 'Solo', 'slug' => 'solo']);
        $this->outside = Department::create(['name' => 'Outside', 'slug' => 'outside']);
        foreach ([$this->root, $this->solo] as $root) {
            DB::table('portal_departments')->insert(['portal_id' => $this->portal->id, 'department_id' => $root->id]);
        }
        $this->actor = new class extends AdminUser
        {
            public bool $administrator = true;

            public bool $view = true;

            public function isAdministrator(): bool
            {
                return $this->administrator;
            }

            public function can($ability, $arguments = []): bool
            {
                return str_ends_with($ability, '.manage') ? false : $this->view;
            }
        };
        $this->actor->id = 1;
        $this->actor->name = 'Tester';
        $entry = \Mockery::mock(PortalEntryController::class);
        $entry->shouldReceive('portalContext')->andReturnUsing(fn () => ['portal' => $this->portal->unsetRelation('menus')->load('menus'), 'user' => $this->actor]);
        $this->app->instance(PortalEntryController::class, $entry);
        $this->mock(AdminWatermarkService::class)->shouldReceive('scriptForAdministrator')->andReturn(null);
        $this->mock(SystemSettingService::class)->shouldReceive('footer')->andReturn(['copyright_text' => '', 'contact_information' => '']);
    }

    private function service(): AssetComputerDepartmentBindingService
    {
        return app(AssetComputerDepartmentBindingService::class);
    }

    private function join(Department $source, string $position = 'member'): void
    {
        $this->actor->administrator = false;
        DB::table('admin_role_users')->where('user_id', 1)->delete();
        DB::table('admin_role_users')->insert(['user_id' => 1, 'role_id' => $source->id, 'position' => $position]);
        $this->actor->unsetRelation('roles')->unsetRelation('departments');
    }

    private function configure(Department $source, array $ids): void
    {
        $previous = $this->actor->administrator;
        $this->actor->administrator = true;
        try {
            $this->service()->save($this->actor, $source->id, $ids, $this->service()->version($source->id));
        } finally {
            $this->actor->administrator = $previous;
        }
    }

    private function importFile(): UploadedFile
    {
        $headers = app(AssetManagerSpreadsheetService::class)->headers('computers');
        $handle = fopen('php://temp', 'r+');
        fputcsv($handle, array_values($headers));
        fputcsv($handle, array_map(fn ($key) => $key === 'serial_number' ? 'Imported' : '', array_keys($headers)));
        rewind($handle);
        $contents = stream_get_contents($handle);
        fclose($handle);

        return UploadedFile::fake()->createWithContent('computer.csv', $contents);
    }

    public function test_only_granted_secondary_or_childless_root_departments_are_configuration_subjects(): void
    {
        $this->assertEqualsCanonicalizing([$this->a->id, $this->b->id, $this->solo->id], $this->service()->sourceDepartments()->pluck('id')->all());
        $this->get($this->configUrl)->assertOk()->assertSee('asset-computer-department-bindings.css')->assertSee('acb-modal');
        foreach ([$this->root, $this->outside] as $invalid) {
            $this->getJson($this->configUrl.'?source_department_id='.$invalid->id)->assertUnprocessable();
        }
        DB::table('portal_departments')->delete();
        $this->get($this->configUrl)->assertOk()->assertSee(__('asset_manager.bindings.no_sources'))->assertDontSee('id="acb-form"', false);
    }

    public function test_admin_saves_reopens_clears_and_logs_bindings_with_conflict_protection(): void
    {
        $this->putJson($this->configUrl, ['source_department_id' => $this->a->id, 'department_ids' => [$this->b->id, $this->outside->id], 'version' => 0])->assertOk();
        $version = $this->service()->version($this->a->id);
        $this->get($this->configUrl.'?source_department_id='.$this->a->id)->assertOk()->assertViewHas('selectedIds', [$this->b->id, $this->outside->id]);
        $this->putJson($this->configUrl, ['source_department_id' => $this->a->id, 'department_ids' => [], 'version' => 0])->assertStatus(409);
        $this->putJson($this->configUrl, ['source_department_id' => $this->a->id, 'department_ids' => [], 'version' => $version])->assertOk();
        $this->assertSame([], $this->service()->selectedIds($this->a->id));
        $logs = DB::table('asset_computer_department_binding_logs')->orderBy('id')->get();
        $this->assertCount(2, $logs);
        $this->assertSame('Tester', $logs[1]->actor_name);
        $this->assertSame([$this->b->id, $this->outside->id], json_decode($logs[1]->before_ids, true));
        $this->assertSame([], json_decode($logs[1]->after_ids, true));
        Department::whereKey($this->b->id)->update(['name' => 'Renamed']);
        $this->get($this->configUrl.'?source_department_id='.$this->a->id)->assertSee('Root / B');
    }

    public function test_no_binding_means_no_scope_even_for_manager_and_members_are_read_only_on_bound_targets(): void
    {
        $own = AssetComputerEquipment::create(['owner_department_id' => $this->a->id, 'brand' => 'Own']);
        $target = AssetComputerEquipment::create(['owner_department_id' => $this->outside->id, 'brand' => 'Target', 'monthly_rent' => '12.34']);
        $this->join($this->a, 'manager');
        $this->get($this->computerUrl)->assertViewHas('rows', fn ($rows) => $rows->total() === 0)->assertViewHas('canManage', false);
        $this->postJson($this->computerUrl, [])->assertForbidden();
        $this->configure($this->a, [$this->outside->id]);
        $this->get($this->computerUrl)->assertViewHas('rows', fn ($rows) => $rows->total() === 1)->assertViewHas('cards', fn ($cards) => $cards['rent_total'] === '12.34')->assertSee('id="am-add"', false);
        $this->getJson($this->computerUrl.'/'.$own->id)->assertNotFound();
        $this->putJson($this->computerUrl.'/'.$target->id, ['brand' => 'Managed'])->assertOk();
        $this->join($this->a);
        $this->get($this->computerUrl)->assertSee('Managed')->assertViewHas('canManage', false)->assertDontSee('id="am-add"', false);
        $this->getJson($this->computerUrl.'/'.$target->id)->assertOk();
        $this->putJson($this->computerUrl.'/'.$target->id, ['brand' => 'Blocked'])->assertForbidden();
        $this->deleteJson($this->computerUrl.'/'.$target->id)->assertForbidden();
        $this->postJson($this->computerUrl.'/import/preview', [])->assertForbidden();
        $this->get($this->computerUrl.'/export')->assertOk();
    }

    public function test_multi_department_memberships_read_union_but_only_leadership_sources_grant_writes(): void
    {
        $this->configure($this->a, [$this->outside->id]);
        $this->configure($this->b, [$this->solo->id]);
        $this->join($this->a, 'deputy');
        DB::table('admin_role_users')->insert(['role_id' => $this->b->id, 'user_id' => 1, 'position' => 'member']);
        $this->assertEqualsCanonicalizing([$this->outside->id, $this->solo->id], $this->service()->departmentIds($this->actor));
        $this->assertSame([$this->outside->id], $this->service()->departmentIds($this->actor, true));
        $readonly = AssetComputerEquipment::create(['owner_department_id' => $this->solo->id]);
        $this->putJson($this->computerUrl.'/'.$readonly->id, ['owner_department_id' => $this->outside->id])->assertForbidden();
        $this->postJson($this->computerUrl, ['owner_department_id' => $this->solo->id])->assertUnprocessable();
    }

    public function test_root_target_expands_children_once_without_following_their_bindings(): void
    {
        $this->configure($this->solo, [$this->root->id]);
        $this->configure($this->a, [$this->outside->id]);
        $this->join($this->solo, 'manager');
        $this->assertEqualsCanonicalizing([$this->root->id, $this->a->id, $this->b->id], $this->service()->departmentIds($this->actor));
        $this->assertNotContains($this->outside->id, $this->service()->departmentIds($this->actor));
        $this->configure($this->solo, [$this->b->id]);
        $this->assertSame([$this->b->id], $this->service()->departmentIds($this->actor));
        $this->configure($this->solo, []);
        $this->assertSame([], $this->service()->departmentIds($this->actor));
    }

    public function test_revocation_rechecks_cached_import_and_source_portal_grant(): void
    {
        $this->configure($this->a, [$this->outside->id]);
        $this->join($this->a, 'manager');
        $preview = $this->postJson($this->computerUrl.'/import/preview', ['file' => $this->importFile()])->assertOk()->json();
        $this->configure($this->a, []);
        $this->postJson($this->computerUrl.'/import', ['token' => $preview['token']])->assertForbidden();
        $this->configure($this->a, [$this->b->id]);
        $this->postJson($this->computerUrl.'/import', ['token' => $preview['token']])->assertUnprocessable();
        $this->configure($this->a, [$this->outside->id]);
        DB::table('portal_departments')->where('department_id', $this->root->id)->delete();
        $this->assertSame([], $this->service()->departmentIds($this->actor));
        $this->postJson($this->computerUrl.'/import', ['token' => $preview['token']])->assertForbidden();
        $this->assertSame(0, AssetComputerEquipment::count());
    }

    public function test_exports_only_contain_bound_targets_and_administrator_bypasses_empty_bindings(): void
    {
        AssetComputerEquipment::create(['owner_department_id' => $this->a->id, 'serial_number' => 'A-secret']);
        AssetComputerEquipment::create(['owner_department_id' => $this->outside->id, 'serial_number' => 'Allowed']);
        AssetComputerEquipment::create(['serial_number' => 'Unassigned']);
        $this->get($this->computerUrl)->assertViewHas('rows', fn ($rows) => $rows->total() === 3);
        $this->configure($this->a, [$this->outside->id]);
        $this->join($this->a);
        $response = $this->get($this->computerUrl.'/export')->assertOk();
        $path = tempnam(sys_get_temp_dir(), 'computer-binding-');
        try {
            file_put_contents($path, $response->streamedContent());
            $book = IOFactory::load($path);
            $contents = json_encode($book->getActiveSheet()->toArray());
            $this->assertStringContainsString('Allowed', $contents);
            $this->assertStringNotContainsString('A-secret', $contents);
            $this->assertStringNotContainsString('Unassigned', $contents);
            $book->disconnectWorksheets();
        } finally {
            @unlink($path);
        }
        $this->getJson($this->configUrl)->assertForbidden();
        $this->putJson($this->configUrl, [])->assertForbidden();
    }

    public function test_invalid_bindings_and_disabled_menu_cannot_change_configuration(): void
    {
        $system = Department::create(['name' => 'System', 'slug' => 'administrator']);
        foreach ([$this->root, $this->outside, $system] as $invalid) {
            $this->putJson($this->configUrl, ['source_department_id' => $invalid->id, 'department_ids' => [], 'version' => 0])->assertUnprocessable();
        }
        foreach ([[$system->id], [99999], [$this->b->id, $this->b->id]] as $invalid) {
            $this->putJson($this->configUrl, ['source_department_id' => $this->a->id, 'department_ids' => $invalid, 'version' => 0])->assertUnprocessable();
        }
        $this->assertSame(0, DB::table('asset_computer_department_binding_logs')->count());
        DB::table('portal_menus')->where('code', 'asset_computer_department_bindings')->update(['is_enabled' => false]);
        $this->getJson($this->configUrl)->assertForbidden();
        $this->putJson($this->configUrl, [])->assertForbidden();
    }

    public function test_missing_portal_fails_migration_before_creating_configuration_tables(): void
    {
        Schema::drop('asset_computer_department_binding_logs');
        Schema::drop('asset_computer_department_bindings');
        DB::table('portals')->delete();
        try {
            (require base_path('database/migrations/2026_11_18_000400_install_asset_computer_department_bindings.php'))->up();
            $this->fail('Expected the missing portal to prevent installation.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('asset-manager', $exception->getMessage());
        }
        $this->assertFalse(Schema::hasTable('asset_computer_department_bindings'));
    }

    public function test_migration_is_repeatable_preserves_custom_menu_and_creates_no_automatic_bindings(): void
    {
        $this->assertSame(0, DB::table('asset_computer_department_bindings')->count());
        DB::table('portal_menus')->where('code', 'asset_computer_department_bindings')->update(['sort' => 999]);
        $this->configure($this->a, [$this->outside->id]);
        (require base_path('database/migrations/2026_11_18_000400_install_asset_computer_department_bindings.php'))->up();
        $this->assertSame(999, DB::table('portal_menus')->where('code', 'asset_computer_department_bindings')->value('sort'));
        $this->assertSame(1, DB::table('portal_menus')->where('code', 'asset_computer_department_bindings')->count());
        $this->assertSame([$this->outside->id], $this->service()->selectedIds($this->a->id));
    }

    public function test_binding_index_names_fit_mysql_identifier_limit(): void
    {
        foreach (['asset_computer_department_bindings', 'asset_computer_department_binding_logs'] as $table) {
            foreach (Schema::getIndexes($table) as $index) {
                $this->assertLessThanOrEqual(64, strlen($index['name']), $index['name']);
            }
        }
        $this->assertTrue(Schema::hasIndex('asset_computer_department_bindings', 'acdb_target_idx'));
        $this->assertTrue(Schema::hasIndex('asset_computer_department_binding_logs', 'acdbl_source_idx'));
    }

    public function test_retry_repairs_missing_indexes_without_losing_bindings_logs_or_custom_menu(): void
    {
        $this->configure($this->a, [$this->outside->id]);
        $beforeLogs = DB::table('asset_computer_department_binding_logs')->get()->toJson();
        DB::table('portal_menus')->where('code', 'asset_computer_department_bindings')->update(['sort' => 999]);
        Schema::table('asset_computer_department_bindings', function (Blueprint $t) {
            $t->dropIndex('acdb_target_idx');
            $t->dropUnique('asset_computer_department_binding_unique');
        });
        Schema::table('asset_computer_department_binding_logs', fn (Blueprint $t) => $t->dropIndex('acdbl_source_idx'));
        $migration = require base_path('database/migrations/2026_11_18_000400_install_asset_computer_department_bindings.php');
        $migration->up();
        $this->assertTrue(Schema::hasIndex('asset_computer_department_binding_logs', ['source_department_id']));
        $this->assertTrue(Schema::hasIndex('asset_computer_department_bindings', ['target_department_id']));
        $this->assertTrue(Schema::hasIndex('asset_computer_department_bindings', ['source_department_id', 'target_department_id'], 'unique'));
        $counts = collect(['asset_computer_department_bindings', 'asset_computer_department_binding_logs'])->mapWithKeys(fn ($table) => [$table => count(Schema::getIndexes($table))])->all();
        $migration->up();
        foreach ($counts as $table => $count) {
            $this->assertCount($count, Schema::getIndexes($table));
        }
        $this->assertSame([$this->outside->id], $this->service()->selectedIds($this->a->id));
        $this->assertSame($beforeLogs, DB::table('asset_computer_department_binding_logs')->get()->toJson());
        $this->assertSame(999, DB::table('portal_menus')->where('code', 'asset_computer_department_bindings')->value('sort'));
    }

    public function test_existing_equivalent_indexes_do_not_get_duplicate_short_indexes(): void
    {
        Schema::table('asset_computer_department_binding_logs', fn (Blueprint $t) => $t->dropIndex('acdbl_source_idx'));
        Schema::table('asset_computer_department_binding_logs', fn (Blueprint $t) => $t->index('source_department_id', 'previous_source_index'));
        (require base_path('database/migrations/2026_11_18_000400_install_asset_computer_department_bindings.php'))->up();
        $this->assertTrue(Schema::hasIndex('asset_computer_department_binding_logs', 'previous_source_index'));
        $this->assertFalse(Schema::hasIndex('asset_computer_department_binding_logs', 'acdbl_source_idx'));
    }
}
