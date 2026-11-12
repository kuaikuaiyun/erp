<?php

namespace App\Console\Commands;

use App\Portals\OperationTasks\OperationTaskAssetUploadService;
use Illuminate\Console\Command;

class PurgeOperationTaskAssetUploads extends Command
{
    protected $signature = 'operation-tasks:uploads:purge {--limit=200}';

    public function handle(OperationTaskAssetUploadService $uploads): int
    {
        $count = $uploads->purge((int) $this->option('limit'));
        $this->info(__('operation_tasks.upload.purged', ['count' => $count]));
        return self::SUCCESS;
    }
}
