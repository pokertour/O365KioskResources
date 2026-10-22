<?php

namespace App\Models;

use Database\Factories\RoomFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Room extends Model
{
    /** @use HasFactory<RoomFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'o365_resource_email',
        'location',
        'capacity',
        'booking_enabled',
        'show_subjects',
        'equipment',
        'kiosk_theme',
        'text_scale',
        'day_start',
        'day_end',
        'night_mode',
        'night_start',
        'night_end',
    ];

    /** Equipment keys and their icon (Heroicons) shown on the screen. */
    public const EQUIPMENT = [
        'video' => 'video-camera',
        'display' => 'tv',
        'whiteboard' => 'pencil-square',
        'phone' => 'phone',
        'power' => 'bolt',
        'accessible' => 'check-badge',
    ];

    /** Global screen settings a room may override (null = inherit). */
    public const SCREEN_OVERRIDES = ['text_scale', 'day_start', 'day_end', 'night_mode', 'night_start', 'night_end'];

    protected $attributes = [
        'booking_enabled' => true,
        'show_subjects' => true,
    ];

    protected function casts(): array
    {
        return [
            'capacity' => 'integer',
            'booking_enabled' => 'boolean',
            'show_subjects' => 'boolean',
            'equipment' => 'array',
            'text_scale' => 'integer',
            'night_mode' => 'boolean',
        ];
    }

    public static function equipmentLabel(string $key): string
    {
        return match ($key) {
            'video' => __('Video conferencing'),
            'display' => __('Display screen'),
            'whiteboard' => __('Whiteboard'),
            'phone' => __('Conference phone'),
            'power' => __('Power outlets'),
            'accessible' => __('Wheelchair accessible'),
            default => $key,
        };
    }

    /**
     * @return list<string>
     */
    public function equipmentList(): array
    {
        return array_values(array_intersect(array_keys(self::EQUIPMENT), (array) $this->equipment));
    }

    public function screens(): HasMany
    {
        return $this->hasMany(Screen::class)->orderBy('id');
    }

    /**
     * First screen of the room (created with the room), used by the convenience methods below.
     */
    public function primaryScreen(): Screen
    {
        return $this->screens()->first() ?? $this->screens()->create(['name' => __('Main screen')]);
    }

    /**
     * Rotates the primary screen's link and returns the new token.
     */
    public function rotateKioskToken(): string
    {
        return $this->primaryScreen()->rotateToken();
    }

    /**
     * Link of the primary screen.
     */
    public function kioskUrl(): ?string
    {
        return $this->primaryScreen()->kioskUrl();
    }

    public function screenFor(string $token): ?Screen
    {
        return $this->screens()->where('token_hash', Screen::hashToken($token))->first();
    }

    public function hasScreenOverrides(): bool
    {
        return $this->kiosk_theme !== null || collect(self::SCREEN_OVERRIDES)->contains(fn ($k) => $this->{$k} !== null);
    }

    public function issueReports(): HasMany
    {
        return $this->hasMany(IssueReport::class);
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public static function uniqueSlug(string $name, ?int $ignoreId = null): string
    {
        $base = Str::slug($name) ?: 'salle';
        $slug = $base;
        $i = 1;
        while (static::query()->where('slug', $slug)->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))->exists()) {
            $slug = $base.'-'.$i;
            $i++;
        }

        return $slug;
    }

    protected static function booted(): void
    {
        static::creating(function (Room $room): void {
            if (blank($room->slug)) {
                $room->slug = static::uniqueSlug($room->name);
            }
        });

        // Every room starts with one screen, so its link is ready right away.
        static::created(function (Room $room): void {
            $room->screens()->create(['name' => __('Main screen')]);
        });
    }
}
