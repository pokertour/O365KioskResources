<?php

use App\Models\AppSetting;
use App\Models\MicrosoftSetting;
use App\Models\Room;
use App\Models\User;
use App\Services\BookingException;
use App\Services\RoomSchedule;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    MicrosoftSetting::current()->forceFill([
        'tenant_id' => 'contoso.onmicrosoft.com',
        'client_id' => '11111111-1111-1111-1111-111111111111',
        'client_secret' => 'secret',
    ])->save();

    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-22 10:00', AppSetting::current()->timezone()));
});

afterEach(fn () => CarbonImmutable::setTestNow());

function fakeGraph(array $events, ?callable $onPost = null): void
{
    Http::fake([
        'login.microsoftonline.com/*' => Http::response(['access_token' => 'token', 'expires_in' => 3600]),
        'graph.microsoft.com/v1.0/users/*/calendar/calendarView*' => Http::response(['value' => $events]),
        'graph.microsoft.com/v1.0/users/*/events' => function ($request) use ($onPost) {
            $onPost && $onPost($request);

            return Http::response(['id' => 'new-event'], 201);
        },
    ]);
}

function graphEvent(string $id, string $start, string $end, array $extra = []): array
{
    return [
        'id' => $id,
        'subject' => 'Réunion '.$id,
        'start' => ['dateTime' => "2026-10-22T{$start}:00.0000000"],
        'end' => ['dateTime' => "2026-10-22T{$end}:00.0000000"],
        'organizer' => ['emailAddress' => ['name' => 'Alice', 'address' => 'alice@contoso.com']],
        ...$extra,
    ];
}

test('status reports the current meeting and when the room frees up', function () {
    fakeGraph([
        graphEvent('a', '09:30', '10:30'),
        graphEvent('b', '10:30', '11:00'),
        graphEvent('c', '14:00', '15:00'),
    ]);
    $schedule = app(RoomSchedule::class);
    $room = Room::factory()->create();

    $status = $schedule->status($schedule->today($room)['events']);

    expect($status['busy'])->toBeTrue()
        ->and($status['current']['id'])->toBe('a')
        ->and($status['until']->format('H:i'))->toBe('11:00');
});

test('cancelled, free and private events are handled for display', function () {
    fakeGraph([
        graphEvent('cancelled', '11:00', '12:00', ['isCancelled' => true]),
        graphEvent('free', '12:00', '13:00', ['showAs' => 'free']),
        graphEvent('private', '13:00', '14:00', ['sensitivity' => 'private']),
    ]);
    $schedule = app(RoomSchedule::class);
    $room = Room::factory()->create();

    $events = $schedule->today($room)['events'];

    expect($events)->toHaveCount(1)
        ->and($schedule->displaySubject($room, $events[0]))->toBe('Reserved');
});

test('bookings overlapping an existing meeting are refused', function () {
    fakeGraph([graphEvent('a', '10:30', '11:00')]);
    $schedule = app(RoomSchedule::class);
    $room = Room::factory()->create();

    $schedule->book($room, 'Test', $schedule->now()->setTime(10, 15), $schedule->now()->setTime(10, 45));
})->throws(BookingException::class, 'overlaps');

test('a free slot is booked in the room calendar', function () {
    $posted = null;
    fakeGraph([graphEvent('a', '11:00', '12:00')], function ($request) use (&$posted) {
        $posted = $request->data();
    });
    $schedule = app(RoomSchedule::class);
    $room = Room::factory()->create();

    $schedule->book($room, 'Point rapide', $schedule->now(), $schedule->now()->addMinutes(30));

    expect($posted['subject'])->toBe('Point rapide')
        ->and($posted['start']['dateTime'])->toBe('2026-10-22T10:00:00');
});

test('the last known agenda is kept when Graph is unavailable', function () {
    $down = false;
    Http::fake(function ($request) use (&$down) {
        if (str_contains($request->url(), 'login.microsoftonline.com')) {
            return Http::response(['access_token' => 'token', 'expires_in' => 3600]);
        }

        return $down
            ? Http::response(['error' => ['code' => 'ServiceUnavailable']], 503)
            : Http::response(['value' => [graphEvent('a', '11:00', '12:00')]]);
    });
    $schedule = app(RoomSchedule::class);
    $room = Room::factory()->create();
    $schedule->today($room);

    $down = true;
    $result = $schedule->today($room, fresh: true);

    expect($result['stale'])->toBeTrue()
        ->and($result['events'])->toHaveCount(1);
});

