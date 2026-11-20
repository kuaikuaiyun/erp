<?php

namespace App\Console\Commands;

use App\Portals\Finance\FinanceSummarySourceChanges;
use App\Portals\Finance\FinanceSummarySourceCache;
use App\Portals\Finance\FinanceSummaryTriggers;
use App\Portals\Finance\FinanceWorkbookService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class FinanceSummaryStatus extends Command
{
    protected $signature = 'finance:summaries:status';
    protected $description = 'Inspect finance input tracking and persistent source reuse without rebuilding reports';

    public function handle(FinanceWorkbookService $workbooks): int
    {
        $db = $workbooks->database();
        $tracking = FinanceSummaryTriggers::available($db);
        $scoped = FinanceSummarySourceChanges::available($db);
        $directory = FinanceSummarySourceCache::directory();
        $writable = is_writable(is_dir($directory) ? $directory : dirname($directory));
        $this->table(['检查项', '状态'], [
            ['基础来源变更追踪', $tracking ? '正常' : '缺失：无法跨查询复用'],
            ['按日期范围失效', $scoped ? '正常' : '未启用：执行 2026_11_20_000003 迁移并检查触发器权限'],
            ['源行文件目录', $writable ? '可写' : '不可写：计算完成后无法持久复用'],
            ['源行文件位置', $directory],
            ['后台最后记录', json_encode(Cache::get('finance:summaries:worker-status'), JSON_UNESCAPED_UNICODE)],
        ]);
        $this->line('多台 Web 服务器须将 FINANCE_SUMMARY_SOURCE_CACHE_PATH 指向同一持久目录；发布时保留该目录。');
        return $tracking && $scoped && $writable ? self::SUCCESS : self::FAILURE;
    }
}
