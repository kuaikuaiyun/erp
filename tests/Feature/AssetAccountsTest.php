<?php

namespace Tests\Feature;

require_once __DIR__.'/DingTalkLoginTest.php';

use App\Models\AdminUser;
use App\Models\Department;
use App\Models\DingTalkBinding;
use App\Models\Portal;
use App\Portal\Http\Controllers\PortalEntryController;
use App\Portals\AssetManager\AccountAuthorizationService;
use App\Services\AdminWatermarkService;
use App\Services\DepartmentAuthorizationService;
use App\Services\SystemSettingService;
use Encore\Admin\Facades\Admin;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

trait AssetAccountsEnvironment
{
    use DingTalkTestEnvironment { setUp as private dingSetUp; }

    protected AdminUser $hrActor;

    protected AdminUser $hrOwner;

    protected Department $hrSource;

    protected Department $teamA;

    protected Department $teamB;

    protected Department $outside;

    protected Portal $hrPortal;

    protected ?DingTalkBinding $operatorBinding = null;

    protected string $hrUrl = '/asset-manager/accounts';

    protected function setUp(): void
    {
        $this->dingSetUp();
        $this->withoutMiddleware();
        Schema::table('admin_users', function (Blueprint $t) {
            $t->date('hired_at')->nullable();
            $t->date('resigned_at')->nullable();
            $t->string('employment_status')->default('employed');
        });
        Schema::table('admin_roles', fn (Blueprint $t) => $t->integer('sort')->default(0));
        Schema::table('admin_user_permission_overrides', function (Blueprint $t) {
            $t->unsignedBigInteger('updated_by')->nullable();
            $t->timestamps();
        });
        Schema::create('admin_department_change_logs', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('actor_id');
            $t->unsignedBigInteger('department_id')->nullable();
            $t->string('event');
            $t->text('payload');
            $t->timestamps();
        });
        Schema::create('portals', function (Blueprint $t) {
            $t->id();
            $t->string('code');
            $t->string('name');
            $t->string('status');
            $t->timestamps();
        });
        Schema::create('portal_departments', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('portal_id');
            $t->unsignedBigInteger('department_id');
        });
        Schema::create('portal_menus', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('portal_id');
            $t->integer('parent_id');
            foreach (['code', 'title', 'title_key', 'path', 'permission_slug', 'icon'] as $column) {
                $t->string($column)->nullable();
            }$t->integer('sort');
            $t->boolean('is_enabled');
            $t->timestamps();
        });
        $this->hrPortal = Portal::create(['code' => 'asset-manager', 'name' => 'HR', 'status' => 'enabled']);
        (require database_path('migrations/2026_11_18_000400_install_asset_computer_department_bindings.php'))->up();
        (require database_path('migrations/2026_11_18_000600_install_asset_accounts.php'))->up();
        $this->hrOwner = $this->account(true);
        $this->hrActor = $this->manager();
        $this->hrSource = $this->hrActor->departments()->first();
        $this->teamA = Department::create(['name' => 'Team A', 'slug' => 'team-a']);
        $this->teamB = Department::create(['name' => 'Team B', 'slug' => 'team-b']);
        $this->outside = Department::create(['name' => 'Outside', 'slug' => 'outside']);
        DB::table('portal_departments')->insert(['portal_id' => $this->hrPortal->id, 'department_id' => $this->hrSource->id]);
        foreach ([$this->teamA, $this->teamB] as $department) {
            DB::table('asset_computer_department_bindings')->insert(['source_department_id' => $this->hrSource->id, 'target_department_id' => $department->id, 'updated_by' => 1]);
        }
        $permission = DB::table('admin_permissions')->insertGetId(['slug' => 'asset-manager.computers', 'name' => 'HR', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('admin_role_permissions')->insert(['role_id' => $this->hrSource->id, 'permission_id' => $permission]);
        $entry = \Mockery::mock(PortalEntryController::class);
        $entry->shouldReceive('portalContext')->andReturnUsing(function (Request $request) {
            Admin::guard()->setUser($this->hrActor);
            if ($this->operatorBinding) {
                $request->setLaravelSession(app('session')->driver());
                $request->session()->start();
                $this->proof($request, $this->operatorBinding);
                $request->attributes->set('dingtalk.identity', $this->operatorBinding->identity()->first());
                $request->attributes->set('dingtalk.account_id', $this->hrActor->id);
            }

            return ['portal' => $this->hrPortal->unsetRelation('menus')->load('menus'), 'user' => $this->hrActor];
        });
        $this->app->instance(PortalEntryController::class, $entry);
        $this->mock(AdminWatermarkService::class)->shouldReceive('scriptForAdministrator')->andReturn(null);
        $this->mock(SystemSettingService::class)->shouldReceive('footer')->andReturn(['copyright_text' => '', 'contact_information' => '']);
        app(SystemSettingService::class)->shouldReceive('loginExpirationSeconds')->andReturn(3600);
    }

    protected function hrMember(Department $department, string $position = 'member'): AdminUser
    {
        $member = $this->account();
        $member->roles()->attach($department->id, ['position' => $position, 'is_primary' => false]);

        return $member;
    }

    protected function hrValues(array $extra = []): array
    {
        return array_replace(['username' => 'new-person', 'name' => 'New Person', 'password' => 'valid-pass-123', 'password_confirmation' => 'valid-pass-123', 'status' => 1, 'employment_status' => 'employed', 'hired_at' => '2026-11-01', 'resigned_at' => null, 'memberships' => [['department_id' => $this->teamA->id, 'position' => 'member']]], $extra);
    }
}

