<?php

namespace Database\Seeders;

use App\Domains\Tenancy\Models\Tenant;
use Illuminate\Database\Seeder;

class TenantSeeder extends Seeder
{
    public function run(): void
    {
        Tenant::firstOrCreate(
            ['subdomain' => 'physics'],
            ['name' => 'Physics Institute', 'status' => Tenant::STATUS_ACTIVE]
        );

        Tenant::firstOrCreate(
            ['subdomain' => 'chemistry'],
            ['name' => 'Chemistry Institute', 'status' => Tenant::STATUS_ACTIVE]
        );
    }
}
