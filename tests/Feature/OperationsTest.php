<?php

use App\Models\ActivityLog;
use App\Models\Announcement;
use App\Models\AppSetting;
use App\Models\IssueReport;
use App\Models\MicrosoftSetting;
use App\Models\Room;
use App\Models\Screen;
use App\Models\ScreenPairing;
use App\Models\User;
use App\Notifications\AdminAlert;
use App\Services\MicrosoftGraphService;
use App\Services\RoomStats;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;

function configureGraph(): void
{
    MicrosoftSetting::current()->forceFill([
        'tenant_id' => 'contoso.onmicrosoft.com',
        'client_id' => '11111111-1111-1111-1111-111111111111',
        'client_secret' => 'secret',
    ])->save();
}

function graphWith(array $events = [], array $extra = []): void
{
    Http::fake($extra + [
        'login.microsoftonline.com/*' => Http::response(['access_token' => 'token', 'expires_in' => 3600]),
        'graph.microsoft.com/v1.0/users/*/calendar/calendarView*' => Http::response(['value' => $events]),
    ]);
}

function kiosk(Room $room)
{
    return Livewire::test('pages::kiosk.room', ['room' => $room, 'token' => $room->rotateKioskToken()]);
}

// ---------------------------------------------------------------- Supervision

test('a screen reports itself without touching its timestamp', function () {
    $room = Room::factory()->create();
    $token = $room->rotateKioskToken();
    $screen = $room->primaryScreen();
    $updatedAt = $screen->fresh()->updated_at;
    $this->travel(5)->minutes();

    Livewire::test('pages::kiosk.room', ['room' => $room, 'token' => $token])->call('reportScreen', 1920, 1080, 1.0);

    $screen->refresh();
    expect($screen->seen_at)->not->toBeNull()
        ->and($screen->info['width'])->toBe(1920)
        ->and($screen->isOnline())->toBeTrue()
        ->and($screen->updated_at->equalTo($updatedAt))->toBeTrue();
});

test('administrators are alerted once per screen going offline, then when it is back', function () {
    Notification::fake();
    Http::fake(['*.logic.azure.com/*' => Http::response([], 202)]);
    AppSetting::current()->forceFill(['offline_alert_minutes' => 15, 'teams_webhook_url' => 'https://prod-00.westeurope.logic.azure.com/workflows/x'])->save();
    $admin = User::factory()->create();
    $room = Room::factory()->create();
    $entrance = $room->primaryScreen();
    $hall = $room->screens()->create(['name' => 'Hall']);
    $entrance->markSeen();
    $hall->markSeen();

    $this->travel(20)->minutes();
    $hall->fresh()->markSeen(); // the hall screen keeps reporting
    $this->artisan('screens:check-offline')->assertSuccessful();
    $this->artisan('screens:check-offline')->assertSuccessful();

    Notification::assertSentToTimes($admin, AdminAlert::class, 1);
    Notification::assertSentTo($admin, AdminAlert::class, fn ($n) => str_contains($n->title, $entrance->name));
    Http::assertSentCount(1);
    expect(ActivityLog::query()->where('action', 'screen.offline')->count())->toBe(1);

    $entrance->fresh()->markSeen();
    Notification::assertSentToTimes($admin, AdminAlert::class, 2);
    expect($entrance->fresh()->offline_alerted_at)->toBeNull();
});

test('a room can have several screens, each with its own link', function () {
    $room = Room::factory()->create();
    $first = $room->primaryScreen();
    $second = $room->screens()->create(['name' => 'Hall']);
    $tokenA = $first->rotateToken();
    $tokenB = $second->rotateToken();

    $this->get(route('kiosk.show', ['room' => $room, 'token' => $tokenA]))->assertOk();
    $this->get(route('kiosk.show', ['room' => $room, 'token' => $tokenB]))->assertOk();

    // Revoking one screen leaves the other one working.
    $second->rotateToken();
    $this->get(route('kiosk.show', ['room' => $room, 'token' => $tokenB]))->assertNotFound();
    $this->get(route('kiosk.show', ['room' => $room, 'token' => $tokenA]))->assertOk();

    // A screen's token does not open another room.
    $other = Room::factory()->create();
    $this->get(route('kiosk.show', ['room' => $other, 'token' => $tokenA]))->assertNotFound();
});