class AssetAccountsTest extends TestCase
{
    use AssetAccountsEnvironment;

    public function test_list_and_department_filter_deduplicate_accounts_and_hide_out_of_scope_accounts(): void
    {
        $shared = $this->hrMember($this->teamA);
        $shared->roles()->attach($this->teamB->id, ['position' => 'member']);
        $hidden = $this->hrMember($this->outside);
        $this->get($this->hrUrl)->assertOk()->assertSee('asset-accounts.js')->assertViewHas('rows', fn ($rows) => $rows->total() === 1)->assertDontSee($hidden->username)->assertDontSee('valid-pass-123');
        $this->get($this->hrUrl.'?department_ids[]='.$this->teamB->id)->assertViewHas('rows', fn ($rows) => $rows->total() === 1);
        $this->get($this->hrUrl.'?department_ids[]='.$this->outside->id)->assertViewHas('rows', fn ($rows) => $rows->total() === 0);
        $this->getJson($this->hrUrl.'/'.$hidden->id)->assertNotFound();
    }

    public function test_create_hashes_password_and_assigns_authorized_memberships(): void
    {
        $this->postJson($this->hrUrl, $this->hrValues())->assertOk();
        $account = AdminUser::where('username', 'new-person')->firstOrFail();
        $this->assertTrue(Hash::check('valid-pass-123', $account->password));
        $this->assertSame($this->teamA->id, (int) $account->departments()->value('id'));
        $this->assertSame('member', $account->departments()->first()->pivot->position);
        $this->assertSame('2026-11-01', $account->hired_at->format('Y-m-d'));
        $payload = DB::table('admin_department_change_logs')->where('event', 'hr_account_created')->value('payload');
        $this->assertStringNotContainsString('valid-pass-123', $payload);
        $this->assertStringNotContainsString($account->password, $payload);
    }

    public function test_edit_changes_global_account_information_but_preserves_unmanaged_memberships(): void
    {
        $account = $this->hrMember($this->teamA);
        $account->roles()->attach($this->outside->id, ['position' => 'deputy', 'position_slot' => '1']);
        $this->putJson($this->hrUrl.'/'.$account->id, $this->hrValues(['username' => $account->username, 'status' => 0, 'employment_status' => 'resigned', 'resigned_at' => '2026-11-15', 'memberships' => [['department_id' => $this->teamB->id, 'position' => 'deputy']]]))->assertOk();
        $this->assertEqualsCanonicalizing([$this->teamB->id, $this->outside->id], $account->departments()->pluck('id')->all());
        $this->assertSame('deputy', $account->departments()->whereKey($this->outside->id)->first()->pivot->position);
        $this->assertSame(0, $account->fresh()->status);
        $this->assertTrue(Hash::check('valid-pass-123', $account->fresh()->password));
        $this->putJson($this->hrUrl.'/'.$account->id, $this->hrValues(['username' => $account->username, 'memberships' => [['department_id' => $this->outside->id, 'position' => 'manager']]]))->assertUnprocessable();
    }

