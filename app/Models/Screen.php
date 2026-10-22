<?php

namespace App\Models;

use App\Services\AdminNotifier;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * A display in front of a room. Each screen has its own secret link (token), its own supervision
 * data and may override the room's screen settings.
 */
class Screen extends Model
{
    /** Settings a screen may override (null = room setting, then global setting). */
    public const OVERRIDES = ['text_scale', 'night_mode', 'night_start', 'night_end'];

    protected $fillable = ['name', 'kiosk_theme', 'text_scale', 'night_mode', 'night_start', 'night_end'];

    protected $hidden = ['token', 'token_hash'];

    protected function casts(): array
    {
        return [
            'token' => 'encrypted',
            'seen_at' => 'datetime',
            'info' => 'array',
            'offline_alerted_at' => 'datetime',
            'text_scale' => 'integer',
            'night_mode' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Screen $screen): void {
            if (blank($screen->token_hash)) {
                $plain = Str::random(48);
                $screen->token = $plain;
                $screen->token_hash = self::hashToken($plain);
            }
        });
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    public static function hashToken(string $plain): string
    {
        return hash('sha256', $plain);
    }

    /**
     * Issues a new link for this screen; the device using the previous one is disconnected.
     */
    public function rotateToken(): string
    {
        $plain = Str::random(48);
        $this->forceFill(['token' => $plain, 'token_hash' => self::hashToken($plain)])->save();

        return $plain;
    }

    public function tokenMatches(string $plain): bool
    {
        return $this->token_hash !== '' && hash_equals($this->token_hash, self::hashToken($plain));
    }

    /**
     * Link to open on the device (only for tokens stored encrypted, i.e. issued by this version).
     */
    public function kioskUrl(): ?string
    {
        $token = $this->token;
        if (! is_string($token) || $token === '' || ! $this->tokenMatches($token)) {
            return null;
        }

        return route('kiosk.show', ['room' => $this->room, 'token' => $token]);
    }

    /**
     * Online when it polled recently (a few refresh intervals).
     */
    public function isOnline(): bool
    {
        $grace = max(180, AppSetting::current()->refreshSeconds() * 3);

        return $this->seen_at !== null && $this->seen_at->gt(now()->subSeconds($grace));
    }

    public function fullName(): string
    {
        return $this->room->name.' · '.$this->name;
    }

    /**
     * Heartbeat. Written at most every 30 s, without bumping updated_at (open screens reload when
     * their settings change). A screen coming back after an offline alert notifies the admins.
     *
     * @param  array<string, mixed>|null  $info
     */
    public function markSeen(?array $info = null): void
    {
        if ($info === null && $this->seen_at?->gt(now()->subSeconds(30))) {
            return;
        }

        $wasAlerted = $this->offline_alerted_at !== null;
        $values = ['seen_at' => now(), 'offline_alerted_at' => null];
        if ($info !== null) {
            $values['info'] = json_encode($info);
        }
        static::query()->toBase()->where('id', $this->id)->update($values);
        $this->forceFill(['seen_at' => $values['seen_at'], 'offline_alerted_at' => null] + ($info !== null ? ['info' => $info] : []))->syncOriginal();

        if ($wasAlerted) {
            ActivityLog::record('screen.online', $this->room, $this->name, actor: 'system');
            app(AdminNotifier::class)->send(
                __('Screen back online: :room', ['room' => $this->fullName()]),
                __('The screen of :room is reachable again.', ['room' => $this->fullName()]),
                route('admin.rooms.show', ['room' => $this->room, 'tab' => 'screen']),
                __('Open the room'),
            );
        }
    }

    public function hasOverrides(): bool
    {
        return $this->kiosk_theme !== null || collect(self::OVERRIDES)->contains(fn ($k) => $this->{$k} !== null);
    }
}