test('kiosk quick booking is capped at the next meeting', function () {
    $posted = null;
    fakeGraph([graphEvent('a', '10:20', '11:00')], function ($request) use (&$posted) {
        $posted = $request->data();
    });
    $room = Room::factory()->create();
    $token = $room->rotateKioskToken();

    Livewire\Livewire::test('pages::kiosk.room', ['room' => $room, 'token' => $token])
        ->call('quickBook', 30);

    expect($posted['end']['dateTime'])->toBe('2026-10-22T10:20:00');
});

test('kiosk booking can be disabled per room', function () {
    $posted = false;
    fakeGraph([], function () use (&$posted) {
        $posted = true;
    });
    $room = Room::factory()->create(['booking_enabled' => false]);
    $token = $room->rotateKioskToken();

    Livewire\Livewire::test('pages::kiosk.room', ['room' => $room, 'token' => $token])
        ->assertDontSee('Other time')
        ->call('quickBook', 30);

    expect($posted)->toBeFalse();
});

test('times follow the configured 12-hour format', function () {
    AppSetting::current()->forceFill(['time_format' => '12h'])->save();

    expect(fmt_time(CarbonImmutable::now()->setTime(14, 30)))->toBe('2:30 PM');
});

test('bookings longer than the configured maximum are refused', function () {
    AppSetting::current()->forceFill(['max_booking_minutes' => 60])->save();
    fakeGraph([]);
    $room = Room::factory()->create();

    $schedule = app(RoomSchedule::class);
    $schedule->book($room, 'Long', $schedule->now(), $schedule->now()->addMinutes(90));
})->throws(BookingException::class);

function fakeGraphWithPatch(array $events, ?array &$patched): void
{
    Http::fake([
        'login.microsoftonline.com/*' => Http::response(['access_token' => 'token', 'expires_in' => 3600]),
        'graph.microsoft.com/v1.0/users/*/calendar/calendarView*' => Http::response(['value' => $events]),
        'graph.microsoft.com/v1.0/users/*/events/*' => function ($request) use (&$patched) {
            $patched = ['method' => $request->method(), 'url' => $request->url(), 'data' => $request->data()];

            return Http::response([], 200);
        },
    ]);
}

test('a meeting starting within the warning delay puts the room in the "soon" state', function () {
    fakeGraph([graphEvent('a', '10:08', '11:00')]);
    $schedule = app(RoomSchedule::class);

    $status = $schedule->status($schedule->today(Room::factory()->create())['events']);

    expect($status['state'])->toBe('soon')->and($status['starts_in'])->toBe(8);

    AppSetting::current()->forceFill(['soon_minutes' => 5])->save();
    expect($schedule->status($schedule->today(Room::factory()->create())['events'])['state'])->toBe('free');
});

test('the meeting in progress can be ended early', function () {
    $patched = null;
    fakeGraphWithPatch([graphEvent('a', '09:30', '11:00')], $patched);
    $room = Room::factory()->create();

    app(RoomSchedule::class)->endNow($room, 'a');

    expect($patched['method'])->toBe('PATCH')
        ->and($patched['data']['end']['dateTime'])->toBe('2026-10-22T10:00:00');
});

test('only the meeting in progress can be ended', function () {
    $patched = null;
    fakeGraphWithPatch([graphEvent('later', '14:00', '15:00')], $patched);

    app(RoomSchedule::class)->endNow(Room::factory()->create(), 'later');
})->throws(BookingException::class);

test('a meeting is extended when the room stays free', function () {
    $patched = null;
    fakeGraphWithPatch([graphEvent('a', '09:30', '10:30'), graphEvent('b', '11:00', '12:00')], $patched);

    $end = app(RoomSchedule::class)->extend(Room::factory()->create(), 'a', 30);

    expect($end->format('H:i'))->toBe('11:00')
        ->and($patched['data']['end']['dateTime'])->toBe('2026-10-22T11:00:00');
});

