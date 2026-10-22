<?php

use App\Http\Controllers\BrandingLogoController;
use App\Http\Controllers\LocaleController;
use App\Http\Controllers\MicrosoftLoginController;
use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');

Route::get('branding/logo', BrandingLogoController::class)->name('branding.logo');

Route::middleware(['guest', 'throttle:20,1'])->group(function () {
    Route::get('auth/microsoft', [MicrosoftLoginController::class, 'redirect'])->name('auth.microsoft');
    Route::get('auth/microsoft/callback', [MicrosoftLoginController::class, 'callback'])->name('auth.microsoft.callback');
});

Route::post('locale', LocaleController::class)
    ->middleware('throttle:20,1')
    ->name('locale.update');

// A new screen opens /kiosk, shows a pairing code and waits for an admin to attach it to a room.
Route::livewire('kiosk', 'pages::kiosk.pair')
    ->middleware(['throttle:30,1'])
    ->name('kiosk.pair');

Route::livewire('kiosk/{room}/{token}', 'pages::kiosk.room')
    ->middleware(['throttle:120,1'])
    ->name('kiosk.show');

// Phone view reached by scanning the QR code of a screen: signed, short-lived link.
Route::livewire('rooms/{room}/mobile', 'pages::mobile.room')
    ->middleware(['signed', 'throttle:60,1'])
    ->name('room.mobile');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', function () {
        if (auth()->user()->is_admin) {
            return redirect()->route('admin.rooms.index');
        }

        return redirect()->route('profile.edit');
    })->name('dashboard');
});

Route::middleware(['auth', 'verified', 'admin'])->prefix('admin')->name('admin.')->group(function () {
    Route::livewire('rooms', 'pages::admin.rooms-index')->name('rooms.index');
    Route::livewire('rooms/{room}', 'pages::admin.room-detail')->name('rooms.show');
    Route::livewire('stats', 'pages::admin.stats')->name('stats');
    Route::livewire('activity', 'pages::admin.activity')->name('activity');
    Route::livewire('announcements', 'pages::admin.announcements')->name('announcements');

    Route::livewire('settings/general', 'pages::admin.general')->name('general');
    Route::livewire('settings/screens', 'pages::admin.screens')->name('screens');
    Route::livewire('settings/appearance', 'pages::admin.branding')->name('branding');
    Route::livewire('settings/notifications', 'pages::admin.notifications')->name('notifications');
    Route::livewire('settings/microsoft', 'pages::admin.microsoft')
        ->middleware('password.confirm')
        ->name('microsoft');
    Route::livewire('settings/users', 'pages::admin.admins')->name('users.index');
    Route::livewire('settings/diagnostics', 'pages::admin.diagnostics')->name('diagnostics');
    Route::livewire('settings/about', 'pages::admin.about')->name('about');

    // Former URLs.
    Route::redirect('apparence', 'settings/appearance');
    Route::redirect('identite', 'settings/appearance');
    Route::redirect('microsoft', 'settings/microsoft');
    Route::redirect('users', 'settings/users');
});

require __DIR__.'/settings.php';
