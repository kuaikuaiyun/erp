<?php

namespace Tests\Feature;

use App\Portals\OperationTasks\Support\OperationTaskTime;
use Carbon\Carbon;
use Tests\TestCase;

class OperationTaskFiltersAndTimezoneTest extends TestCase
{
    public function test_beijing_display_crosses_the_date_boundary_without_mutating_the_source(): void
    {
        $utc = Carbon::parse('2026-11-12 17:00:00', 'UTC');
        self::assertSame('2026-11-13 01:00', OperationTaskTime::format($utc));
        self::assertSame('2026-11-12 17:00', $utc->format('Y-m-d H:i'));
        self::assertSame('2026-11-13 00:00', OperationTaskTime::format('2026-11-12 16:00:00'));
    }

    public function test_search_fields_keep_free_text_and_escape_values_and_suggestions(): void
    {
        app()->setLocale('zh-CN');
        $this->app['request']->query->set('team_search', '服装组');
        $this->app['request']->query->set('person_search', '<script>alert(1)</script>');
        $html = view('portals.operation-tasks.partials.search-filters', [
            'searchTeamOptions' => ['服装一组'], 'searchPersonOptions' => ['张三', '<script>'],
        ])->render();
        self::assertStringContainsString('value="服装组"', $html);
        self::assertStringContainsString('data-value="服装一组"', $html);
        self::assertStringContainsString('data-value="张三"', $html);
        self::assertStringContainsString('&lt;script&gt;', $html);
        self::assertStringNotContainsString('<script>alert(1)</script>', $html);
        self::assertSame(2, substr_count($html, 'data-clear-search'));
        self::assertStringContainsString('aria-controls="ot-options-person_search"', $html);
        self::assertStringNotContainsString('<datalist', $html);
    }
    public function test_operation_task_templates_compile_to_valid_php(): void
    {
        $compiler = app('blade.compiler');
        foreach (['tests', 'reviews', 'dashboard', 'recaps', 'partials.search-filters', 'partials.date-filters', 'partials.delete-confirm', 'test-detail'] as $view) {
            $source = file_get_contents(resource_path('views/portals/operation-tasks/'.str_replace('.', '/', $view).'.blade.php'));
            $compiled = $compiler->compileString($source);
            self::assertNotEmpty(token_get_all($compiled, TOKEN_PARSE), $view);
        }
    }

}
