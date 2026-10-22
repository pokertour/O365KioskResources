<?php

use App\Models\ActivityLog;
use App\Models\ScreenPairing;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Requires the scheduler: `* * * * * php artisan schedule:run` (or `php artisan schedule:work`).
Schedule::command('screens:check-offline')->everyMinute()->withoutOverlapping();
Schedule::command('model:prune', ['--model' => [ActivityLog::class, ScreenPairing::class]])->daily();
