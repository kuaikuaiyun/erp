<?php

namespace Tests\Unit;

use App\Portals\Operation\ShopifyProductCsvImporter;
use PHPUnit\Framework\TestCase;

class ShopifyProductCsvImporterTest extends TestCase
{
    public function test_it_parses_shopify_csv_product_variants_images_and_multiline_html(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'shopify-product-');
        $csv = <<<'CSV'
"Handle","Title","Body (HTML)","Vendor","Type","Published","Option1 Name","Option1 Value","Variant SKU","Variant Inventory Tracker","Variant Inventory Qty","Variant Inventory Policy","Variant Fulfillment Service","Variant Price","Variant Requires Shipping","Image Src","Image Position","Image Alt Text","Status","Variant Image"
"animal-socks","Animal Socks","<p>Line one
Line two</p>","Vendor","Socks","TRUE","Style","Panda","sku-panda","shopify","12","deny","manual","19.9","TRUE","https://cdn.example.com/main.jpg","1","Main","active","https://cdn.example.com/panda.jpg"
"animal-socks","","","","","","Style","Koala","sku-koala","shopify","8","continue","manual","20.5","TRUE","https://cdn.example.com/second.jpg","2","Second","","https://cdn.example.com/koala.jpg"
CSV;
        file_put_contents($path, "\xEF\xBB\xBF".$csv);

        try {
            $payload = (new ShopifyProductCsvImporter)->parse($path);
        } finally {
            @unlink($path);
        }

        $this->assertSame('animal-socks', $payload['handle']);
        $this->assertSame("<p>Line one\nLine two</p>", $payload['description_html']);
        $this->assertSame(['Style'], $payload['option_names']);
        $this->assertCount(2, $payload['variants']);
        $this->assertSame('sku-koala', $payload['variants'][1]['sku']);
        $this->assertSame(8, $payload['variants'][1]['inventory_quantity']);
        $this->assertCount(2, $payload['images']);
        $this->assertSame('ACTIVE', $payload['status']);
    }
}
