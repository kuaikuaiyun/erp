<?php

namespace App\Jobs;

use App\Portals\AssetManager\SubmissionNotificationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SendAssetSubmissionNotification implements ShouldQueue
{
    use Queueable;
    public int $tries = 1;
    public int $timeout = 60;
    public function __construct(public int $runId)
    {
        $this->onConnection('database')->onQueue('asset-notifications')->afterCommit();
    }
    public function handle(SubmissionNotificationService $service): void { $service->send($this->runId); }
}
