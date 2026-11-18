<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\AssetCodexRenewal;
use App\Models\AssetResource;
use App\Portals\Assistant\AssistantDailySubmissionPendingCountService;
use App\Portals\Assistant\AssistantAdvertisingReviewPendingCountService;
use App\Models\AssetComputerEquipment;
use App\Models\AssetIpfoxyAccount;
use App\Models\AssetIpfoxyProxy;
use App\Models\Department;
use App\Models\Portal;
use App\Portal\Http\Controllers\PortalEntryController;
use App\Portals\AssetManager\AssetManagerService;
use App\Portals\AssetManager\AssetManagerSpreadsheetService;
use App\Portals\AssetManager\IpfoxyService;
use App\Services\AdminWatermarkService;
use App\Services\SystemSettingService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

class AssetManagerTest extends TestCase
{
    private AdminUser $actor;

    private Portal $portal;

    private Portal $assistant;

    private ?Portal $forcedPortal = null;

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
            foreach (['code', 'title', 'title_key', 'path', 'permission_slug', 'icon'] as $k) {
                $t->string($k)->nullable();
            }$t->integer('sort');
            $t->boolean('is_enabled');
            $t->timestamps();
        });
        Schema::create('admin_permissions', function (Blueprint $t) {
            $t->id();
            foreach (['name', 'slug', 'http_method', 'http_path'] as $k) {
                $t->string($k);
            }$t->timestamps();
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
            $t->integer('status')->default(1);
            $t->timestamps();
        });
        $this->portal = Portal::create(['code' => 'asset-manager', 'name' => 'Assets', 'status' => 'enabled']);
        (require base_path('database/migrations/2026_11_17_000200_install_asset_manager.php'))->up();
        $this->assistant = Portal::create(['code' => 'zhuli', 'name' => 'Assistant', 'status' => 'enabled']);
        (require base_path('database/migrations/2026_11_18_000100_move_asset_pages_to_assistant.php'))->up();
        Schema::create('admin_role_users', function (Blueprint $t) {
            $t->unsignedBigInteger('role_id');
            $t->unsignedBigInteger('user_id');
            $t->string('position')->default('member');
            $t->string('position_slot')->nullable();
            $t->boolean('is_primary')->default(false);
            $t->timestamps();
        });
        $this->actor = new class extends AdminUser
        {
            public bool $manage = true;
            public bool $view = true;
            public bool $administrator = true;

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
        $this->actor->name = 'Tester';
        $entries = \Mockery::mock(PortalEntryController::class);
        $entries->shouldReceive('portalContext')->andReturnUsing(fn (Request $request) => ['portal' => ($this->forcedPortal ?? (str_starts_with($request->path(), 'assistant/assets') ? $this->assistant : $this->portal))->unsetRelation('menus')->load('menus'), 'user' => $this->actor]);
        $this->app->instance(PortalEntryController::class, $entries);
        $this->mock(AssistantDailySubmissionPendingCountService::class)->shouldReceive('forUser')->andReturn([]);
        $this->mock(AssistantAdvertisingReviewPendingCountService::class)->shouldReceive('forUser')->andReturn([]);
        $this->mock(AdminWatermarkService::class)->shouldReceive('scriptForAdministrator')->andReturn(null);
        $this->mock(SystemSettingService::class)->shouldReceive('footer')->andReturn(['copyright_text' => '', 'contact_information' => '']);
    }

    public function test_four_pages_render_and_only_ipfoxy_has_no_import(): void
    {
        foreach (['computers', 'resources', 'codex-renewals', 'ipfoxy'] as $kind) {
            $this->get(($kind === 'computers' ? '/asset-manager/' : '/assistant/assets/').$kind)->assertOk()->assertSee('asset-manager.js');
        }$this->postJson('/assistant/assets/ipfoxy/import')->assertStatus(405);
    }

    public function test_crud_and_write_permission_and_disabled_menu(): void
    {
        $this->postJson('/asset-manager/computers', ['brand' => 'Brand', 'monthly_rent' => '10.10'])->assertOk();
        $id = AssetComputerEquipment::first()->id;
        $this->getJson('/asset-manager/computers/'.$id)->assertOk()->assertJsonPath('monthly_rent', '10.10');
        $this->putJson('/asset-manager/computers/'.$id, ['monthly_rent' => '20.20'])->assertOk();
        $this->actor->manage = false;
        $this->deleteJson('/asset-manager/computers/'.$id)->assertForbidden();
        $this->actor->manage = true;
        $this->deleteJson('/asset-manager/computers/'.$id)->assertOk();
        $this->assertSame(0, AssetComputerEquipment::count());
        DB::table('portal_menus')->where('code', 'asset_manager_computers')->update(['is_enabled' => false]);
        $this->get('/asset-manager/computers')->assertForbidden();
    }

    public function test_china_day_boundaries_and_all_filtered_rent(): void
    {
        foreach ([['2026-11-16 15:59:59', '1.00'], ['2026-11-16 16:00:00', '10.10'], ['2026-11-17 15:59:59', '20.20'], ['2026-11-17 16:00:00', '100.00']] as [$at,$rent]) {
            AssetComputerEquipment::create(['created_at' => $at, 'monthly_rent' => $rent]);
        }$s = app(AssetManagerService::class);
        $q = $s->query('computers', Request::create('/', 'GET', ['from' => '2026-11-17', 'to' => '2026-11-17']));
        $this->assertSame(2, $q->count());
        $this->assertSame('30.30', $s->cards('computers', $q)['rent_total']);
    }

    public function test_renewal_calendar_boundaries(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-17 12:00:00', 'Asia/Shanghai'));
        try {
            foreach (['2026-11-16', '2026-11-17', '2026-11-18', '2026-11-24', '2026-11-25', null] as $date) {
                AssetCodexRenewal::create(['renewal_date' => $date]);
            }$cards = app(AssetManagerService::class)->cards('codex-renewals', AssetCodexRenewal::query());
            $this->assertSame(1, $cards['expired']);
            $this->assertSame(1, $cards['today']);
            $this->assertSame(2, $cards['upcoming']);
            $this->assertSame(1, $cards['unset']);
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_department_choices_include_children_and_childless_roots_only(): void
    {
        $root = Department::create(['name' => 'Root', 'slug' => 'root']);
        $child = Department::create(['name' => 'Child', 'slug' => 'child', 'parent_id' => $root->id]);
        $leaf = Department::create(['name' => 'Leaf', 'slug' => 'leaf']);
        Department::create(['name' => 'Administrator', 'slug' => 'administrator']);
        $ids = array_keys(app(AssetManagerService::class)->departments());
        $this->assertEqualsCanonicalizing([$child->id, $leaf->id], $ids);
    }

    public function test_import_preview_commit_replay_and_excel_export(): void
    {
        $headers = app(AssetManagerSpreadsheetService::class)->headers('computers');
        $csv = fopen('php://temp', 'r+');
        fputcsv($csv, array_values($headers));
        fputcsv($csv, ['', 'Laptop', 'Brand', '00123', '10.25', '', '', '', '', '', '2026-11-17 08:00:00']);
        rewind($csv);
        $file = UploadedFile::fake()->createWithContent('assets.csv', stream_get_contents($csv));
        fclose($csv);
        $r = $this->postJson('/asset-manager/computers/import/preview', ['file' => $file])->assertOk()->assertJsonPath('valid', 1)->assertJsonPath('errors', []);
        $token = $r->json('token');
        $this->postJson('/asset-manager/computers/import', ['token' => $token])->assertOk();
        $this->assertSame('00123', AssetComputerEquipment::first()->serial_number);
        $this->assertSame('2026-11-17 00:00:00', AssetComputerEquipment::first()->created_at->format('Y-m-d H:i:s'));
        $this->postJson('/asset-manager/computers/import', ['token' => $token])->assertUnprocessable();
        $this->get('/asset-manager/computers/export')->assertOk()->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    }

    public function test_ipfoxy_pagination_atomicity_idempotency_and_failure_retention(): void
    {
        Http::preventStrayRequests();
        $a = AssetIpfoxyAccount::create(['label' => 'Account', 'api_id' => 'api-id', 'api_token' => 'secret']);
        $this->assertStringNotContainsString('secret', DB::table('asset_ipfoxy_accounts')->value('api_token'));
        $this->assertArrayNotHasKey('api_token', $a->toArray());
        $page = 0;
        Http::fake(function ($request) use (&$page) {
            $this->assertSame('secret', $request->header('api-token')[0]);
            $page++;

            return Http::response(['code' => 0, 'data' => ['list' => [['id' => $page === 1 ? 'one' : 'two', 'host' => '127.0.0.1', 'port' => '80', 'type' => 'http', 'country_code' => 'US', 'expire_time' => 1800000000]], 'total' => 2]]);
        });
        $s = app(IpfoxyService::class);
        $run = $s->begin($a, 1);
        $this->assertFalse($s->step($run['id'], 1)['done']);
        $this->assertSame(0, AssetIpfoxyProxy::count());
        $this->assertTrue($s->step($run['id'], 1)['done']);
        $this->assertSame(2, AssetIpfoxyProxy::count());
        $page = 0;
        $run = $s->begin($a, 1);
        $s->step($run['id'], 1);
        $s->step($run['id'], 1);
        $this->assertSame(2, AssetIpfoxyProxy::count());
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(fn () => Http::response(['code' => 60401], 401));
        $run = $s->begin($a, 1);
        try {
            $s->step($run['id'], 1);
            $this->fail('Expected sync failure');
        } catch (ValidationException) {
            $this->assertSame(2, AssetIpfoxyProxy::where('missing', false)->count());
            $this->assertFalse(Cache::has('asset-ipfoxy-busy:'.$a->id));
        }
    }

    public function test_server_pagination_limits_rendered_rows(): void
    {
        for ($i = 0; $i < 25; $i++) {
            AssetComputerEquipment::create(['serial_number' => 'SN-'.$i]);
        }$response = $this->get('/asset-manager/computers?per_page=20')->assertOk();
        $response->assertViewHas('rows', fn ($rows) => $rows->count() === 20 && $rows->total() === 25);
    }

    public function test_bad_money_and_dates_are_rejected_without_writes(): void
    {
        $this->postJson('/asset-manager/computers', ['monthly_rent' => '-1'])->assertUnprocessable();
        $this->postJson('/assistant/assets/codex-renewals', ['renewal_date' => '2026-02-30'])->assertUnprocessable();
        $this->assertSame(0, AssetComputerEquipment::count());
    }

    public function test_export_is_real_excel_keeps_identifiers_and_literals(): void
    {
        AssetComputerEquipment::create(['serial_number' => '00123', 'category' => '=1+1', 'monthly_rent' => '0.00']);
        $response = $this->get('/asset-manager/computers/export')->assertOk();
        $file = tempnam(sys_get_temp_dir(), 'asset-export-');
        try {
            file_put_contents($file, $response->streamedContent());
            $book = IOFactory::load($file);
            $sheet = $book->getSheet(0);
            $this->assertSame('00123', (string) $sheet->getCell('D2')->getValue());
            $this->assertSame('=1+1', (string) $sheet->getCell('B2')->getValue());
            $this->assertNotSame('f', $sheet->getCell('B2')->getDataType());
            $this->assertSame('0.00', (string) $sheet->getCell('E2')->getValue());
            $book->disconnectWorksheets();
        } finally {
            unlink($file);
        }
    }

    public function test_invalid_import_does_not_allow_commit(): void
    {
        $headers = app(AssetManagerSpreadsheetService::class)->headers('computers');
        $csv = fopen('php://temp', 'r+');
        fputcsv($csv, array_values($headers));
        fputcsv($csv, ['', 'Laptop', 'Brand', '001', '-10', '', '', '', '', '', '']);
        rewind($csv);
        $file = UploadedFile::fake()->createWithContent('invalid.csv', stream_get_contents($csv));
        fclose($csv);
        $response = $this->postJson('/asset-manager/computers/import/preview',['file' => $file])->assertOk();
        $this->assertCount(1,$response->json('errors'));
        $this->postJson('/asset-manager/computers/import',['token' => $response->json('token')])->assertUnprocessable();
        $this->assertSame(0,AssetComputerEquipment::count());
    }

    private function departmentsForIsolation(): array
    {
        $root = Department::create(['name' => 'Assistant', 'slug' => 'dept-000004']);
        $a = Department::create(['name' => 'Team A', 'slug' => 'assistant-a', 'parent_id' => $root->id]);
        $b = Department::create(['name' => 'Team B', 'slug' => 'assistant-b', 'parent_id' => $root->id]);

        return [$root, $a, $b];
    }

    private function joinDepartment(Department $department, string $position = 'member'): void
    {
        $this->actor->administrator = false;
        DB::table('admin_role_users')->where('user_id', $this->actor->id)->delete();
        DB::table('admin_role_users')->insert(['role_id' => $department->id, 'user_id' => $this->actor->id, 'position' => $position]);
        $this->actor->unsetRelation('roles')->unsetRelation('departments');
    }

    public function test_migration_moves_only_three_menus_and_is_repeatable(): void
    {
        $this->assertSame(['asset_manager_computers'], $this->portal->menus()->pluck('code')->all());
        $this->assertCount(3, $this->assistant->menus);
        $migration = require base_path('database/migrations/2026_11_18_000100_move_asset_pages_to_assistant.php');
        $sorts = $this->assistant->menus()->pluck('sort', 'id')->all();
        $migration->up();
        $this->assertSame($sorts, $this->assistant->menus()->pluck('sort', 'id')->all());
        $this->assertSame(3, $this->assistant->menus()->count());
        foreach (['asset_resources', 'asset_codex_renewals', 'asset_ipfoxy_accounts'] as $table) {
            $this->assertTrue(Schema::hasColumn($table, 'owner_department_id'));
        }
        $migration->down();
        $this->assertSame(4, $this->portal->menus()->count());
        $this->assertTrue(Schema::hasColumn('asset_resources', 'owner_department_id'));
        $migration->up();
        $this->assertSame(3, $this->assistant->menus()->count());
    }

    public function test_old_pages_and_interfaces_are_retired(): void
    {
        foreach (['resources', 'codex-renewals', 'ipfoxy'] as $kind) {
            $this->get('/asset-manager/'.$kind)->assertNotFound();
            $this->get('/asset-manager/'.$kind.'/export')->assertNotFound();
        }
        $this->postJson('/asset-manager/resources', [])->assertNotFound();
        $this->putJson('/asset-manager/codex-renewals/1', [])->assertNotFound();
        $this->postJson('/asset-manager/ipfoxy/accounts/1/sync', [])->assertNotFound();
        $this->get('/asset-manager/computers')->assertOk();
    }

    public function test_resource_and_codex_crud_statistics_and_exports_are_department_scoped(): void
    {
        [$root, $a, $b] = $this->departmentsForIsolation();
        $this->joinDepartment($a);
        foreach (['resources' => AssetResource::class, 'codex-renewals' => AssetCodexRenewal::class] as $kind => $class) {
            $mine = $class::create(['project' => 'visible-A', 'owner_department_id' => $a->id]);
            $other = $class::create(['project' => 'secret-B', 'owner_department_id' => $b->id]);
            $class::create(['project' => 'unassigned']);
            $url = '/assistant/assets/'.$kind;
            $this->get($url)->assertOk()->assertSee('visible-A')->assertDontSee('secret-B')->assertDontSee('unassigned')
                ->assertViewHas('rows', fn ($rows) => $rows->total() === 1);
            if ($kind === 'codex-renewals') {
                $this->get($url)->assertViewHas('cards', fn ($cards) => $cards['total_assets'] === 1 && $cards['unset'] === 1);
            }
            $this->getJson($url.'/'.$other->id)->assertNotFound();
            $this->putJson($url.'/'.$other->id, ['project' => 'changed'])->assertNotFound();
            $this->deleteJson($url.'/'.$other->id)->assertNotFound();
            $this->putJson($url.'/'.$mine->id, ['owner_department_id' => $b->id])->assertUnprocessable();
            $this->postJson($url, ['owner_department_id' => $b->id])->assertUnprocessable();
            $this->postJson($url, ['project' => 'new-A', 'department_id' => $b->id])->assertOk();
            $created = $class::orderByDesc('id')->first();
            $this->assertSame($a->id, (int) $created->owner_department_id);
            $this->assertSame($b->id, (int) $created->department_id);
            $this->get($url.'?filter[owner_department_id]='.$b->id)->assertViewHas('rows', fn ($rows) => $rows->total() === 0);
            $export = $this->get($url.'/export?selected[]='.$other->id.'&selected[]='.$mine->id)->assertOk();
            $file = tempnam(sys_get_temp_dir(), 'asset-scope-');
            try {
                file_put_contents($file, $export->streamedContent());
                $book = IOFactory::load($file);
                $values = json_encode($book->getSheet(0)->toArray());
                $this->assertStringContainsString('visible-A', $values);
                $this->assertStringNotContainsString('secret-B', $values);
                $book->disconnectWorksheets();
            } finally {
                unlink($file);
            }
            $this->putJson($url.'/'.$mine->id, ['project' => 'updated-A'])->assertOk();
            $this->deleteJson($url.'/'.$mine->id)->assertOk();
        }
    }

    public function test_management_visibility_requires_existing_assistant_role(): void
    {
        [$root, $a, $b] = $this->departmentsForIsolation();
        AssetResource::create(['project' => 'visible-to-manager', 'owner_department_id' => $b->id]);
        AssetResource::create(['project' => 'unassigned-to-manager']);
        foreach (['manager', 'deputy'] as $position) {
            $this->joinDepartment($root, $position);
            $this->get('/assistant/assets/resources')->assertOk()->assertViewHas('rows', fn ($rows) => $rows->total() === 2);
            $this->postJson('/assistant/assets/resources', ['project' => 'missing-owner'])->assertUnprocessable();
        }
        $this->joinDepartment($root, 'member');
        $this->get('/assistant/assets/resources')->assertForbidden();
        $outside = Department::create(['name' => 'Other', 'slug' => 'outside']);
        $this->joinDepartment($outside);
        $this->get('/assistant/assets/resources')->assertForbidden();
        $this->joinDepartment($a);
        $this->actor->manage = false;
        $this->get('/assistant/assets/resources')->assertOk();
        $this->postJson('/assistant/assets/resources', [])->assertForbidden();
        DB::table('portal_menus')->where('code', 'asset_manager_resources')->update(['is_enabled' => false]);
        $this->get('/assistant/assets/resources')->assertForbidden();
    }

    public function test_import_ownership_is_cached_and_rechecked_after_department_changes(): void
    {
        [$root, $a, $b] = $this->departmentsForIsolation();
        $this->joinDepartment($a);
        $foreign = AssetResource::create(['owner_department_id' => $b->id, 'project' => 'foreign']);
        $headers = app(AssetManagerSpreadsheetService::class)->headers('resources');
        $csv = fopen('php://temp', 'r+');
        fputcsv($csv, array_values($headers));
        fputcsv($csv, [$foreign->id, 'import-project', '', 'import-resource', '', '', '', '', '', '']);
        rewind($csv);
        $content = stream_get_contents($csv);
        fclose($csv);
        $url = '/assistant/assets/resources';
        $this->postJson($url.'/import/preview', ['owner_department_id' => $b->id, 'file' => UploadedFile::fake()->createWithContent('resources.csv', $content)])->assertUnprocessable();
        $preview = $this->postJson($url.'/import/preview', ['file' => UploadedFile::fake()->createWithContent('resources.csv', $content)])
            ->assertOk()->assertJsonPath('valid', 1)->assertJsonPath('skipped', 0);
        $token = $preview->json('token');
        $this->joinDepartment($b);
        $this->postJson($url.'/import', ['token' => $token])->assertUnprocessable();
        $this->joinDepartment($a);
        $this->postJson($url.'/import', ['token' => $token, 'owner_department_id' => $b->id])->assertOk();
        $created = AssetResource::orderByDesc('id')->first();
        $this->assertSame($a->id, (int) $created->owner_department_id);
        $this->assertSame('import-project', $created->project);
        $this->postJson($url.'/import', ['token' => $token])->assertUnprocessable();
    }

    public function test_ipfoxy_accounts_proxies_exports_and_each_sync_step_are_scoped(): void
    {
        Http::preventStrayRequests();
        [$root, $a, $b] = $this->departmentsForIsolation();
        $mine = AssetIpfoxyAccount::create(['owner_department_id' => $a->id, 'label' => 'A-account', 'api_id' => 'a-api', 'api_token' => 'secret-a']);
        $other = AssetIpfoxyAccount::create(['owner_department_id' => $b->id, 'label' => 'B-secret-account', 'api_id' => 'b-api', 'api_token' => 'secret-b']);
        foreach ([$mine, $other] as $account) {
            AssetIpfoxyProxy::create(['account_id' => $account->id, 'proxy_id' => 'proxy-'.$account->id, 'host' => $account === $mine ? 'A-host' : 'B-secret-host']);
        }
        $this->joinDepartment($a);
        $url = '/assistant/assets/ipfoxy';
        $this->get($url)->assertOk()->assertSee('A-account')->assertDontSee('B-secret-account')->assertDontSee('B-secret-host')->assertDontSee('secret-a')
            ->assertViewHas('rows', fn ($rows) => $rows->total() === 1)->assertViewHas('accounts', fn ($accounts) => $accounts->count() === 1);
        $this->putJson($url.'/accounts/'.$other->id, [])->assertNotFound();
        $this->deleteJson($url.'/accounts/'.$other->id)->assertNotFound();
        $this->postJson($url.'/accounts/'.$other->id.'/sync', [])->assertNotFound();
        $this->putJson($url.'/accounts/'.$mine->id, ['owner_department_id' => $b->id])->assertUnprocessable();
        $this->postJson($url.'/accounts', ['label' => 'new-A', 'api_id' => 'new-api', 'api_token' => 'new-secret'])->assertOk();
        $this->assertSame($a->id, (int) AssetIpfoxyAccount::orderByDesc('id')->first()->owner_department_id);
        $run = $this->postJson($url.'/accounts/'.$mine->id.'/sync', [])->assertOk()->json('id');
        $this->joinDepartment($b);
        $this->postJson($url.'/sync/'.$run, [])->assertNotFound();
        Http::assertNothingSent();
        $this->joinDepartment($a);
        Http::fake(['apis.ipfoxy.com/*' => Http::response(['code' => 0, 'data' => ['list' => [], 'total' => 0]])]);
        $this->postJson($url.'/sync/'.$run, [])->assertOk()->assertJsonPath('done', true);
        $this->putJson($url.'/accounts/'.$mine->id, ['label' => 'A-account-edited', 'api_id' => 'a-api', 'api_token' => ''])->assertOk();
        $this->assertSame('secret-a', $mine->fresh()->api_token);
        $export = $this->get($url.'/export')->assertOk();
        $file = tempnam(sys_get_temp_dir(), 'proxy-export-');
        try {
            file_put_contents($file, $export->streamedContent());
            $book = IOFactory::load($file);
            $this->assertStringNotContainsString('B-secret', json_encode($book->getSheet(0)->toArray()));
            $book->disconnectWorksheets();
        } finally {
            unlink($file);
        }
        $this->deleteJson($url.'/accounts/'.$mine->id)->assertOk();
        $this->assertSame(0, AssetIpfoxyProxy::where('account_id', $mine->id)->count());
        $this->assertNotNull($other->fresh());
    }

    public function test_portal_origin_and_view_permissions_cannot_be_bypassed(): void
    {
        $this->forcedPortal = $this->portal;
        $this->get('/assistant/assets/resources')->assertNotFound();
        $this->forcedPortal = $this->assistant;
        $this->get('/asset-manager/computers')->assertNotFound();
        $this->forcedPortal = null;
        [$root, $a, $b] = $this->departmentsForIsolation();
        $this->joinDepartment($a);
        $this->actor->view = false;
        $this->get('/assistant/assets/resources')->assertForbidden();
        $this->get('/assistant/assets/resources/export')->assertForbidden();
        $this->postJson('/assistant/assets/resources', [])->assertForbidden();
        $this->actor->view = true;
        DB::table('admin_role_users')->insert(['role_id' => $b->id, 'user_id' => $this->actor->id, 'position' => 'member']);
        $this->get('/assistant/assets/resources')->assertForbidden();
    }
}
