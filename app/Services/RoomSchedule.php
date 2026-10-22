<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\AppSetting;
use App\Models\Room;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Today's agenda of a room, cached briefly so that several screens polling the same
 * room (and the admin page) don't each hit Microsoft Graph.
 */
class RoomSchedule
{
    public const FRESH_SECONDS = 20;

    public const MIN_DURATION_MINUTES = 5;

    /**
     * Graph's calendarView is eventually consistent: right after a change it may still return the
     * previous state for a few seconds. Changes made from the app are replayed on top of Graph's
     * answer for this long so screens switch state immediately.
     */
    public const OVERRIDE_SECONDS = 120;

    public function __construct(private MicrosoftGraphService $graph) {}

    public function timezone(): string
    {
        return AppSetting::current()->timezone();
    }

    public function now(): CarbonImmutable
    {
        return CarbonImmutable::now($this->timezone());
    }

    /**
     * @return array{events: list<array{id: string, subject: string, start: CarbonImmutable, end: CarbonImmutable, organizer: ?string, is_private: bool, is_all_day: bool}>, fetched_at: ?CarbonImmutable, stale: bool, error: ?string}
     */
    public function today(Room $room, bool $fresh = false, int $ttl = self::FRESH_SECONDS): array
    {
        return $this->day($room, $this->now(), $fresh, $ttl);
    }

    /**
     * Agenda of any local day (today, or the next working day for the preview).
     *
     * @return array{events: list<array<string, mixed>>, fetched_at: ?CarbonImmutable, stale: bool, error: ?string}
     */
    public function day(Room $room, CarbonImmutable $date, bool $fresh = false, int $ttl = self::FRESH_SECONDS): array
    {
        if (! $this->graph->isConfigured()) {
            return ['events' => [], 'fetched_at' => null, 'stale' => false, 'error' => 'not_configured'];
        }

        $start = $date->setTimezone($this->timezone())->startOfDay();
        $day = $start->toDateString();
        $freshKey = "room-schedule:{$room->id}:{$day}";
        $lastGoodKey = "room-schedule:{$room->id}:{$day}:last-good";

        if ($fresh) {
            Cache::forget($freshKey);
        }

        try {
            $payload = Cache::remember($freshKey, $ttl, function () use ($room, $lastGoodKey, $start): array {
                $events = $this->graph->listCalendarView($room->o365_resource_email, $start, $start->endOfDay());
                $payload = ['events' => $events, 'fetched_at' => $this->now()->toIso8601String()];
                Cache::put($lastGoodKey, $payload, now()->addDay());

                return $payload;
            });
            $stale = false;
            $error = null;
        } catch (\Throwable $e) {
            Log::warning('Room schedule fetch failed', ['room' => $room->id, 'exception' => $e->getMessage()]);
            // Keep showing the last known agenda rather than an empty screen during a Graph hiccup.
            $payload = Cache::get($lastGoodKey, ['events' => [], 'fetched_at' => null]);
            $stale = true;
            $error = $e->getMessage();
        }

        return [
            'events' => $this->normalize($this->applyOverrides($room, $day, $payload['events'])),
            'fetched_at' => $payload['fetched_at'] ? CarbonImmutable::parse($payload['fetched_at'])->setTimezone($this->timezone()) : null,
            'stale' => $stale,
            'error' => $error,
        ];
    }

    /**
     * Next Monday–Friday after the given day.
     */
    public function nextWorkingDay(?CarbonImmutable $from = null): CarbonImmutable
    {
        $day = ($from ?? $this->now())->addDay()->startOfDay();
        while ($day->isWeekend()) {
            $day = $day->addDay();
        }

        return $day;
    }