test('a meeting cannot be extended over the next one', function () {
    $patched = null;
    fakeGraphWithPatch([graphEvent('a', '09:30', '10:30'), graphEvent('b', '10:40', '12:00')], $patched);

    app(RoomSchedule::class)->extend(Room::factory()->create(), 'a', 15);
})->throws(BookingException::class);

test('the kiosk only offers extensions that fit', function () {
    fakeGraph([graphEvent('a', '09:30', '10:30'), graphEvent('b', '10:50', '12:00')]);
    $room = Room::factory()->create();
    $token = $room->rotateKioskToken();

    Livewire\Livewire::test('pages::kiosk.room', ['room' => $room, 'token' => $token])
        ->assertSee('+15 min')
        ->assertDontSee('+30 min');
});

test('night mode handles ranges spanning midnight', function () {
    $settings = AppSetting::current()->forceFill(['night_mode' => true, 'night_start' => '20:00', 'night_end' => '07:00']);
    $at = fn (string $time) => CarbonImmutable::parse("2026-10-22 {$time}");

    expect($settings->isNight($at('22:00')))->toBeTrue()
        ->and($settings->isNight($at('03:00')))->toBeTrue()
        ->and($settings->isNight($at('07:00')))->toBeFalse()
        ->and($settings->isNight($at('12:00')))->toBeFalse();

    $settings->night_mode = false;
    expect($settings->isNight($at('22:00')))->toBeFalse();
});

test('the kiosk shows the night view outside working hours', function () {
    AppSetting::current()->forceFill(['night_mode' => true, 'night_start' => '09:00', 'night_end' => '18:00'])->save();
    fakeGraph([]);
    $room = Room::factory()->create();
    $token = $room->rotateKioskToken();

    Livewire\Livewire::test('pages::kiosk.room', ['room' => $room, 'token' => $token])
        ->assertSee('Touch the screen to wake it up');
});

test('free slots are the gaps of at least 15 minutes', function () {
    $s = app(RoomSchedule::class);
    $at = fn (string $t) => CarbonImmutable::parse("2026-10-22 {$t}", $s->timezone());
    $events = [
        ['start' => $at('10:30'), 'end' => $at('11:00')],
        ['start' => $at('11:10'), 'end' => $at('12:00')],
    ];

    $slots = $s->freeSlots($events, $at('10:00'), $at('13:00'));

    expect(array_map(fn ($x) => $x['start']->format('H:i').'-'.$x['end']->format('H:i'), $slots))
        ->toBe(['10:00-10:30', '12:00-13:00']);
});

test('overlapping meetings are laid out side by side', function () {
    $s = app(RoomSchedule::class);
    $at = fn (string $t) => CarbonImmutable::parse("2026-10-22 {$t}", $s->timezone());

    $laid = $s->layoutColumns([
        ['start' => $at('09:00'), 'end' => $at('10:00')],
        ['start' => $at('09:30'), 'end' => $at('10:30')],
        ['start' => $at('11:00'), 'end' => $at('12:00')],
    ]);

    expect(array_map(fn ($e) => [$e['col'], $e['cols']], $laid))->toBe([[0, 2], [1, 2], [0, 1]]);
});

test('the next working day skips the weekend', function () {
    $s = app(RoomSchedule::class);

    expect($s->nextWorkingDay(CarbonImmutable::parse('2026-10-23 18:00'))->toDateString())->toBe('2026-10-26')
        ->and($s->nextWorkingDay(CarbonImmutable::parse('2026-10-22 18:00'))->toDateString())->toBe('2026-10-23');
});

test('the timeline shows tappable free slots that pre-fill the booking form', function () {
    fakeGraph([graphEvent('a', '11:00', '12:00')]);
    $room = Room::factory()->create();
    $token = $room->rotateKioskToken();

    Livewire\Livewire::test('pages::kiosk.room', ['room' => $room, 'token' => $token])
        ->assertSee('Free · 1 h')
        ->assertSeeHtml("openBookingForm('10:00', '11:00')")
        ->call('openBookingForm', '10:00', '11:00')
        ->assertSet('start_time', '10:00')
        ->assertSet('end_time', '11:00')
        ->call('openBookingForm', 'bad', '25:99')
        ->assertSet('start_time', '10:00')
        ->assertSet('end_time', '10:30');
});

