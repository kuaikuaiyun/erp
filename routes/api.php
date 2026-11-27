<?php

use App\Portals\Logistics\Http\Controllers\TongtoolSkuReceiverController;
use Illuminate\Support\Facades\Route;

Route::match(['get', 'post'], '/logistics/tongtool-sku', TongtoolSkuReceiverController::class)
    ->name('logistics.tongtool-sku');
