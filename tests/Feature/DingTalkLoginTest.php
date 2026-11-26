<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\DingTalkBinding;
use App\Models\DingTalkIdentity;
use App\Services\DingTalk\BindingService;
use App\Services\DingTalk\LoginService;
use Encore\Admin\Facades\Admin;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

// Shared fixtures stay in the three approved test files; no business migrations or real APIs run.
trait DingTalkTestEnvironment
{
    protected \App\Models\DingTalkCompany $company;
    protected array $members;
    protected bool $apiUnavailable = false;
    protected string $authenticatedUnion = 'union-a';
    protected ?string $returnedCorp = 'dingcorp-test';
    protected ?int $legacyError = null;
    protected int $contactType = 0;

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:',
            'admin.database.connection' => null, 'app.key' => 'base64:'.base64_encode(str_repeat('k', 32)),
            'cache.default' => 'array', 'session.driver' => 'array',
            'portals.main_domain' => '', 'portals.root_domain' => '',
            'dingtalk.challenge_seconds' => 300]);
        DB::purge('sqlite');
        Cache::flush();
        config(['dingtalk.auth_mode' => 'dingtalk']);
        app()->setLocale('zh-CN');
        $this->travelTo(now()->setDate(2026, 11, 13)->setTime(12, 0));
        Schema::create('admin_users', function (Blueprint $table): void {
            $table->id(); $table->string('username'); $table->string('name'); $table->string('password');
            $table->integer('status')->default(1); $table->rememberToken(); $table->timestamps();
        });
        Schema::create('admin_roles', function (Blueprint $table): void {
            $table->id(); $table->string('name'); $table->string('slug'); $table->unsignedBigInteger('parent_id')->nullable(); $table->timestamps();
        });
        Schema::create('admin_role_users', function (Blueprint $table): void {
            $table->unsignedBigInteger('user_id'); $table->unsignedBigInteger('role_id'); $table->string('position')->nullable();
            $table->string('position_slot')->nullable(); $table->boolean('is_primary')->default(false); $table->timestamps();
        });
        Schema::create('system_settings', function (Blueprint $table): void {
            $table->id(); $table->string('key'); $table->text('value')->nullable(); $table->boolean('is_encrypted')->default(false);
        });
        (require database_path('migrations/2026_11_13_010000_create_dingtalk_authentication_tables.php'))->up();
        (require database_path('migrations/2026_11_15_000100_create_erp_authentication_state_tables.php'))->up();
        Schema::create('admin_permissions', function (Blueprint $t): void { $t->id(); $t->string('slug'); $t->string('name'); $t->string('http_method')->nullable(); $t->text('http_path')->nullable(); $t->timestamps(); });
        Schema::create('admin_user_permissions', function (Blueprint $t): void { $t->unsignedBigInteger('user_id'); $t->unsignedBigInteger('permission_id'); $t->timestamps(); });
        Schema::create('admin_user_permission_overrides', function (Blueprint $t): void { $t->id(); $t->unsignedBigInteger('user_id'); $t->unsignedBigInteger('permission_id'); $t->string('effect'); });
        Schema::create('admin_role_permissions', function (Blueprint $t): void { $t->unsignedBigInteger('role_id'); $t->unsignedBigInteger('permission_id'); $t->timestamps(); });
        Schema::create('admin_department_permission_scopes', function (Blueprint $t): void { $t->id(); $t->unsignedBigInteger('role_id'); $t->unsignedBigInteger('permission_id'); $t->string('scope'); });
        Schema::create('admin_menu', function (Blueprint $t): void { $t->id(); $t->unsignedBigInteger('parent_id')->default(0); $t->integer('order')->default(0); $t->string('title'); $t->string('icon')->default('fa-circle'); $t->string('uri'); $t->string('permission')->nullable(); $t->timestamps(); });
        Schema::create('admin_role_menu', function (Blueprint $t): void { $t->unsignedBigInteger('role_id'); $t->unsignedBigInteger('menu_id'); });
        DB::table('admin_permissions')->insert(['slug'=>'dingtalk.bindings','name'=>'Bindings','http_method'=>'GET,POST,DELETE','http_path'=>'/auth/dingtalk/*','created_at'=>now(),'updated_at'=>now()]);
        DB::table('admin_roles')->insert(['name'=>'Administrator','slug'=>'administrator','created_at'=>now(),'updated_at'=>now()]);
        DB::table('admin_menu')->insert(['title'=>\Illuminate\Support\Facades\Lang::get('configuration.menu.configuration_center',[],'zh-CN'),'uri'=>'','created_at'=>now(),'updated_at'=>now()]);
        (require database_path('migrations/2026_11_15_000400_install_dingtalk_companies.php'))->up();
        (require database_path('migrations/2026_11_16_000100_add_dingtalk_company_login_slug.php'))->up();
        (require database_path('migrations/2026_11_26_010000_configure_enterprise_login_sites.php'))->up();
        (require database_path('migrations/2026_11_26_020000_add_company_name_to_erp_login_sites.php'))->up();
        (require database_path('migrations/2026_11_26_030000_add_root_domain_to_erp_login_sites.php'))->up();
        $this->company=\App\Models\DingTalkCompany::query()->create(['name'=>'Test company','login_slug'=>'test-company','corp_id'=>'dingcorp-test','app_key'=>'app-test','app_secret'=>'secret-test',
            'redirect_uri'=>'http://localhost/auth/dingtalk/callback','enabled'=>true])->fresh();
        $this->members = [
            'user-a' => ['corp_id' => 'dingcorp-test', 'user_id' => 'user-a', 'union_id' => 'union-a', 'name' => '测试成员 A'],
            'user-b' => ['corp_id' => 'dingcorp-test', 'user_id' => 'user-b', 'union_id' => 'union-b', 'name' => '测试成员 B'],
        ];
        Http::preventStrayRequests();
        Http::fake(function (ClientRequest $request) {
            if ($this->apiUnavailable) {
                return Http::response(['code' => 'ServiceUnavailable'], 503);
            }
            if ($this->legacyError !== null && str_contains($request->url(), '/topapi/')) {
                return Http::response(['errcode' => $this->legacyError]);
            }
            return match (parse_url($request->url(), PHP_URL_PATH)) {
                '/v1.0/oauth2/accessToken' => Http::response(['accessToken' => 'app-token-test', 'expireIn' => 7200]),
                '/v1.0/oauth2/userAccessToken' => Http::response(['accessToken' => 'user-token-test', 'corpId' => $this->returnedCorp]),
                '/v1.0/contact/users/me' => Http::response(['unionId' => $this->authenticatedUnion]),
                '/topapi/v2/user/get' => isset($this->members[$request['userid']])
                    ? Http::response(['errcode' => 0, 'result' => ['userid' => $request['userid'], 'active' => true,
                        'unionid' => $this->members[$request['userid']]['union_id'], 'name' => $this->members[$request['userid']]['name']]])
                    : Http::response(['errcode' => 60111]),
                '/topapi/user/getbyunionid' => ($member = collect($this->members)->firstWhere('union_id', $request['unionid']))
                    ? Http::response(['errcode' => 0, 'result' => ['userid' => $member['user_id'], 'contact_type' => $this->contactType]])
                    : Http::response(['errcode' => 60121]),
                default => throw new \LogicException('Unexpected HTTP request in DingTalk tests'),
            };
        });
    }

    protected function tearDown(): void
    {
        $this->travelBack();
        parent::tearDown();
    }

    protected function createPortalFixture(): void
    {
        Schema::create('portals', function (Blueprint $table): void {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->string('status');
            $table->timestamps();
        });
    }

    protected function account(bool $administrator = false): AdminUser
    {
        $index = AdminUser::query()->count() + 1;
        $account = (new AdminUser())->forceFill(['username' => 'account-'.$index, 'name' => '测试账号 '.$index,
            'password' => bcrypt('test-password-123'), 'status' => 1]);
        $account->save();
        if ($administrator) {
            $roleId = DB::table('admin_roles')->where('slug','administrator')->value('id');
            $account->roles()->attach($roleId, ['position' => 'member', 'is_primary' => false]);
        }
        return $account->unsetRelation('roles');
    }

    protected function manager(string $position = 'manager'): AdminUser
    {
        $user=$this->account();
        $department=DB::table('admin_roles')->insertGetId(['name'=>'Department '.$user->id,'slug'=>'department-'.$user->id,'parent_id'=>null,'created_at'=>now(),'updated_at'=>now()]);
        $user->roles()->attach($department,['position'=>$position,'is_primary'=>true]);
        return $user->unsetRelation('roles');
    }
    protected function memberOf(AdminUser $manager): AdminUser
    {
        $user=$this->account();
        $user->roles()->attach($manager->departments()->value('id'),['position'=>'member','is_primary'=>true]);
        return $user;
    }

    protected function browser(bool $separate = false): Request
    {
        $request = Request::create('http://localhost/kky/auth/login');
        $session = $separate ? new Store('ding-test', new ArraySessionHandler(120)) : app('session')->driver();
        $session->start();
        $request->setLaravelSession($session);
        app()->instance('request', $request);
        return $request;
    }

    protected function proof(Request $request, DingTalkBinding $binding): void
    {
        $identity = $binding->identity()->firstOrFail();
        $modes = app(\App\Services\Authentication\ModeService::class);
        $modes->stamp($request, $binding->account()->firstOrFail(), $modes->current());
        $request->session()->put(LoginService::PROOF_KEY, ['company_id' => $this->company->id, 'company_version' => (int)$this->company->version, 'identity_id' => $identity->id, 'identity_version' => $identity->version,
            'binding_id' => $binding->id, 'binding_version' => $binding->version, 'account_id' => $binding->account_id, 'issued_at' => now()->timestamp]);
    }

    protected function denied(callable $action, int $status): void
    {
        try {
            $action();
            $this->fail('Expected DingTalk access rejection');
        } catch (HttpException $exception) {
            self::assertSame($status, $exception->getStatusCode());
        }
    }
}

