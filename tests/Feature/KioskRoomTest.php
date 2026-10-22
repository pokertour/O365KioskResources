<?php

use App\Models\Room;

test('kiosk page loads with valid token', function () {
    $room = Room::factory()->create(['slug' => 'salle-test']);
    $plain = $room->rotateKioskToken();

    $this->get(route('kiosk.show', ['room' => $room, 'token' => $plain]))
        ->assertOk();
});

test('kiosk page returns not found for invalid token', function () {
    $room = Room::factory()->create(['slug' => 'salle-x']);

    $this->get(route('kiosk.show', ['room' => $room, 'token' => 'wrong-token-xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx']))
        ->assertNotFound();
});