test('the list layout folds past meetings', function () {
    AppSetting::current()->forceFill(['agenda_view' => 'list'])->save();
    fakeGraph([graphEvent('a', '08:00', '09:00'), graphEvent('b', '11:00', '12:00')]);
    $room = Room::factory()->create();
    $token = $room->rotateKioskToken();

    Livewire\Livewire::test('pages::kiosk.room', ['room' => $room, 'token' => $token])
        ->assertSee('1 meeting ended')
        ->assertSee('Free · 1 h');
});

test('the next working day is previewed once today is over', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-22 20:30', AppSetting::current()->timezone()));
    Http::fake([
        'login.microsoftonline.com/*' => Http::response(['access_token' => 'token', 'expires_in' => 3600]),
        'graph.microsoft.com/*' => function ($request) {
            $tomorrow = str_contains(urldecode($request->url()), '2026-10-23');

            return Http::response(['value' => $tomorrow
                ? [graphEvent('t', '09:00', '10:00', ['subject' => 'Kick-off', 'start' => ['dateTime' => '2026-10-23T09:00:00'], 'end' => ['dateTime' => '2026-10-23T10:00:00']])]
                : [graphEvent('a', '09:00', '10:00')]]);
        },
    ]);
    $room = Room::factory()->create();
    $token = $room->rotateKioskToken();

    Livewire\Livewire::test('pages::kiosk.room', ['room' => $room, 'token' => $token])
        ->assertSee('Tomorrow')
        ->assertSee('Kick-off');
});

/**
 * Graph keeps answering with the state *before* any change, like its lagging calendarView does.
 */
function fakeLaggingGraph(array $events, array &$calls): void
{
    Http::fake(function ($request) use ($events, &$calls) {
        if (str_contains($request->url(), 'login.microsoftonline.com')) {
            return Http::response(['access_token' => 'token', 'expires_in' => 3600]);
        }
        if (str_contains($request->url(), 'calendarView')) {
            return Http::response(['value' => $events]);
        }
        $calls[] = $request->method();

        return $request->method() === 'POST'
            ? Http::response(['id' => 'created'], 201)
            : Http::response([], $request->method() === 'DELETE' ? 204 : 200);
    });
}

test('ending a meeting frees the room immediately even if Graph lags', function () {
    $calls = [];
    fakeLaggingGraph([graphEvent('a', '09:30', '11:00')], $calls);
    $schedule = app(RoomSchedule::class);
    $room = Room::factory()->create();

    $schedule->endNow($room, 'a');

    expect($calls)->toBe(['PATCH'])
        ->and($schedule->status($schedule->today($room, fresh: true)['events'])['state'])->toBe('free');
});

test('a meeting that started this minute is removed rather than shortened', function () {
    $calls = [];
    fakeLaggingGraph([graphEvent('a', '10:00', '10:30')], $calls);
    $schedule = app(RoomSchedule::class);
    $room = Room::factory()->create();

    $schedule->endNow($room, 'a');

    expect($calls)->toBe(['DELETE'])
        ->and($schedule->today($room, fresh: true)['events'])->toBeEmpty();
});

test('a new booking shows up immediately even if Graph lags', function () {
    $calls = [];
    fakeLaggingGraph([], $calls);
    $schedule = app(RoomSchedule::class);
    $room = Room::factory()->create();

    $schedule->book($room, 'Point rapide', $schedule->now(), $schedule->now()->addMinutes(30));

    expect($schedule->status($schedule->today($room, fresh: true)['events'])['state'])->toBe('busy');
});

test('replayed changes expire once Graph has caught up', function () {
    $calls = [];
    fakeLaggingGraph([graphEvent('a', '09:30', '11:00')], $calls);
    $schedule = app(RoomSchedule::class);
    $room = Room::factory()->create();
    $schedule->endNow($room, 'a');

    $this->travel(RoomSchedule::OVERRIDE_SECONDS + 5)->seconds();

    expect($schedule->status($schedule->today($room, fresh: true)['events'])['state'])->toBe('busy');
});

