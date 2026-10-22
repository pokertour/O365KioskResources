<?php

namespace App\Services;

use App\Models\AppSetting;
use App\Models\Room;
use App\Models\Screen;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Health checks shown on the admin "Diagnostics" page. Cheap checks run on every visit;
 * the Microsoft 365 round-trips only when asked ($live), since they call Graph.
 *
 * Every check is a list item: [label, status (ok|warning|error|info), value, hint].
 */
class SystemHealth
{
    /** Written every minute by the scheduler (routes/console.php). */
    public const SCHEDULER_HEARTBEAT_KEY = 'health:scheduler-heartbeat';

    public function __construct(
        private MicrosoftGraphService $graph,
        private RoomSchedule $schedule,
    ) {}

    /**
     * @return array<string, list<array{label: string, status: string, value: ?string, hint: ?string}>>
     */
    public function checks(bool $live = false): array
    {
        return [
            'microsoft' => $this->microsoft($live),
            'background' => $this->background(),
            'screens' => $this->screens(),
            'server' => $this->server(),
        ];
    }

    /**
     * Number of warnings and errors among the cheap checks, cached for the sidebar badge.
     */
    public function problemCount(): int
    {
        return Cache::remember('health:problem-count', 60, fn () => collect($this->checks())
            ->flatten(1)
            ->whereIn('status', ['warning', 'error'])
            ->count());
    }

    /**
     * Age of the last successful agenda sync of each room (read from the cache, no Graph call).
     *
     * @return list<array{room: Room, synced_at: ?CarbonImmutable, status: string}>
     */
    public function roomSync(): array
    {
        $day = $this->schedule->now()->toDateString();

        return Room::query()->with('screens')->orderBy('name')->get()->map(function (Room $room) use ($day) {
            $payload = Cache::get("room-schedule:{$room->id}:{$day}:last-good");
            $syncedAt = is_array($payload) && $payload['fetched_at'] ? CarbonImmutable::parse($payload['fetched_at']) : null;
            $watched = $room->screens->contains(fn (Screen $s) => $s->isOnline());

            return [
                'room' => $room,
                'synced_at' => $syncedAt,
                // Agendas are only fetched while someone looks at them: an old sync is only
                // worrying when a screen of the room is online.
                'status' => match (true) {
                    $syncedAt !== null && $syncedAt->gt(now()->subMinutes(10)) => 'ok',
                    $watched => 'warning',
                    default => 'info',
                },
            ];
        })->all();
    }

    private function microsoft(bool $live): array
    {
        if (! $this->graph->isConfigured()) {
            return [$this->check(__('Connection'), 'error', __('Not configured'), __('Enter the app registration in Settings › Microsoft 365.'))];
        }

        $checks = [$this->check(__('Connection'), 'ok', __('Configured'))];

        if (! $live) {
            $checks[] = $this->check(__('Live test'), 'info', __('Not run'), __('Run the tests to request a token and call Microsoft Graph.'));

            return $checks;
        }

        $started = hrtime(true);
        try {
            $this->graph->testConnection();
            $checks[] = $this->check(__('Access token'), 'ok', $this->ms($started));
        } catch (\Throwable $e) {
            $checks[] = $this->check(__('Access token'), 'error', __('Refused'), $e->getMessage());

            return $checks;
        }

        $granted = $this->graph->grantedRoles();
        foreach (MicrosoftGraphService::APPLICATION_PERMISSIONS as $permission => $required) {
            $checks[] = in_array($permission, $granted, true)
                ? $this->check($permission, 'ok', __('Granted'))
                : $this->check($permission, $required ? 'error' : 'info', __('Not granted'), $required
                    ? __('Add this application permission and grant admin consent.')
                    : __('Only needed to import rooms from Microsoft 365.'));
        }

        $room = Room::query()->orderBy('id')->first();
        if ($room) {
            $started = hrtime(true);
            try {
                $now = $this->schedule->now();
                $this->graph->listCalendarView($room->o365_resource_email, $now, $now->addMinute());
                $elapsed = (hrtime(true) - $started) / 1e6;
                $checks[] = $this->check(__('Calendar of :room', ['room' => $room->name]), $elapsed > 3000 ? 'warning' : 'ok', $this->ms($started),
                    $elapsed > 3000 ? __('Microsoft Graph answers slowly; screens may lag behind.') : null);
            } catch (\Throwable $e) {
                $checks[] = $this->check(__('Calendar of :room', ['room' => $room->name]), 'error', __('Failed'), $e->getMessage());
            }
        }

        return $checks;
    }

