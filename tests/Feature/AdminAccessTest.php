<?php

use App\Models\User;

test('guests cannot access admin rooms', function () {
    $this->get(route('admin.rooms.index'))
        ->assertRedirect(route('login'));
});

test('non admin users cannot access admin area', function () {
    $user = User::factory()->notAdmin()->create();

    $this->actingAs($user)
        ->get(route('admin.rooms.index'))
        ->assertForbidden();
});

test('admins can access admin rooms', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('admin.rooms.index'))
        ->assertOk();
});

test('admins can access branding page', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('admin.branding'))
        ->assertOk();
});