function roomBooking(Room $room, string $id, string $start, string $end): array
{
    return graphEvent($id, $start, $end, ['organizer' => ['emailAddress' => ['name' => $room->name, 'address' => strtoupper($room->o365_resource_email)]]]);
}

function recordingGraph(array $events, array &$calls): void
{
    Http::fake(function ($request) use ($events, &$calls) {
        if (str_contains($request->url(), 'login.microsoftonline.com')) {
            return Http::response(['access_token' => 'token', 'expires_in' => 3600]);
        }
        if (str_contains($request->url(), 'calendarView')) {
            return Http::response(['value' => $events]);
        }
        $calls[] = [$request->method(), $request->data()];

        return Http::response([], $request->method() === 'DELETE' ? 204 : 200);
    });
}

test('a booking made from the screen can be rescheduled there', function () {
    $room = Room::factory()->create();
    $token = $room->rotateKioskToken();
    $calls = [];
    recordingGraph([roomBooking($room, 'mine', '14:00', '15:00')], $calls);

    Livewire\Livewire::test('pages::kiosk.room', ['room' => $room, 'token' => $token])
        ->call('openEvent', 'mine')
        ->assertSee('Edit')
        ->call('editEvent')
        ->assertSet('start_time', '14:00')
        ->set('start_time', '15:00')
        ->set('end_time', '15:30')
        ->call('saveEvent')
        ->assertHasNoErrors();

    expect($calls[0][0])->toBe('PATCH')
        ->and($calls[0][1]['start']['dateTime'])->toBe('2026-10-22T15:00:00')
        ->and($calls[0][1]['end']['dateTime'])->toBe('2026-10-22T15:30:00');
});

test('a booking made from the screen can be cancelled there', function () {
    $room = Room::factory()->create();
    $token = $room->rotateKioskToken();
    $calls = [];
    recordingGraph([roomBooking($room, 'mine', '14:00', '15:00')], $calls);

    Livewire\Livewire::test('pages::kiosk.room', ['room' => $room, 'token' => $token])
        ->call('openEvent', 'mine')
        ->call('cancelEvent');

    expect($calls[0][0])->toBe('DELETE');
});

test('meetings booked from Outlook cannot be changed from the screen', function () {
    $room = Room::factory()->create();
    $token = $room->rotateKioskToken();
    $calls = [];
    recordingGraph([graphEvent('theirs', '14:00', '15:00')], $calls);

    Livewire\Livewire::test('pages::kiosk.room', ['room' => $room, 'token' => $token])
        ->call('openEvent', 'theirs')
        ->assertSee('Outlook or Teams')
        ->call('editEvent')
        ->assertSet('editingEvent', false)
        ->call('cancelEvent');

    expect($calls)->toBeEmpty();
});

test('rescheduling refuses to overlap another meeting', function () {
    $room = Room::factory()->create();
    $calls = [];
    recordingGraph([roomBooking($room, 'mine', '14:00', '15:00'), graphEvent('other', '15:30', '16:00')], $calls);
    $s = app(RoomSchedule::class);

    $s->reschedule($room, 'mine', 'Test', $s->now()->setTime(15, 0), $s->now()->setTime(15, 45));
})->throws(BookingException::class, 'overlaps');

test('admins can edit any meeting of a room', function () {
    $room = Room::factory()->create();
    $calls = [];
    recordingGraph([graphEvent('theirs', '14:00', '15:00')], $calls);

    Livewire\Livewire::actingAs(User::factory()->create())
        ->test('pages::admin.room-detail', ['room' => $room])
        ->call('openEditEvent', 'theirs')
        ->set('subject', 'Renamed')
        ->set('end_time', '15:30')
        ->call('saveEditEvent')
        ->assertHasNoErrors();

    expect($calls[0][0])->toBe('PATCH')
        ->and($calls[0][1]['subject'])->toBe('Renamed')
        ->and($calls[0][1]['end']['dateTime'])->toBe('2026-10-22T15:30:00');
});