    private function background(): array
    {
        $checks = [];

        $beat = Cache::get(self::SCHEDULER_HEARTBEAT_KEY);
        $beat = is_string($beat) ? CarbonImmutable::parse($beat) : null;
        $checks[] = match (true) {
            $beat === null => $this->check(__('Scheduler'), 'warning', __('Never seen'), __('Offline alerts and clean-up need `php artisan schedule:run` every minute (the Docker image runs it in the “scheduler” container).')),
            $beat->lt(now()->subMinutes(3)) => $this->check(__('Scheduler'), 'error', __('Last run :ago', ['ago' => $beat->diffForHumans()]), __('The scheduler stopped: offline screens are no longer reported.')),
            default => $this->check(__('Scheduler'), 'ok', __('Last run :ago', ['ago' => $beat->diffForHumans()])),
        };

        $connection = (string) config('queue.default');
        $driver = (string) config("queue.connections.{$connection}.driver");
        if ($driver === 'sync') {
            $checks[] = $this->check(__('Queue'), 'info', 'sync', __('Notifications are sent during the request; no worker is needed.'));
        } elseif ($driver === 'database' && Schema::hasTable($table = (string) config("queue.connections.{$connection}.table", 'jobs'))) {
            $pending = DB::table($table)->count();
            $oldest = DB::table($table)->whereNull('reserved_at')->min('available_at');
            $late = $oldest !== null && (int) $oldest < now()->subMinutes(5)->getTimestamp();
            $checks[] = $this->check(__('Queue'), $late ? 'error' : 'ok', trans_choice(':count job waiting|:count jobs waiting', $pending),
                $late ? __('Jobs have been waiting for more than 5 minutes: is `php artisan queue:work` running (the “worker” container)?') : null);
        } else {
            $checks[] = $this->check(__('Queue'), 'info', $driver ?: $connection);
        }

        $failedTable = (string) config('queue.failed.table', 'failed_jobs');
        if (Schema::hasTable($failedTable)) {
            $failed = DB::table($failedTable)->count();
            $checks[] = $this->check(__('Failed jobs'), $failed > 0 ? 'warning' : 'ok', (string) $failed,
                $failed > 0 ? __('Some notifications could not be sent. Details: `php artisan queue:failed`.') : null);
        }

        $mailer = (string) config('mail.default');
        $settings = AppSetting::current();
        $checks[] = in_array($mailer, ['log', 'array'], true) && $settings->notify_admins_by_email
            ? $this->check(__('E-mail'), 'warning', $mailer, __('E-mail alerts are enabled but only written to the log: configure MAIL_MAILER.'))
            : $this->check(__('E-mail'), 'ok', $mailer);

        return $checks;
    }

    private function screens(): array
    {
        $screens = Screen::query()->get();
        $never = $screens->whereNull('seen_at')->count();
        $online = $screens->filter->isOnline()->count();
        $offline = $screens->count() - $never - $online;

        return [
            $this->check(__('Online'), 'ok', (string) $online),
            $this->check(__('Offline'), $offline > 0 ? 'warning' : 'ok', (string) $offline, $offline > 0 ? __('Check the network or the power of these screens.') : null),
            $this->check(__('Never connected'), 'info', (string) $never),
        ];
    }

    private function server(): array
    {
        $production = app()->isProduction();
        $checks = [
            $this->check(__('Version'), 'info', app_version()),
            $this->check('PHP / Laravel', 'info', PHP_VERSION.' / '.app()->version()),
            $this->check(__('Environment'), 'info', app()->environment()),
        ];

        $checks[] = config('app.debug') && $production
            ? $this->check(__('Debug mode'), 'error', __('Enabled'), __('Set APP_DEBUG=false: error pages would reveal internal details.'))
            : $this->check(__('Debug mode'), config('app.debug') ? 'info' : 'ok', config('app.debug') ? __('Enabled') : __('Disabled'));

        $https = str_starts_with((string) config('app.url'), 'https://');
        $checks[] = $this->check(__('Public URL'), $https || ! $production ? 'ok' : 'warning', (string) config('app.url'),
            $https || ! $production ? null : __('Serve the application over HTTPS in production.'));

        $connection = (string) config('database.default');
        $driver = (string) config("database.connections.{$connection}.driver");
        $database = (string) config("database.connections.{$connection}.database");
        $checks[] = $this->check(__('Database'), 'ok', $driver === 'sqlite' && is_file($database)
            ? 'sqlite · '.$this->bytes((int) filesize($database))
            : $driver);

        $free = @disk_free_space(storage_path());
        $total = @disk_total_space(storage_path());
        if ($free !== false && $total) {
            $ratio = $free / $total;
            $checks[] = $this->check(__('Disk space'), match (true) {
                $ratio < 0.02 || $free < 200 * 1024 ** 2 => 'error',
                $ratio < 0.10 || $free < 1024 ** 3 => 'warning',
                default => 'ok',
            }, __(':free free of :total', ['free' => $this->bytes((int) $free), 'total' => $this->bytes((int) $total)]));
        }

        $writable = is_writable(storage_path('framework')) && is_writable(storage_path('logs'));
        $checks[] = $this->check(__('Storage folder'), $writable ? 'ok' : 'error', $writable ? __('Writable') : __('Read-only'),
            $writable ? null : __('The web server must be able to write to storage/.'));

        $store = (string) config('cache.default');
        $checks[] = $this->check(__('Cache'), $store === 'array' ? 'warning' : 'ok', $store,
            $store === 'array' ? __('Nothing is kept between requests: every screen refresh calls Microsoft 365.') : null);

        $opcache = function_exists('opcache_get_status') && (@opcache_get_status(false)['opcache_enabled'] ?? false);
        $checks[] = $this->check('OPcache', $opcache || ! $production ? 'ok' : 'info', $opcache ? __('Enabled') : __('Disabled'),
            $opcache || ! $production ? null : __('Enabling OPcache makes every page faster.'));

        return $checks;
    }

    private function check(string $label, string $status, ?string $value = null, ?string $hint = null): array
    {
        return ['label' => $label, 'status' => $status, 'value' => $value, 'hint' => $hint];
    }

    private function ms(int|float $startedNs): string
    {
        return number_format((hrtime(true) - $startedNs) / 1e6, 0, ',', ' ').' ms';
    }

    private function bytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = $bytes > 0 ? min((int) floor(log($bytes, 1024)), count($units) - 1) : 0;

        return round($bytes / 1024 ** $i, $i > 1 ? 1 : 0).' '.$units[$i];
    }
}
