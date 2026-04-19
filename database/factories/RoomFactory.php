<?php

namespace Database\Factories;

use App\Models\Room;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Room>
 */
class RoomFactory extends Factory
{
    protected $model = Room::class;

    public function definition(): array
    {
        $name = fake()->words(2, true).' Room';

        return [
            'name' => $name,
            'slug' => Str::slug($name).'-'.fake()->unique()->numerify('###'),
            'o365_resource_email' => fake()->unique()->safeEmail(),
            'kiosk_token_hash' => Room::hashKioskToken(Str::random(48)),
        ];
    }
}
