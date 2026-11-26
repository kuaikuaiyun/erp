<?php
namespace Tests\Feature;
require_once __DIR__.'/DingTalkLoginTest.php';
use App\Admin\Controllers\ConfigurationCenter\DingTalkCompanyController;
use App\Models\DingTalkCompany;
use App\Models\ErpLoginSite;
use App\Services\DingTalk\AuditService;
use App\Services\DingTalk\DirectoryService;
use App\Services\DingTalk\CompanyService;
use Encore\Admin\Facades\Admin;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;
class DingTalkCompanyTest extends TestCase
{
    use DingTalkTestEnvironment;
    private function enterpriseInputs(): array
    {
        return [
            'login_configuration' => '1', 'name' => 'Internal company name', 'domain' => 'A.test',
            'display_name' => 'Sign-in brand', 'password_enabled' => '1', 'dingtalk_enabled' => '0',
        ];
    }

    private function signInForCompanySettings(): void
    {
        config(['portals.scheme' => 'http']);
        ErpLoginSite::query()->create(['domain' => 'admin.test', 'display_name' => 'Test admin', 'password_enabled' => true]);
        $admin = $this->account(true);
        $this->post('http://admin.test/kky/auth/login', [
            'username' => $admin->username, 'password' => 'test-password-123',
        ])->assertRedirect('http://admin.test/kky');
    }

    public function test_admin_company_editor_saves_lists_and_updates_a_password_only_enterprise(): void
    {
        Storage::fake('public');
        $this->signInForCompanySettings();

        $response = $this->post('http://admin.test/kky/configuration/dingtalk/companies', $this->enterpriseInputs() + [
            'logo' => UploadedFile::fake()->image('brand.png'),
        ], ['Accept' => 'application/json']);
        $response->assertOk()->assertJsonPath('id', null)->assertJsonPath('login_site.domain', 'a.test');
        $site = ErpLoginSite::query()->findOrFail($response->json('login_site.id'));
        self::assertSame('Internal company name', $site->company_name);
        self::assertSame('Sign-in brand', $site->display_name);
        self::assertNull($site->dingtalk_company_id);
        self::assertSame(1, DingTalkCompany::query()->count());
        Storage::disk('public')->assertExists($site->logo_path);
        $response->assertJsonPath('login_site.logo_url', '/auth/enterprise-logo/'.basename($site->logo_path));

        $this->getJson('http://admin.test/kky/configuration/dingtalk')->assertOk()->assertJsonFragment([
            'id' => null, 'name' => 'Internal company name', 'archived' => false,
        ])->assertJsonMissing(['app_secret' => 'secret-test']);
        $this->get('http://admin.test/kky/configuration/dingtalk')->assertOk()
            ->assertSee('name="domain"', false)->assertDontSee('href="http://admin.test/kky/configuration/enterprise-login"', false);
        $this->get('http://admin.test/kky/configuration/enterprise-login?site='.$site->id)
            ->assertRedirect('http://admin.test/kky/configuration/dingtalk?site='.$site->id);

        $edited = array_replace($this->enterpriseInputs(), [
            'site_id' => $site->id, 'domain' => 'renamed.test', 'remove_logo' => '1',
        ]);
        $this->postJson('http://admin.test/kky/configuration/dingtalk/companies', $edited)
            ->assertOk()->assertJsonPath('login_site.id', $site->id)->assertJsonPath('login_site.domain', 'renamed.test');
        $this->assertDatabaseMissing('erp_login_sites', ['domain' => 'a.test']);
        $this->assertDatabaseCount('erp_login_sites', 2);
        Storage::disk('public')->assertMissing($site->logo_path);

        $adminSite = ErpLoginSite::query()->where('domain', 'admin.test')->firstOrFail();
        $this->postJson('http://admin.test/kky/configuration/dingtalk/companies', array_replace($this->enterpriseInputs(), [
            'site_id' => $adminSite->id, 'domain' => 'new-admin.test',
        ]))->assertOk()->assertJsonPath('redirect_url', 'http://new-admin.test/kky/configuration/dingtalk');
    }

    public function test_first_registration_of_current_host_reports_that_sign_in_is_required(): void
    {
        config([
            'portals.scheme' => 'http', 'dingtalk.auth_mode' => 'password',
            'portals.main_domain' => 'erp.test', 'portals.root_domain' => 'erp.test',
        ]);
        $admin = $this->account(true);
        $this->post('http://erp.test/kky/auth/login', [
            'username' => $admin->username, 'password' => 'test-password-123',
        ])->assertRedirect('http://erp.test/kky');

        $list = 'http://erp.test/kky/configuration/dingtalk';
        $this->postJson($list.'/companies', array_replace($this->enterpriseInputs(), ['domain' => 'erp.test']))
            ->assertOk()->assertJsonPath('redirect_url', $list);
        $this->get($list)->assertRedirect('http://erp.test/kky/auth/login');
        $this->assertDatabaseHas('erp_login_sites', ['domain' => 'erp.test']);
    }