test('a screen setting overrides the room, which overrides the global setting', function () {
    AppSetting::current()->forceFill(['text_scale' => 100])->save();
    $room = Room::factory()->create(['text_scale' => 115, 'kiosk_theme' => 'light']);
    $screen = $room->primaryScreen();

    expect(AppSetting::forScreen($screen)->textScale())->toBe(115);

    $screen->forceFill(['text_scale' => 130, 'kiosk_theme' => 'dark'])->save();
    $token = $screen->rotateToken();
    expect(AppSetting::forScreen($screen->fresh())->textScale())->toBe(130);

    $html = $this->get(route('kiosk.show', ['room' => $room, 'token' => $token]))->getContent();
    expect($html)->toContain('* 1.3)')->toContain('class="h-full dark"');
});

test('removing a screen disconnects its device', function () {
    $room = Room::factory()->create();
    $screen = $room->screens()->create(['name' => 'Hall']);
    $token = $screen->rotateToken();
    $component = Livewire::test('pages::kiosk.room', ['room' => $room, 'token' => $token]);

    Livewire::actingAs(User::factory()->create())
        ->test('pages::admin.room-detail', ['room' => $room])
        ->call('openScreen', $screen->id)
        ->call('deleteScreen');

    expect(Screen::query()->find($screen->id))->toBeNull();
    $component->call('$refresh');
    expect(json_encode($component->effects))->toContain('location.reload');
});

test('admins can add a screen and rename it', function () {
    $room = Room::factory()->create();

    Livewire::actingAs(User::factory()->create())
        ->test('pages::admin.room-detail', ['room' => $room])
        ->call('openAddScreen')
        ->set('new_screen_name', 'Couloir est')
        ->call('addScreen')
        ->set('s_name', 'Couloir ouest')
        ->set('s_text_scale', '130')
        ->call('saveScreen')
        ->assertHasNoErrors();

    $screen = $room->screens()->where('name', 'Couloir ouest')->sole();
    expect($screen->text_scale)->toBe(130)->and($screen->kioskUrl())->not->toBeNull();
});

// ---------------------------------------------------------------- Pairing

test('a new screen gets a pairing code and switches to the room once paired', function () {
    AppSetting::current()->forceFill(['locale' => 'en'])->save();
    $room = Room::factory()->create();

    $this->get(route('kiosk.pair'))->assertOk()->assertSee('Pairing code');
    $pairing = ScreenPairing::query()->sole();
    expect($pairing->code)->toMatch('/^\d{6}$/');

    Livewire::actingAs(User::factory()->create())
        ->test('pages::admin.room-detail', ['room' => $room])
        ->call('openPairing')
        ->set('pairing_code', $pairing->code)
        ->set('pairing_name', 'Entrée')
        ->call('pairScreen')
        ->assertHasNoErrors();

    $screen = $room->screens()->where('name', 'Entrée')->sole();
    expect($pairing->fresh()->screen_id)->toBe($screen->id)
        ->and($room->screens()->count())->toBe(2);

    // The device that asked (same session) is sent to its own screen link.
    Livewire::withQueryParams([])->test('pages::kiosk.pair')
        ->call('check')
        ->assertRedirect($screen->fresh()->kioskUrl());
});

test('an unknown pairing code is refused', function () {
    $room = Room::factory()->create();

    Livewire::actingAs(User::factory()->create())
        ->test('pages::admin.room-detail', ['room' => $room])
        ->call('openPairing')
        ->set('pairing_code', '123456')
        ->call('pairScreen')
        ->assertHasErrors('pairing_code');
});

// ---------------------------------------------------------------- Import

