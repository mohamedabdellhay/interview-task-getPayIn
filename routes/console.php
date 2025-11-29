<?php

use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Console Routes
|--------------------------------------------------------------------------
*/

// Here you may define all of your Closure based console commands.
Schedule::command('holds:expire')
    ->everyMinute() // Run every minute
    ->withoutOverlapping() // Prevent overlapping runs
    ->runInBackground(); // Run in the background