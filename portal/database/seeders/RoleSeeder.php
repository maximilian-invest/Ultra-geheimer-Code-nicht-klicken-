<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;

class RoleSeeder extends Seeder
{
    public function run(): void
    {
        // Create roles
        $admin = Role::firstOrCreate(['name' => 'admin']);
        $makler = Role::firstOrCreate(['name' => 'makler']);
        $backoffice = Role::firstOrCreate(['name' => 'backoffice']);
        $eigentuemer = Role::firstOrCreate(['name' => 'eigentuemer']);

        // Create admin user
        $user = User::firstOrCreate(
            ['email' => 'maximilian@hoelzl.investments'],
            [
                'name' => 'Maximilian Hölzl',
                'password' => Hash::make('SRHomes2026!'),
                'user_type' => 'admin',
            ]
        );
        $user->assignRole('admin');

        $this->command->info('Roles and admin user created.');
    }
}
