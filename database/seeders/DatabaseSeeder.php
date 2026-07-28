<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            RolePermissionSeeder::class,
        ]);

        $admin = User::firstOrCreate(
            ['email' => 'admin@xerex.local'],
            [
                'name'      => 'Xerex Admin',
                'password'  => Hash::make('password'),
                'is_admin'  => true,
                'is_active' => true,
            ]
        );
        $admin->assignRole('admin');

        $user = User::firstOrCreate(
            ['email' => 'demo@xerex.local'],
            [
                'name'      => 'Demo Customer',
                'password'  => Hash::make('password'),
                'is_admin'  => false,
                'is_active' => true,
            ]
        );
        $user->assignRole('customer');

        $this->command->info('Database seeded. Admin: admin@xerex.local / password');
    }
}
