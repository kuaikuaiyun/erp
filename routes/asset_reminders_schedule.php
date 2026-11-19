<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('asset-reminders:process')->everyMinute()->timezone('Asia/Shanghai')->withoutOverlapping(10)->runInBackground();
