<?php

namespace App\Console\Commands;

use App\Models\OperationTaskAsset;
use App\Portals\OperationTasks\OperationTaskVideoService;
use Illuminate\Console\Command;

class OptimizeOperationTaskVideos extends Command
{
    protected $signature = 'operation-tasks:videos:optimize {--asset= : Asset ID} {--apply : Replace only after verification}';

    public function handle(OperationTaskVideoService $videos): int
    {
        $query = OperationTaskAsset::query()->whereNotNull('file_path')->where(function ($query): void {
            $query->where('mime_type', 'video/mp4')->orWhere('original_name', 'like', '%.mp4');
        });
        if ($this->option('asset') !== null) {
            if (! ctype_digit((string) $this->option('asset')) || (int) $this->option('asset') < 1) {
                $this->error(__('operation_tasks.video_processing.invalid_id'));
                return self::FAILURE;
            }
            $query->whereKey((int) $this->option('asset'));
        }
        $found = false; $failed = false;
        foreach ($query->orderBy('id')->lazyById(100) as $asset) {
            $found = true;
            try {
                $result = $videos->optimize($asset->file_path, (bool) $this->option('apply'));
            } catch (\Throwable $exception) {
                report($exception); $result = 'failed';
            }
            $this->line('#'.$asset->id.' '.($asset->original_name ?: $asset->id).' — '.__('operation_tasks.video_processing.'.$result));
            if (in_array($result, ['failed', 'invalid', 'no_space'], true)) $failed = true;
        }
        if (! $found) $this->warn(__('operation_tasks.video_processing.empty'));
        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
