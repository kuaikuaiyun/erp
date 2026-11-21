<?php

namespace App\Console\Commands;

use App\Portals\Finance\FinanceSummarySourceChanges;
use App\Portals\Finance\FinanceSummarySourceCache;
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
        $tracking = \App\Portals\Finance\FinanceApplicationChanges::available($db);
        $scoped = FinanceSummarySourceChanges::available($db);
        $directory = FinanceSummarySourceCache::directory();
        $writable = is_writable(is_dir($directory) ? $directory : dirname($directory));
        $external = app(\App\Portals\Finance\FinanceExternalChanges::class)->status($db);
        $this->table(['检查项', '状态'], [
            ['财务写入追踪', $tracking ? '应用事务追踪：正常' : '未启用应用追踪'],
            ['其他系统来源通知', $external['writable'] ? '提交后通知；待接收 '.$external['pending'].' 个' : '目录不可读写：需核对来源变更'],
            ['来源通知目录', $external['directory']],
            ['按日期范围失效', $scoped ? '正常' : '未启用：执行普通表迁移'],
            ['源行文件目录', $writable ? '可写' : '不可写：计算完成后无法持久复用'],
            ['源行文件位置', $directory],
            ['后台最后记录', json_encode(Cache::get('finance:summaries:worker-status'), JSON_UNESCAPED_UNICODE)],
        ]);
        $this->line('多台 Web 服务器须将 FINANCE_SUMMARY_SOURCE_CACHE_PATH 指向同一持久目录；发布时保留该目录。');
        $this->line('来源通知目录也须持久共享；可用 FINANCE_SOURCE_CHANGE_PATH 指定，不能随普通缓存清理。');
        if (! $tracking || ! $scoped) {
            $this->warn('请执行新增普通表迁移并重启旧后台进程；本版本不创建、不检测、不修复数据库触发器。');
        }
        return $tracking && $scoped && $writable && $external['writable'] ? self::SUCCESS : self::FAILURE;
    }
}
