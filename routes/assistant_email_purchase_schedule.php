<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('assistant:email-purchases:sync')
    ->dailyAt('09:00')
    ->timezone('Asia/Shanghai')
    ->withoutOverlapping(120)
    ->appendOutputTo(storage_path('logs/assistant-email-purchases-schedule.log'))
    ->runInBackground();
