<?php

namespace Database\Seeders;

use App\Models\EventType;
use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed local development data. Never run this in production.
     * The factory password for every seeded user is "password".
     */
    public function run(): void
    {
        $admin = User::factory()->admin()->create([
            'name' => 'Admin User',
            'email' => 'admin@example.com',
            'username' => 'admin-user',
        ]);

        EventType::factory()->for($admin)->create([
            'title' => 'Intro call',
            'slug' => 'intro-call',
            'buffer_after_minutes' => 10,
            'min_notice_minutes' => 240,
        ]);

        User::factory()->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'username' => 'test-user',
            'timezone' => 'America/New_York',
        ]);
    }
}
