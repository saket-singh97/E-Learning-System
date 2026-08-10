<?php

namespace Database\Factories;

use App\Domains\Tenancy\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Tenant>
 */
class TenantFactory extends Factory
{
    protected $model = Tenant::class;

    public function definition(): array
    {
        return [
            'name' => fake()->company(),
            'subdomain' => fake()->unique()->domainWord(),
            'status' => Tenant::STATUS_ACTIVE,
            'primary_color' => fake()->hexColor(),
            'accent_color' => fake()->hexColor(),
        ];
    }

    public function suspended(): static
    {
        return $this->state(fn () => ['status' => Tenant::STATUS_SUSPENDED]);
    }
}
