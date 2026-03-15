<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // User::factory(10)->create();

        User::firstOrCreate(
            ['email' => 'admin@herokid.test'],
            [
                'name'     => 'Admin User',
                'password' => \Illuminate\Support\Facades\Hash::make('password'),
                'role'     => 'admin',
            ]
        );

        $this->call([
            StorySeeder::class,
            FaqSeeder::class,
            SettingsSeeder::class,
            TestimonialSeeder::class,
        ]);
    }
}
