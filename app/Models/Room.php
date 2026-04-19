<?php

namespace App\Models;

use Database\Factories\RoomFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Room extends Model
{
    /** @use HasFactory<RoomFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'o365_resource_email',
        'kiosk_token_hash',
    ];

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public static function hashKioskToken(string $plain): string
    {
        return hash('sha256', $plain);
    }

    /**
     * @return array{0: string, 1: string} Plain token, hash to persist
     */
    public static function generateKioskCredentials(): array
    {
        $plain = Str::random(48);

        return [$plain, self::hashKioskToken($plain)];
    }

    public function kioskTokenMatches(string $plain): bool
    {
        if ($this->kiosk_token_hash === '' || $this->kiosk_token_hash === null) {
            return false;
        }

        $hash = self::hashKioskToken($plain);

        return hash_equals($this->kiosk_token_hash, $hash);
    }

    protected static function booted(): void
    {
        static::creating(function (Room $room): void {
            if (filled($room->slug)) {
                return;
            }
            $base = Str::slug($room->name);
            $slug = $base;
            $i = 1;
            while (static::query()->where('slug', $slug)->exists()) {
                $slug = $base.'-'.$i;
                $i++;
            }
            $room->slug = $slug;
        });
    }
}
