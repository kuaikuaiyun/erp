<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('assistant:shopify-analytics:auto-sync')
    ->everyThirtyMinutes()
    ->withoutOverlapping(120);

Schedule::command('assistant:shopify-analytics:process-batches --limit=20')
    ->everyMinute()
    ->withoutOverlapping(180);
