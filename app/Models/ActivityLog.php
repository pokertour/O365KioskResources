<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ActivityLog extends Model
{
    use MassPrunable;

    public const UPDATED_AT = null;

    public const RETENTION_DAYS = 180;

    protected $fillable = ['room_id', 'user_id', 'actor', 'action', 'description', 'meta', 'ip'];

    protected function casts(): array
    {
        return ['meta' => 'array'];
    }

    /**
     * Records what happened, attributing it to the signed-in admin, or to the room screen otherwise.
     *
     * @param  array<string, mixed>  $meta
     */
    public static function record(string $action, ?Room $room = null, ?string $description = null, array $meta = [], ?string $actor = null): self
    {
        $user = auth()->user();

        return static::query()->create([
            'room_id' => $room?->id,
            'user_id' => $user?->id,
            'actor' => $actor ?? ($user ? 'admin' : 'screen'),
            'action' => $action,
            'description' => $description !== null ? mb_substr($description, 0, 255) : null,
            'meta' => $meta ?: null,
            'ip' => app()->runningInConsole() ? null : request()->ip(),
        ]);
    }

    public function label(): string
    {
        return match ($this->action) {
            'room.created' => __('Room created'),
            'room.updated' => __('Room settings changed'),
            'room.deleted' => __('Room deleted'),
            'screen.link_rotated' => __('Screen link regenerated'),
            'screen.paired' => __('Screen paired'),
            'screen.added' => __('Screen added'),
            'screen.removed' => __('Screen removed'),
            'screen.offline' => __('Screen offline'),
            'screen.online' => __('Screen back online'),
            'booking.created' => __('Booking created'),
            'booking.updated' => __('Booking changed'),
            'booking.cancelled' => __('Booking cancelled'),
            'meeting.ended' => __('Meeting ended early'),
            'meeting.extended' => __('Meeting extended'),
            'issue.reported' => __('Issue reported'),
            'issue.resolved' => __('Issue resolved'),
            'announcement.saved' => __('Announcement saved'),
            'announcement.deleted' => __('Announcement deleted'),
            'settings.updated' => __('Settings changed'),
            'user.created' => __('Administrator created'),
            'user.updated' => __('Access changed'),
            'user.deleted' => __('Account deleted'),
            'auth.sso' => __('Signed in with Microsoft'),
            'signage.created' => __('Wayfinding display created'),
            'signage.updated' => __('Wayfinding display changed'),
            'signage.deleted' => __('Wayfinding display deleted'),
            'signage.link_rotated' => __('Wayfinding display link regenerated'),
            default => $this->action,
        };
    }

    public function icon(): string
    {
        return match ($this->action) {
            'meeting.ended' => 'stop-circle',
            'meeting.extended' => 'plus-circle',
            'booking.cancelled' => 'x-circle',
            'issue.resolved' => 'check-circle',
            'screen.offline' => 'signal-slash',
            'screen.online' => 'signal',
            'screen.link_rotated' => 'arrow-path',
            'auth.sso' => 'key',
            default => match (strtok($this->action, '.')) {
                'booking', 'meeting' => 'calendar-days',
                'screen' => 'tv',
                'issue' => 'exclamation-triangle',
                'announcement' => 'megaphone',
                'room' => 'building-office-2',
                'signage' => 'map',
                'user', 'auth' => 'user-circle',
                default => 'cog-6-tooth',
            },
        };
    }

    /**
     * Colour family of the event in the timeline: brand, available, busy, soon, warning or neutral.
     */
    public function tone(): string
    {
        return match (true) {
            in_array($this->action, ['booking.created', 'booking.updated'], true) => 'brand',
            in_array($this->action, ['meeting.ended', 'screen.online', 'screen.paired', 'issue.resolved', 'room.created', 'user.created', 'signage.created'], true) => 'available',
            $this->action === 'meeting.extended' => 'soon',
            in_array($this->action, ['screen.offline', 'issue.reported'], true) => 'warning',
            $this->action === 'booking.cancelled' || str_ends_with($this->action, '.deleted') || $this->action === 'screen.removed' => 'busy',
            default => 'neutral',
        };
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function prunable(): Builder
    {
        return static::query()->where('created_at', '<', now()->subDays(self::RETENTION_DAYS));
    }
}
