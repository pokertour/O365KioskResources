<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\AppSetting;
use App\Models\IssueReport;
use App\Models\Room;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Occupancy statistics over the last N days, measured within working hours on weekdays.
 * Agendas come from Graph (cached one hour); screen actions from the activity log.
 */
class RoomStats
{
    public function __construct(
        private MicrosoftGraphService $graph,
        private RoomSchedule $schedule,
    ) {}

    /**
     * @return array{
     *     rooms: list<array{room: Room, meetings: int, hours: float, occupancy: float, avg_minutes: int, app_bookings: int, ended_early: int, extended: int, issues: int, error: ?string}>,
     *     heatmap: array<int, array<int, float>>,
     *     hours: list<int>,
     *     daily: list<array{date: CarbonImmutable, occupancy: float}>,
     *     totals: array{occupancy: float, meetings: int, hours: float, app_bookings: int, issues: int},
     *     from: CarbonImmutable,
     *     to: CarbonImmutable,
     * }
     */
    public function compute(int $days): array
    {
        $tz = $this->schedule->timezone();
        $to = CarbonImmutable::now($tz);
        $from = $to->subDays($days)->startOfDay();
        [$h0, $h1] = AppSetting::current()->dayHours();
        $startMin = (int) round($h0 * 60);
        $endMin = (int) round($h1 * 60);
        $hours = range((int) floor($h0), (int) ceil($h1) - 1);

        // Working minutes available per room over the period (weekdays only, up to now).
        $available = 0;
        $slotsPerCell = []; // [weekday][hour] => number of hour-slots in the period
        $availablePerDay = []; // [Y-m-d] => working minutes of that day
        for ($day = $from; $day->lessThan($to); $day = $day->addDay()) {
            if ($day->isWeekend()) {
                continue;
            }
            $dayEnd = $day->isSameDay($to) ? min($endMin, $to->hour * 60 + $to->minute) : $endMin;
            $available += max(0, $dayEnd - $startMin);
            $availablePerDay[$day->toDateString()] = max(0, $dayEnd - $startMin);
            foreach ($hours as $h) {
                if ($h * 60 < $dayEnd) {
                    $slotsPerCell[$day->dayOfWeekIso][$h] = ($slotsPerCell[$day->dayOfWeekIso][$h] ?? 0) + 1;
                }
            }
        }

        $busyPerCell = [];
        $busyPerDay = []; // [Y-m-d] => occupied minutes, all rooms
        $rows = [];
        $rooms = Room::query()->orderBy('name')->get();

        foreach ($rooms as $room) {
            $row = ['room' => $room, 'meetings' => 0, 'hours' => 0.0, 'occupancy' => 0.0, 'avg_minutes' => 0, 'app_bookings' => 0, 'ended_early' => 0, 'extended' => 0, 'issues' => 0, 'error' => null];

            try {
                $events = $this->events($room, $from, $to, $days);
            } catch (\Throwable $e) {
                Log::warning('Stats fetch failed', ['room' => $room->id, 'exception' => $e->getMessage()]);
                $row['error'] = $e->getMessage();
                $rows[] = $row;

                continue;
            }

            $durations = [];
            $busy = []; // [Y-m-d] => list<[startMin, endMin]> clipped to working hours
            foreach ($events as $event) {
                $start = CarbonImmutable::parse($event['start'], $tz);
                $end = CarbonImmutable::parse($event['end'], $tz);
                if ($end->lessThanOrEqualTo($from) || $start->greaterThanOrEqualTo($to)) {
                    continue;
                }
                $row['meetings']++;
                $durations[] = (int) $start->diffInMinutes($end);
                if ($event['organizer_email'] === strtolower($room->o365_resource_email)) {
                    $row['app_bookings']++;
                }

                // Same-day meetings only (multi-day events are rare for rooms and skew the stats).
                if (! $start->isSameDay($end) || $start->isWeekend()) {
                    continue;
                }
                $a = max($startMin, $start->hour * 60 + $start->minute);
                $b = min($endMin, $end->hour * 60 + $end->minute);
                if ($b > $a) {
                    $busy[$start->toDateString()][] = [$a, $b];
                }
            }

            $occupied = 0;
            foreach ($busy as $date => $intervals) {
                $weekday = CarbonImmutable::parse($date, $tz)->dayOfWeekIso;
                foreach ($this->merge($intervals) as [$a, $b]) {
                    $occupied += $b - $a;
                    $busyPerDay[$date] = ($busyPerDay[$date] ?? 0) + $b - $a;
                    foreach ($hours as $h) {
                        $overlap = min($b, ($h + 1) * 60) - max($a, $h * 60);
                        if ($overlap > 0) {
                            $busyPerCell[$weekday][$h] = ($busyPerCell[$weekday][$h] ?? 0) + $overlap / 60;
                        }
                    }
                }
            }

            $row['hours'] = round(array_sum($durations) / 60, 1);
            $row['avg_minutes'] = $durations ? (int) round(array_sum($durations) / count($durations)) : 0;
            $row['occupancy'] = $available > 0 ? round($occupied / $available * 100, 1) : 0.0;
            $rows[] = $row;
        }

        // Screen actions and issues come from the local log.
        $actions = ActivityLog::query()
            ->whereIn('action', ['meeting.ended', 'meeting.extended'])
            ->where('created_at', '>=', $from->utc())
            ->selectRaw('room_id, action, count(*) as n')
            ->groupBy('room_id', 'action')
            ->get();
        $issues = IssueReport::query()->where('created_at', '>=', $from->utc())
            ->selectRaw('room_id, count(*) as n')->groupBy('room_id')->pluck('n', 'room_id');

        foreach ($rows as &$row) {
            $id = $row['room']->id;
            $row['ended_early'] = (int) $actions->where('room_id', $id)->where('action', 'meeting.ended')->sum('n');
            $row['extended'] = (int) $actions->where('room_id', $id)->where('action', 'meeting.extended')->sum('n');
            $row['issues'] = (int) ($issues[$id] ?? 0);
        }
        unset($row);

        $roomCount = max(1, collect($rows)->whereNull('error')->count());
        $heatmap = [];
        foreach (range(1, 5) as $weekday) {
            foreach ($hours as $h) {
                $slots = ($slotsPerCell[$weekday][$h] ?? 0) * $roomCount;
                $heatmap[$weekday][$h] = $slots > 0 ? round(($busyPerCell[$weekday][$h] ?? 0) / $slots * 100, 1) : 0.0;
            }
        }

        $ok = collect($rows)->whereNull('error');

        $daily = [];
        foreach ($availablePerDay as $date => $minutes) {
            $daily[] = [
                'date' => CarbonImmutable::parse($date, $tz),
                'occupancy' => $minutes > 0 ? round(($busyPerDay[$date] ?? 0) / ($minutes * $roomCount) * 100, 1) : 0.0,
            ];
        }

        return [
            'rooms' => $rows,
            'heatmap' => $heatmap,
            'hours' => $hours,
            'daily' => $daily,
            'totals' => [
                'occupancy' => $ok->count() ? round($ok->avg('occupancy'), 1) : 0.0,
                'meetings' => (int) $ok->sum('meetings'),
                'hours' => round($ok->sum('hours'), 1),
                'app_bookings' => (int) $ok->sum('app_bookings'),
                'issues' => (int) collect($rows)->sum('issues'),
            ],
            'from' => $from,
            'to' => $to,
        ];
    }

    /**
     * @return list<array{start: ?string, end: ?string, organizer_email: string}>
     */
    private function events(Room $room, CarbonImmutable $from, CarbonImmutable $to, int $days): array
    {
        $key = "room-stats:{$room->id}:{$days}:".$to->format('Y-m-d-H');

        return Cache::remember($key, now()->addHour(), fn () => $this->graph->listCalendarRange($room->o365_resource_email, $from, $to));
    }

    /**
     * @param  list<array{0: int, 1: int}>  $intervals
     * @return list<array{0: int, 1: int}>
     */
    private function merge(array $intervals): array
    {
        usort($intervals, fn ($x, $y) => $x[0] <=> $y[0]);
        $out = [];
        foreach ($intervals as [$a, $b]) {
            if ($out && $a <= $out[count($out) - 1][1]) {
                $out[count($out) - 1][1] = max($out[count($out) - 1][1], $b);
            } else {
                $out[] = [$a, $b];
            }
        }

        return $out;
    }
}
