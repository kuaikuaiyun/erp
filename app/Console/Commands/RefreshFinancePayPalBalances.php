<?php

namespace App\Console\Commands;

use App\Models\FinancePaymentAccount;
use App\Portals\Finance\FinancePayPalSyncService;
use Illuminate\Console\Command;

class RefreshFinancePayPalBalances extends Command
{
    protected $signature = 'finance:paypal-balances:refresh';

    protected $description = '后台刷新已配置并启用的财务 PayPal 账户余额';

    public function handle(FinancePayPalSyncService $sync): int
    {
        $success = 0;
        $failed = 0;
        foreach (FinancePaymentAccount::query()->configuredPayPal()->lazyById() as $account) {
            try {
                if ($sync->balances($account, 0)['ok']) {
                    $success++;

                    continue;
                }
            } catch (\Throwable $e) {
                report($e);
            }
            $failed++;
            $this->error('账户 '.$account->id.' 余额刷新失败，下次调度将继续尝试。');
        }
        $this->info("余额刷新成功 {$success} 个，失败 {$failed} 个。");

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
