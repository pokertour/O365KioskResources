<?php

use App\Models\AppSetting;
use App\Models\User;
use Livewire\Livewire;

test('guests see the organisation default language', function () {
    AppSetting::current()->forceFill(['locale' => 'fr'])->save();

    $this->get(route('login'))->assertOk()->assertSee('Mot de passe oublié ?');

    AppSetting::current()->forceFill(['locale' => 'en'])->save();
    AppSetting::flushCache();

    $this->get(route('login'))->assertOk()->assertSee('Forgot your password?');
});

test('visitors can switch language', function () {
    AppSetting::current()->forceFill(['locale' => 'fr'])->save();

    $this->post(route('locale.update'), ['locale' => 'en'])->assertRedirect();

    $this->get(route('login'))->assertSee('Forgot your password?');
});

test('the language choice is saved on the signed-in user', function () {
    $user = User::factory()->create(['locale' => 'en']);

    $this->actingAs($user)->post(route('locale.update'), ['locale' => 'fr']);

    expect($user->fresh()->locale)->toBe('fr');
    $this->actingAs($user->fresh())->get(route('admin.rooms.index'))->assertSee('Nouvelle salle');
});

test('unknown languages are rejected', function () {
    $this->post(route('locale.update'), ['locale' => 'xx'])->assertSessionHasErrors('locale');
});

test('admins can update general settings', function () {
    Livewire::actingAs(User::factory()->create())
        ->test('pages::admin.general')
        ->set('locale', 'en')
        ->set('timezone', 'America/New_York')
        ->set('time_format', '12h')
        ->call('save')
        ->assertHasNoErrors();

    $settings = AppSetting::current()->fresh();
    expect($settings->timezone)->toBe('America/New_York')
        ->and($settings->time_format)->toBe('12h')
        ->and($settings->locale)->toBe('en');
});

test('an invalid time zone is rejected', function () {
    Livewire::actingAs(User::factory()->create())
        ->test('pages::admin.general')
        ->set('timezone', 'Mars/Olympus')
        ->call('save')
        ->assertHasErrors('timezone');
});

test('admins can configure the screens', function () {
    Livewire::actingAs(User::factory()->create())
        ->test('pages::admin.screens')
        ->set('kiosk_refresh_seconds', 60)
        ->set('quick_book_durations', ['30', '10', '90'])
        ->set('max_booking_minutes', 120)
        ->call('save')
        ->assertHasNoErrors();

    $settings = AppSetting::current()->fresh();
    expect($settings->quickBookDurations())->toBe([10, 30, 90])
        ->and($settings->refreshSeconds())->toBe(60)
        ->and($settings->max_booking_minutes)->toBe(120);
});

test('at most three quick booking durations', function () {
    Livewire::actingAs(User::factory()->create())
        ->test('pages::admin.screens')
        ->set('quick_book_durations', [10, 15, 30, 60])
        ->call('save')
        ->assertHasErrors('quick_book_durations');
});

test('every admin settings page renders', function (string $route) {
    $this->actingAs(User::factory()->create())
        ->withSession(['auth.password_confirmed_at' => time()])
        ->get(route($route))
        ->assertOk();
})->with(['admin.rooms.index', 'admin.general', 'admin.screens', 'admin.branding', 'admin.microsoft', 'admin.users.index']);

test('screen behaviour settings are saved', function () {
    Livewire::actingAs(User::factory()->create())
        ->test('pages::admin.screens')
        ->set('soon_minutes', 5)
        ->set('allow_end_extend', false)
        ->set('text_scale', 130)
        ->set('night_mode', true)
        ->set('night_start', '21:00')
        ->set('night_end', '06:30')
        ->call('save')
        ->assertHasNoErrors();

    $s = AppSetting::current()->fresh();
    expect($s->soonMinutes())->toBe(5)
        ->and($s->allow_end_extend)->toBeFalse()
        ->and($s->textScale())->toBe(130)
        ->and($s->night_mode)->toBeTrue()
        ->and($s->night_start)->toBe('21:00');
});

test('agenda layout and working hours are saved', function () {
    Livewire::actingAs(User::factory()->create())
        ->test('pages::admin.screens')
        ->set('agenda_view', 'list')
        ->set('day_start', '07:30')
        ->set('day_end', '18:00')
        ->call('save')
        ->assertHasNoErrors();

    $s = AppSetting::current()->fresh();
    expect($s->agendaView())->toBe('list')->and($s->dayHours())->toBe([7.5, 18.0]);
});

test('working hours must end after they start', function () {
    Livewire::actingAs(User::factory()->create())
        ->test('pages::admin.screens')
        ->set('day_start', '18:00')
        ->set('day_end', '08:00')
        ->call('save')
        ->assertHasErrors('day_end');
});

test('the app version is shown, "local-dev" outside tagged builds', function () {
    config(['app.version' => 'local-dev']);
    expect(app_version())->toBe('local-dev');

    config(['app.version' => '1.4.2']);
    expect(app_version())->toBe('v1.4.2');

    $this->actingAs(User::factory()->create())->get(route('admin.general'))->assertSee('v1.4.2');
});
