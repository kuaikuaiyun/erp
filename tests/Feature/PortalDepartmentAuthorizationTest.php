<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\Department;
use App\Models\Portal;
use App\Models\PortalDepartment;
use App\Portal\Services\PortalAccessService;
use App\Portal\Services\PortalService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class PortalDepartmentAuthorizationTest extends TestCase
{
    private AdminUser $actor;
    private Portal $portal;
    private Department $root;
    private Department $child;
    private Department $sibling;

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default'=>'sqlite', 'database.connections.sqlite.database'=>':memory:', 'admin.database.connection'=>null]);
        DB::purge('sqlite');
        app()->setLocale('zh-CN');
        Schema::create('admin_users', function (Blueprint $t): void { $t->id(); $t->string('username'); $t->string('name'); $t->string('password'); $t->integer('status')->default(1); $t->timestamps(); });
        Schema::create('admin_roles', function (Blueprint $t): void { $t->id(); $t->string('name'); $t->string('slug'); $t->unsignedBigInteger('parent_id')->nullable(); $t->integer('sort')->default(0); $t->timestamps(); });
        Schema::create('admin_role_users', function (Blueprint $t): void { $t->unsignedBigInteger('user_id'); $t->unsignedBigInteger('role_id'); $t->string('position')->nullable(); $t->string('position_slot')->nullable(); $t->boolean('is_primary')->default(false); $t->timestamps(); });
        Schema::create('portals', function (Blueprint $t): void { $t->id(); $t->string('name'); $t->string('code'); $t->text('remark')->nullable(); $t->string('status'); $t->timestamps(); });
        Schema::create('portal_menus', function (Blueprint $t): void { $t->id(); $t->unsignedBigInteger('portal_id'); $t->integer('sort')->default(0); });
        Schema::create('portal_departments', function (Blueprint $t): void { $t->id(); $t->unsignedBigInteger('portal_id'); $t->unsignedBigInteger('department_id'); $t->boolean('includes_descendants'); $t->unsignedBigInteger('granted_by'); $t->timestamp('granted_at'); $t->timestamps(); });
        Schema::create('portal_change_logs', function (Blueprint $t): void { $t->id(); $t->unsignedBigInteger('portal_id'); $t->unsignedBigInteger('department_id')->nullable(); $t->string('department_name_snapshot')->nullable(); $t->string('event'); $t->text('payload'); $t->unsignedBigInteger('actor_id'); $t->string('actor_name_snapshot'); $t->timestamps(); });
        $this->actor = $this->user();
        $this->root = Department::create(['name'=>'Root', 'slug'=>'root']);
        $this->child = Department::create(['name'=>'Child', 'slug'=>'child', 'parent_id'=>$this->root->id]);
        $this->sibling = Department::create(['name'=>'Sibling', 'slug'=>'sibling', 'parent_id'=>$this->root->id]);
        $this->portal = Portal::create(['name'=>'Portal', 'code'=>'test', 'status'=>'enabled']);
    }

    private function user(?Department $department = null): AdminUser
    {
        $user = new AdminUser();
        $user->forceFill(['username'=>'u'.AdminUser::count(), 'name'=>'User', 'password'=>'unused', 'status'=>1])->save();
        if ($department) $user->roles()->attach($department->id, ['position'=>'member']);
        return $user;
    }

    private function saveGrants(array $ids): void
    {
        app(PortalService::class)->update($this->portal, ['name'=>'Portal', 'remark'=>null, 'status'=>'enabled'], $ids, $this->actor);
    }

    public function test_explicit_child_grant_excludes_sibling_and_parent(): void
    {
        $this->saveGrants([$this->child->id]);
        $access = app(PortalAccessService::class);
        $this->assertTrue($access->canEnter($this->portal, $this->user($this->child)));
        $this->assertFalse($access->canEnter($this->portal, $this->user($this->sibling)));
        $this->assertFalse($access->canEnter($this->portal, $this->user($this->root)));
        $this->assertSame([$this->portal->id], $access->accessiblePortalsFor($this->user($this->child))->pluck('id')->all());
        $this->assertCount(0, $access->accessiblePortalsFor($this->user($this->sibling)));
    }

    public function test_legacy_inheritance_survives_until_that_portal_is_saved(): void
    {
        PortalDepartment::create(['portal_id'=>$this->portal->id, 'department_id'=>$this->root->id, 'includes_descendants'=>true, 'granted_by'=>$this->actor->id, 'granted_at'=>now()]);
        $user = $this->user($this->sibling);
        $access = app(PortalAccessService::class);
        $this->assertTrue($access->canEnter($this->portal, $user));
        $this->saveGrants([$this->root->id, $this->child->id]);
        $this->assertFalse($access->canEnter($this->portal, $user));
        $this->assertFalse($this->portal->departmentGrants()->where('includes_descendants', true)->exists());
        $future = Department::create(['name'=>'Future', 'slug'=>'future', 'parent_id'=>$this->root->id]);
        $this->assertFalse($access->canEnter($this->portal, $this->user($future)));
        $this->assertTrue($access->canEnter($this->portal, $this->user($this->root)));
        $this->assertSame(3, $this->portal->changeLogs()->whereIn('event', ['department_revoked','department_granted'])->count());
    }

    public function test_invalid_ids_roll_back_and_empty_selection_revokes_everything(): void
    {
        $this->saveGrants([$this->child->id]);
        try { $this->saveGrants([$this->child->id, 999999]); $this->fail('Expected invalid department'); }
        catch (ValidationException $e) { $this->assertSame([$this->child->id], $this->portal->departmentGrants()->pluck('department_id')->all()); }
        $this->saveGrants([]);
        $this->assertFalse(app(PortalAccessService::class)->canEnter($this->portal, $this->user($this->child)));
    }

    public function test_root_helper_keeps_assistant_membership_semantics(): void
    {
        $this->assertSame([$this->root->id], app(PortalAccessService::class)->authorizedRootDepartmentIds($this->user($this->child))->all());
    }

    public function test_invalid_save_preserves_legacy_scope_and_does_not_touch_other_portals(): void
    {
        $other = Portal::create(['name'=>'Other', 'code'=>'other', 'status'=>'enabled']);
        foreach ([$this->portal, $other] as $portal) {
            PortalDepartment::create(['portal_id'=>$portal->id, 'department_id'=>$this->root->id, 'includes_descendants'=>true, 'granted_by'=>$this->actor->id, 'granted_at'=>now()]);
        }
        try { $this->saveGrants([999999]); $this->fail('Expected invalid department'); }
        catch (ValidationException $e) { $this->assertTrue($this->portal->departmentGrants()->first()->includes_descendants); }
        $this->saveGrants([$this->child->id]);
        $this->assertTrue($other->departmentGrants()->first()->includes_descendants);
        $this->assertTrue(app(PortalAccessService::class)->canEnter($other, $this->user($this->sibling)));
    }

    public function test_banned_accounts_and_disabled_portals_remain_denied(): void
    {
        $this->saveGrants([$this->child->id]);
        $user = $this->user($this->child);
        $user->status = 0; $user->save();
        $access = app(PortalAccessService::class);
        $this->assertFalse($access->canEnter($this->portal, $user));
        $user->status = 1;
        $this->portal->status = Portal::STATUS_DISABLED;
        $this->assertFalse($access->canEnter($this->portal, $user));
        $this->portal->status = Portal::STATUS_MAINTENANCE;
        $this->assertFalse($access->canEnter($this->portal, $user));
    }
}
