<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\Department;
use App\Models\DisciplineRecord;
use App\Models\DisciplineRecordLog;
use App\Models\Portal;
use App\Portal\Http\Controllers\PortalEntryController;
use App\Portals\AssetManager\AssetComputerDepartmentScope;
use App\Portals\AssetManager\DisciplineService;
use App\Services\AdminWatermarkService;
use App\Services\SystemSettingService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

class DisciplineManagementTest extends TestCase
{
    private AdminUser $actor;

    private Portal $portal;

    private Department $department;

    private Department $outside;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('x', 32)), 'app.locale' => 'zh-CN', 'database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:', 'admin.database.connection' => null, 'cache.default' => 'array']);
        DB::purge('sqlite');
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
            foreach (['code', 'title', 'title_key', 'path', 'permission_slug', 'icon'] as $key) {
                $t->string($key)->nullable();
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
            $t->string('slug');
            $t->string('name');
            $t->unsignedBigInteger('parent_id')->nullable();
            $t->integer('sort')->default(0);
            $t->timestamps();
        });
        Schema::create('admin_users', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('username');
            $t->string('employment_status')->default('employed');
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
        $this->portal = Portal::create(['code' => 'asset-manager', 'name' => 'Assets', 'status' => 'enabled']);
        (require base_path('database/migrations/2026_11_18_000300_install_discipline_management.php'))->up();
        Schema::create('portal_departments', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('portal_id');
            $t->unsignedBigInteger('department_id');
        });
        (require base_path('database/migrations/2026_11_18_000400_install_asset_computer_department_bindings.php'))->up();
        $this->actor = new class extends AdminUser
        {
            public bool $administrator = true;

            public bool $manage = true;

            public bool $view = true;

            public function isAdministrator(): bool
            {
                return $this->administrator;
            }

            public function can($ability, $arguments = []): bool
            {
                return str_ends_with($ability, '.manage') ? $this->manage : $this->view;
            }
        };
        $this->actor->id = 1;
        $this->actor->name = 'Self';
        DB::table('admin_users')->insert(['id' => 1, 'name' => 'Self', 'username' => 'self']);
        $this->department = Department::create(['name' => 'Team', 'slug' => 'team']);
        $this->outside = Department::create(['name' => 'Outside', 'slug' => 'outside']);
        $this->bind($this->department, [$this->department->id]);
        $this->bind($this->outside, [$this->outside->id]);
        $this->member(2, 'Member', $this->department);
        $this->member(3, 'Other', $this->outside);
        DB::table('admin_role_users')->insert(['role_id' => $this->department->id, 'user_id' => 1, 'position' => 'manager']);
        $entries = \Mockery::mock(PortalEntryController::class);
        $entries->shouldReceive('portalContext')->andReturnUsing(fn (Request $request) => ['portal' => $this->portal->unsetRelation('menus')->load('menus'), 'user' => $this->actor]);
        $this->app->instance(PortalEntryController::class, $entries);
        $this->mock(AdminWatermarkService::class)->shouldReceive('scriptForAdministrator')->andReturn(null);
        $this->mock(SystemSettingService::class)->shouldReceive('footer')->andReturn(['copyright_text' => '', 'contact_information' => '']);
    }

    private function member(int $id, string $name, Department $department, string $employment = 'employed'): void
    {
        DB::table('admin_users')->insert(['id' => $id, 'name' => $name, 'username' => 'account'.$id, 'employment_status' => $employment]);
        DB::table('admin_role_users')->insert(['role_id' => $department->id, 'user_id' => $id, 'position' => 'member']);
    }

    private function bind(Department $source, array $targets): void
    {
        DB::table('portal_departments')->updateOrInsert(['portal_id' => $this->portal->id, 'department_id' => $source->parent_id ?: $source->id], []);
        DB::table('asset_computer_department_bindings')->where('source_department_id', $source->id)->delete();
        foreach ($targets as $target) {
            DB::table('asset_computer_department_bindings')->insert(['source_department_id' => $source->id, 'target_department_id' => $target, 'updated_by' => 1]);
        }
    }

    private function ordinaryMember(): void
    {
        $this->actor->administrator = false;
        DB::table('admin_role_users')->where('user_id', 1)->update(['position' => 'member']);
        $this->actor->unsetRelation('roles')->unsetRelation('departments');
    }

    private function data(array $extra = []): array
    {
        return array_replace(['member_id' => 2, 'department_id' => $this->department->id, 'record_date' => '2026-11-18', 'remarks' => 'Remark'], $extra);
    }

    private function add(array $extra = []): array
    {
        return $this->postJson('/asset-manager/discipline/records', $this->data($extra))->assertOk()->json();
    }

    public function test_calendar_renders_and_new_menu_is_idempotent_without_overwriting_customization(): void
    {
        $this->get('/asset-manager/discipline?month=2026-11')->assertOk()->assertSee('discipline.js')->assertSee('Self');
        DB::table('portal_menus')->update(['sort' => 99]);
        (require base_path('database/migrations/2026_11_18_000300_install_discipline_management.php'))->up();
        $this->assertSame(2, DB::table('portal_menus')->count());
        $this->assertSame(99, DB::table('portal_menus')->value('sort'));
        $this->assertSame(3, DB::table('admin_permissions')->count());
    }

    public function test_missing_portal_fails_install_before_creating_tables(): void
    {
        Schema::drop('discipline_record_logs');
        Schema::drop('discipline_records');
        DB::table('portals')->delete();
        try {
            (require base_path('database/migrations/2026_11_18_000300_install_discipline_management.php'))->up();
            $this->fail('Expected missing portal failure');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('asset-manager', $e->getMessage());
        }
        $this->assertFalse(Schema::hasTable('discipline_records'));
    }

    public function test_multiple_records_edit_conflict_soft_delete_and_audit_are_atomic(): void
    {
        $one = $this->add();
        $two = $this->add(['remarks' => 'Second']);
        $this->getJson('/asset-manager/discipline?month=2026-11')->assertJsonPath('summaries.0.count', 2);
        $this->putJson('/asset-manager/discipline/records/'.$one['id'], ['record_date' => '2026-11-19', 'remarks' => 'Changed', 'version' => $one['version'], 'member_id' => 3, 'department_id' => $this->outside->id])->assertOk();
        $fresh = $this->getJson('/asset-manager/discipline/records/'.$one['id'])->assertOk()->assertJsonPath('member_id', 2)->assertJsonPath('department_id', $this->department->id)->json();
        $this->deleteJson('/asset-manager/discipline/records/'.$one['id'], ['version' => $one['version']])->assertStatus(409);
        $this->deleteJson('/asset-manager/discipline/records/'.$one['id'], ['version' => $fresh['version']])->assertOk();
        $this->getJson('/asset-manager/discipline/records/'.$one['id'])->assertNotFound();
        $this->assertSame(1, DisciplineRecord::count());
        $this->assertSame(2, DisciplineRecord::withTrashed()->count());
        $logs = DisciplineRecordLog::where('record_id', $one['id'])->orderBy('id')->get();
        $this->assertSame(['create', 'update', 'delete'], $logs->pluck('action')->all());
        $this->assertSame('Remark', $logs[1]->before_data['remarks']);
        $this->assertSame('Changed', $logs[1]->after_data['remarks']);
        $this->assertNotNull($logs[2]->after_data['deleted_at']);
    }

    public function test_regular_member_reads_bound_department_members_and_cannot_write(): void
    {
        $other = $this->add();
        $own = $this->add(['member_id' => 1]);
        $hidden = $this->add(['member_id' => 3, 'department_id' => $this->outside->id]);
        $this->ordinaryMember();
        $this->actor->manage = false;
        $this->getJson('/asset-manager/discipline?month=2026-11')->assertOk()->assertJsonCount(2, 'members')->assertJsonPath('members.0.id', 1);
        $this->getJson('/asset-manager/discipline/records/'.$other['id'])->assertOk()->assertJsonPath('writable', false);
        $this->getJson('/asset-manager/discipline/records/'.$hidden['id'])->assertNotFound();
        $this->getJson('/asset-manager/discipline/records/'.$own['id'])->assertOk()->assertJsonMissingPath('logs');
        $this->postJson('/asset-manager/discipline/records', $this->data())->assertForbidden();
        $this->putJson('/asset-manager/discipline/records/'.$own['id'], ['record_date' => '2026-11-18', 'remarks' => 'changed', 'version' => $own['version']])->assertForbidden();
        $this->getJson('/asset-manager/discipline/members')->assertForbidden();
        $this->getJson('/asset-manager/discipline/records?month=2026-11&date=2026-11-18&member_id=2')->assertJsonCount(1, 'records');
    }

    public function test_manager_cannot_access_other_departments_and_root_includes_descendants(): void
    {
        $outside = $this->add(['member_id' => 3, 'department_id' => $this->outside->id]);
        $child = Department::create(['name' => 'Child', 'slug' => 'child', 'parent_id' => $this->department->id]);
        $this->member(4, 'ChildMember', $child);
        $this->bind($child, [$this->department->id]);
        $this->actor->administrator = false;
        $this->getJson('/asset-manager/discipline?month=2026-11')->assertJsonCount(3, 'members');
        $this->add(['member_id' => 4, 'department_id' => $child->id]);
        $this->getJson('/asset-manager/discipline/records/'.$outside['id'])->assertNotFound();
        $this->postJson('/asset-manager/discipline/records', $this->data(['member_id' => 3, 'department_id' => $this->outside->id]))->assertForbidden();
        DB::table('admin_role_users')->where('user_id', 1)->update(['position' => 'deputy']);
        $this->actor->unsetRelation('departments');
        $this->getJson('/asset-manager/discipline?month=2026-11')->assertJsonCount(0, 'members');
    }

    public function test_department_transfer_does_not_bypass_bound_scope_for_self_history(): void
    {
        $row = $this->add(['member_id' => 1]);
        DB::table('admin_role_users')->where('user_id', 1)->update(['role_id' => $this->outside->id]);
        $this->actor->administrator = false;
        $this->getJson('/asset-manager/discipline/records/'.$row['id'])->assertNotFound();
        $this->putJson('/asset-manager/discipline/records/'.$row['id'], ['record_date' => '2026-11-18', 'remarks' => 'x', 'version' => $row['version']])->assertNotFound();
        $this->assertSame($this->department->id, DisciplineRecord::first()->department_id);
    }

    public function test_resigned_and_deleted_members_only_appear_when_historical_records_exist(): void
    {
        $this->add();
        $this->add(['member_id' => 3, 'department_id' => $this->outside->id]);
        DB::table('admin_users')->where('id', 2)->update(['employment_status' => 'resigned']);
        DB::table('admin_users')->where('id', 3)->delete();
        $this->getJson('/asset-manager/discipline?month=2026-11')->assertJsonCount(3, 'members');
        $this->getJson('/asset-manager/discipline?month=2026-12')->assertJsonCount(1, 'members');
        $this->getJson('/asset-manager/discipline/members?month=2026-11')->assertJsonCount(1, 'members');
        $this->getJson('/asset-manager/discipline?month=2026-11&search=Other')->assertJsonPath('members.0.id', 3);
    }

    public function test_validation_calendar_month_boundaries_and_member_department_mismatch(): void
    {
        $this->getJson('/asset-manager/discipline?month=2026-13')->assertUnprocessable();
        $this->getJson('/asset-manager/discipline?month=1899-12')->assertUnprocessable();
        $this->assertCount(29, app(DisciplineService::class)->days('2028-02'));
        $this->postJson('/asset-manager/discipline/records', $this->data(['record_date' => '2026-02-30']))->assertUnprocessable();
        $this->postJson('/asset-manager/discipline/records', $this->data(['remarks' => '  ']))->assertUnprocessable();
        $this->postJson('/asset-manager/discipline/records', $this->data(['remarks' => str_repeat('字', 2001)]))->assertUnprocessable();
        $this->postJson('/asset-manager/discipline/records', $this->data(['department_id' => $this->outside->id]))->assertUnprocessable();
        $this->assertSame(0, DisciplineRecordLog::count());
        $this->add(['record_date' => '2026-10-31']);
        $this->add(['record_date' => '2026-11-01']);
        $this->add(['record_date' => '2026-12-01']);
        $this->getJson('/asset-manager/discipline?month=2026-11')->assertJsonCount(1, 'summaries')->assertJsonPath('summaries.0.date', '2026-11-01');
    }

    public function test_pagination_and_filters_do_not_leak_records_from_other_members(): void
    {
        for ($i = 4; $i <= 40; $i++) {
            $this->member($i, 'Person'.$i, $this->department);
        }
        $this->add(['member_id' => 40]);
        $this->getJson('/asset-manager/discipline?month=2026-11')->assertJsonCount(30, 'members')->assertJsonCount(0, 'summaries')->assertJsonPath('total', 40);
        $this->getJson('/asset-manager/discipline?month=2026-11&page=2')->assertJsonCount(10, 'members')->assertJsonCount(1, 'summaries');
        $this->getJson('/asset-manager/discipline?month=2026-11&search=Person40')->assertJsonCount(1, 'members');
        $this->getJson('/asset-manager/discipline?month=2026-11&department_id='.$this->outside->id)->assertJsonCount(1, 'members')->assertJsonPath('members.0.id', 3);
    }

    public function test_disabled_menu_and_missing_permission_block_every_entry(): void
    {
        $row = $this->add();
        $this->actor->administrator = false;
        $this->actor->view = false;
        foreach (['/', '/members', '/export', '/records/'.$row['id']] as $path) {
            $this->getJson('/asset-manager/discipline'.$path)->assertForbidden();
        }
        $this->actor->view = true;
        DB::table('portal_menus')->update(['is_enabled' => false]);
        $this->getJson('/asset-manager/discipline')->assertForbidden();
        $this->postJson('/asset-manager/discipline/records', $this->data())->assertForbidden();
    }

    public function test_shared_member_records_are_scoped_by_historical_department_not_current_membership(): void
    {
        DB::table('admin_role_users')->insert(['user_id' => 2, 'role_id' => $this->outside->id, 'position' => 'member']);
        $this->add(['remarks' => 'Allowed']);
        $hidden = $this->add(['department_id' => $this->outside->id, 'remarks' => 'OtherDepartment']);
        $this->actor->administrator = false;
        $this->getJson('/asset-manager/discipline?month=2026-11')->assertJsonPath('summaries.0.count', 1)->assertDontSee('OtherDepartment');
        $this->getJson('/asset-manager/discipline/records?month=2026-11&date=2026-11-18&member_id=2')->assertJsonCount(1, 'records')->assertJsonPath('records.0.remarks', 'Allowed');
        $this->getJson('/asset-manager/discipline/records/'.$hidden['id'])->assertNotFound();
    }

    public function test_day_records_and_audit_are_paginated_and_inactive_members_cannot_be_added_again(): void
    {
        $first = $this->add();
        for ($i = 0; $i < 30; $i++) {
            $this->add(['remarks' => 'Daily '.$i]);
        }
        $this->getJson('/asset-manager/discipline/records?month=2026-11&date=2026-11-18&member_id=2')->assertJsonCount(30, 'records')->assertJsonPath('total', 31);
        $this->getJson('/asset-manager/discipline/records?month=2026-11&date=2026-11-18&member_id=2&page=2')->assertJsonCount(1, 'records');
        for ($i = 0; $i < 21; $i++) {
            $this->putJson('/asset-manager/discipline/records/'.$first['id'], ['record_date' => '2026-11-18', 'remarks' => 'Edit '.$i, 'version' => $first['version']])->assertOk();
            $first = $this->getJson('/asset-manager/discipline/records/'.$first['id'])->assertOk()->json();
        }
        $this->assertCount(20, $first['logs']);
        $this->getJson('/asset-manager/discipline/records/'.$first['id'].'?page=2')->assertJsonCount(2, 'logs');
        DB::table('admin_users')->where('id', 2)->update(['employment_status' => 'resigned']);
        $this->getJson('/asset-manager/discipline?month=2026-11&search=Member')->assertJsonCount(0, 'members.0.departments');
        $this->postJson('/asset-manager/discipline/records', $this->data())->assertNotFound();
    }

    public function test_long_day_export_keeps_full_notes_in_detail_sheet(): void
    {
        $note = str_repeat('😀', 2000);
        for ($i = 0; $i < 9; $i++) {
            $this->add(['remarks' => $note]);
        }
        $path = tempnam(sys_get_temp_dir(), 'discipline-long-');
        try {
            file_put_contents($path, $this->get('/asset-manager/discipline/export?month=2026-11')->assertOk()->streamedContent());
            $book = IOFactory::load($path);
            $cell = (string) $book->getSheet(0)->getCell('S3')->getValue();
            $this->assertStringContainsString(__('discipline.full_details'), $cell);
            $this->assertLessThan(32767, strlen(mb_convert_encoding($cell, 'UTF-16LE', 'UTF-8')) / 2);
            $this->assertSame(10, $book->getSheet(1)->getHighestRow());
            $this->assertSame($note, (string) $book->getSheet(1)->getCell('D10')->getValue());
            $book->disconnectWorksheets();
        } finally {
            @unlink($path);
        }
    }

    public function test_export_contains_all_pages_multiple_literal_notes_and_only_authorized_members(): void
    {
        for ($i = 4; $i <= 32; $i++) {
            $this->member($i, 'Person'.$i, $this->department);
        }
        $this->add(['member_id' => 32, 'remarks' => '=1+2']);
        $this->add(['member_id' => 32, 'remarks' => 'Second']);
        $this->add(['member_id' => 1, 'remarks' => 'SelfNote']);
        $response = $this->get('/asset-manager/discipline/export?month=2026-11&page=1&per_page=30')->assertOk();
        $path = tempnam(sys_get_temp_dir(), 'discipline-test-');
        try {
            file_put_contents($path, $response->streamedContent());
            $book = IOFactory::load($path);
            $this->assertSame(33, $book->getSheet(0)->getHighestRow());
            $this->assertSame("=1+2\nSecond", (string) $book->getSheet(0)->getCell('S33')->getValue());
            $this->assertNotSame('f', $book->getSheet(0)->getCell('S33')->getDataType());
            $this->assertSame(4, $book->getSheet(1)->getHighestRow());
            $book->disconnectWorksheets();
            $this->ordinaryMember();
            $this->actor->manage = false;
            file_put_contents($path, $this->get('/asset-manager/discipline/export?month=2026-11')->assertOk()->streamedContent());
            $book = IOFactory::load($path);
            $this->assertSame(32, $book->getSheet(0)->getHighestRow());
            $this->assertSame('SelfNote', (string) $book->getSheet(0)->getCell('S2')->getValue());
            $book->disconnectWorksheets();
        } finally {
            @unlink($path);
        }
    }

    public function test_discipline_uses_computer_department_mapping_and_manager_needs_no_manage_permission(): void
    {
        $own = $this->add(['member_id' => 1]);
        $target = $this->add(['member_id' => 3, 'department_id' => $this->outside->id, 'remarks' => 'Mapped']);
        $this->bind($this->department, [$this->outside->id]);
        $this->actor->administrator = false;
        $this->actor->manage = false;
        $service = app(DisciplineService::class);
        $computerScope = app(AssetComputerDepartmentScope::class);
        $this->assertSame(array_keys($computerScope->departments($this->actor)), $service->departments($this->actor)->pluck('id')->all());
        $this->assertSame($computerScope->writableDepartmentIds($this->actor), $service->managedIds($this->actor)->all());
        $this->getJson('/asset-manager/discipline?month=2026-11')->assertJsonCount(1, 'members')->assertJsonPath('members.0.id', 3)->assertJsonPath('summaries.0.summary', 'Mapped');
        $this->getJson('/asset-manager/discipline/records/'.$own['id'])->assertNotFound();
        $this->getJson('/asset-manager/discipline/members?month=2026-11')->assertJsonCount(1, 'members')->assertJsonPath('members.0.departments.0.id', $this->outside->id);
        $this->putJson('/asset-manager/discipline/records/'.$target['id'], ['record_date' => '2026-11-18', 'remarks' => 'Managed', 'version' => $target['version']])->assertOk();
        $created = $this->add(['member_id' => 3, 'department_id' => $this->outside->id]);
        $this->deleteJson('/asset-manager/discipline/records/'.$created['id'], ['version' => $created['version']])->assertOk();
        $this->ordinaryMember();
        $this->getJson('/asset-manager/discipline?month=2026-11')->assertJsonCount(1, 'members')->assertJsonCount(0, 'members.0.departments');
        $this->getJson('/asset-manager/discipline/records/'.$target['id'])->assertJsonPath('writable', false)->assertJsonMissingPath('logs');
        $this->get('/asset-manager/discipline?month=2026-11')->assertOk()->assertSee('discipline-filter-department')->assertDontSee('id="discipline-add"', false);
    }

    public function test_cleared_or_revoked_authorization_removes_self_history_and_blocks_stale_edits_and_export(): void
    {
        $own = $this->add(['member_id' => 1]);
        $this->actor->administrator = false;
        $this->bind($this->department, []);
        $this->getJson('/asset-manager/discipline?month=2026-11')->assertJsonCount(0, 'members')->assertJsonCount(0, 'summaries');
        $this->getJson('/asset-manager/discipline/records/'.$own['id'])->assertNotFound();
        $this->putJson('/asset-manager/discipline/records/'.$own['id'], ['record_date' => '2026-11-18', 'remarks' => 'stale', 'version' => $own['version']])->assertForbidden();
        $this->deleteJson('/asset-manager/discipline/records/'.$own['id'], ['version' => $own['version']])->assertForbidden();
        $this->postJson('/asset-manager/discipline/records', $this->data())->assertForbidden();
        $path = tempnam(sys_get_temp_dir(), 'discipline-empty-');
        try {
            file_put_contents($path, $this->get('/asset-manager/discipline/export?month=2026-11')->assertOk()->streamedContent());
            $book = IOFactory::load($path);
            $this->assertSame(1, $book->getSheet(0)->getHighestRow());
            $this->assertSame(1, $book->getSheet(1)->getHighestRow());
            $book->disconnectWorksheets();
        } finally {
            @unlink($path);
        }
        $this->bind($this->department, [$this->department->id]);
        DB::table('portal_departments')->where('department_id', $this->department->id)->delete();
        $this->getJson('/asset-manager/discipline?month=2026-11')->assertJsonCount(0, 'members');
        $this->assertSame(1, DisciplineRecordLog::count());
    }

    public function test_discipline_separates_read_and_write_sources_and_keeps_numeric_pagination_filters(): void
    {
        $this->actor->administrator = false;
        DB::table('admin_role_users')->insert(['user_id' => 1, 'role_id' => $this->outside->id, 'position' => 'member']);
        $this->getJson('/asset-manager/discipline?month=2026-11')->assertJsonCount(3, 'members');
        $this->getJson('/asset-manager/discipline/members?month=2026-11')->assertJsonCount(2, 'members');
        $this->postJson('/asset-manager/discipline/records', $this->data(['member_id' => 3, 'department_id' => $this->outside->id]))->assertForbidden();
        for ($i = 4; $i <= 40; $i++) {
            $this->member($i, 'Person'.$i, $this->department);
        }
        $this->get('/asset-manager/discipline?month=2026-11&search=Person')->assertOk()->assertSee('<section class="content-header">', false)->assertSee('discipline-pagination')->assertSee('search=Person', false)->assertSee('name="search" value="Person"', false)->assertSee('aria-current="page"', false);
        $this->bind($this->outside, []);
        $this->getJson('/asset-manager/discipline?month=2026-11&department_id='.$this->outside->id)->assertJsonCount(0,'members')->assertJsonCount(0,'summaries');
    }
}
