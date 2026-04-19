<?php

use App\Models\Room;
use Illuminate\Support\Str;

test('kiosk page loads with valid token', function () {
    $plain = Str::random(48);
    $room = Room::factory()->create([
        'slug' => 'salle-test',
        'kiosk_token_hash' => Room::hashKioskToken($plain),
    ]);

    $this->get(route('kiosk.show', ['room' => $room, 'token' => $plain]))
        ->assertOk();
});

test('kiosk page returns not found for invalid token', function () {
    $room = Room::factory()->create(['slug' => 'salle-x']);

    $this->get(route('kiosk.show', ['room' => $room, 'token' => 'wrong-token-xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx']))
        ->assertNotFound();
});