class DingTalkLoginTest extends TestCase
{
    use DingTalkTestEnvironment;

    public function test_callback_does_not_authenticate_until_account_checks_finish(): void
    {
        $account = $this->account();
        app(BindingService::class)->bind($account->id, 'user-a');
        $request = $this->browser();
        $login = app(LoginService::class);
        $flow = $login->start($request);
        self::assertCount(1, $login->acceptCallback($request, $flow['state'], 'code-test'));
        self::assertTrue(Admin::guard()->guest());
        $login->complete($request, $account->id);
        self::assertSame((int) $account->id, (int) Admin::guard()->id());
        self::assertSame($account->id, $request->session()->get(LoginService::PROOF_KEY)['account_id']);
    }

    public function test_company_member_without_binding_is_denied_and_never_registered(): void
    {
        $request = $this->browser();
        $login = app(LoginService::class);
        $flow = $login->start($request);
        $this->denied(fn () => $login->acceptCallback($request, $flow['state'], 'code-test'), 403);
        self::assertSame(0, AdminUser::query()->count());
        self::assertSame(0, DingTalkIdentity::query()->count());
        self::assertTrue(Admin::guard()->guest());
    }

    public function test_external_identity_is_denied(): void
    {
        $this->authenticatedUnion = 'external-union';
        $request = $this->browser();
        $login = app(LoginService::class);
        $flow = $login->start($request);
        $this->denied(fn () => $login->acceptCallback($request, $flow['state'], 'code-test'), 403);
        self::assertTrue(Admin::guard()->guest());
    }

