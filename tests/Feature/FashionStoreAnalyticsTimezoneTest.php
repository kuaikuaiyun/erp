<?php

namespace Tests\Feature;

use App\Portals\Assistant\Http\Controllers\FashionStoreAnalyticsController;
use Illuminate\Http\Request;
use ReflectionMethod;
use Tests\TestCase;

class FashionStoreAnalyticsTimezoneTest extends TestCase
{
    public function test_fixed_offset_timezone_is_preserved_by_filters(): void
    {
        $controller = app(FashionStoreAnalyticsController::class);
        $method = new ReflectionMethod($controller, 'filters');
        $filters = $method->invoke($controller, Request::create('/assistant/fashion-store-analytics', 'GET', [
            'timezone' => 'Etc/GMT+6',
            'from_date' => '2026-11-17',
            'to_date' => '2026-11-17',
        ]));

        self::assertSame('Etc/GMT+6', $filters['timezone']);
        self::assertSame('2026-11-17', $filters['from_date']);
        self::assertSame('2026-11-17', $filters['to_date']);
    }

    public function test_unknown_timezone_falls_back_to_shanghai(): void
    {
        $controller = app(FashionStoreAnalyticsController::class);
        $method = new ReflectionMethod($controller, 'filters');
        $filters = $method->invoke($controller, Request::create('/assistant/fashion-store-analytics', 'GET', [
            'timezone' => 'invalid/timezone',
        ]));

        self::assertSame('Asia/Shanghai', $filters['timezone']);
    }
}
