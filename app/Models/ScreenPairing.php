<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A screen waiting to be attached to a room: it shows `code`, an admin types it in the room page.
 * The device proves it is the one that asked through a secret kept in its session (`device_hash`).
 */
class ScreenPairing extends Model
{
    use MassPrunable;

    public const TTL_MINUTES = 15;

    protected $fillable = ['code', 'device_hash', 'room_id', 'screen_id', 'expires_at'];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime'];
    }

    public static function generateCode(): string
    {
        do {
            $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        } while (static::query()->where('code', $code)->exists());

        return $code;
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    public function screen(): BelongsTo
    {
        return $this->belongsTo(Screen::class);
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function prunable(): Builder
    {
        return static::query()->where('expires_at', '<', now()->subDay());
    }
}
