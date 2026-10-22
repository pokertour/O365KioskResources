<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Str;

/**
 * Wayfinding display: a screen in a lobby or a corridor showing the status of several rooms,
 * each with the direction to take. Like room screens, it is opened with its own secret link.
 */
class Signboard extends Model
{
    /** Directions an administrator can give to a room (null = no arrow). */
    public const DIRECTIONS = ['left', 'right', 'up', 'down', 'up-left', 'up-right', 'down-left', 'down-right', 'stairs-up', 'stairs-down', 'elevator'];

    public const ROOMS_PER_PAGE = [3, 4, 5, 6, 8];

    /** Seconds each page stays on screen when the rooms do not fit on one. */
    public const PAGE_SECONDS = 10;

    protected $fillable = ['name', 'rooms_per_page'];

    protected $hidden = ['token', 'token_hash'];

    protected $attributes = ['rooms_per_page' => 5];

    protected function casts(): array
    {
        return [
            'token' => 'encrypted',
            'seen_at' => 'datetime',
            'rooms_per_page' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Signboard $signboard): void {
            if (blank($signboard->token_hash)) {
                $plain = Str::random(48);
                $signboard->token = $plain;
                $signboard->token_hash = Screen::hashToken($plain);
            }
        });
    }

    public function rooms(): BelongsToMany
    {
        return $this->belongsToMany(Room::class)
            ->withPivot(['position', 'direction'])
            ->orderByPivot('position');
    }

    /**
     * Issues a new link; the display using the previous one stops working.
     */
    public function rotateToken(): string
    {
        $plain = Str::random(48);
        $this->forceFill(['token' => $plain, 'token_hash' => Screen::hashToken($plain)])->save();

        return $plain;
    }

    public function tokenMatches(string $plain): bool
    {
        return $this->token_hash !== '' && hash_equals($this->token_hash, Screen::hashToken($plain));
    }

    public function url(): ?string
    {
        $token = $this->token;

        return is_string($token) && $token !== '' && $this->tokenMatches($token)
            ? route('kiosk.signage', ['signboard' => $this, 'token' => $token])
            : null;
    }

    public function isOnline(): bool
    {
        $grace = max(180, AppSetting::current()->refreshSeconds() * 3);

        return $this->seen_at !== null && $this->seen_at->gt(now()->subSeconds($grace));
    }

    /**
     * Heartbeat, at most every 30 s and without bumping updated_at (the display reloads when its
     * configuration changes).
     */
    public function markSeen(): void
    {
        if ($this->seen_at?->gt(now()->subSeconds(30))) {
            return;
        }

        static::query()->toBase()->where('id', $this->id)->update(['seen_at' => now()]);
        $this->forceFill(['seen_at' => now()])->syncOriginal();
    }

    /**
     * Rooms, in order, with their direction: [room_id => direction|null]. Replaces the current list.
     *
     * @param  array<int, ?string>  $directions
     */
    public function syncRooms(array $directions): void
    {
        $rows = [];
        foreach (array_keys($directions) as $position => $roomId) {
            $direction = $directions[$roomId];
            $rows[$roomId] = [
                'position' => $position,
                'direction' => in_array($direction, self::DIRECTIONS, true) ? $direction : null,
            ];
        }
        $this->rooms()->sync($rows);
        $this->touch();
    }
}
