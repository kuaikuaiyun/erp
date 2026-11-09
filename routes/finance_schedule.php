<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('finance:paypal-orders:enqueue')->hourly()->withoutOverlapping(10)
    ->onOneServer()->sendOutputTo(storage_path('logs/finance-paypal-orders-schedule.log'));

$balanceRefresh = Schedule::command('finance:paypal-balances:refresh')->hourly()->withoutOverlapping(30)
    ->onOneServer()->sendOutputTo(storage_path('logs/finance-paypal-balances-schedule.log'));

$orderWorker = Schedule::command('queue:work finance_orders --queue=finance-orders --stop-when-empty --tries=1 --timeout=900')
    ->everyMinute()->withoutOverlapping(20);

if (! $financeScheduleOnly) {
    $balanceRefresh->runInBackground();
    $orderWorker->runInBackground();
}