test('rooms are imported from the tenant, skipping existing ones', function () {
    configureGraph();
    Room::factory()->create(['o365_resource_email' => 'existing@contoso.com']);
    graphWith([], ['graph.microsoft.com/v1.0/places/*' => Http::response(['value' => [
        ['displayName' => 'Existing', 'emailAddress' => 'existing@contoso.com'],
        ['displayName' => 'Atlas', 'emailAddress' => 'Atlas@contoso.com', 'capacity' => 10, 'building' => 'HQ', 'floorLabel' => '3'],
    ]])]);

    Livewire::actingAs(User::factory()->create())
        ->test('pages::admin.rooms-index')
        ->call('openImport')
        ->assertSet('importable', fn ($rooms) => count($rooms) === 1 && $rooms[0]['email'] === 'atlas@contoso.com')
        ->set('importSelection', ['atlas@contoso.com'])
        ->call('importRooms');

    $atlas = Room::query()->where('o365_resource_email', 'atlas@contoso.com')->sole();
    expect($atlas->capacity)->toBe(10)->and($atlas->location)->toBe('HQ · 3');
});

test('a missing Place.Read.All permission is explained', function () {
    configureGraph();
    graphWith([], ['graph.microsoft.com/v1.0/places/*' => Http::response(['error' => ['code' => 'Authorization_RequestDenied']], 403)]);

    Livewire::actingAs(User::factory()->create())
        ->test('pages::admin.rooms-index')
        ->call('openImport')
        ->assertSet('importError', fn ($e) => str_contains($e, 'Place.Read.All'));
});

// ---------------------------------------------------------------- Statistics

test('occupancy is computed within working hours on weekdays', function () {
    configureGraph();
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-23 18:00', 'Europe/Paris')); // Friday
    $room = Room::factory()->create();
    graphWith([
        // Thursday 9:00–11:00 (2 h) and a weekend meeting that must be ignored for occupancy.
        ['id' => 'a', 'start' => ['dateTime' => '2026-10-22T09:00:00'], 'end' => ['dateTime' => '2026-10-22T11:00:00'], 'organizer' => ['emailAddress' => ['address' => $room->o365_resource_email]]],
        ['id' => 'b', 'start' => ['dateTime' => '2026-10-18T09:00:00'], 'end' => ['dateTime' => '2026-10-18T10:00:00']],
    ]);

    $stats = app(RoomStats::class)->compute(7);
    $row = $stats['rooms'][0];

    // Fri 16 + Mon–Thu (5 × 11 h) + Fri 23 until 18:00 (10 h) = 65 h available; 2 h occupied.
    expect($row['meetings'])->toBe(2)
        ->and($row['app_bookings'])->toBe(1)
        ->and($row['occupancy'])->toBe(round(2 / 65 * 100, 1))
        ->and($stats['heatmap'][4][9])->toBeGreaterThan(0);

    CarbonImmutable::setTestNow();
});

// ---------------------------------------------------------------- Activity, announcements, issues

test('screen actions are written to the activity log', function () {
    configureGraph();
    graphWith([], ['graph.microsoft.com/v1.0/users/*/events' => Http::response(['id' => 'new'], 201)]);
    $room = Room::factory()->create();

    kiosk($room)->call('quickBook', 15);

    $log = ActivityLog::query()->where('action', 'booking.created')->sole();
    expect($log->actor)->toBe('screen')->and($log->room_id)->toBe($room->id);
});

test('an active announcement is shown on the targeted screens only', function () {
    $a = Room::factory()->create();
    $b = Room::factory()->create();
    Announcement::query()->create(['message' => 'Fire drill at 2 pm', 'level' => 'warning', 'room_ids' => [$a->id]]);
    Announcement::query()->create(['message' => 'Old news', 'ends_at' => now()->subHour()]);

    kiosk($a)->assertSee('Fire drill at 2 pm')->assertDontSee('Old news');
    kiosk($b)->assertDontSee('Fire drill at 2 pm');
});

test('admins can publish an announcement', function () {
    Livewire::actingAs(User::factory()->create())
        ->test('pages::admin.announcements')
        ->call('create')
        ->set('message', 'Room closed for works')
        ->set('level', 'danger')
        ->call('save')
        ->assertHasNoErrors();

    expect(Announcement::query()->sole()->isActive())->toBeTrue();
});

