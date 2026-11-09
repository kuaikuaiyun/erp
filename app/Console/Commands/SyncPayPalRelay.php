<?php

namespace App\Console\Commands;

use App\Portals\Logistics\PayPalRelaySyncService;
use Illuminate\Console\Command;

class SyncPayPalRelay extends Command
{
    protected $signature = 'logistics:paypal-relay:sync
        {--account= : 只同步指定的物流 PayPal 账户 ID}
        {--force : 在全局自动拉取开关关闭时，手动执行本次同步}
        {--dry-run : 只验证 VPS 数据完整性，不创建导入批次或写入 ERP 数据}';

    protected $description = '通过 VPS 中转拉取完整 PayPal 数据，并交给现有物流导入链路处理';

    public function handle(PayPalRelaySyncService $sync): int
    {
        if (! $this->option('force') && ! config('logistics.paypal.relay.sync_enabled', false)) {
            $this->warn('PayPal VPS 自动拉取当前已关闭；旧 VPS 推送和手工 JSON 导入不受影响。需要手动测试时可增加 --force。');

            return self::SUCCESS;
        }

        $value = $this->option('account');
        if ($value !== null && (! ctype_digit((string) $value) || (int) $value < 1)) {
            $this->error('--account 必须是正整数。');

            return self::INVALID;
        }

        $result = $sync->syncAll($value === null ? null : (int) $value, null, (bool) $this->option('dry-run'));
        $this->table(['账户', '状态', '区间', '交易', '退款', '纠纷', '说明'], array_map(
            fn (array $row): array => [
                $row['account_name'],
                $row['status'],
                trim(($row['from'] ?? '').' ~ '.($row['to'] ?? ''), ' ~'),
                $row['transactions'],
                $row['refunds'],
                $row['disputes'],
                $row['message'],
            ],
            $result['results'],
        ));
        $this->line(sprintf('完成：成功 %d，失败 %d，跳过 %d。', $result['succeeded'], $result['failed'], $result['skipped']));

        return $result['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
