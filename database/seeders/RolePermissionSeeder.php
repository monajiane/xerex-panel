<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RolePermissionSeeder extends Seeder
{
    public function run(): void
    {
        // Reset cached roles and permissions
        app()['cache']->forget('spatie.permission.cache');

        $permissions = [
            'admin.dashboard.view',
            'admin.users.manage',
            'admin.roles.manage',
            'admin.edges.view',    'admin.edges.manage',
            'admin.origins.view',  'admin.origins.manage',
            'admin.domains.view',  'admin.domains.manage',
            'admin.rules.view',    'admin.rules.manage',
            'admin.dns.manage',
            'admin.ssl.manage',
            'admin.monitoring.view',
            'admin.audit.view',

            'customer.domains.view',  'customer.domains.manage',
            'customer.origins.view',  'customer.origins.manage',
            'customer.rules.view',    'customer.rules.manage',
            'customer.monitoring.view',
        ];

        foreach ($permissions as $p) {
            Permission::firstOrCreate(['name' => $p, 'guard_name' => 'web']);
        }

        $admin = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        $admin->syncPermissions(Permission::all());

        $operator = Role::firstOrCreate(['name' => 'operator', 'guard_name' => 'web']);
        $operator->syncPermissions(array_filter($permissions, fn ($p) => str_starts_with($p, 'admin.') && ! str_contains($p, 'users.manage') && ! str_contains($p, 'roles.manage')));

        $customer = Role::firstOrCreate(['name' => 'customer', 'guard_name' => 'web']);
        $customer->syncPermissions(array_filter($permissions, fn ($p) => str_starts_with($p, 'customer.')));

        $this->command->info('Roles and permissions seeded.');
    }
}
