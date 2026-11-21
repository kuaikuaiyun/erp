<?php

namespace App\Console\Commands;

use App\Models\FinanceRecord;
use App\Portals\Finance\FinanceTransactions;
use App\Portals\Finance\FinanceWorkbookService;
use Illuminate\Console\Command;

class FinanceMigrateTransactions extends Command
{
    protected $signature = 'finance:transactions:migrate {--batch=500 : Records per transaction}';
    protected $description = 'Backfill indexed transaction headers and references, then verify coverage before switching queries';

    public function handle(FinanceTransactions $transactions, FinanceWorkbookService $workbooks): int
    {
        if (! $transactions->installed()) {
            $this->error('请先执行数据库迁移，创建结构化交易表。');
            return self::FAILURE;
        }
        $db = $workbooks->database();
        $batchSize = max(50, min(2000, (int) $this->option('batch')));
        $last = (int) $db->table('finance_transaction_storage')->where('id', 1)->value('last_record_id');
        $processed = 0;
        do {
            $count = $db->transaction(function () use ($db, $transactions, $batchSize, &$last): int {
                $records = FinanceRecord::query()->where('id', '>', $last)->orderBy('id')->limit($batchSize)->lockForUpdate()->get();
                if ($records->isEmpty()) return 0;
                $transactions->sync($records);
                $last = $records->last()->id;
                $db->table('finance_transaction_storage')->where('id', 1)->update(['last_record_id' => $last]);
                return $records->count();
            }, 3);
            $processed += $count;
        } while ($count > 0);
        $missing = $db->table('finance_records as r')->leftJoin('finance_transactions as t', 't.id', '=', 'r.id')
            ->leftJoin('finance_card_transaction_details as d', 'd.record_id', '=', 'r.id')
            ->where(fn ($query) => $query->whereNull('t.id')->orWhereNull('d.record_id'))->count();
        if ($missing > 0) {
            $this->error('有 '.$missing.' 条交易缺少结构化数据，未启用新查询。请修复后重新回填。');
            return self::FAILURE;
        }
        $db->table('finance_transaction_storage')->where('id', 1)->update(['state' => 'ready']);
        $this->info('已回填 '.$processed.' 条交易，覆盖校验通过，已启用索引查询。');
        return self::SUCCESS;
    }
}
