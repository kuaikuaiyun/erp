<?php

use App\Http\Controllers\AdyenInboxController;
use Illuminate\Support\Facades\Route;

Route::post('/api/adyen/webhook', [AdyenInboxController::class, 'receive']);
Route::get('/api/adyen/webhook-inbox', [AdyenInboxController::class, 'pending']);
Route::post('/api/adyen/webhook-inbox/{delivery}/ack', [AdyenInboxController::class, 'acknowledge'])->whereNumber('delivery');
