<?php

namespace Database\Seeders;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UsersTableSeeder extends Seeder
{
    /**
     * Run the database seed.
     *
     * Idempotent: uses firstOrCreate so re-running (updater) won't duplicate the default user.
     */
    public function run()
    {
        User::firstOrCreate(
            ['username' => 'koodilab'],
            [
                'email' => 'support@koodilab.com',
                'email_verified_at' => Carbon::now(),
                'password' => Hash::make('havefun'),
            ]
        );
    }
}
