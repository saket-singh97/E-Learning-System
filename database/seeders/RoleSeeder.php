<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;

class RoleSeeder extends Seeder
{
    /**
     * Roles carry no tenant_id (see app/Domains/Tenancy/Services/RoleHierarchy.php
     * for the assignment-order rationale) — this is a single global catalog,
     * not seeded per tenant.
     */
    public function run(): void
    {
        foreach (['super_admin', 'institute_admin', 'instructor', 'student', 'parent'] as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }
    }
}
