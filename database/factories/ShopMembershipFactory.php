<?php

namespace Database\Factories;

use App\Models\Role;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class ShopMembershipFactory extends Factory
{
    public function definition(): array
    {
        return ['shop_id' => Shop::factory(), 'user_id' => User::factory(), 'role_id' => fn () => Role::where('name', 'owner')->sole()->id, 'status' => 'active'];
    }
}
