<?php

namespace Tests\Feature;

require_once __DIR__.'/DingTalkLoginTest.php';

use App\Admin\Controllers\ConfigurationCenter\DingTalkCompanyController;
use App\Models\ErpLoginSite;
use App\Portal\Services\PortalDomainService;
use App\Services\Authentication\ModeService;
use App\Services\Authentication\PasswordLoginService;
use App\Services\DingTalk\LoginService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class DingTalkLoginEntryTest extends TestCase
{
    use DingTalkTestEnvironment;

    private function input(): array
    {
        return ['id'=>$this->company->id,'name'=>$this->company->name,'corp_id'=>$this->company->corp_id,
            'app_key'=>$this->company->app_key,'redirect_uri'=>$this->company->redirect_uri,
            'enabled'=>true];
    }


    private function site(string $domain, bool $dingtalk = false): ErpLoginSite
    {
        if ($dingtalk) {
            $this->company->update(['redirect_uri' => 'http://'.$domain.'/auth/dingtalk/callback']);
        }

        return ErpLoginSite::query()->create([
            'domain' => $domain, 'display_name' => 'Brand '.$domain,
            'password_enabled' => true, 'dingtalk_enabled' => $dingtalk,
            'dingtalk_company_id' => $dingtalk ? $this->company->id : null,
        ]);
    }

    public function test_registered_domains_show_their_own_brand_methods_and_cookie_scope(): void
    {
        config(['portals.scheme' => 'http']);
        $a = $this->site('a.test', true);
        $this->site('b.test');
        $a->update(['display_name' => '<script>a()</script>']);

        $this->get('http://a.test/kky/auth/login?company=999')->assertOk()
            ->assertSee('<script>a()</script>')->assertDontSee('<script>a()</script>', false)
            ->assertSee('data-open-scan', false)->assertSee('admin-login-form', false);
        $response = $this->get('http://b.test/kky/auth/login')->assertOk()
            ->assertSee('Brand b.test')->assertDontSee('data-open-scan', false)->assertSee('admin-login-form', false);
        $cookie = $response->getCookie(config('session.cookie'), false);
        self::assertSame('b.test', ltrim($cookie->getDomain(), '.'));
        $a->update(['password_enabled' => false]);
        $this->get('http://a.test/kky/auth/login')->assertOk()
            ->assertSee('data-auto-scan', false)->assertDontSee('admin-login-form', false);
        $this->get('http://a.test/kky/auth/login/test-company')->assertNotFound();
        $this->get('http://other.test/kky/auth/login')->assertNotFound();
    }

    public function test_existing_erp_account_can_sign_in_on_a_different_enterprise_domain(): void
    {
        config(['portals.scheme' => 'http']);
        $this->site('a.test', true);
        $this->site('b.test');
        $account = $this->account();

        $this->post('http://b.test/kky/auth/login', ['username' => $account->username, 'password' => 'test-password-123'])
            ->assertRedirect('http://b.test/kky');
        $this->assertAuthenticatedAs($account, 'admin');
    }

    public function test_password_session_is_scoped_and_remains_valid_after_switch_off(): void
    {
        config(['portals.scheme' => 'http']);
        $site = $this->site('a.test', true);
        $this->site('b.test');
        $account = $this->account();
        $request = \Illuminate\Http\Request::create('http://a.test/kky/auth/login', 'POST', [
            'username' => $account->username, 'password' => 'test-password-123',
        ]);
        $request->setLaravelSession($this->browser(true)->session());
        app()->instance('request', $request);
        $modes = app(ModeService::class);

        app(PasswordLoginService::class)->login($request);
        $site->update(['password_enabled' => false]);
        self::assertSame('password', $modes->validate($request, $account));
        $this->denied(fn () => app(PasswordLoginService::class)->login($request), 403);

        $other = \Illuminate\Http\Request::create('http://b.test/kky/auth/login');
        $other->setLaravelSession($request->session());
        $this->denied(fn () => $modes->validate($other, $account), 401);

        $portal = \Illuminate\Http\Request::create('http://wuliu.a.test/');
        $portal->setLaravelSession($request->session());
        self::assertSame('password', $modes->validate($portal, $account));
        $account->forceFill(['status' => 0])->save();
        $this->denied(fn () => $modes->validate($request, $account), 401);
    }

    public function test_scan_started_before_disabling_cannot_finish_after_reenabling(): void
    {
        config(['portals.scheme' => 'http']);
        $site = $this->site('a.test', true);
        $request = $this->browser(true);
        $request->initialize([], [], [], [], [], ['HTTP_HOST' => 'a.test', 'REQUEST_URI' => '/kky/auth/dingtalk/start']);
        $login = app(LoginService::class);
        $flow = $login->start($request);
        $site->update(['dingtalk_enabled' => false]);
        $this->denied(fn () => $login->acceptCallback($request, $flow['state'], 'code'), 403);
        $site->dingtalk_version += 2;
        $site->dingtalk_enabled = true;
        $site->save();
        $this->denied(fn () => $login->acceptCallback($request, $flow['state'], 'code'), 401);
    }

    public function test_http_scan_callback_checks_the_configured_enterprise_and_keeps_existing_sessions(): void
    {
        config(['portals.scheme' => 'http']);
        $site = $this->site('a.test', true);
        $this->site('b.test');
        $account = $this->account();
        app(\App\Services\DingTalk\BindingService::class)->bind($account->id, 'user-a');
        $this->postJson('http://b.test/kky/auth/dingtalk/start')->assertForbidden();
        $flow = $this->postJson('http://a.test/kky/auth/dingtalk/start')->assertOk()->json();

        $this->returnedCorp = 'wrong-enterprise';
        $this->get('http://a.test/auth/dingtalk/callback?'.http_build_query(['state' => $flow['state'], 'code' => 'wrong-company']))
            ->assertRedirect('http://a.test/kky/auth/login');
        $this->assertGuest('admin');

        $this->returnedCorp = $this->company->corp_id;
        $flow = $this->postJson('http://a.test/kky/auth/dingtalk/start')->assertOk()->json();
        $this->get('http://a.test/auth/dingtalk/callback?'.http_build_query(['state' => $flow['state'], 'code' => 'valid']))
            ->assertRedirect('http://a.test/kky');
        $this->assertAuthenticatedAs($account, 'admin');
        $site->update(['dingtalk_enabled' => false]);
        $this->get('http://a.test/kky/auth/login')->assertRedirect('http://a.test/kky');
    }

    public function test_enterprise_setup_requires_a_method_and_stores_brand_parts_independently(): void
    {
        Storage::fake('public');
        $controller = app(\App\Admin\Controllers\ConfigurationCenter\ErpLoginSiteController::class);
        $sites = app(\App\Services\Authentication\LoginSiteService::class);
        $request = $this->browser();
        $request->merge(['domain' => 'B.test', 'display_name' => 'Enterprise B']);
        try {
            $controller->save($request, $sites);
            self::fail('Both sign-in methods were disabled');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('password_enabled', $e->errors());
        }

        $request->merge(['password_enabled' => true]);
        $request->files->set('logo', UploadedFile::fake()->image('logo.png'));
        $controller->save($request, $sites);
        $site = ErpLoginSite::query()->where('domain', 'b.test')->firstOrFail();
        self::assertSame('Enterprise B', $site->display_name);
        self::assertNull($site->dingtalk_company_id);
        Storage::disk('public')->assertExists($site->logo_path);
    }

    public function test_saved_logo_is_available_before_login_without_a_public_storage_link(): void
    {
        Storage::fake('public');
        config(['portals.scheme' => 'http']);
        $site = $this->site('a.test');
        $path = UploadedFile::fake()->image('company.png')->store('erp/login-site-logos', 'public');
        $site->update(['logo_path' => $path]);
        $url = '/auth/enterprise-logo/'.basename($path);

        $this->get('http://a.test/kky/auth/login')->assertOk()->assertSee('src="'.$url.'"', false);
        $image = $this->get('http://a.test'.$url)->assertOk()
            ->assertHeader('Content-Type', 'image/png')->assertHeader('X-Content-Type-Options', 'nosniff');
        self::assertSame(Storage::disk('public')->get($path), $image->streamedContent());

        Storage::disk('public')->put('erp/login-site-logos/orphan.png', Storage::disk('public')->get($path));
        $this->get('http://a.test/auth/enterprise-logo/orphan.png')->assertNotFound();

        Storage::disk('public')->delete($path);
        $this->get('http://a.test'.$url)->assertNotFound();
        $this->get('http://a.test/kky/auth/login')->assertOk()->assertSee('login-brand-default', false);
    }

    public function test_portal_links_use_the_matching_enterprise_root(): void
    {
        config(['portals.scheme' => 'http']);
        $this->site('a.test');
        $this->site('b.test');
        app()->instance('request', \Illuminate\Http\Request::create('http://wuliu.b.test/orders'));
        $domains = app(PortalDomainService::class);

        self::assertSame('http://wuliu.b.test/orders', $domains->portalUrl('wuliu', '/orders'));
        self::assertSame('http://b.test/kky/auth/login', $domains->mainLoginUrl());
    }
    public function test_logo_upload_replacement_removal_and_failed_save_preserve_files_correctly(): void
    {
        Storage::fake('admin'); config(['admin.upload.disk'=>'admin']);
        $request=$this->browser(); $request->merge($this->input());
        $request->files->set('logo',UploadedFile::fake()->image('first.png',100,100));
        app(DingTalkCompanyController::class)->save($request);
        $first=$this->company->fresh()->login_logo_path; Storage::disk('admin')->assertExists($first);
        $request=$this->browser(); $request->merge($this->input());
        $request->files->set('logo',UploadedFile::fake()->image('second.jpg',100,100));
        app(DingTalkCompanyController::class)->save($request);
        $second=$this->company->fresh()->login_logo_path;
        Storage::disk('admin')->assertMissing($first); Storage::disk('admin')->assertExists($second);
        $request=$this->browser(); $request->merge($this->input());
        $request->files->set('logo',UploadedFile::fake()->create('bad.svg',1,'image/svg+xml'));
        try { app(DingTalkCompanyController::class)->save($request); self::fail('SVG accepted'); }
        catch (ValidationException $e) { self::assertArrayHasKey('logo',$e->errors()); }
        self::assertSame($second,$this->company->fresh()->login_logo_path); Storage::disk('admin')->assertExists($second);
        $request=$this->browser(); $request->merge($this->input() + ['logo_remove'=>true]);
        app(DingTalkCompanyController::class)->save($request);
        self::assertNull($this->company->fresh()->login_logo_path); Storage::disk('admin')->assertMissing($second);
        self::assertSame(1,$this->company->fresh()->version);
    }

    public function test_a_failed_transaction_removes_the_new_upload_and_preserves_the_old_logo(): void
    {
        Storage::fake('admin'); config(['admin.upload.disk'=>'admin']);
        $old='dingtalk/company-logos/old.png'; Storage::disk('admin')->put($old,'existing-logo');
        $this->company->update(['login_logo_path'=>$old,'login_logo_disk'=>'admin']);
        $this->mock(\App\Services\DingTalk\AuditService::class,function ($mock): void {
            $mock->shouldReceive('record')->once()->andThrow(new \RuntimeException('simulated transaction failure'));
        });
        $request=$this->browser(); $request->merge($this->input());
        $request->files->set('logo',UploadedFile::fake()->image('new.png',100,100));
        try { app(DingTalkCompanyController::class)->save($request); self::fail('Save unexpectedly succeeded'); }
        catch (\RuntimeException $e) { self::assertSame('simulated transaction failure',$e->getMessage()); }
        self::assertSame($old,$this->company->fresh()->login_logo_path);
        self::assertSame([$old],Storage::disk('admin')->allFiles('dingtalk/company-logos'));
    }

}