    public function test_admin_company_editor_creates_a_dual_login_site_updates_callback_and_keeps_the_dingtalk_secret(): void
    {
        $this->signInForCompanySettings();
        $input = array_replace($this->enterpriseInputs(), ['dingtalk_enabled' => '1']) + [
            'corp_id' => 'ding-enterprise-a', 'app_key' => 'app-key-a', 'app_secret' => 'app-secret-a',
            'enabled' => '1', 'redirect_uri' => 'https://ignored.test/auth/dingtalk/callback',
        ];
        $url = 'http://admin.test/kky/configuration/dingtalk/companies';
        $response = $this->postJson($url, $input)->assertOk();
        $company = DingTalkCompany::query()->findOrFail($response->json('id'));
        $site = ErpLoginSite::query()->findOrFail($response->json('login_site.id'));
        self::assertSame('http://a.test/auth/dingtalk/callback', $company->redirect_uri);
        self::assertSame($company->id, $site->dingtalk_company_id);
        self::assertTrue($site->dingtalk_enabled);
        self::assertTrue($site->password_enabled);
        self::assertSame('app-secret-a', $company->app_secret);

        $other = array_replace($this->enterpriseInputs(), ['domain' => ' a.TEST ']);
        $this->postJson($url, $other)->assertUnprocessable()->assertJsonValidationErrors('domain');
        $this->postJson($url, array_replace($other, ['domain' => 'nested.a.test']))
            ->assertUnprocessable()->assertJsonValidationErrors('domain');

        $input = array_replace($input, [
            'id' => $company->id, 'site_id' => $site->id, 'domain' => 'moved.test', 'app_secret' => '',
            'dingtalk_enabled' => '0',
        ]);
        $this->postJson($url, $input)->assertOk()->assertJsonPath('id', $company->id)->assertJsonPath('login_site.id', $site->id);
        $company->refresh();
        $site->refresh();
        self::assertSame('http://moved.test/auth/dingtalk/callback', $company->redirect_uri);
        self::assertSame('app-secret-a', $company->app_secret);
        self::assertTrue($company->enabled);
        self::assertFalse($site->dingtalk_enabled);
        self::assertSame(2, $site->password_version);
        self::assertSame(2, $site->dingtalk_version);
        $this->assertDatabaseCount('erp_login_sites', 2);
    }

    public function test_existing_password_site_can_reuse_an_unlinked_dingtalk_company(): void
    {
        $this->signInForCompanySettings();
        $url = 'http://admin.test/kky/configuration/dingtalk/companies';
        $site = $this->postJson($url, $this->enterpriseInputs())->assertOk()->json('login_site.id');
        $attach = array_replace($this->enterpriseInputs(), [
            'site_id' => $site, 'existing_company_id' => $this->company->id,
            'dingtalk_enabled' => '1', 'enabled' => '1', 'corp_id' => $this->company->corp_id,
            'app_key' => $this->company->app_key, 'app_secret' => '',
        ]);
        $this->postJson($url, $attach)->assertOk()->assertJsonPath('id', $this->company->id);
        $this->assertDatabaseHas('erp_login_sites', ['id' => $site, 'dingtalk_company_id' => $this->company->id]);
        self::assertSame('secret-test', $this->company->fresh()->app_secret);
        $this->assertDatabaseCount('dingtalk_companies', 1);

        $other = ErpLoginSite::query()->where('domain', 'admin.test')->value('id');
        $this->postJson($url, array_replace($attach, ['site_id' => $other, 'domain' => 'admin.test']))
            ->assertUnprocessable()->assertJsonValidationErrors('existing_company_id');
        $this->assertDatabaseHas('erp_login_sites', ['id' => $other, 'dingtalk_company_id' => null]);
    }

    public function test_unified_save_validates_both_methods_and_rolls_back_on_audit_failure(): void
    {
        Storage::fake('public');
        Admin::guard()->setUser($this->account(true));
        $request = $this->browser();
        $request->merge(array_replace($this->enterpriseInputs(), ['password_enabled' => '0']));
        $controller = app(DingTalkCompanyController::class);
        try {
            $controller->save($request);
            self::fail('An enterprise without a sign-in method was saved');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('password_enabled', $exception->errors());
        }

        $request->merge([
            'dingtalk_enabled' => '1', 'id' => $this->company->id, 'corp_id' => $this->company->corp_id,
            'app_key' => 'replacement-key', 'enabled' => '1',
        ]);
        $request->files->set('logo', UploadedFile::fake()->image('new.png'));
        $this->mock(AuditService::class, function ($mock): void {
            $mock->shouldReceive('record')->once()->andThrow(new \RuntimeException('audit failure'));
        });
        try {
            $controller->save($request);
            self::fail('An enterprise was saved when auditing failed');
        } catch (\RuntimeException $exception) {
            self::assertSame('audit failure', $exception->getMessage());
        }
        self::assertSame('app-test', $this->company->fresh()->app_key);
        $this->assertDatabaseCount('erp_login_sites', 0);
        self::assertSame([], Storage::disk('public')->allFiles('erp/login-site-logos'));
    }

