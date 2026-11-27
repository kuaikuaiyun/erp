<?php

use App\Http\Controllers\AdyenInboxController;
use Illuminate\Support\Facades\Route;

Route::post('/api/adyen/webhook', [AdyenInboxController::class, 'receive']);
Route::get('/api/adyen/webhook-inbox', [AdyenInboxController::class, 'pending']);
Route::post('/api/adyen/webhook-inbox/{delivery}/ack', [AdyenInboxController::class, 'acknowledge'])->whereNumber('delivery');
Route::get('/api/adyen/reports/{merchant}/{filename}', [AdyenInboxController::class, 'report'])
    ->where(['merchant' => '[A-Za-z0-9_-]{1,100}', 'filename' => '(?:received_payments_report|settlement_detail_report)_[A-Za-z0-9_-]{1,100}\.csv']);