    /**
     * Free periods of at least $minMinutes between $from and $to.
     *
     * @param  list<array{start: CarbonImmutable, end: CarbonImmutable}>  $events
     * @return list<array{start: CarbonImmutable, end: CarbonImmutable, minutes: int}>
     */
    public function freeSlots(array $events, CarbonImmutable $from, CarbonImmutable $to, int $minMinutes = 15): array
    {
        $slots = [];
        $cursor = $from;

        foreach ($events as $event) {
            if ($event['end']->lessThanOrEqualTo($cursor)) {
                continue;
            }
            if ($event['start']->greaterThan($cursor)) {
                $slots[] = [$cursor, $event['start']->min($to)];
            }
            $cursor = $cursor->max($event['end']);
            if ($cursor->greaterThanOrEqualTo($to)) {
                break;
            }
        }

        if ($cursor->lessThan($to)) {
            $slots[] = [$cursor, $to];
        }

        $out = [];
        foreach ($slots as [$start, $end]) {
            $minutes = (int) $start->diffInMinutes($end);
            if ($minutes >= $minMinutes) {
                $out[] = ['start' => $start, 'end' => $end, 'minutes' => $minutes];
            }
        }

        return $out;
    }

    /**
     * Side-by-side layout for overlapping events: adds `col` (0-based) and `cols` to each event.
     *
     * @param  list<array{start: CarbonImmutable, end: CarbonImmutable}>  $events
     * @return list<array<string, mixed>>
     */
    public function layoutColumns(array $events): array
    {
        $out = [];
        $group = [];
        $groupEnd = null;
        $flush = function () use (&$out, &$group): void {
            $cols = $group === [] ? 1 : max(array_column($group, 'col')) + 1;
            foreach ($group as $event) {
                $out[] = $event + ['cols' => $cols];
            }
            $group = [];
        };

        foreach ($events as $event) {
            if ($groupEnd !== null && $event['start']->greaterThanOrEqualTo($groupEnd)) {
                $flush();
                $groupEnd = null;
            }

            // First column whose last event has ended.
            $col = 0;
            while (collect($group)->contains(fn ($e) => $e['col'] === $col && $e['end']->greaterThan($event['start']))) {
                $col++;
            }

            $group[] = $event + ['col' => $col];
            $groupEnd = $groupEnd === null ? $event['end'] : $groupEnd->max($event['end']);
        }
        $flush();

        return $out;
    }

    public function forget(Room $room): void
    {
        Cache::forget("room-schedule:{$room->id}:{$this->now()->toDateString()}");
    }

    /**
     * Drops every cached agenda of the current local day, including the "last good" fallbacks.
     */
    public function forgetAll(): void
    {
        $day = $this->now()->toDateString();
        foreach (Room::query()->pluck('id') as $id) {
            Cache::forget("room-schedule:{$id}:{$day}");
            Cache::forget("room-schedule:{$id}:{$day}:last-good");
        }
    }

    /**
     * @param  list<array{start: CarbonImmutable, end: CarbonImmutable}>  $events
     * @return array{state: 'busy'|'soon'|'free', busy: bool, soon: bool, current: ?array, next: ?array, until: ?CarbonImmutable, starts_in: ?int}
     */
    public function status(array $events, ?CarbonImmutable $now = null): array
    {
        $now ??= $this->now();
        $current = null;
        $next = null;

        foreach ($events as $event) {
            if ($event['start']->lessThanOrEqualTo($now) && $event['end']->greaterThan($now)) {
                $current ??= $event;
            } elseif ($event['start']->greaterThan($now) && $next === null) {
                $next = $event;
            }
        }

        if ($current !== null) {
            // Back-to-back meetings: the room stays busy until the end of the chain.
            $until = $current['end'];
            foreach ($events as $event) {
                if ($event['start']->lessThanOrEqualTo($until) && $event['end']->greaterThan($until)) {
                    $until = $event['end'];
                }
            }

            return ['state' => 'busy', 'busy' => true, 'soon' => false, 'current' => $current, 'next' => $next, 'until' => $until, 'starts_in' => null];
        }

        $startsIn = $next ? (int) ceil($now->diffInSeconds($next['start']) / 60) : null;
        $soonMinutes = AppSetting::current()->soonMinutes();
        $soon = $startsIn !== null && $soonMinutes > 0 && $startsIn <= $soonMinutes;

        return [
            'state' => $soon ? 'soon' : 'free',
            'busy' => false,
            'soon' => $soon,
            'current' => null,
            'next' => $next,
            'until' => $next['start'] ?? null,
            'starts_in' => $startsIn,
        ];
    }

