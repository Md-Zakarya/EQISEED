<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Run the role seeder first
        $this->call(AdminRoleSeeder::class);
        
        // Then create the admin user
        $this->call(AdminUserSeeder::class);



        $this->call(PredefinedRoundSeeder::class);
        $this->call(PredefinedSectorSeeder::class);


        // Your existing test user creation
        // User::factory()->create([
        //     'name' => 'Test User',
        //     'email' => 'test@example.com',
        // ]);
    }
}