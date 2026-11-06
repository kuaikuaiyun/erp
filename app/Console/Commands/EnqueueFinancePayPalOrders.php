<?php

namespace App\Console\Commands;

use App\Models\FinancePaymentAccount;
use App\Portals\Finance\FinanceOrderRefreshProgress;
use Illuminate\Console\Command;

class EnqueueFinancePayPalOrders extends Command
{
    protected $signature = 'finance:paypal-orders:enqueue';

    protected $description = '每小时将启用的财务PayPal账户订单同步排入后台队列';

    public function handle(FinanceOrderRefreshProgress $progress): int
    {
        $accounts = FinancePaymentAccount::query()->where('account_type', 'paypal')->where('status', 'enabled')
            ->whereNotNull('relay_url')->where('relay_url', '!=', '')
            ->whereNotNull('relay_certificate_sha1')->where('relay_certificate_sha1', '!=', '')
            ->whereNotNull('relay_hmac_key_id')->where('relay_hmac_key_id', '!=', '')
            ->whereNotNull('relay_hmac_secret')->where('relay_hmac_secret', '!=', '')
            ->orderBy('id')->get(['id', 'account_name']);
        $failed = 0;
        foreach ($accounts as $account) {
            try {
                $progress->enqueue($account, 0);
            } catch (\Throwable $e) {
                report($e);
                $this->error('账户 '.$account->id.' 排队失败，请检查后台日志。');
                $failed++;
            }
        }
        $this->info('账户 '.($accounts->count() - $failed).' 个已排队或已有任务，失败 '.$failed.' 个。');

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
