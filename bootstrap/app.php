<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

$app = Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function (): void {
            require __DIR__.'/../routes/dingtalk.php';
            require __DIR__.'/../routes/finance_sync.php';
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(
            prepend: [\App\Http\Middleware\UseEnterpriseSessionDomain::class],
            append: [\App\Http\Middleware\EnforceDingTalkSession::class],
        );
        $middleware->validateCsrfTokens(except: [
            'shopify/webhooks/orders',
            'api/paypal/import',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(\App\Http\Controllers\FinanceSyncController::renderException(...));
        $exceptions->dontFlash(['relay_hmac_secret', 'authCode', 'code', 'state', 'encrypt', 'app_secret', 'callback_token', 'callback_aes_key']);
    })->create();

$app->booted(function (): void {
    // Preserve existing account behavior while invalidating every old proof on status changes/deletion.
    $revokeAccountSessions = function (\App\Models\AdminUser $account): void {
        if (!\Illuminate\Support\Facades\Schema::hasTable('dingtalk_bindings')) {
            return;
        }
        \Illuminate\Support\Facades\DB::transaction(function () use ($account): void {
            if (\Illuminate\Support\Facades\Schema::hasTable('erp_authentication_account_versions')) {
                app(\App\Services\Authentication\ModeService::class)->accountVersion((int) $account->id);
                \Illuminate\Support\Facades\DB::table('erp_authentication_account_versions')->where('account_id', $account->id)->increment('version');
            }
            $changes = ['version' => \Illuminate\Support\Facades\DB::raw('version + 1'), 'updated_at' => now()];
            if (!$account->exists) {
                $changes['active'] = false;
            }
            \App\Models\DingTalkBinding::query()->where('account_id', $account->id)->update($changes);
            app(\App\Services\DingTalk\AuditService::class)->record('account.changed', null, (int) $account->id,
                ['target_account_id' => (int) $account->id]);
        });
    };
    \App\Models\AdminUser::updated(function (\App\Models\AdminUser $account) use ($revokeAccountSessions): void {
        if ($account->wasChanged('status')) {
            $revokeAccountSessions($account);
        }
    });
    \App\Models\AdminUser::deleted($revokeAccountSessions);
});

return $app;