test('people at the screen can report a problem, which notifies the admins', function () {
    Notification::fake();
    $admin = User::factory()->create();
    $room = Room::factory()->create(['equipment' => ['video', 'display']]);

    kiosk($room)
        ->call('openIssueForm')
        ->set('issueCategory', 'video')
        ->set('issueMessage', 'The camera does not start')
        ->call('reportIssue')
        ->assertHasNoErrors();

    $issue = IssueReport::query()->sole();
    expect($issue->category)->toBe('video')->and($issue->message)->toBe('The camera does not start');
    Notification::assertSentTo($admin, AdminAlert::class);
});

test('issue categories are limited to the room equipment and general topics', function () {
    $room = Room::factory()->create(['equipment' => ['video']]);

    kiosk($room)->set('issueCategory', 'whiteboard')->call('reportIssue')->assertHasErrors('issueCategory');
});

// ---------------------------------------------------------------- Per-room settings

test('a room can override the global screen settings', function () {
    AppSetting::current()->forceFill(['text_scale' => 100, 'night_mode' => false])->save();
    $room = Room::factory()->create(['text_scale' => 130, 'night_mode' => true, 'night_start' => '00:00', 'night_end' => '23:59', 'kiosk_theme' => 'light']);

    $settings = AppSetting::forRoom($room);
    expect($settings->textScale())->toBe(130)->and($settings->night_mode)->toBeTrue()
        ->and(AppSetting::current()->textScale())->toBe(100);

    $html = $this->get($room->fresh()->kioskUrl() ?? route('kiosk.show', ['room' => $room, 'token' => $room->rotateKioskToken()]))->getContent();
    expect($html)->toContain('* 1.3)')->not->toContain('class="h-full dark"');
});

// ---------------------------------------------------------------- QR code & phone view

test('the screen shows a QR code to a signed phone view', function () {
    $room = Room::factory()->create();

    kiosk($room)->assertSeeHtml('<svg');

    $this->get(route('room.mobile', $room))->assertForbidden();
    $this->get(URL::temporarySignedRoute('room.mobile', now()->addMinutes(30), ['room' => $room]))
        ->assertOk()
        ->assertSee($room->name);
});

test('the phone view stops booking once its link expired', function () {
    configureGraph();
    graphWith([], ['graph.microsoft.com/v1.0/users/*/events' => Http::response(['id' => 'x'], 201)]);
    $room = Room::factory()->create();

    Livewire::withQueryParams(['expires' => now()->subMinute()->getTimestamp()])
        ->test('pages::mobile.room', ['room' => $room])
        ->call('quickBook', 15);

    Http::assertNotSent(fn ($request) => $request->method() === 'POST' && str_contains($request->url(), '/events'));
});

// ---------------------------------------------------------------- Microsoft sign-in

test('admins sign in with Microsoft and are matched by e-mail', function () {
    configureGraph();
    AppSetting::current()->forceFill(['sso_enabled' => true])->save();
    $user = User::factory()->create(['email' => 'alice@contoso.com']);

    $redirect = $this->get(route('auth.microsoft'));
    $redirect->assertRedirectContains('login.microsoftonline.com/contoso.onmicrosoft.com/oauth2/v2.0/authorize');
    parse_str(parse_url($redirect->headers->get('Location'), PHP_URL_QUERY), $query);
    expect($query['code_challenge_method'])->toBe('S256');

    Http::fake([
        'login.microsoftonline.com/*' => Http::response(['access_token' => 'user-token']),
        'graph.microsoft.com/v1.0/me*' => Http::response(['id' => 'oid-123', 'mail' => 'Alice@contoso.com', 'displayName' => 'Alice']),
    ]);

    $this->get(route('auth.microsoft.callback', ['code' => 'abc', 'state' => $query['state']]))
        ->assertRedirect(route('dashboard'));

    $this->assertAuthenticatedAs($user);
    expect($user->fresh()->microsoft_oid)->toBe('oid-123');
});