    public function test_multiple_accounts_require_valid_selection_and_cannot_select_an_unbound_account(): void
    {
        $ordinary = $this->account();
        $admin = $this->account(true);
        $unbound = $this->account();
        app(BindingService::class)->bind($ordinary->id, 'user-a');
        app(BindingService::class)->bind($admin->id, 'user-a');
        $request = $this->browser();
        $login = app(LoginService::class);
        $flow = $login->start($request);
        self::assertCount(2, $login->acceptCallback($request, $flow['state'], 'code-test'));
        self::assertTrue(Admin::guard()->guest());
        $this->denied(fn () => $login->complete($request, $unbound->id), 403);
        self::assertTrue(Admin::guard()->guest());
        $login->complete($request, $ordinary->id);
        self::assertSame((int) $ordinary->id, (int) Admin::guard()->id());
    }

    public function test_state_is_bound_to_browser_and_is_single_use(): void
    {
        $account = $this->account();
        app(BindingService::class)->bind($account->id, 'user-a');
        $request = $this->browser();
        $login = app(LoginService::class);
        $flow = $login->start($request);
        $other = $this->browser(true);
        $this->denied(fn () => $login->acceptCallback($other, $flow['state'], 'code-test'), 401);
        $login->acceptCallback($request, $flow['state'], 'code-test');
        $this->denied(fn () => $login->acceptCallback($request, $flow['state'], 'code-test'), 401);
    }

