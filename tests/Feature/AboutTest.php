<?php

use App\Models\User;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

function fakeLatestRelease(?string $tag): void
{
    Http::fake([
        'api.github.com/repos/*/releases/latest' => $tag
            ? Http::response(['tag_name' => $tag, 'html_url' => "https://github.com/pokertour/O365KioskResources/releases/tag/{$tag}", 'published_at' => '2026-10-22T12:00:00Z'])
            : Http::response(['message' => 'Not Found'], 404),
    ]);
}

test('the about page shows the version and the licence', function () {
    config(['app.version' => '1.2.0']);
    fakeLatestRelease('1.2.0');

    $this->actingAs(User::factory()->create())
        ->get(route('admin.about'))
        ->assertOk()
        ->assertSee('1.2.0')
        ->assertSee(__('MIT licence'))
        ->assertSee('github.com/pokertour/O365KioskResources');

    // GitHub is only asked once the page is displayed.
    Http::assertNothingSent();
});

test('a newer release is announced', function () {
    config(['app.version' => '1.0.2']);
    fakeLatestRelease('1.1.0');

    Livewire::actingAs(User::factory()->create())
        ->test('pages::admin.about')
        ->call('load')
        ->assertSee(__('Version :version is available', ['version' => '1.1.0']))
        ->assertSee('docker compose pull');
});

test('the update check knows when it is up to date, a dev build or offline', function (string $version, ?string $latest, string $state) {
    config(['app.version' => $version]);
    fakeLatestRelease($latest);

    $component = Livewire::actingAs(User::factory()->create())->test('pages::admin.about')->call('load');

    expect($component->instance()->updateState)->toBe($state);
})->with([
    'up to date' => ['1.1.0', '1.1.0', 'ok'],
    'ahead of the latest release' => ['1.2.0', '1.1.0', 'ok'],
    'development build' => ['local-dev', '1.1.0', 'dev'],
    'GitHub unreachable' => ['1.1.0', null, 'unknown'],
]);

test('non administrators cannot open the about page', function () {
    $this->actingAs(User::factory()->create(['is_admin' => false]))
        ->get(route('admin.about'))
        ->assertForbidden();
});
