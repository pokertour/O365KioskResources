<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

class AppSetting extends Model
{
    public const LOCALES = [
        'fr' => 'Français',
        'en' => 'English',
    ];

    public const TIME_FORMATS = ['24h', '12h'];

    public const REFRESH_OPTIONS = [15, 30, 60, 120];

    public const DURATION_OPTIONS = [10, 15, 20, 30, 45, 60, 90, 120];

    /** 0 disables the "starting soon" state. */
    public const SOON_OPTIONS = [0, 5, 10, 15];

    public const TEXT_SCALES = [90, 100, 115, 130];

    public const AGENDA_VIEWS = ['timeline', 'list'];

    public const DEFAULTS = [
        'locale' => 'fr',
        'timezone' => 'Europe/Paris',
        'time_format' => '24h',
        'kiosk_refresh_seconds' => 30,
        'quick_book_durations' => '[15,30,60]',
        'max_booking_minutes' => 240,
        'show_organizer' => true,
        'soon_minutes' => 10,
        'allow_end_extend' => true,
        'text_scale' => 100,
        'night_mode' => false,
        'night_start' => '20:00',
        'night_end' => '07:00',
        'agenda_view' => 'timeline',
        'day_start' => '08:00',
        'day_end' => '19:00',
        'offline_alert_minutes' => 15,
        'notify_admins_by_email' => true,
        'sso_enabled' => false,
        'password_login_enabled' => true,
        'show_qr_code' => true,
    ];

    public const OFFLINE_ALERT_OPTIONS = [0, 5, 15, 30, 60];

    protected $fillable = [
        'locale',
        'timezone',
        'time_format',
        'kiosk_refresh_seconds',
        'quick_book_durations',
        'max_booking_minutes',
        'show_organizer',
        'soon_minutes',
        'allow_end_extend',
        'text_scale',
        'night_mode',
        'night_start',
        'night_end',
        'agenda_view',
        'day_start',
        'day_end',
        'offline_alert_minutes',
        'notify_admins_by_email',
        'teams_webhook_url',
        'sso_enabled',
        'password_login_enabled',
        'show_qr_code',
    ];

    protected $hidden = ['teams_webhook_url'];

    protected $attributes = self::DEFAULTS;

    private static ?self $cached = null;

    private static float $cachedAt = 0;

    protected function casts(): array
    {
        return [
            'quick_book_durations' => 'array',
            'kiosk_refresh_seconds' => 'integer',
            'max_booking_minutes' => 'integer',
            'show_organizer' => 'boolean',
            'soon_minutes' => 'integer',
            'allow_end_extend' => 'boolean',
            'text_scale' => 'integer',
            'night_mode' => 'boolean',
            'offline_alert_minutes' => 'integer',
            'notify_admins_by_email' => 'boolean',
            'teams_webhook_url' => 'encrypted',
            'sso_enabled' => 'boolean',
            'password_login_enabled' => 'boolean',
            'show_qr_code' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::saved(fn () => static::$cached = null);
    }

    /**
     * Settings row, memoised briefly (long-running workers still see admin changes).
     * Falls back to defaults before migrations run.
     */
    public static function current(): self
    {
        if (static::$cached !== null && microtime(true) - static::$cachedAt < 10) {
            return static::$cached;
        }

        try {
            if (! Schema::hasTable('app_settings')) {
                return new self;
            }

            static::$cachedAt = microtime(true);

            return static::$cached = static::query()->firstOrCreate([]);
        } catch (\Throwable) {
            // Database unavailable (e.g. while rendering an error page): use the defaults.
            return new self;
        }
    }

    /**
     * Global settings with the room's own screen overrides applied (not persisted).
     */
    public static function forRoom(Room $room): self
    {
        $settings = clone static::current();
        foreach (Room::SCREEN_OVERRIDES as $key) {
            if ($room->{$key} !== null) {
                $settings->{$key} = $room->{$key};
            }
        }

        return $settings;
    }

    /**
     * Settings of a given screen: global, then room overrides, then screen overrides.
     */
    public static function forScreen(Screen $screen): self
    {
        $settings = static::forRoom($screen->room);
        foreach (Screen::OVERRIDES as $key) {
            if ($screen->{$key} !== null) {
                $settings->{$key} = $screen->{$key};
            }
        }

        return $settings;
    }

    /**
     * Password login stays available unless SSO is on and an admin explicitly turned passwords off.
     */
    public function passwordLoginAllowed(): bool
    {
        return $this->password_login_enabled || ! $this->sso_enabled;
    }

    public static function flushCache(): void
    {
        static::$cached = null;
    }

    public function timezone(): string
    {
        return in_array($this->timezone, timezone_identifiers_list(), true) ? $this->timezone : 'UTC';
    }

    public function locale(): string
    {
        return array_key_exists($this->locale, self::LOCALES) ? $this->locale : self::DEFAULTS['locale'];
    }

    public function uses12h(): bool
    {
        return $this->time_format === '12h';
    }

    /**
     * @return list<int>
     */
    public function quickBookDurations(): array
    {
        $durations = array_values(array_intersect(self::DURATION_OPTIONS, array_map('intval', (array) $this->quick_book_durations)));

        return $durations !== [] ? $durations : [15, 30, 60];
    }

    public function soonMinutes(): int
    {
        return in_array($this->soon_minutes, self::SOON_OPTIONS, true) ? $this->soon_minutes : 10;
    }

    public function textScale(): int
    {
        return in_array($this->text_scale, self::TEXT_SCALES, true) ? $this->text_scale : 100;
    }

    /**
     * Whether the screens are in night mode at the given local time. The range may span midnight.
     */
    public function isNight(\DateTimeInterface $now): bool
    {
        if (! $this->night_mode || $this->night_start === $this->night_end) {
            return false;
        }

        $time = $now->format('H:i');

        return $this->night_start < $this->night_end
            ? $time >= $this->night_start && $time < $this->night_end
            : $time >= $this->night_start || $time < $this->night_end;
    }

    public function agendaView(): string
    {
        return in_array($this->agenda_view, self::AGENDA_VIEWS, true) ? $this->agenda_view : 'timeline';
    }

    /**
     * Working hours shown by the timeline, as hours since midnight (e.g. 8.0 and 19.5).
     *
     * @return array{0: float, 1: float}
     */
    public function dayHours(): array
    {
        $toHours = function (?string $time, float $fallback): float {
            if (! is_string($time) || preg_match('/^(\d{2}):(\d{2})$/', $time, $m) !== 1) {
                return $fallback;
            }

            return (int) $m[1] + (int) $m[2] / 60;
        };

        $start = $toHours($this->day_start, 8);
        $end = $toHours($this->day_end, 19);

        return $end > $start ? [$start, $end] : [8.0, 19.0];
    }

    public function refreshSeconds(): int
    {
        return in_array($this->kiosk_refresh_seconds, self::REFRESH_OPTIONS, true) ? $this->kiosk_refresh_seconds : 30;
    }
}