    /**
     * Ends the meeting in progress now (frees the room).
     *
     * @throws BookingException
     */
    public function endNow(Room $room, string $eventId): void
    {
        $event = $this->currentEvent($room, $eventId);
        $end = $this->now()->startOfMinute();

        // Started this very minute: Graph refuses an end equal to the start, so drop the meeting.
        if ($end->lessThanOrEqualTo($event['start'])) {
            try {
                $this->graph->deleteCalendarEvent($room->o365_resource_email, $eventId);
            } catch (RuntimeException $e) {
                Log::warning('Room event delete failed', ['room' => $room->id, 'exception' => $e->getMessage()]);
                throw new BookingException(__('Microsoft 365 rejected the change. Please try again in a moment.'), previous: $e);
            } finally {
                $this->forget($room);
            }
            $this->recordOverride($room, $eventId, ['deleted' => true]);
            ActivityLog::record('meeting.ended', $room, $this->displaySubject($room, $event));

            return;
        }

        $this->updateEnd($room, $eventId, $end);
        ActivityLog::record('meeting.ended', $room, $this->displaySubject($room, $event), ['end' => $end->format('H:i')]);
    }

    /**
     * Extends the meeting in progress, if the room stays free long enough.
     *
     * @throws BookingException
     */
    public function extend(Room $room, string $eventId, int $minutes): CarbonImmutable
    {
        $event = $this->currentEvent($room, $eventId);
        $end = $event['end']->addMinutes($minutes);

        if ($end->greaterThan($this->now()->endOfDay())) {
            throw new BookingException(__('The meeting cannot be extended past midnight.'));
        }

        foreach ($this->today($room)['events'] as $other) {
            if ($other['id'] !== $eventId && $other['start']->lessThan($end) && $other['end']->greaterThan($event['end'])) {
                throw new BookingException(__('The room is booked at :time.', ['time' => fmt_time($other['start'])]));
            }
        }

        $this->updateEnd($room, $eventId, $end);
        ActivityLog::record('meeting.extended', $room, $this->displaySubject($room, $event), ['minutes' => $minutes, 'end' => $end->format('H:i')]);

        return $end;
    }

    /**
     * @return array{id: string, start: CarbonImmutable, end: CarbonImmutable}
     */
    private function currentEvent(Room $room, string $eventId): array
    {
        if (! $this->graph->isConfigured()) {
            throw new BookingException(__('The Microsoft 365 connection is not configured.'));
        }

        $schedule = $this->today($room, fresh: true);
        if ($schedule['stale']) {
            throw new BookingException(__('The room’s availability cannot be checked right now.'));
        }

        $current = $this->status($schedule['events'])['current'];
        if ($current === null || $current['id'] !== $eventId) {
            throw new BookingException(__('This meeting is no longer in progress.'));
        }

        return $current;
    }

    private function updateEnd(Room $room, string $eventId, CarbonImmutable $end): void
    {
        try {
            $this->graph->updateEventEnd($room->o365_resource_email, $eventId, $end);
        } catch (RuntimeException $e) {
            Log::warning('Room event update failed', ['room' => $room->id, 'exception' => $e->getMessage()]);
            throw new BookingException(__('Microsoft 365 rejected the change. Please try again in a moment.'), previous: $e);
        } finally {
            $this->forget($room);
        }
        $this->recordOverride($room, $eventId, ['end' => $end->format('Y-m-d\TH:i:s')]);
    }

