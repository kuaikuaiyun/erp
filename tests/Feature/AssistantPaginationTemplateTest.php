<?php

namespace Tests\Feature;

use Illuminate\Pagination\LengthAwarePaginator;
use Tests\TestCase;

class AssistantPaginationTemplateTest extends TestCase
{
    public function test_page_size_selector_can_change_the_shared_assistant_pagination_size(): void
    {
        $paginator = new LengthAwarePaginator(
            range(1, 20),
            200,
            20,
            1,
            ['path' => '/assistant/example'],
        );

        $html = view('portals.assistant.partials.advertising-template-pagination', [
            'paginator' => $paginator,
            'unit' => 'records',
        ])->render();

        $this->assertStringContainsString('data-assistant-page-size', $html);
        $this->assertStringContainsString('data-page-name="page"', $html);
        $this->assertStringNotContainsString('onchange=', $html);
        $this->assertStringNotContainsString('url.searchParams.delete("page")', $html);
    }
}
