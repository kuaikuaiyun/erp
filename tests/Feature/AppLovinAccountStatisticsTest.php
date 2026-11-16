<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\Department;
use App\Models\Portal;
use App\Portal\Http\Controllers\PortalEntryController;
use App\Portals\Assistant\AssistantStoreOwnerService;
use App\Portals\Assistant\Http\Controllers\AppLovinAccountStatisticsController;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\TestCase;

class AppLovinAccountStatisticsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-16 02:00:00', 'UTC'));
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:', 'admin.database.connection' => null]);
        app('db')->purge('sqlite');
        Schema::create('admin_users', function (Blueprint $table): void {
            $table->id(); $table->string('name'); $table->string('username'); $table->date('hired_at')->nullable();
        });
        Schema::create('admin_roles', function (Blueprint $table): void {
            $table->id(); $table->string('name'); $table->string('slug'); $table->unsignedBigInteger('parent_id')->nullable(); $table->integer('sort')->default(0);
        });
        Schema::create('operation_reports', function (Blueprint $table): void {
            $table->id(); $table->date('report_date'); $table->unsignedBigInteger('operator_user_id');
            $table->string('operator_name'); $table->unsignedBigInteger('operation_department_id');
            $table->string('operation_department_name'); $table->string('status'); $table->text('advertising_rows'); $table->softDeletes();
        });
        Schema::create('applovin_account_store_bindings', function (Blueprint $table): void {
            $table->id(); $table->string('account_key'); $table->unsignedBigInteger('assistant_domain_store_id')->nullable();
        });
        Schema::create('assistant_domain_stores', function (Blueprint $table): void {
            $table->id(); $table->string('domain');
        });
        DB::table('admin_roles')->insert(['id' => 1, 'name' => 'Team', 'slug' => 'team']);
        for ($id = 1; $id <= 25; $id++) {
            DB::table('admin_users')->insert(['id' => $id, 'name' => 'Member '.$id, 'username' => 'member'.$id,
                'hired_at' => match ($id) { 1 => '2026-11-16', 2 => '2026-11-07', 4 => '2026-11-17', default => null }]);
            DB::table('operation_reports')->insert([
                'report_date' => '2026-11-15', 'operator_user_id' => $id, 'operator_name' => 'Member '.$id,
                'operation_department_id' => 1, 'operation_department_name' => 'Team', 'status' => 'approved',
                'advertising_rows' => json_encode([['media_source' => 'AppLovin', 'account_number' => 'account'.$id, 'actual_spend' => $id * 10]]),
            ]);
        }
        $viewer = Mockery::mock(AdminUser::class)->makePartial();
        $viewer->id = 99;
        $viewer->shouldReceive('isAdministrator')->andReturn(true);
        $portal = new Portal(['code' => 'yunying']);
        $portal->setRelation('menus', new EloquentCollection());
        $entries = Mockery::mock(PortalEntryController::class);
        $entries->shouldReceive('portalContext')->andReturn(['user' => $viewer, 'portal' => $portal]);
        $this->app->instance(PortalEntryController::class, $entries);
        $owners = Mockery::mock(AssistantStoreOwnerService::class);
        $owners->shouldReceive('storeOwnershipOperationDepartments')->andReturn(collect([new Department(['id' => 1])]));
        $this->app->instance(AssistantStoreOwnerService::class, $owners);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function statistics(array $query = []): array
    {
        $request = Request::create('/account-statistics', 'GET', $query);
        $route = Route::get('/account-statistics', fn () => null)->name('operation.applovin-account-statistics.testing');
        $request->setRouteResolver(fn () => $route);
        $this->app->instance('request', $request);
        return app(AppLovinAccountStatisticsController::class)->index($request)->getData();
    }

    public function test_numeric_sorting_applies_before_pagination_and_keeps_filters(): void
    {
        $data = $this->statistics(['sort_by' => 'total_spend', 'sort_direction' => 'asc', 'page' => 2, 'operator' => 'Member', 'department_ids' => [1]]);
        $rows = $data['operators'];
        self::assertSame([21, 22, 23, 24, 25], $rows->getCollection()->pluck('operator_user_id')->all());
        self::assertSame(25, $rows->total());
        parse_str(parse_url($rows->previousPageUrl(), PHP_URL_QUERY), $query);
        self::assertSame('asc', $query['sort_direction']);
        self::assertSame('Member', $query['operator']);
        self::assertSame(['1'], $query['department_ids']);
        $descending = $this->statistics(['sort_by' => 'total_spend', 'sort_direction' => 'desc']);
        self::assertSame(25, $descending['operators']->getCollection()->first()['operator_user_id']);
    }

    public function test_hire_days_include_today_and_missing_dates_sort_last_in_both_directions(): void
    {
        $ascending = $this->statistics(['sort_by' => 'hire_days', 'sort_direction' => 'asc'])['operators']->getCollection();
        self::assertSame([0, 1, 10], $ascending->take(3)->pluck('hire_days')->all());
        self::assertNull($ascending->last()['hire_days']);
        $descending = $this->statistics(['sort_by' => 'hire_days', 'sort_direction' => 'desc'])['operators']->getCollection();
        self::assertSame([10, 1, 0], $descending->take(3)->pluck('hire_days')->all());
        self::assertNull($descending->last()['hire_days']);
    }

    public function test_names_use_natural_sorting_and_new_headers_render_with_sort_links(): void
    {
        $data = $this->statistics(['sort_by' => 'operator_name', 'sort_direction' => 'asc', 'page' => 1, 'operator' => 'Member']);
        self::assertSame(['Member 1', 'Member 2', 'Member 3'], $data['operators']->getCollection()->take(3)->pluck('operator_name')->all());
        $this->app->setLocale('zh-CN');
        // Render the actual list without the shared shell and its unrelated database dependencies.
        $source = file_get_contents(resource_path('views/portals/assistant/applovin-account-statistics/index.blade.php'));
        $source = preg_replace('/^@(extends|section\(\x27page_title)[^\r\n]*[\r\n]*/m', '', $source);
        $source = str_replace(["@section('content')", '@endsection'], '', $source);
        $html = Blade::render($source, $data);
        self::assertStringContainsString('入职天数', $html);
        self::assertStringContainsString('10 天', $html);
        $document = new \DOMDocument();
        @$document->loadHTML('<?xml encoding="UTF-8">'.$html);
        $links = (new \DOMXPath($document))->query('//a[contains(@class,"aas-sort")]');
        self::assertCount(8, $links);
        foreach ($links as $link) {
            parse_str(parse_url($link->getAttribute('href'), PHP_URL_QUERY), $query);
            self::assertSame('1', (string) $query['page']);
            self::assertSame('Member', $query['operator']);
            if ($query['sort_by'] === 'operator_name') self::assertSame('desc', $query['sort_direction']);
        }
    }
}
