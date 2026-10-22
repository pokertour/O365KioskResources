<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $email = env('ADMIN_EMAIL', env('ADMIN_SEED_EMAIL', 'admin@example.com'));
        $password = env('ADMIN_PASSWORD', env('ADMIN_SEED_PASSWORD', 'password'));
        $name = env('ADMIN_NAME', env('ADMIN_SEED_NAME', 'Administrateur'));

        User::query()->firstOrNew(['email' => $email])
            ->forceFill([
                'name' => $name,
                'password' => Hash::make($password),
                'is_admin' => true,
                'email_verified_at' => now(),
            ])
            ->save();
    }
}
