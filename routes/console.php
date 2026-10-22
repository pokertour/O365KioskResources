<?php

use App\Models\ActivityLog;
use App\Models\ScreenPairing;
use App\Services\SystemHealth;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Requires the scheduler: `* * * * * php artisan schedule:run` (or `php artisan schedule:work`).
Schedule::command('screens:check-offline')->everyMinute()->withoutOverlapping();
Schedule::command('model:prune', ['--model' => [ActivityLog::class, ScreenPairing::class]])->daily();

// Lets Settings › Diagnostics tell whether the scheduler is running.
Schedule::call(fn () => Cache::forever(SystemHealth::SCHEDULER_HEARTBEAT_KEY, now()->toIso8601String()))
    ->everyMinute()
    ->name('health-heartbeat');