    public function test_expired_state_is_rejected_before_api_call(): void
    {
        $request = $this->browser();
        $login = app(LoginService::class);
        $flow = $login->start($request);
        $this->travel(6)->minutes();
        $this->denied(fn () => $login->acceptCallback($request, $flow['state'], 'code-test'), 401);
        Http::assertNothingSent();
    }

    public function test_account_selection_cannot_be_replayed(): void
    {
        $account = $this->account();
        app(BindingService::class)->bind($account->id, 'user-a');
        $request = $this->browser();
        $login = app(LoginService::class);
        $flow = $login->start($request);
        $login->acceptCallback($request, $flow['state'], 'code-test');
        $login->complete($request, $account->id);
        $this->denied(fn () => $login->complete($request, $account->id), 401);
    }

    public function test_local_password_endpoint_is_closed_even_for_a_correct_password(): void
    {
        $this->account();
        $this->post('/kky/auth/login', ['username' => 'account-1', 'password' => 'test-password-123'])->assertForbidden();
        self::assertTrue(Admin::guard()->guest());
    }

    public function test_new_login_during_api_outage_fails_closed(): void
    {
        $request = $this->browser();
        $login = app(LoginService::class);
        $flow = $login->start($request);
        $this->apiUnavailable = true;
        $this->denied(fn () => $login->acceptCallback($request, $flow['state'], 'code-test'), 503);
        self::assertTrue(Admin::guard()->guest());
    }

    public function test_wrong_company_and_missing_company_claim_cannot_pass_authorization(): void
    {
        $request = $this->browser();
        $login = app(LoginService::class);
        $flow = $login->start($request);
        $this->returnedCorp = 'another-company';
        $this->denied(fn () => $login->acceptCallback($request, $flow['state'], 'code-test'), 403);
        $flow = $login->start($request);
        $this->returnedCorp = null;
        $this->denied(fn () => $login->acceptCallback($request, $flow['state'], 'code-test'), 503);
        self::assertTrue(Admin::guard()->guest());
    }

    public function test_external_contact_is_not_treated_as_internal_employee(): void
    {
        $request = $this->browser();
        $login = app(LoginService::class);
        $flow = $login->start($request);
        $this->contactType = 1;
        $this->denied(fn () => $login->acceptCallback($request, $flow['state'], 'code-test'), 403);
    }

    public function test_account_selection_does_not_extend_the_original_five_minute_flow(): void
    {
        $account = $this->account();
        app(BindingService::class)->bind($account->id, 'user-a');
        $request = $this->browser();
        $login = app(LoginService::class);
        $flow = $login->start($request);
        $this->travel(4)->minutes();
        $login->acceptCallback($request, $flow['state'], 'code-test');
        $this->travel(2)->minutes();
        $this->denied(fn () => $login->complete($request, $account->id), 401);
    }
}
