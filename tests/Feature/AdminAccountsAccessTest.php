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
        $item['uri'] = 'organization/departments';
        $item['permission'] = 'organization.departments';
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
}
