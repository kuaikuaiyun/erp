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

$separateReads = config('finance.read_models.enabled', true);
$summaryRefresh = $separateReads ? null : Schedule::command('finance:summaries:refresh')->everyMinute()
    ->sendOutputTo(storage_path('logs/finance-summaries-schedule.log'));

$changeConsumer = $separateReads ? Schedule::command('finance:changes:consume')->everyMinute()
    ->sendOutputTo(storage_path('logs/finance-changes-schedule.log')) : null;

$readModelBuilder = $separateReads ? Schedule::command('finance:read-models:build')->everyMinute()
    ->sendOutputTo(storage_path('logs/finance-read-models-schedule.log')) : null;

if (! $financeScheduleOnly) {
    $balanceRefresh->runInBackground();
    $orderWorker->runInBackground();
    $indexRefresh->runInBackground();
    $summaryRefresh?->runInBackground();
    $changeConsumer?->runInBackground();
    $readModelBuilder?->runInBackground();
}
