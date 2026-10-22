<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class Announcement extends Model
{
    public const LEVELS = ['info', 'warning', 'danger'];

    protected $fillable = ['message', 'level', 'room_ids', 'starts_at', 'ends_at'];

    protected function casts(): array
    {
        return [
            'room_ids' => 'array',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query
            ->where(fn ($q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
            ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', now()));
    }

    /**
     * Announcement to show on a room screen right now (the most recent one wins).
     */
    public static function currentFor(Room $room): ?self
    {
        return static::query()->active()->latest()->get()
            ->first(fn (self $a) => empty($a->room_ids) || in_array($room->id, array_map('intval', $a->room_ids), true));
    }

    public function isActive(): bool
    {
        return ($this->starts_at === null || $this->starts_at->lte(now()))
            && ($this->ends_at === null || $this->ends_at->gt(now()));
    }
}
