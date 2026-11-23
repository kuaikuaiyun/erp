<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('finance:paypal-orders:enqueue')->hourly()->withoutOverlapping(10)
    ->onOneServer()->sendOutputTo(storage_path('logs/finance-paypal-orders-schedule.log'));

$balanceRefresh = Schedule::command('finance:paypal-balances:refresh')->hourly()->withoutOverlapping(30)
    ->onOneServer()->sendOutputTo(storage_path('logs/finance-paypal-balances-schedule.log'));

$orderWorker = Schedule::command('queue:work finance_orders --queue=finance-orders --stop-when-empty --tries=1 --timeout=900')
    ->everyMinute()->withoutOverlapping(20)
    ->appendOutputTo(storage_path('logs/finance-order-worker.log'));

$indexRefresh = Schedule::command('finance:indexes:apply')->everyMinute()->withoutOverlapping(30)
    ->sendOutputTo(storage_path('logs/finance-indexes-schedule.log'));

$summaryRefresh = Schedule::command('finance:summaries:refresh')->everyMinute()
    ->sendOutputTo(storage_path('logs/finance-summaries-schedule.log'));

$overviewBuilder = Schedule::command('finance:overviews:build')->everyMinute()
    ->sendOutputTo(storage_path('logs/finance-overviews-schedule.log'));

if (! $financeScheduleOnly) {
    $balanceRefresh->runInBackground();
    $orderWorker->runInBackground();
    $indexRefresh->runInBackground();
    $summaryRefresh->runInBackground();
    $overviewBuilder->runInBackground();
}
