<?php

use App\Models\MicrosoftSetting;
use App\Models\Room;
use App\Models\User;
use App\Services\SystemHealth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

/** Fakes Microsoft with a token granting the given application permissions. */
function fakeGraphGranting(array $roles): void
{
    MicrosoftSetting::current()->forceFill([
        'tenant_id' => 'contoso.onmicrosoft.com',
        'client_id' => '11111111-1111-1111-1111-111111111111',
        'client_secret' => 'secret',
    ])->save();

    $payload = rtrim(strtr(base64_encode(json_encode(['roles' => $roles])), '+/', '-_'), '=');
    Http::fake([
        'login.microsoftonline.com/*' => Http::response(['access_token' => "header.{$payload}.signature", 'expires_in' => 3600]),
        'graph.microsoft.com/v1.0/users/*/calendar/calendarView*' => Http::response(['value' => []]),
    ]);
}

function healthCheck(array $checks, string $label): array
{
    return collect($checks)->firstWhere('label', $label) ?? throw new RuntimeException("No check named {$label}");
}

test('only administrators see the diagnostics', function () {
    $this->actingAs(User::factory()->create(['is_admin' => false]))
        ->get(route('admin.diagnostics'))
        ->assertForbidden();

    $this->actingAs(User::factory()->create())
        ->get(route('admin.diagnostics'))
        ->assertOk()
        ->assertSee(__('Background tasks'))
        ->assertSee(__('Agenda sync'));
});

test('the scheduler heartbeat tells whether the scheduler runs', function () {
    $scheduler = fn () => healthCheck(app(SystemHealth::class)->checks()['background'], __('Scheduler'))['status'];

    expect($scheduler())->toBe('warning');

    $this->artisan('schedule:run')->assertSuccessful();
    expect(Cache::get(SystemHealth::SCHEDULER_HEARTBEAT_KEY))->not->toBeNull()
        ->and($scheduler())->toBe('ok');

    $this->travel(10)->minutes();
    expect($scheduler())->toBe('error');
});

test('jobs stuck in the queue point to a missing worker', function () {
    config(['queue.default' => 'database']);
    DB::table('jobs')->insert(['queue' => 'default', 'payload' => '{}', 'attempts' => 0, 'available_at' => now()->subMinutes(20)->getTimestamp(), 'created_at' => now()->subMinutes(20)->getTimestamp()]);

    $queue = healthCheck(app(SystemHealth::class)->checks()['background'], __('Queue'));

    expect($queue['status'])->toBe('error')->and($queue['hint'])->toContain('queue:work');
});

test('the live test reports a missing required permission', function () {
    fakeGraphGranting(['Place.Read.All']);
    Room::factory()->create();

    $checks = app(SystemHealth::class)->checks(live: true)['microsoft'];

    expect(healthCheck($checks, __('Access token'))['status'])->toBe('ok')
        ->and(healthCheck($checks, 'Calendars.ReadWrite')['status'])->toBe('error')
        ->and(healthCheck($checks, 'Place.Read.All')['status'])->toBe('ok');
});

test('Microsoft 365 is only called when the administrator runs the tests', function () {
    fakeGraphGranting(['Calendars.ReadWrite']);
    $this->actingAs(User::factory()->create());

    $page = Livewire::test('pages::admin.diagnostics');
    Http::assertNothingSent();

    $page->call('runTests')
        ->assertSet('microsoft', fn ($checks) => healthCheck($checks, 'Calendars.ReadWrite')['status'] === 'ok')
        ->assertSee(__('Granted'));
    Http::assertSent(fn ($request) => str_contains($request->url(), 'login.microsoftonline.com'));
});

test('the report never contains the Microsoft credentials', function () {
    fakeGraphGranting(['Calendars.ReadWrite']);
    $this->actingAs(User::factory()->create());

    $report = Livewire::test('pages::admin.diagnostics')->call('runTests')->instance()->report;

    expect($report)->toContain('Microsoft 365')
        ->not->toContain('contoso.onmicrosoft.com')
        ->not->toContain('11111111-1111-1111-1111-111111111111')
        ->not->toContain('secret');
});
