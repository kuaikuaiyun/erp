<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

class PaymentProductAlertPresentationTest extends TestCase
{
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
