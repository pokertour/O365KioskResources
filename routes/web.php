<?php

use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');

Route::livewire('kiosk/{room}/{token}', 'pages::kiosk.room')
    ->middleware(['throttle:120,1'])
    ->name('kiosk.show');

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
    Route::livewire('users', 'pages::admin.admins')->name('users.index');
    Route::livewire('identite', 'pages::admin.branding')->name('branding');
});

require __DIR__.'/settings.php';
