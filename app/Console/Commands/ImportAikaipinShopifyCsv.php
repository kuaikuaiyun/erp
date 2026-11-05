<?php

namespace App\Console\Commands;

use App\Portals\Operation\ShopifyProductCsvImporter;
use Illuminate\Console\Command;
use Throwable;

class ImportAikaipinShopifyCsv extends Command
{
    protected $signature = 'aikaipin:import-shopify-csv
        {file : Shopify 商品 CSV 文件路径}
        {--product-id= : 指定 AI 产品库记录 ID}
        {--product-code= : 指定 AI 产品编号}
        {--dry-run : 只检查匹配和数据，不写入数据库}';

    protected $description = '将 Shopify 商品 CSV 的详情、规格、价格和图片补充到 AI 产品库';

    public function handle(ShopifyProductCsvImporter $importer): int
    {
        $file = (string) $this->argument('file');
        if (! is_file($file) || ! is_readable($file)) {
            $this->error('CSV 文件不存在或不可读：'.$file);

            return self::FAILURE;
        }

        try {
            $payload = $importer->parse($file);
            $product = $importer->findProduct(
                $payload,
                ($id = (int) $this->option('product-id')) > 0 ? $id : null,
                $this->option('product-code') ?: null,
            );

            $this->table(['匹配产品', 'Shopify 标题', '规格', '图片', 'Handle'], [[
                $product->product_code.' / '.$product->name,
                $payload['title'],
                count($payload['variants']),
                count($payload['images']),
                $payload['handle'],
            ]]);

            if ($this->option('dry-run')) {
                $this->info('检查完成，未写入数据库。');

                return self::SUCCESS;
            }

            $product = $importer->supplement($product, $payload, $file);
            $this->info('导入完成：AI 产品 '.$product->product_code.' 已补充 Shopify 完整商品数据。');

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }
}
