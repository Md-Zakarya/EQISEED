<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;

class AdminRoleSeeder extends Seeder
{
    public function run()
    {
        // Reset cached roles and permissions
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        // Create admin role
        Role::create(['name' => 'admin']);
        Role::create(['name' => 'user']);
        
        // Create admin permissions
        Permission::create(['name' => 'approve rounds']);
        Permission::create(['name' => 'activate rounds']);
        Permission::create(['name' => 'reject rounds']);
        Permission::create(['name' => 'manage rounds']);
        
        // Assign permissions to admin role
        $adminRole = Role::findByName('admin');
        $adminRole->givePermissionTo([
            'approve rounds',
            'activate rounds', 
            'reject rounds',
            'manage rounds'
        ]);
    }
}