    /**
     * Creates a booking after re-checking the live agenda for overlaps.
     *
     * @throws BookingException with a message safe to show on a public screen
     */
    public function book(Room $room, string $subject, CarbonImmutable $start, CarbonImmutable $end): void
    {
        if (! $this->graph->isConfigured()) {
            throw new BookingException(__('The Microsoft 365 connection is not configured.'));
        }

        $this->assertSlotAvailable($room, $start, $end);

        try {
            $id = $this->graph->createCalendarEvent($room->o365_resource_email, $subject, $start, $end);
        } catch (RuntimeException $e) {
            Log::warning('Room booking failed', ['room' => $room->id, 'exception' => $e->getMessage()]);
            throw new BookingException(__('Microsoft 365 rejected the booking. Please try again in a moment.'), previous: $e);
        } finally {
            $this->forget($room);
        }

        $this->recordOverride($room, $id, ['added' => [
            'id' => $id,
            'subject' => $subject,
            'start' => $start->format('Y-m-d\TH:i:s'),
            'end' => $end->format('Y-m-d\TH:i:s'),
            'organizer' => $room->name,
            'organizer_email' => strtolower($room->o365_resource_email),
            'is_private' => false,
            'is_all_day' => false,
        ]]);
        ActivityLog::record('booking.created', $room, $subject, ['start' => $start->format('H:i'), 'end' => $end->format('H:i')]);
    }

    /**
     * Changes the subject and times of an existing meeting of today.
     *
     * @throws BookingException
     */
    public function reschedule(Room $room, string $eventId, string $subject, CarbonImmutable $start, CarbonImmutable $end): void
    {
        if (! $this->graph->isConfigured()) {
            throw new BookingException(__('The Microsoft 365 connection is not configured.'));
        }

        $this->assertSlotAvailable($room, $start, $end, ignoreId: $eventId);

        try {
            $this->graph->updateCalendarEvent($room->o365_resource_email, $eventId, $subject, $start, $end);
        } catch (RuntimeException $e) {
            Log::warning('Room event update failed', ['room' => $room->id, 'exception' => $e->getMessage()]);
            throw new BookingException(__('Microsoft 365 rejected the change. Please try again in a moment.'), previous: $e);
        } finally {
            $this->forget($room);
        }

        ActivityLog::record('booking.updated', $room, $subject, ['start' => $start->format('H:i'), 'end' => $end->format('H:i')]);
        $this->recordOverride($room, $eventId, [
            'subject' => $subject,
            'start' => $start->format('Y-m-d\TH:i:s'),
            'end' => $end->format('Y-m-d\TH:i:s'),
        ]);
    }

    /**
     * Duration limits and overlap check against the live agenda.
     *
     * @throws BookingException
     */
    private function assertSlotAvailable(Room $room, CarbonImmutable $start, CarbonImmutable $end, ?string $ignoreId = null): void
    {
        $minutes = $start->diffInMinutes($end, false);
        $max = AppSetting::current()->max_booking_minutes;
        if ($minutes < self::MIN_DURATION_MINUTES) {
            throw new BookingException(__('A booking must last at least :minutes minutes.', ['minutes' => self::MIN_DURATION_MINUTES]));
        }
        if ($minutes > $max) {
            throw new BookingException(__('A booking cannot exceed :duration.', ['duration' => fmt_duration($max)]));
        }
        if ($end->lessThanOrEqualTo($this->now())) {
            throw new BookingException(__('This time slot has already passed.'));
        }

        $schedule = $this->today($room, fresh: true);
        if ($schedule['stale']) {
            throw new BookingException(__('The room’s availability cannot be checked right now.'));
        }

        if ($ignoreId !== null && ! collect($schedule['events'])->contains('id', $ignoreId)) {
            throw new BookingException(__('This meeting no longer exists.'));
        }

        foreach ($schedule['events'] as $event) {
            if ($event['id'] !== $ignoreId && $event['start']->lessThan($end) && $event['end']->greaterThan($start)) {
                throw new BookingException(__('This slot overlaps “:subject” (:start – :end).', [
                    'subject' => $this->displaySubject($room, $event),
                    'start' => fmt_time($event['start']),
                    'end' => fmt_time($event['end']),
                ]));
            }
        }
    }

