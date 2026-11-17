<?php

namespace App\Console\Commands;

use App\Models\AikaipinProduct;
use App\Portals\Aikaipin\AikaipinHistoricalVideoImporter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

class ImportAikaipinHistoricalVideos extends Command
{
    protected $signature = 'aikaipin:import-historical-videos {--product=} {--dry-run}';

    protected $description = '将 AI 产品历史 HTML 参考页中的视频提取到独立视频表和文件存储';

    public function handle(AikaipinHistoricalVideoImporter $importer): int
    {
        $connection = config('admin.database.connection') ?: config('database.default');
        if (! Schema::connection($connection)->hasColumn('operation_ai_product_reference_videos', 'source_reference_index')) {
            $this->error('请先执行 php artisan migrate --force。');
            return self::FAILURE;
        }

        $query = AikaipinProduct::query()
            ->whereNotNull('reference_file')
            ->where('reference_file', '!=', '');
        if (($product = (int) $this->option('product')) > 0) $query->whereKey($product);

        $summary = ['products' => 0, 'found' => 0, 'imported' => 0, 'skipped' => 0, 'failed' => 0];
        $query->orderBy('id')->chunkById(20, function ($products) use ($importer, &$summary): void {
            foreach ($products as $product) {
                $result = $importer->import($product, (bool) $this->option('dry-run'));
                if ($result['found'] === 0) continue;
                $summary['products']++;
                foreach (['found','imported','skipped','failed'] as $key) $summary[$key] += $result[$key];
                $this->line(sprintf('%s：发现 %d，%s %d，跳过 %d，失败 %d', $product->product_code, $result['found'], $this->option('dry-run') ? '可迁移' : '已迁移', $result['imported'], $result['skipped'], $result['failed']));
                foreach ($result['errors'] as $error) $this->warn('  '.$error);
            }
        });

        $this->info(sprintf('完成：产品 %d，视频 %d，%s %d，跳过 %d，失败 %d。', $summary['products'], $summary['found'], $this->option('dry-run') ? '可迁移' : '已迁移', $summary['imported'], $summary['skipped'], $summary['failed']));

        return $summary['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
