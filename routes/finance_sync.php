<?php

use App\Http\Controllers\FinanceSyncController;
use App\Http\Middleware\AuthenticateFinanceSync;
use App\Services\FinanceSync\FinanceSyncCatalog;
use Illuminate\Support\Facades\Route;

Route::prefix('api/v1/finance-sync')->name('finance-sync.')
    ->middleware(AuthenticateFinanceSync::class)
    ->controller(FinanceSyncController::class)
    ->group(function (): void {
        Route::get('snapshot/{resource}', 'snapshot')
            ->whereIn('resource', array_keys(FinanceSyncCatalog::RESOURCES))->name('snapshot');
        Route::get('changes/{resource}', 'changes')
            ->whereIn('resource', array_keys(FinanceSyncCatalog::RESOURCES))->name('changes');
        Route::get('orders/{order}/logistics', 'logistics')->where('order', '[1-9][0-9]{0,18}')->name('logistics');
        Route::get('orders/{order}/logistics-logs', 'logisticsLogs')->where('order', '[1-9][0-9]{0,18}')->name('logistics-logs');
    });
