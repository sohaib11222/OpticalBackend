<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class ShopFactory extends Factory
{
    public function definition(): array
    {
        return ['name' => fake()->company(), 'status' => 'pending'];
    }

    public function active(): static
    {
        return $this->state(fn () => ['status' => 'active']);
    }
}
