<?php

namespace App\Console\Commands;

use App\Models\LogisticsPayPalAccount;
use App\Portals\Payment\PaymentPayPalBalanceRefreshService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class RefreshPaymentPayPalBalances extends Command
{
    protected $signature = 'payment:paypal-balances:refresh';

    public function __construct()
    {
        parent::__construct();
        $this->setDescription(__('payment.balance_refresh_command.description'));
    }

    public function handle(PaymentPayPalBalanceRefreshService $refresh): int
    {
        $lock = Cache::lock('payment:paypal-balances:refresh', 86400);
        if (! $lock->get()) {
            $this->warn(__('payment.balance_refresh_command.running'));

            return self::SUCCESS;
        }

        try {
            $success = 0;
            $failed = 0;
            $skipped = 0;
            foreach (LogisticsPayPalAccount::query()->where('is_enabled', true)->lazyById(100) as $account) {
                // Check presence without decrypting the secret; validation stays in the existing refresh service.
                if (trim((string) $account->relay_url) === ''
                    || trim((string) $account->relay_certificate_thumbprint_sha1) === ''
                    || trim((string) $account->relay_hmac_key_id) === ''
                    || trim((string) $account->getRawOriginal('relay_hmac_secret')) === '') {
                    $skipped++;

                    continue;
                }

                try {
                    $refresh->refresh($account);
                    $success++;
                } catch (\Throwable $exception) {
                    $failed++;
                    $message = __('payment.balance_refresh_command.failed', ['account' => $account->getKey()]);
                    $this->error($message);
                    Log::warning($message, [
                        'account_id' => $account->getKey(),
                        'exception_class' => get_class($exception),
                    ]);
                }
            }

            $message = __('payment.balance_refresh_command.summary', compact('success', 'failed', 'skipped'));
            $this->info($message);
            Log::info($message);

            return $failed > 0 ? self::FAILURE : self::SUCCESS;
        } finally {
            $lock->release();
        }
    }
}