    public function test_membership_slots_and_invalid_department_rollback_account_creation(): void
    {
        $this->hrMember($this->teamA, 'manager');
        $before = AdminUser::count();
        $this->postJson($this->hrUrl, $this->hrValues(['memberships' => [['department_id' => $this->teamA->id, 'position' => 'manager']]]))->assertUnprocessable();
        $this->postJson($this->hrUrl, $this->hrValues(['memberships' => [['department_id' => $this->outside->id, 'position' => 'member']]]))->assertUnprocessable();
        $this->postJson($this->hrUrl, $this->hrValues(['memberships' => []]))->assertUnprocessable();
        $this->assertSame($before, AdminUser::count());
        $this->postJson($this->hrUrl, $this->hrValues(['memberships' => [['department_id' => $this->teamA->id, 'position' => 'deputy']]]))->assertOk();
    }

    public function test_readonly_and_empty_scope_cannot_write_or_manage_dingtalk(): void
    {
        $account = $this->hrMember($this->teamA);
        DB::table('admin_role_users')->where('user_id', $this->hrActor->id)->update(['position' => 'member']);
        $this->hrActor->unsetRelation('roles')->unsetRelation('departments');
        $this->get($this->hrUrl)->assertOk()->assertViewHas('canManage', false)->assertDontSee('id="hr-add"', false);
        $this->getJson($this->hrUrl.'/'.$account->id)->assertOk()->assertJsonMissingPath('account.password');
        $this->postJson($this->hrUrl, $this->hrValues())->assertForbidden();
        $this->putJson($this->hrUrl.'/'.$account->id, $this->hrValues())->assertForbidden();
        $this->deleteJson($this->hrUrl.'/'.$account->id)->assertForbidden();
        $this->getJson($this->hrUrl.'/'.$account->id.'/dingtalk')->assertForbidden();
        $this->getJson($this->hrUrl.'/'.$account->id.'/permissions')->assertForbidden();
        DB::table('asset_computer_department_bindings')->delete();
        $this->get($this->hrUrl)->assertViewHas('rows', fn ($rows) => $rows->total() === 0);
    }

    public function test_global_organization_authorization_remains_unchanged_and_superadmin_is_protected(): void
    {
        $account = $this->hrMember($this->teamA);
        $this->assertFalse(app(DepartmentAuthorizationService::class)->canManageAccount($this->hrActor, $account));
        $this->assertTrue(app(AccountAuthorizationService::class)->canManageAccount($this->hrActor, $account));
        $this->getJson($this->hrUrl.'/'.$this->hrOwner->id)->assertNotFound();
        $this->hrActor = $this->hrOwner;
        $this->get($this->hrUrl)->assertOk()->assertViewHas('rows', fn ($rows) => $rows->total() === 3);
        $this->deleteJson($this->hrUrl.'/'.$this->hrOwner->id)->assertUnprocessable();
    }

    public function test_delete_removes_entire_account_and_memberships_and_logs_original_scope(): void
    {
        $account = $this->hrMember($this->teamA);
        $account->roles()->attach($this->outside->id, ['position' => 'member']);
        $this->deleteJson($this->hrUrl.'/'.$account->id)->assertOk();
        $this->assertNull(AdminUser::find($account->id));
        $this->assertSame(0, DB::table('admin_role_users')->where('user_id', $account->id)->count());
        $this->assertSame(1, DB::table('admin_department_change_logs')->where('event', 'account_deleted')->count());
    }

    public function test_menu_installation_is_repeatable_and_disabled_menu_blocks_api(): void
    {
        DB::table('portal_menus')->where('code', 'asset_manager_accounts')->update(['title' => 'Custom', 'sort' => 99]);
        (require database_path('migrations/2026_11_18_000600_install_asset_accounts.php'))->up();
        $this->assertSame(1, DB::table('portal_menus')->where('code', 'asset_manager_accounts')->count());
        $this->assertSame('Custom', DB::table('portal_menus')->where('code', 'asset_manager_accounts')->value('title'));
        DB::table('portal_menus')->where('code', 'asset_manager_accounts')->update(['is_enabled' => false]);
        $this->getJson($this->hrUrl)->assertForbidden();
        $this->postJson($this->hrUrl, $this->hrValues())->assertForbidden();
    }

