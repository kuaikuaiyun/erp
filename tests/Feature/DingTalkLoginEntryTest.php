<?php

namespace Tests\Feature;

require_once __DIR__.'/DingTalkLoginTest.php';

use App\Admin\Controllers\AuthController;
use App\Admin\Controllers\ConfigurationCenter\DingTalkCompanyController;
use App\Http\Middleware\EnforceDingTalkSession;
use App\Models\DingTalkCompany;
use App\Services\DingTalk\CompanyService;
use App\Services\DingTalk\LoginEntryService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\ViewErrorBag;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class DingTalkLoginEntryTest extends TestCase
{
    use DingTalkTestEnvironment;

    private function input(): array
    {
        return ['id'=>$this->company->id,'name'=>$this->company->name,'corp_id'=>$this->company->corp_id,
            'app_key'=>$this->company->app_key,'redirect_uri'=>$this->company->redirect_uri,
            'callback_owner_key'=>$this->company->callback_owner_key,'enabled'=>true,'login_slug'=>'test-company'];
    }

    public function test_public_page_never_lists_companies_even_with_old_query_parameter_or_session_hint(): void
    {
        $request=$this->browser(); $request->query->set('company',$this->company->id);
        app(LoginEntryService::class)->remember($request,$this->company);
        view()->share('errors',new ViewErrorBag());
        $response=app(AuthController::class)->getLogin();
        self::assertSame(200,$response->getStatusCode());
        self::assertStringNotContainsString('Test company',$response->getContent());
        self::assertStringNotContainsString('ding-login-frame',$response->getContent());
        self::assertStringNotContainsString('<select',$response->getContent());
    }

    public function test_http_routes_allow_the_company_entry_but_public_query_parameters_do_not_open_login(): void
    {
        $this->get('/kky/auth/login/test-company?company=999')->assertOk()->assertSee('ding-login-frame',false);
        $this->get('/kky/auth/login?company='.$this->company->id)->assertOk()->assertDontSee('ding-login-frame',false)->assertDontSee('Test company');
        $this->get('/kky/auth/login/missing-company')->assertNotFound()->assertDontSee('Test company');
    }

    public function test_slug_selects_only_its_company_and_unavailable_links_return_the_same_error(): void
    {
        self::assertSame($this->company->id,app(CompanyService::class)->forLoginSlug('test-company')->id);
        $this->denied(fn()=>app(CompanyService::class)->forLoginSlug('missing-company'),404);
        $this->company->update(['enabled'=>false]);
        $this->denied(fn()=>app(CompanyService::class)->forLoginSlug('test-company'),404);
        $this->company->delete();
        $this->denied(fn()=>app(CompanyService::class)->forLoginSlug('test-company'),404);
    }

    public function test_branding_is_plain_text_and_query_parameters_cannot_switch_the_login_company(): void
    {
        $this->company->update(['login_display_name'=>'<script>brand()</script>','login_page_title'=>'Custom title','login_welcome'=>'Welcome A']);
        $request=$this->browser(); $request->query->set('company',999);
        view()->share('errors',new ViewErrorBag());
        $response=app(AuthController::class)->getLogin('test-company');
        self::assertSame(200,$response->getStatusCode());
        self::assertStringContainsString('&lt;script&gt;brand()&lt;/script&gt;',$response->getContent());
        self::assertStringNotContainsString('<script>brand()</script>',$response->getContent());
        self::assertStringContainsString('client_id=app-test',$response->getContent());
        self::assertStringContainsString('Custom title',$response->getContent());
        self::assertSame($this->company->id,$request->session()->get(LoginEntryService::KEY));
    }

    public function test_slug_change_disables_old_link_without_revoking_an_existing_authentication_version(): void
    {
        $request=$this->browser(); $request->merge(array_merge($this->input(),['login_slug'=>'new-company','login_welcome'=>'Changed message']));
        app(DingTalkCompanyController::class)->save($request);
        self::assertSame(1,$this->company->fresh()->version);
        $this->denied(fn()=>app(CompanyService::class)->forLoginSlug('test-company'),404);
        self::assertSame($this->company->id,app(CompanyService::class)->forLoginSlug('new-company')->id);
    }

    public function test_archived_company_keeps_its_identifier_reserved(): void
    {
        $other=$this->company->replicate(); $other->corp_id='other-corp'; $other->login_slug='other-company'; $other->save(); $other->delete();
        $request=$this->browser(); $request->merge(array_merge($this->input(),['login_slug'=>'other-company']));
        try { app(DingTalkCompanyController::class)->save($request); self::fail('Duplicate slug accepted'); }
        catch (ValidationException $e) { self::assertArrayHasKey('login_slug',$e->errors()); }
    }

    public function test_logo_upload_replacement_removal_and_failed_save_preserve_files_correctly(): void
    {
        Storage::fake('admin'); config(['admin.upload.disk'=>'admin']);
        $request=$this->browser(); $request->merge($this->input());
        $request->files->set('logo',UploadedFile::fake()->image('first.png',100,100));
        app(DingTalkCompanyController::class)->save($request);
        $first=$this->company->fresh()->login_logo_path; Storage::disk('admin')->assertExists($first);
        $request->files->set('logo',UploadedFile::fake()->image('second.jpg',100,100));
        app(DingTalkCompanyController::class)->save($request);
        $second=$this->company->fresh()->login_logo_path;
        Storage::disk('admin')->assertMissing($first); Storage::disk('admin')->assertExists($second);
        $request->files->set('logo',UploadedFile::fake()->create('bad.svg',1,'image/svg+xml'));
        try { app(DingTalkCompanyController::class)->save($request); self::fail('SVG accepted'); }
        catch (ValidationException $e) { self::assertArrayHasKey('logo',$e->errors()); }
        self::assertSame($second,$this->company->fresh()->login_logo_path); Storage::disk('admin')->assertExists($second);
        $request->files->remove('logo'); $request->merge(['logo_remove'=>true]);
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

    public function test_guest_and_logout_redirects_keep_the_company_entry_and_emergency_mode_uses_public_login(): void
    {
        $request=$this->browser(); $entries=app(LoginEntryService::class); $entries->remember($request,$this->company);
        self::assertStringEndsWith('/kky/auth/login/test-company',$entries->url($request));
        $request->server->set('REQUEST_URI','/kky/organization/accounts');
        // A genuine protected URL is used so the guest middleware can route before admin authentication.
        $request=\Illuminate\Http\Request::create('http://localhost/kky/organization/accounts');
        $request->setLaravelSession(app('session')->driver()); app()->instance('request',$request);
        $response=app(EnforceDingTalkSession::class)->handle($request,fn()=>response('unexpected'));
        self::assertStringEndsWith('/kky/auth/login/test-company',$response->getTargetUrl());
        $response=app(AuthController::class)->getLogout($request);
        self::assertStringEndsWith('/kky/auth/login/test-company',$response->getTargetUrl());
        self::assertNull($request->session()->get(LoginEntryService::KEY));
        config(['dingtalk.auth_mode'=>'password']);
        self::assertStringEndsWith('/kky/auth/login',$entries->url($request));
    }

    public function test_expiration_captures_the_company_url_before_invalidating_the_session(): void
    {
        $request=$this->browser(); app(LoginEntryService::class)->remember($request,$this->company);
        \Encore\Admin\Facades\Admin::guard()->login($this->account());
        $request->session()->put(\App\Http\Middleware\EnforceAdminLoginExpiration::LOGIN_ISSUED_AT_KEY,now()->subDays(30)->timestamp);
        $response=app(\App\Http\Middleware\EnforceAdminLoginExpiration::class)->handle($request,fn()=>response('unexpected'));
        self::assertStringEndsWith('/kky/auth/login/test-company',$response->getTargetUrl());
        self::assertNull($request->session()->get(LoginEntryService::KEY));
    }

    public function test_emergency_mode_opens_password_page_and_routes_company_links_to_the_public_entry(): void
    {
        $this->browser(); view()->share('errors',new ViewErrorBag()); config(['dingtalk.auth_mode'=>'password']);
        $response=app(AuthController::class)->getLogin('test-company');
        self::assertStringEndsWith('/kky/auth/login',$response->getTargetUrl());
        $response=app(AuthController::class)->getLogin();
        self::assertStringContainsString('admin-login-form',$response->getContent());
    }
}
