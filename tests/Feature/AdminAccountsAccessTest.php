<?php

namespace Tests\Feature;

require_once __DIR__.'/AssetAccountsTest.php';

use App\Http\Middleware\RequireSystemAdministrator;
use App\Models\AdminUser;
use Encore\Admin\Facades\Admin;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AdminAccountsAccessTest extends TestCase
{
    use AssetAccountsEnvironment { setUp as private accountsSetUp; }

    protected function setUp(): void
    {
        $this->accountsSetUp();
        $this->withMiddleware();

        // Exercise the real route guard, independent of login/domain/CSRF fixtures.
        $router = app('router');
        $middleware = app(Kernel::class)->getGlobalMiddleware();
        foreach ($router->getRoutes() as $route) {
            $middleware = array_merge($middleware, $router->gatherRouteMiddleware($route));
        }
        $middleware = array_map(fn ($entry) => explode(':', $entry, 2)[0], $middleware);
        $this->withoutMiddleware(array_values(array_diff(array_unique($middleware), [
            RequireSystemAdministrator::class, SubstituteBindings::class,
            \App\Http\Middleware\RequireDingTalkBindingAccess::class,
            \App\Http\Middleware\RequireDingTalkBindingOwner::class,
            \Illuminate\Session\Middleware\StartSession::class,
            \Illuminate\View\Middleware\ShareErrorsFromSession::class,
        ])));
    }

    private function mainUrl(string $suffix = ''): string
    {
        return '/'.trim(config('admin.route.prefix'), '/').'/organization/accounts'.$suffix;
    }

    public function test_managers_deputies_and_members_cannot_use_any_main_account_endpoint(): void
    {
        $target = $this->hrMember($this->teamA);
        $before = AdminUser::count();
        $endpoints = [
            ['GET', ''], ['GET', '/create'], ['POST', ''],
            ['GET', '/'.$target->id.'/edit'], ['PUT', '/'.$target->id],
            ['PATCH', '/'.$target->id], ['DELETE', '/'.$target->id],
            ['GET', '/'.$target->id.'/permissions'], ['PUT', '/'.$target->id.'/permissions'],
            ['GET', '/'.$target->id.'/dingtalk'], ['POST', '/dingtalk/bindings'],
            ['DELETE', '/dingtalk/bindings/1'], ['GET', '/dingtalk/directory'],
            ['GET', '/dingtalk/audits'], ['GET', '/dingtalk/company-grants'],
            ['POST', '/dingtalk/company-grants'],
        ];
        foreach (['manager', 'deputy', 'member'] as $position) {
            DB::table('admin_role_users')->where('user_id', $this->hrActor->id)->update(['position' => $position]);
            Admin::guard()->setUser($this->hrActor->unsetRelation('roles')->unsetRelation('departments'));
            foreach ($endpoints as [$method, $suffix]) {
                $this->json($method, $this->mainUrl($suffix), [])->assertForbidden();
            }
            $this->get('/'.trim(config('admin.route.prefix'), '/').'/auth/users')->assertForbidden();
        }
        $this->assertSame($before, AdminUser::count());
        $this->assertNotNull($target->fresh());
    }

    public function test_explicit_and_wildcard_permissions_do_not_bypass_the_main_guard(): void
    {
        foreach (['organization.accounts', '*'] as $slug) {
            $id = DB::table('admin_permissions')->insertGetId(['slug' => $slug, 'name' => $slug]);
            DB::table('admin_user_permissions')->insert(['user_id' => $this->hrActor->id, 'permission_id' => $id]);
        }
        Admin::guard()->setUser($this->hrActor);
        $this->assertTrue($this->hrActor->can('organization.accounts'));
        $this->getJson($this->mainUrl())->assertForbidden();
        $this->postJson($this->mainUrl(), $this->hrValues())->assertForbidden();
    }

    public function test_superadministrator_can_manage_main_accounts(): void
    {
        Admin::guard()->setUser($this->hrOwner);
        $this->get($this->mainUrl())->assertOk();
        $this->get($this->mainUrl('/create'))->assertOk();
        $this->post($this->mainUrl(), $this->hrValues())->assertRedirect();
        $target = AdminUser::where('username', 'new-person')->firstOrFail();
        $this->get($this->mainUrl('/'.$target->id.'/edit'))->assertOk();
        $this->put($this->mainUrl('/'.$target->id), $this->hrValues(['name' => 'Updated Person']))->assertRedirect();
        $this->assertSame('Updated Person', $target->fresh()->name);
        $this->getJson($this->mainUrl('/'.$target->id.'/dingtalk'))->assertOk();
        $this->withHeader('X-Requested-With', 'XMLHttpRequest')
            ->deleteJson($this->mainUrl('/'.$target->id))->assertOk();
        $this->assertNull($target->fresh());
    }

    public function test_superadministrator_role_is_not_restricted_to_account_one(): void
    {
        $administrator = $this->account(true);
        $this->assertNotSame(1, (int) $administrator->id);
        Admin::guard()->setUser($administrator);
        $this->get($this->mainUrl('/create'))->assertOk();
        // Existing DingTalk owner rules remain stricter than account management.
        $this->getJson($this->mainUrl('/dingtalk/company-grants'))->assertForbidden();
    }

    public function test_main_menu_hides_account_links_but_keeps_other_menus(): void
    {
        $item = [
            'title' => 'Account management', 'icon' => 'fa-users', 'roles' => [],
            'uri' => 'organization/accounts', 'permission' => 'organization.accounts',
        ];
        Admin::guard()->setUser($this->hrActor);
        $this->assertStringNotContainsString('<li', view('admin::partials.menu', compact('item'))->render());
        foreach (['organization/accounts', '/organization/accounts/create', 'auth/users', admin_url('organization/accounts')] as $uri) {
            $item['uri'] = $uri;
            $item['permission'] = null;
            $this->assertStringNotContainsString('<li', view('admin::partials.menu', compact('item'))->render());
        }
        $item['uri'] = 'auth/setting';
        $item['permission'] = null;
        $this->assertStringContainsString('<li', view('admin::partials.menu', compact('item'))->render());
        $item['uri'] = 'organization/accounts';
        $item['permission'] = 'organization.accounts';
        Admin::guard()->setUser($this->hrOwner);
        $this->assertStringContainsString('<li', view('admin::partials.menu', compact('item'))->render());
    }

    public function test_main_rejection_does_not_block_hr_department_authorization(): void
    {
        Admin::guard()->setUser($this->hrActor);
        $this->getJson($this->mainUrl())->assertForbidden();
        $this->get($this->hrUrl)->assertOk();
        $this->postJson($this->hrUrl, $this->hrValues())->assertOk();
        $target = AdminUser::where('username', 'new-person')->firstOrFail();
        $this->putJson($this->hrUrl.'/'.$target->id, $this->hrValues(['name' => 'HR Updated Person']))->assertOk();
        $this->assertSame('HR Updated Person', $target->fresh()->name);
        $outside = $this->hrMember($this->outside);
        $this->getJson($this->hrUrl.'/'.$outside->id)->assertNotFound();
    }

    public function test_hr_manager_can_still_bind_and_unbind_with_department_and_company_grants(): void
    {
        $target = $this->hrMember($this->teamA);
        \App\Models\DingTalkCompanyGrant::create([
            'company_id' => $this->company->id, 'user_id' => $this->hrActor->id, 'created_by' => 1,
        ]);
        $this->operatorBinding = app(\App\Services\DingTalk\BindingService::class)
            ->bind($this->hrActor->id, 'user-b', false, $this->company->id);
        Admin::guard()->setUser($this->hrActor);
        $this->getJson($this->mainUrl('/'.$target->id.'/dingtalk'))->assertForbidden();
        $this->getJson($this->hrUrl.'/'.$target->id.'/dingtalk')->assertOk()->assertJsonCount(1, 'companies');
        $this->postJson($this->hrUrl.'/dingtalk/bindings', [
            'account_id' => $target->id, 'company_id' => $this->company->id, 'user_id' => 'user-a',
        ])->assertOk();
        $binding = \App\Models\DingTalkBinding::where('account_id', $target->id)->firstOrFail();
        $this->assertTrue($binding->active);
        $this->getJson($this->hrUrl.'/dingtalk/audits?account_id='.$target->id.'&kind=operation')
            ->assertOk()->assertJsonCount(1, 'rows');
        $this->deleteJson($this->hrUrl.'/dingtalk/bindings/'.$binding->id)->assertOk();
        $this->assertFalse($binding->fresh()->active);
    }

    public function test_nonadministrators_cannot_use_department_menu_log_or_legacy_endpoints(): void
    {
        $department = $this->hrSource;
        $endpoints = [
            ['GET', 'organization/departments'], ['GET', 'organization/departments/create'],
            ['POST', 'organization/departments'], ['GET', 'organization/departments/'.$department->id.'/edit'],
            ['PUT', 'organization/departments/'.$department->id], ['PATCH', 'organization/departments/'.$department->id],
            ['DELETE', 'organization/departments/'.$department->id],
            ['GET', 'organization/departments/'.$department->id.'/members'],
            ['POST', 'organization/departments/'.$department->id.'/members'],
            ['GET', 'organization/departments/bindings'], ['PUT', 'organization/departments/bindings'],
            ['GET', 'organization/departments/'.$department->id.'/permissions'],
            ['PUT', 'organization/departments/'.$department->id.'/permissions'],
            ['GET', 'organization/departments/'.$department->id.'/dissolution'],
            ['POST', 'organization/departments/'.$department->id.'/dissolution'],
            ['GET', 'auth/menu'], ['POST', 'auth/menu'], ['GET', 'auth/menu/1'],
            ['GET', 'auth/menu/1/edit'], ['PUT', 'auth/menu/1'], ['PATCH', 'auth/menu/1'], ['DELETE', 'auth/menu/1'],
            ['GET', 'auth/logs'], ['DELETE', 'auth/logs/1'],
            ['GET', 'auth/roles'], ['GET', 'auth/permissions'], ['GET', 'organization/department-permissions'],
        ];
        // Even an existing wildcard grant must not restore main-backend management.
        $permission = DB::table('admin_permissions')->insertGetId(['slug' => '*', 'name' => 'All']);
        DB::table('admin_user_permissions')->insert(['user_id' => $this->hrActor->id, 'permission_id' => $permission]);
        foreach (['manager', 'deputy', 'member'] as $position) {
            DB::table('admin_role_users')->where('user_id', $this->hrActor->id)->update(['position' => $position]);
            Admin::guard()->setUser($this->hrActor->unsetRelation('roles')->unsetRelation('departments'));
            foreach ($endpoints as [$method, $path]) {
                $this->json($method, '/'.trim(config('admin.route.prefix'), '/').'/'.$path)->assertForbidden();
            }
        }
        $this->assertNotNull($department->fresh());
        $this->assertSame('Department '.$this->hrActor->id, $department->fresh()->name);
    }

    public function test_management_group_disappears_for_nonadministrators_and_remains_for_superadministrators(): void
    {
        $children = [];
        foreach (['organization/accounts', 'organization/departments', 'auth/menu', 'auth/logs'] as $uri) {
            $children[] = ['uri' => $uri, 'title' => $uri, 'icon' => 'fa-circle', 'roles' => [], 'permission' => null];
        }
        $item = ['uri' => '', 'title' => 'Admin', 'icon' => 'fa-tasks', 'roles' => [], 'children' => $children];
        Admin::guard()->setUser($this->hrActor);
        $this->assertStringNotContainsString('<li', view('admin::partials.menu', compact('item'))->render());
        foreach (['organization/departments', 'organization/departments/1/members', 'organization/department-permissions', 'auth/roles', 'auth/permissions', 'auth/menu', 'auth/menu/1/edit', 'auth/logs', admin_url('auth/menu')] as $uri) {
            $menu = $children[0];
            $menu['uri'] = $uri;
            $this->assertStringNotContainsString('<li', view('admin::partials.menu', ['item' => $menu])->render());
        }
        $menu = $children[0];
        $menu['uri'] = '';
        foreach (['organization.departments', 'auth.management'] as $permission) {
            $menu['permission'] = $permission;
            $this->assertStringNotContainsString('<li', view('admin::partials.menu', ['item' => $menu])->render());
        }
        Admin::guard()->setUser($this->hrOwner);
        $html = view('admin::partials.menu', compact('item'))->render();
        $this->assertStringContainsString('treeview', $html);
        foreach ($children as $child) {
            $this->assertStringContainsString(admin_url($child['uri']), $html);
        }
    }

    public function test_superadministrator_can_open_management_pages_and_edit_departments(): void
    {
        \Illuminate\Support\Facades\Schema::table('admin_roles', function (\Illuminate\Database\Schema\Blueprint $table) {
            $table->string('short_name')->nullable();
        });
        \Illuminate\Support\Facades\Schema::create(config('admin.database.operation_log_table'), function (\Illuminate\Database\Schema\Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('path');
            $table->string('method');
            $table->string('ip');
            $table->text('input');
            $table->timestamps();
        });
        Admin::guard()->setUser($this->hrOwner);
        $prefix = '/'.trim(config('admin.route.prefix'), '/').'/';
        $this->get($prefix.'organization/departments')->assertOk();
        $this->get($prefix.'auth/menu')->assertOk();
        $this->get($prefix.'auth/logs')->assertOk();
        $this->get($prefix.'auth/roles')->assertRedirect();
        $this->get($prefix.'auth/permissions')->assertRedirect();
        $this->get($prefix.'organization/department-permissions')->assertRedirect();
        $this->put($prefix.'organization/departments/'.$this->teamA->id, [
            'name' => 'Updated Team A', 'sort' => 0,
        ])->assertRedirect();
        $this->assertSame('Updated Team A', $this->teamA->fresh()->name);
    }
}