    public function test_permissions_remain_superadmin_only_and_reuse_existing_page_permission_rules(): void
    {
        $account = $this->hrMember($this->teamA);
        $url = $this->hrUrl.'/'.$account->id.'/permissions';
        $this->getJson($url)->assertForbidden();
        $this->putJson($url, ['permissions' => []])->assertForbidden();
        $this->hrActor = $this->hrOwner;
        $this->getJson($url)->assertOk()->assertJsonStructure(['tree', 'overrides']);
        $permission = DB::table('admin_permissions')->where('slug', 'asset-manager.computers')->value('id');
        $this->putJson($url, ['permissions' => [$permission => 'deny']])->assertOk();
        $this->assertSame('deny', DB::table('admin_user_permission_overrides')->where('user_id', $account->id)->value('effect'));
        $this->putJson($url, ['permissions' => []])->assertOk();
        $this->assertSame(0, DB::table('admin_user_permission_overrides')->where('user_id', $account->id)->count());
    }

    public function test_account_validation_and_deputy_slots_are_enforced_without_partial_writes(): void
    {
        $this->postJson($this->hrUrl, $this->hrValues(['password' => 'short', 'password_confirmation' => 'short']))->assertUnprocessable();
        $this->postJson($this->hrUrl, $this->hrValues(['username' => $this->hrActor->username]))->assertUnprocessable();
        for ($i = 0; $i < 2; $i++) {
            $this->postJson($this->hrUrl, $this->hrValues(['username' => 'deputy-'.$i, 'memberships' => [['department_id' => $this->teamA->id, 'position' => 'deputy']]]))->assertOk();
        }
        $before = AdminUser::count();
        $this->postJson($this->hrUrl, $this->hrValues(['username' => 'deputy-3', 'memberships' => [['department_id' => $this->teamA->id, 'position' => 'deputy']]]))->assertUnprocessable();
        $this->assertSame($before, AdminUser::count());
        $this->get($this->hrUrl.'?search=deputy-&per_page=20')->assertOk()->assertViewHas('rows', fn ($rows) => $rows->total() === 2);
    }

    public function test_superadmin_fields_are_locked_and_account_menu_requires_computer_page_access(): void
    {
        $owner = $this->hrOwner;
        $this->hrActor = $owner;
        $this->putJson($this->hrUrl.'/'.$owner->id, $this->hrValues(['username' => 'changed-owner', 'status' => 0, 'memberships' => [['department_id' => $this->teamA->id, 'position' => 'member']]]))->assertOk();
        $this->assertSame($owner->username, $owner->fresh()->username);
        $this->assertSame(1, $owner->fresh()->status);
        $this->assertTrue($owner->fresh()->isAdministrator());
        $this->hrActor = $this->manager();
        $this->getJson($this->hrUrl)->assertForbidden();
    }

    public function test_original_branch_position_and_assistant_single_child_rules_are_preserved(): void
    {
        Department::create(['name' => 'Child', 'slug' => 'child', 'parent_id' => $this->teamA->id]);
        $this->postJson($this->hrUrl, $this->hrValues(['memberships' => [['department_id' => $this->teamA->id, 'position' => 'member']]]))->assertUnprocessable();
        $root = Department::create(['name' => 'Assistant', 'slug' => 'dept-000004']);
        $a = Department::create(['name' => 'Assistant A', 'slug' => 'assistant-a', 'parent_id' => $root->id]);
        $b = Department::create(['name' => 'Assistant B', 'slug' => 'assistant-b', 'parent_id' => $root->id]);
        foreach ([$a, $b] as $department) {
            DB::table('asset_computer_department_bindings')->insert(['source_department_id' => $this->hrSource->id, 'target_department_id' => $department->id, 'updated_by' => 1]);
        }
        $before = AdminUser::count();
        $this->postJson($this->hrUrl, $this->hrValues(['memberships' => [['department_id' => $a->id, 'position' => 'member'], ['department_id' => $b->id, 'position' => 'member']]]))->assertUnprocessable();
        $this->assertSame($before, AdminUser::count());
    }

    public function test_menu_migration_fails_when_portal_is_missing_and_does_not_touch_accounts(): void
    {
        $count = AdminUser::count();
        DB::table('portals')->delete();
        try {
            (require database_path('migrations/2026_11_18_000600_install_asset_accounts.php'))->up();
            $this->fail('Expected missing portal to prevent menu installation.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('asset-manager', $exception->getMessage());
        }
        $this->assertSame($count, AdminUser::count());
    }
}
