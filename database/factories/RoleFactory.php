<?php

namespace Database\Factories;

use App\Models\Role;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Role>
 */
class RoleFactory extends Factory
{
    protected $model = Role::class;

    public function definition(): array
    {
        $code = Str::slug(fake()->unique()->words(2, true), '_');

        return [
            'code' => $code,
            'name' => Str::headline($code),
            'description' => fake()->sentence(),
        ];
    }
}
