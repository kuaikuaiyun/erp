<?php

use App\Http\Controllers\DingTalkLoginController;
use App\Portal\Http\Middleware\EnsureMainPortalDomain;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', EnsureMainPortalDomain::class])->prefix('auth/dingtalk')->name('dingtalk.')->group(function (): void {
    Route::get('callback', [DingTalkLoginController::class, 'callback'])->middleware('throttle:'.config('dingtalk.callback_rate').',1')->name('callback');
    Route::get('accounts', [DingTalkLoginController::class, 'accounts'])->middleware('throttle:'.config('dingtalk.callback_rate').',1')->name('accounts');
    Route::post('accounts', [DingTalkLoginController::class, 'select'])->middleware('throttle:'.config('dingtalk.selection_rate').',1')->name('select');
});
