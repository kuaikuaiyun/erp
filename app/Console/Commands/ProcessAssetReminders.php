<?php

namespace App\Console\Commands;

use App\Portals\AssetManager\ReminderService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class ProcessAssetReminders extends Command
{
    protected $signature = 'asset-reminders:process';

    public function handle(ReminderService $service): int
    {
        app()->setLocale('zh-CN');
        $lock = Cache::lock('asset-reminder:processor', 300);
        if (! $lock->get()) { $this->warn(__('asset_reminders.busy')); return self::SUCCESS; }
        try {
            app(\App\Portals\AssetManager\SubmissionNotificationService::class)->recover();
            $errors = $service->process(now('Asia/Shanghai'));
            $this->info(__('asset_reminders.command_result', ['count' => $errors]));
            return $errors > 0 ? self::FAILURE : self::SUCCESS;
        } finally { $lock->release(); }
    }
}
