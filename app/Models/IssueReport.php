<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IssueReport extends Model
{
    /** Report categories besides the room's equipment items. */
    public const GENERAL_CATEGORIES = ['cleanliness', 'temperature', 'furniture', 'other'];

    protected $fillable = ['room_id', 'category', 'message'];

    protected function casts(): array
    {
        return ['resolved_at' => 'datetime'];
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNull('resolved_at');
    }

    public static function categoryLabel(string $category): string
    {
        if (array_key_exists($category, Room::EQUIPMENT)) {
            return Room::equipmentLabel($category);
        }

        return match ($category) {
            'cleanliness' => __('Cleanliness'),
            'temperature' => __('Temperature'),
            'furniture' => __('Furniture'),
            default => __('Other'),
        };
    }
}
