<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('assistant:shopify-analytics:auto-sync --limit=10')
    ->everyMinute()
    ->withoutOverlapping(30);

Schedule::command('assistant:shopify-analytics:backfill --limit=5')
    ->everyMinute()
    ->withoutOverlapping(30);

Schedule::command('assistant:shopify-analytics:process-batches --limit=10')
    ->everyMinute()
    ->withoutOverlapping(30);
