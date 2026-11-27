<?php

namespace Tests\Feature;

use App\Portals\Payment\Http\Controllers\PaymentPortalController;
use App\Portals\Payment\PaymentProductAlertService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Blade;
use ReflectionMethod;
use Tests\TestCase;

class PaymentProductAlertPresentationTest extends TestCase
{
    public function test_alert_filters_accept_multiple_choices_and_match_across_fields(): void
    {
        $request = Request::create('/product-alerts', 'GET', [
            'flow_status' => 'all',
            'processing_status' => ['stopped', 'observing', 'observing', 'invalid'],
            'channel' => 'paypal',
            'severity' => ['critical', 'warning'],
            'sample' => 'all',
        ]);
        $parse = new ReflectionMethod(PaymentPortalController::class, 'productAlertFilters');
        $filters = $parse->invoke(app(PaymentPortalController::class), $request);

        self::assertSame(['stopped', 'observing'], $filters['processing_status']);
        self::assertSame(['paypal'], $filters['channel']);
        self::assertSame(['warning', 'critical'], $filters['severity']);
        self::assertSame('all', $filters['sample']);

        $base = ['workflow_status_key' => 'processing', 'dispute_amount' => 0, 'dispute_rate' => 0];
        $alerts = collect([
            [...$base, 'code' => 'PW-P-1', 'status_key' => 'stopped', 'channel_type' => 'paypal', 'severity_key' => 'critical', 'eligible_order_count' => 10],
            [...$base, 'code' => 'PW-P-2', 'status_key' => 'observing', 'channel_type' => 'paypal', 'severity_key' => 'warning', 'eligible_order_count' => 680],
            [...$base, 'code' => 'PW-C-3', 'status_key' => 'observing', 'channel_type' => 'credit_card', 'severity_key' => 'warning', 'eligible_order_count' => 90],
            [...$base, 'code' => 'PW-P-4', 'status_key' => 'other', 'channel_type' => 'paypal', 'severity_key' => 'warning', 'eligible_order_count' => 90],
        ]);
        $filter = new ReflectionMethod(PaymentProductAlertService::class, 'filterRows');
        $rows = $filter->invoke(app(PaymentProductAlertService::class), $alerts, $filters);

        self::assertSame(['PW-P-1', 'PW-P-2'], $rows->pluck('code')->all());
    }

    public function test_product_information_renders_image_pcl_and_escaped_name_in_order(): void
    {
        app()->setLocale('zh-CN');
        $source = file_get_contents(resource_path('views/portals/payment/product-alerts.blade.php'));
        $start = strpos($source, '<td><div class="payment-alert-product-information">');
        $fragment = substr($source, $start, strpos($source, '</td>', $start) + 5 - $start);
        $alert = ['sku' => 'PCL-001', 'product_name' => '<测试产品>', 'product_image_url' => 'https://example.com/product.jpg'];
        $html = Blade::render($fragment, ['alert' => $alert, 'translationPrefix' => 'payment.product_alerts']);
        self::assertStringContainsString('loading="lazy"', $html);
        self::assertStringContainsString('&lt;测试产品&gt;', $html);
        self::assertLessThan(strpos($html, 'PCL-001'), strpos($html, '<img'));
        $alert['product_image_url'] = null;
        $html = Blade::render($fragment, ['alert' => $alert, 'translationPrefix' => 'payment.product_alerts']);
        self::assertStringContainsString('暂无图片', $html);
        self::assertStringNotContainsString('<img', $html);
    }
}
