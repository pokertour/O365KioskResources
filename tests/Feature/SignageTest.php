<?php

use App\Models\ActivityLog;
use App\Models\MicrosoftSetting;
use App\Models\Room;
use App\Models\Signboard;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

function fakeSignageGraph(array $eventsByRoom = []): void
{
    MicrosoftSetting::current()->forceFill([
        'tenant_id' => 'contoso.onmicrosoft.com',
        'client_id' => '11111111-1111-1111-1111-111111111111',
        'client_secret' => 'secret',
    ])->save();

    Http::fake(function ($request) use ($eventsByRoom) {
        if (str_contains($request->url(), 'login.microsoftonline.com')) {
            return Http::response(['access_token' => 'token', 'expires_in' => 3600]);
        }
        foreach ($eventsByRoom as $email => $events) {
            if (str_contains($request->url(), rawurlencode($email)) || str_contains($request->url(), $email)) {
                return Http::response(['value' => $events]);
            }
        }

        return Http::response(['value' => []]);
    });
}

test('an administrator creates a display with rooms, order and directions', function () {
    $atlas = Room::factory()->create(['name' => 'Atlas']);
    $boreal = Room::factory()->create(['name' => 'Boréal']);
    Room::factory()->create(['name' => 'Cap Nord']);

    $page = Livewire::actingAs(User::factory()->create())
        ->test('pages::admin.signage')
        ->call('create')
        ->set('name', '2nd floor lobby');

    // Rooms are listed alphabetically: enable Atlas and Boréal, then put Boréal first.
    $page->set('rooms.0.enabled', true)->set('rooms.0.direction', 'right')
        ->set('rooms.1.enabled', true)->set('rooms.1.direction', 'stairs-up')
        ->call('move', 1, -1)
        ->call('save')
        ->assertHasNoErrors();

    $signboard = Signboard::query()->sole();
    expect($signboard->name)->toBe('2nd floor lobby')
        ->and($signboard->rooms->pluck('id')->all())->toBe([$boreal->id, $atlas->id])
        ->and($signboard->rooms->pluck('pivot.direction')->all())->toBe(['stairs-up', 'right'])
        ->and($signboard->url())->not->toBeNull()
        ->and(ActivityLog::query()->where('action', 'signage.created')->exists())->toBeTrue();
});

test('unknown directions are not stored', function () {
    $room = Room::factory()->create();
    $signboard = Signboard::create(['name' => 'Lobby']);

    $signboard->syncRooms([$room->id => 'teleport']);

    expect($signboard->rooms()->first()->pivot->direction)->toBeNull();
});

test('the display shows each room with its status and direction', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-22 10:00', 'Europe/Paris'));
    $busy = Room::factory()->create(['name' => 'Horizon', 'o365_resource_email' => 'horizon@contoso.com']);
    $free = Room::factory()->create(['name' => 'Duo', 'o365_resource_email' => 'duo@contoso.com']);
    fakeSignageGraph([
        'horizon@contoso.com' => [[
            'id' => 'a', 'subject' => 'Board meeting',
            'start' => ['dateTime' => '2026-10-22T09:30:00'], 'end' => ['dateTime' => '2026-10-22T11:00:00'],
            'organizer' => ['emailAddress' => ['name' => 'Julie', 'address' => 'julie@contoso.com']],
        ]],
    ]);
    $signboard = Signboard::create(['name' => 'Lobby']);
    $signboard->syncRooms([$busy->id => 'left', $free->id => 'elevator']);
    $token = $signboard->rotateToken();

    $this->get(route('kiosk.signage', ['signboard' => $signboard, 'token' => $token]))
        ->assertOk()
        ->assertHeader('X-Robots-Tag')
        ->assertSeeInOrder(['Horizon', __('Busy'), 'Board meeting', 'Duo', __('Available'), __('Free all day')]);

    expect($signboard->fresh()->seen_at)->not->toBeNull();
    CarbonImmutable::setTestNow();
});

test('a wrong or regenerated link does not open the display', function () {
    $signboard = Signboard::create(['name' => 'Lobby']);
    $old = $signboard->rotateToken();

    $this->get(route('kiosk.signage', ['signboard' => $signboard, 'token' => 'nope']))->assertNotFound();

    $signboard->rotateToken();
    $this->get(route('kiosk.signage', ['signboard' => $signboard, 'token' => $old]))->assertNotFound();
});

test('only administrators manage the displays', function () {
    $this->actingAs(User::factory()->create(['is_admin' => false]))
        ->get(route('admin.signage'))
        ->assertForbidden();
});