    /**
     * Meetings created through this app (screen or admin) are organised by the room mailbox itself:
     * those are the only ones people at the screen may change or cancel.
     */
    public function isAppBooking(Room $room, array $event): bool
    {
        return ($event['organizer_email'] ?? null) !== null
            && $event['organizer_email'] === strtolower($room->o365_resource_email);
    }

    public function cancel(Room $room, string $eventId): void
    {
        try {
            $this->graph->deleteCalendarEvent($room->o365_resource_email, $eventId);
        } finally {
            $this->forget($room);
        }
        $this->recordOverride($room, $eventId, ['deleted' => true]);
        ActivityLog::record('booking.cancelled', $room);
    }

    /**
     * @param  array{deleted?: bool, start?: string, end?: string, subject?: string, added?: array<string, mixed>}  $change
     */
    private function recordOverride(Room $room, string $eventId, array $change): void
    {
        $key = "room-schedule:{$room->id}:overrides";
        $overrides = Cache::get($key, []);
        $overrides[$eventId] = $change + ['at' => now()->getTimestamp()];
        Cache::put($key, $overrides, self::OVERRIDE_SECONDS);
    }

    /**
     * Replays recent changes made from the app on top of a (possibly lagging) Graph answer.
     *
     * @param  list<array<string, mixed>>  $events  raw events (dates as strings)
     * @return list<array<string, mixed>>
     */
    private function applyOverrides(Room $room, string $day, array $events): array
    {
        $overrides = array_filter(
            Cache::get("room-schedule:{$room->id}:overrides", []),
            fn (array $o) => ($o['at'] ?? 0) >= now()->getTimestamp() - self::OVERRIDE_SECONDS,
        );
        if ($overrides === []) {
            return $events;
        }

        $out = [];
        foreach ($events as $event) {
            $override = $overrides[$event['id']] ?? null;
            if ($override['deleted'] ?? false) {
                continue;
            }
            foreach (['start', 'end', 'subject'] as $field) {
                if (isset($override[$field])) {
                    $event[$field] = $override[$field];
                }
            }
            $out[] = $event;
        }

        $ids = array_column($out, 'id');
        foreach ($overrides as $id => $override) {
            $added = $override['added'] ?? null;
            if ($added && ! in_array($id, $ids, true) && str_starts_with($added['start'], $day)) {
                $out[] = $added;
            }
        }

        return $out;
    }

    public function displayOrganizer(Room $room, array $event): ?string
    {
        // Bookings made through the app are organised by the room itself: nothing useful to show.
        if ($event['is_private'] || ! $room->show_subjects || ! AppSetting::current()->show_organizer || $this->isAppBooking($room, $event)) {
            return null;
        }

        return $event['organizer'];
    }

    public function displaySubject(Room $room, array $event): string
    {
        if ($event['is_private'] || ! $room->show_subjects) {
            return __('Reserved');
        }

        return trim($event['subject']) !== '' ? $event['subject'] : __('Untitled');
    }

    /**
     * @param  list<array<string, mixed>>  $events
     * @return list<array<string, mixed>>
     */
    private function normalize(array $events): array
    {
        $tz = $this->timezone();
        $out = [];
        foreach ($events as $event) {
            try {
                $event['start'] = CarbonImmutable::parse($event['start'], $tz);
                $event['end'] = CarbonImmutable::parse($event['end'], $tz);
            } catch (\Throwable) {
                continue;
            }
            $event['organizer'] ??= null;
            $event['organizer_email'] ??= null;
            $event['online'] ??= null;
            $event['is_private'] ??= false;
            $event['is_all_day'] ??= false;
            $out[] = $event;
        }

        usort($out, fn (array $a, array $b): int => $a['start'] <=> $b['start']);

        return $out;
    }
}
