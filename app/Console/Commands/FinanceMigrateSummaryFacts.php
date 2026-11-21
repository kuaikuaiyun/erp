<?php

namespace App\Console\Commands;

use App\Portals\Finance\FinanceReportTimezones;
use App\Portals\Finance\FinanceSummaryFacts;
use App\Portals\Finance\FinanceWorkbookService;
use Illuminate\Console\Command;

class FinanceMigrateSummaryFacts extends Command
{
    protected $signature = 'finance:summaries:migrate-facts {--month= : YYYY-MM} {--channel= : paypal or credit_card}';
    protected $description = 'Build numeric summary facts and reconcile both monetary views before retiring legacy JSON snapshots';

    public function handle(FinanceWorkbookService $workbooks, FinanceSummaryFacts $facts): int
    {
        if (! $facts->installed()) { $this->error('请先执行结构化汇总表迁移。'); return self::FAILURE; }
        $month = $this->option('month'); $channel = $this->option('channel');
        if (($month && ! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/D', $month)) || ($channel && ! in_array($channel, ['paypal', 'credit_card'], true))) {
            $this->error('月份格式为 YYYY-MM，通道为 paypal 或 credit_card。'); return self::FAILURE;
        }
        $db = $workbooks->database();
        $periods = $db->table('finance_summary_periods')->when($month, fn ($query) => $query->where('month', $month))
            ->when($channel, fn ($query) => $query->where('channel', $channel))->orderBy('month')->orderBy('channel')->get();
        foreach ($periods as $period) {
            $facts->refresh($period);
            foreach (FinanceReportTimezones::groups($period->channel) as $zone => $platforms) {
                // Recheck current source amounts and counts before any legacy cleanup.
                if (! $facts->verify($period->channel, $period->month, $zone)) {
                    $this->error($period->month.' '.$period->channel.' '.$zone.' 金额/订单数对账未通过，旧数据未清理。');
                    return self::FAILURE;
                }
            }
            $this->info($period->month.' '.$period->channel.'：美元、原币金额及订单数对账通过。');
        }
        return self::SUCCESS;
    }
}
