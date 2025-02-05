<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;

class AdminUserSeeder extends Seeder
{
    public function run()
    {   
        DB::statement('SET FOREIGN_KEY_CHECKS=0;');
        User::where('email', 'admin@admin.com')->delete();
        DB::statement('SET FOREIGN_KEY_CHECKS=1;');
        $admin = User::create([
            'first_name' => 'Admin',
            'last_name' => 'User',
            'email' => 'admin@admin.com',
            'password' => Hash::make('admin123'),
            'phone' => '1234567890',
            'user_type' => 'admin',
            'has_experience' => true,
            'country_code' => '+1'
        ]);

        $admin->assignRole('admin');
    }
}