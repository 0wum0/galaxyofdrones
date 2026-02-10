<?php

namespace Database\Seeders;

use App\Models\Setting;
use Illuminate\Database\Seeder;

class SettingsTableSeeder extends Seeder
{
    /**
     * Run the database seed.
     *
     * Idempotent: uses firstOrCreate on unique 'key' column.
     */
    public function run()
    {
        Setting::firstOrCreate(
            ['key' => 'title'],
            ['value' => ['en' => 'Galaxy of Drones Online']]
        );

        Setting::firstOrCreate(
            ['key' => 'description'],
            ['value' => ['en' => 'An open source multiplayer space strategy game.']]
        );

        Setting::firstOrCreate(
            ['key' => 'author'],
            ['value' => ['en' => 'App']]
        );
    }
}