    public function test_only_system_admin_can_submit_login_settings_and_scan_only_company_cannot_be_archived(): void
    {
        $request = $this->browser();
        $request->merge($this->enterpriseInputs());
        Admin::guard()->setUser($this->account());
        $controller = app(DingTalkCompanyController::class);
        $this->denied(fn () => $controller->save($request), 403);

        Admin::guard()->setUser($this->account(true));
        $request->merge([
            'password_enabled' => '0', 'dingtalk_enabled' => '1', 'id' => $this->company->id,
            'corp_id' => $this->company->corp_id, 'app_key' => $this->company->app_key, 'enabled' => '1',
        ]);
        $controller->save($request);
        try {
            $controller->destroy($this->company->id);
            self::fail('The only sign-in method was archived');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('enabled', $exception->errors());
        }

        $request->merge(['password_enabled' => '1']);
        $controller->save($request);
        $controller->destroy($this->company->id);
        $this->assertSoftDeleted('dingtalk_companies', ['id' => $this->company->id]);
        self::assertFalse(ErpLoginSite::query()->where('domain', 'a.test')->firstOrFail()->dingtalk_enabled);
    }

    public function test_secrets_are_encrypted_hidden_and_blank_edits_keep_existing_values(): void
    {
        self::assertNotSame('secret-test',DB::table('dingtalk_companies')->value('app_secret'));
        self::assertArrayNotHasKey('app_secret',$this->company->toArray());
        $request=$this->browser(); $request->merge(['id'=>$this->company->id,'login_slug'=>'test-company','name'=>'Renamed company','corp_id'=>$this->company->corp_id,'app_key'=>'app-test',
            'redirect_uri'=>$this->company->redirect_uri,'enabled'=>true,'app_secret'=>'']);
        app(DingTalkCompanyController::class)->save($request);
        self::assertSame('secret-test',$this->company->fresh()->app_secret); self::assertSame(1,$this->company->fresh()->version);
        $request->merge(['app_secret'=>'new-secret']); app(DingTalkCompanyController::class)->save($request);
        self::assertSame(2,$this->company->fresh()->version); self::assertSame('new-secret',$this->company->fresh()->app_secret);
    }
    public function test_archiving_disables_company_without_deleting_its_configuration(): void
    {
        $request=$this->browser(); app(DingTalkCompanyController::class)->destroy($this->company->id);
        self::assertSoftDeleted('dingtalk_companies',['id'=>$this->company->id]);
        $this->denied(fn()=>app(CompanyService::class)->enabled($this->company->id),403);
    }
    public function test_directory_sync_deduplicates_members_across_pages_and_departments_and_publishes_only_when_complete(): void
    {
        Http::swap(new \Illuminate\Http\Client\Factory()); Http::preventStrayRequests();
        Http::fake(function ($request) {
            $path=parse_url($request->url(),PHP_URL_PATH);
            if ($path==='/v1.0/oauth2/accessToken') return Http::response(['accessToken'=>'token','expireIn'=>7200]);
            if ($path==='/topapi/v2/department/listsub') return Http::response(['errcode'=>0,'result'=>(int)$request['dept_id']===1 ? [['dept_id'=>2,'name'=>'Child']] : []]);
            if ($path==='/topapi/v2/user/list') {
                $ids=(int)$request['dept_id']===2 ? ['u3','u4'] : ((int)$request['cursor']===0 ? ['u1','u2'] : ['u1','u3']);
                return Http::response(['errcode'=>0,'result'=>['list'=>array_map(fn ($id)=>['userid'=>$id,'name'=>$id],$ids),'has_more'=>(int)$request['dept_id']===1 && (int)$request['cursor']===0,'next_cursor'=>100]]);
            }
            throw new \LogicException('Unexpected API');
        });
        $directory=app(DirectoryService::class);
        self::assertFalse($directory->syncStep($this->company)['done']); self::assertNull($this->company->fresh()->member_count);
        self::assertFalse($directory->syncStep($this->company)['done']); self::assertNull($this->company->fresh()->member_count);
        self::assertTrue($directory->syncStep($this->company)['done']); self::assertSame(4,(int)$this->company->fresh()->member_count);
        self::assertSame(4,DB::table('dingtalk_directory_members')->where('company_id',$this->company->id)->count());
        self::assertNull($this->company->fresh()->directory_state);
    }
}
