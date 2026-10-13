<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('assistant:shopify-analytics:auto-sync --limit=20')
    ->everyThirtyMinutes()
    ->withoutOverlapping(30);

Schedule::command('assistant:shopify-analytics:backfill --limit=10')
    ->everyMinute()
    ->withoutOverlapping(30);

Schedule::command('assistant:shopify-analytics:process-batches --limit=20')
    ->everyMinute()
    ->withoutOverlapping(30);
