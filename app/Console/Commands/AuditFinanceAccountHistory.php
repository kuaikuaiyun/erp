<?php

namespace App\Console\Commands;

use App\Models\FinancePaymentAccount;
use App\Portals\Finance\FinanceWorkbookService;
use Illuminate\Console\Command;

class AuditFinanceAccountHistory extends Command
{
    protected $signature = 'finance:audit-account-history';

    protected $description = '只读检查财务账户状态、历史名称和交易归属，不合并或删除数据';

    public function handle(FinanceWorkbookService $workbooks): int
    {
        $db = $workbooks->database();
        $accounts = FinancePaymentAccount::withTrashed()->get();
        $this->table(['ID', '当前名称', '历史标识', '通道', '状态'], $accounts->map(fn ($a) => [
            $a->id, $a->account_name, $a->activity_account, $a->account_type,
            $a->deleted_at ? '已删除（不参与汇总）' : ($a->status === 'disabled' ? '停用（保留历史）' : '启用'),
        ])->all());

        // Aggregate once; do not run a correlated query for each transaction.
        $groups = $db->table('finance_records as r')
            ->leftJoin('finance_payment_transactions as t', 't.finance_record_id', '=', 'r.id')
            ->where('r.dataset', 'paypal-orders')
            ->select('r.account', 't.payment_account_id')->selectRaw('COUNT(*) as total')
            ->groupBy('r.account', 't.payment_account_id')->get();
        $this->table(['交易历史标识', '关联账户ID', '笔数', '判定'], $groups->map(function ($g) use ($accounts) {
            $owner = $accounts->firstWhere('id', $g->payment_account_id);
            $matches = $accounts->where('account_type', 'paypal')->where('activity_account', $g->account);
            $live = $matches->whereNull('deleted_at');
            $reason = $owner ? ($owner->deleted_at ? '已删除账户遗留，汇总排除' : '按账户ID归属：'.$owner->account_name)
                : ($live->isNotEmpty() ? '按历史标识归属：'.$live->first()->account_name
                    : ($matches->isNotEmpty() ? '已删除账户遗留，汇总排除' : '未绑定历史数据，需核对真实账户'));

            return [$g->account, $g->payment_account_id ?? '无', $g->total, $reason];
        })->all());
        $this->line('名称相似、余额相同或部分交易号重合均不能单独证明是同一账户；本检查不会自动合并。');

        return self::SUCCESS;
    }
}