test('Microsoft sign-in rejects a wrong state and unknown people', function () {
    configureGraph();
    AppSetting::current()->forceFill(['sso_enabled' => true])->save();

    $this->get(route('auth.microsoft'));
    $this->get(route('auth.microsoft.callback', ['code' => 'abc', 'state' => 'forged']))->assertRedirect(route('login'));
    $this->assertGuest();

    $redirect = $this->get(route('auth.microsoft'));
    parse_str(parse_url($redirect->headers->get('Location'), PHP_URL_QUERY), $query);
    Http::fake([
        'login.microsoftonline.com/*' => Http::response(['access_token' => 'user-token']),
        'graph.microsoft.com/v1.0/me*' => Http::response(['id' => 'oid-x', 'mail' => 'stranger@contoso.com']),
    ]);
    $this->get(route('auth.microsoft.callback', ['code' => 'abc', 'state' => $query['state']]))->assertRedirect(route('login'));
    $this->assertGuest();
});

test('password sign-in can be turned off once SSO is on', function () {
    AppSetting::current()->forceFill(['sso_enabled' => true, 'password_login_enabled' => false])->save();
    configureGraph();
    $user = User::factory()->create();

    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])
        ->assertSessionHasErrors('email');
    $this->assertGuest();
});

test('passwords cannot be disabled by an admin who never signed in with Microsoft', function () {
    configureGraph();

    Livewire::actingAs(User::factory()->create())
        ->withQueryParams([])
        ->test('pages::admin.microsoft')
        ->set('sso_enabled', true)
        ->set('password_login_enabled', false)
        ->call('saveSso')
        ->assertHasErrors('password_login_enabled');

    expect(AppSetting::current()->fresh()->password_login_enabled)->toBeTrue();
});

test('the new admin pages render', function (string $route) {
    $this->actingAs(User::factory()->create())->get(route($route))->assertOk();
})->with(['admin.stats', 'admin.activity', 'admin.announcements', 'admin.notifications']);

function fakeJwt(array $roles): string
{
    $b64 = fn ($v) => rtrim(strtr(base64_encode(json_encode($v)), '+/', '-_'), '=');

    return $b64(['alg' => 'none']).'.'.$b64(['roles' => $roles]).'.sig';
}

test('granted application permissions are read from the token', function () {
    configureGraph();
    Http::fake(['login.microsoftonline.com/*' => Http::response(['access_token' => fakeJwt(['Calendars.ReadWrite']), 'expires_in' => 3600])]);

    expect(app(MicrosoftGraphService::class)->grantedRoles())->toBe(['Calendars.ReadWrite']);
});

test('the room import retries with a fresh token after a permission was just granted', function () {
    configureGraph();
    $tokens = 0;
    Http::fake(function ($request) use (&$tokens) {
        if (str_contains($request->url(), 'login.microsoftonline.com')) {
            $tokens++;

            return Http::response(['access_token' => 'token-'.$tokens, 'expires_in' => 3600]);
        }

        // The old token lacks Place.Read.All; the new one has it.
        return $request->header('Authorization')[0] === 'Bearer token-1'
            ? Http::response(['error' => ['code' => 'Authorization_RequestDenied']], 403)
            : Http::response(['value' => [['displayName' => 'Atlas', 'emailAddress' => 'atlas@contoso.com']]]);
    });

    $rooms = app(MicrosoftGraphService::class)->listRooms();

    expect($tokens)->toBe(2)->and($rooms[0]['email'])->toBe('atlas@contoso.com');
});

test('Microsoft sign-in is refused to accounts without administrator access', function () {
    configureGraph();
    AppSetting::current()->forceFill(['sso_enabled' => true])->save();
    User::factory()->notAdmin()->create(['email' => 'bob@contoso.com']);

    $redirect = $this->get(route('auth.microsoft'));
    parse_str(parse_url($redirect->headers->get('Location'), PHP_URL_QUERY), $query);
    Http::fake([
        'login.microsoftonline.com/*' => Http::response(['access_token' => 'user-token']),
        'graph.microsoft.com/v1.0/me*' => Http::response(['id' => 'oid-bob', 'mail' => 'bob@contoso.com']),
    ]);

    $this->get(route('auth.microsoft.callback', ['code' => 'abc', 'state' => $query['state']]))
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors('email');
    $this->assertGuest();
});
