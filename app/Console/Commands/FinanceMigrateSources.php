<?php

namespace App\Console\Commands;

use App\Portals\Finance\FinanceSourceMigration;
use Illuminate\Console\Command;

class FinanceMigrateSources extends Command
{
    protected $signature = 'finance:sources:migrate {--batch=150 : Rows per transaction} {--verify : Verify without writing}';
    protected $description = 'Migrate finance documents to ordinary channel columns and event tables with full read-back verification';

    public function handle(FinanceSourceMigration $migration): int
    {
        $batch = max(10, min(250, (int) $this->option('batch')));
        if ($this->option('verify')) {
            foreach (array_keys(\App\Portals\Finance\FinanceSourceStorage::SOURCES) as $kind) $this->info($kind.': '.$migration->verify($kind, $batch).' verified');
        } else {
            $last = [];
            $counts = $migration->migrate($batch, function ($kind, $count) use (&$last): void {
                if ($count - ($last[$kind] ?? -5000) >= 5000) { $this->line($kind.': '.$count.' verified'); $last[$kind] = $count; }
            });
            foreach ($counts as $kind => $count) $this->info($kind.': '.$count.' migrated and verified');
        }
        return self::SUCCESS;
    }
}
