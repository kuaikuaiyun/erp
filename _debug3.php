<?php
require __DIR__.'/vendor/autoload.php';
$app = require __DIR__.'/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

$statistics = app(\App\Portals\Logistics\LogisticsProductStatisticsService::class);

// 1. 看 page() 返回的第一个 product 的 id 是什么格式
$list = $statistics->page(['decision' => 'matched', 'require_tongtu_match' => true, 'per_page' => 3, 'page' => 1]);
echo "=== page() 返回前3个 products ===\n";
foreach ($list['products'] as $p) {
    echo "id={$p['id']}  name={$p['name']}  has_tongtu_match=" . ($p['has_tongtu_match']?'Y':'N') . "\n";
}

// 2. 用第一个 product 的 id 直接调 detailPage，不带任何 filters
$firstId = $list['products'][0]['id'] ?? null;
if ($firstId) {
    echo "\n=== 直接用 page() 返回的 product_id=$firstId 调 detailPage ===\n";
    $d = $statistics->detailPage([], $firstId, ['tab'=>'variants','page'=>1,'per_page'=>50,'with_order_stats'=>false]);
    if ($d === null) {
        echo "❌ detailPage 返回 null（decodeProductId 失败？）\n";
        echo "product_id 前 8 字符: " . substr($firstId, 0, 8) . "\n";
        echo "product_id 长度: " . strlen($firstId) . "\n";
    } else {
        $rows = $d['detail']['rows'] ?? [];
        echo "✅ detailPage rows=" . count($rows) . "\n";
        foreach (array_slice($rows, 0, 2) as $r) {
            $img = $r['product_image_url'] ?? '';
            $src = strpos($img,'tongtool')!==false?'通途':(strpos($img,'shopify')!==false?'Shopify':'空/其他');
            echo "  sku={$r['tongtu_sku']}  image=[$src]\n";
        }
    }
}
