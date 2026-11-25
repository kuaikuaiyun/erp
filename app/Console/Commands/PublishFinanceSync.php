<?php

namespace App\Console\Commands;

use App\Services\FinanceSync\FinanceSyncPublisher;
use Illuminate\Console\Command;

final class PublishFinanceSync extends Command
{
    protected $signature = 'finance-sync:publish {--batch=200} {--batches=20}';

    public function __construct()
    {
        parent::__construct();
        $this->setDescription(__('finance_sync.command.description'));
    }

    public function handle(FinanceSyncPublisher $publisher): int
    {
        $size = (int) $this->option('batch');
        $count = (int) $this->option('batches');
        if ($size < 1 || $size > 500 || $count < 1 || $count > 200) {
            $this->error(__('finance_sync.command.invalid_batch'));

            return self::INVALID;
        }

        $published = $publisher->run($size, $count);
        $this->info(__('finance_sync.command.published', ['count' => $published]));

        return self::SUCCESS;
    }
}
