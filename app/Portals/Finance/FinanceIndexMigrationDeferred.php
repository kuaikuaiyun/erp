<?php

namespace App\Portals\Finance;

use Illuminate\Contracts\Debug\ShouldntReport;
use RuntimeException;
use Symfony\Component\Console\Output\OutputInterface;

class FinanceIndexMigrationDeferred extends RuntimeException implements ShouldntReport
{
    public function renderForConsole(OutputInterface $output): void
    {
        $output->writeln('<comment>财务索引迁移已延后：'.htmlspecialchars($this->getMessage(), ENT_NOQUOTES).'</comment>');
        $output->writeln('未将未完成的迁移登记为成功，定时任务将在数据库空闲后重试。');
    }
}
