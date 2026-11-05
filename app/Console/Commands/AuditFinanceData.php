<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class AuditFinanceData extends Command
{
    protected $signature = 'finance:audit-data';

    protected $description = '只读核查财务存量数据，不删除数据、不输出接口密钥';

    public function handle(): int
    {
        $db = DB::connection(config('admin.database.connection') ?: config('database.default'));
        $schema = $db->getSchemaBuilder();
        foreach (['finance_payment_accounts', 'finance_records', 'finance_payment_transactions', 'finance_balance_snapshots', 'finance_domain_groups', 'finance_monthly_exchange_rates', 'finance_period_locks'] as $table) {
            $this->line($table.': '.($schema->hasTable($table) ? $db->table($table)->count() : '未安装'));
        }
        if ($schema->hasTable('finance_payment_accounts')) {
            $rows = $db->table('finance_payment_accounts')->get()->map(fn ($row) => [
                $row->id, $row->account_name, $row->deleted_at ? '已删除' : '有效',
                filled($row->relay_url) || filled($row->relay_hmac_secret) ? '有接口配置' : '无接口配置',
                $row->system_paypal_account_id ?? '无',
            ]);
            $this->table(['ID', '账户', '状态', '配置', '旧系统关联ID'], $rows);
        }
        if ($schema->hasTable('finance_payment_transactions')) {
            $this->table(['账户ID', '交易数量'], $db->table('finance_payment_transactions')
                ->selectRaw('payment_account_id, COUNT(*) as total')->groupBy('payment_account_id')->get()
                ->map(fn ($row) => [$row->payment_account_id, $row->total]));
        }
        if ($schema->hasTable('finance_monthly_exchange_rates')) {
            $this->table(['月份', '币种', '汇率', '来源'], $db->table('finance_monthly_exchange_rates')
                ->orderBy('month')->get()->map(fn ($row) => [$row->month, $row->currency, $row->cny_rate, $row->source ?? '未记录']));
        }
        $this->warn('以上仅为核查清单。旧关联、名称或无接口配置不能单独证明数据来自开发环境；清理前需备份并核对接口日志、人工维护及结账记录。');

        return self::SUCCESS;
    }
}
