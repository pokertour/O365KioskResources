<?php

use App\Http\Middleware\EnsureUserIsAdmin;
use App\Models\AppSetting;
use App\Models\BrandingSetting;
use App\Models\Room;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Once;
use Livewire\Livewire;

test('admin middleware is re-applied on livewire update requests', function () {
    expect(Livewire::getPersistentMiddleware())->toContain(EnsureUserIsAdmin::class);
});

test('responses carry security headers', function () {
    $this->get(route('home'))
        ->assertOk()
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('X-Frame-Options', 'SAMEORIGIN')
        ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
});

test('kiosk pages never leak their token through referer or caches', function () {
    $room = Room::factory()->create();
    $token = $room->rotateKioskToken();

    $response = $this->get(route('kiosk.show', ['room' => $room, 'token' => $token]))
        ->assertOk()
        ->assertHeader('Referrer-Policy', 'no-referrer')
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow');

    expect($response->headers->get('Cache-Control'))->toContain('no-store');
});

test('regenerating the kiosk token invalidates the previous link', function () {
    $room = Room::factory()->create();
    $old = $room->rotateKioskToken();
    $new = $room->rotateKioskToken();

    $this->get(route('kiosk.show', ['room' => $room, 'token' => $old]))->assertNotFound();
    $this->get(route('kiosk.show', ['room' => $room, 'token' => $new]))->assertOk();
    expect($room->fresh()->kioskUrl())->toEndWith($new);
});

test('an open kiosk reloads itself once its token is revoked', function () {
    $room = Room::factory()->create();
    $token = $room->rotateKioskToken();

    $component = Livewire::test('pages::kiosk.room', ['room' => $room, 'token' => $token]);
    $room->rotateKioskToken();

    $component->call('$refresh');

    expect(json_encode($component->effects))->toContain('location.reload');
});

test('kiosk token is stored encrypted and hidden from serialization', function () {
    $room = Room::factory()->create();
    $token = $room->rotateKioskToken();

    $raw = DB::table('screens')->where('id', $room->primaryScreen()->id)->value('token');
    expect($raw)->not->toContain($token)
        ->and($room->toArray())->not->toHaveKeys(['kiosk_token', 'kiosk_token_hash']);
});

test('microsoft settings require a recent password confirmation', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('admin.microsoft'))
        ->assertRedirect(route('password.confirm'));
});

test('is_admin cannot be mass assigned', function () {
    $user = User::factory()->notAdmin()->create();
    $user->fill(['is_admin' => true])->save();

    expect($user->fresh()->is_admin)->toBeFalse();
});

test('logos are stored privately and served with a sandboxing policy', function () {
    Storage::fake('local');
    $admin = User::factory()->create();

    Livewire::actingAs($admin)
        ->test('pages::admin.branding')
        ->set('logo', UploadedFile::fake()->create('logo.svg', 2, 'image/svg+xml'))
        ->call('save')
        ->assertHasNoErrors();

    $branding = BrandingSetting::current();
    Storage::disk('local')->assertExists($branding->logo_path);

    $response = $this->get($branding->logoUrl())
        ->assertOk()
        ->assertHeader('X-Content-Type-Options', 'nosniff');

    expect($response->headers->get('Content-Security-Policy'))->toContain('sandbox');
});

test('branding colours are validated before reaching the page css', function () {
    $admin = User::factory()->create();

    Livewire::actingAs($admin)
        ->test('pages::admin.branding')
        ->set('primary_color', 'red;}</style><script>')
        ->call('save')
        ->assertHasErrors(['primary_color']);

    expect(BrandingSetting::sanitizeColor('url(x)', '#000000'))->toBe('#000000');
});

test('missing pages use the branded error page in the organisation language', function () {
    AppSetting::current()->forceFill(['locale' => 'fr'])->save();

    $this->get('/does-not-exist')
        ->assertNotFound()
        ->assertSee('Page introuvable')
        ->assertSee('Retour à l’accueil');
});

test('a revoked screen link offers to pair the device again', function () {
    AppSetting::current()->forceFill(['locale' => 'en'])->save();
    $room = Room::factory()->create();

    $this->get(route('kiosk.show', ['room' => $room, 'token' => str_repeat('x', 48)]))
        ->assertNotFound()
        ->assertSee('This screen link is no longer valid')
        ->assertSee(route('kiosk.pair'));
});

test('error pages render even when the database is unavailable', function (int $status) {
    Schema::shouldReceive('hasTable')->andThrow(new RuntimeException('database down'));
    AppSetting::flushCache();

    $html = view('errors.'.$status)->render();

    expect($html)->toContain((string) $status);
})->with([403, 404, 419, 429, 500, 503]);

test('a second logo can be set for dark backgrounds', function () {
    Storage::fake('local');

    Livewire::actingAs(User::factory()->create())
        ->test('pages::admin.branding')
        ->set('logo', UploadedFile::fake()->image('light.png'))
        ->set('logo_dark', UploadedFile::fake()->image('dark.png'))
        ->call('save')
        ->assertHasNoErrors();

    $branding = BrandingSetting::current();
    expect($branding->logoUrl('light'))->not->toBe($branding->logoUrl('dark'))
        ->and($branding->logoUrlFor('dark'))->toBe($branding->logoUrl('dark'));

    $this->get($branding->logoUrl('dark'))->assertOk()->assertHeader('Content-Type', 'image/png');

    // Each theme gets its own image: the dark one only shows under the `dark` class.
    Once::flush(); // the shared $branding is memoised per request
    $html = Blade::render('<x-brand-mark />');
    expect($html)->toContain($branding->logoUrl('light'))
        ->toContain($branding->logoUrl('dark'))
        ->toContain('dark:hidden')
        ->toContain('dark:block');
});

test('a single logo is used on both backgrounds', function () {
    Storage::fake('local');

    Livewire::actingAs(User::factory()->create())
        ->test('pages::admin.branding')
        ->set('logo_dark', UploadedFile::fake()->image('white.png'))
        ->call('save');

    $branding = BrandingSetting::current();
    expect($branding->hasLogo('light'))->toBeFalse()
        ->and($branding->logoUrlFor('light'))->toBe($branding->logoUrl('dark'));

    Once::flush();
    expect(Blade::render('<x-brand-mark />'))->toContain($branding->logoUrl('dark'))->not->toContain('dark:hidden');
});

test('removing the dark logo keeps the light one', function () {
    Storage::fake('local');
    $admin = User::factory()->create();

    Livewire::actingAs($admin)
        ->test('pages::admin.branding')
        ->set('logo', UploadedFile::fake()->image('light.png'))
        ->set('logo_dark', UploadedFile::fake()->image('dark.png'))
        ->call('save');
    $darkPath = BrandingSetting::current()->logo_dark_path;

    Livewire::actingAs($admin)
        ->test('pages::admin.branding')
        ->call('confirmRemoveLogo', 'dark')
        ->call('removeLogo');

    $branding = BrandingSetting::current();
    Storage::disk('local')->assertMissing($darkPath);
    expect($branding->hasLogo('light'))->toBeTrue()
        ->and($branding->hasLogo('dark'))->toBeFalse()
        ->and($branding->logoUrlFor('dark'))->toBe($branding->logoUrl('light'));

    $this->get('/branding/logo/dark')->assertNotFound();
    $this->get('/branding/logo/other')->assertNotFound();
});
