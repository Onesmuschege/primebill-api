<?php

namespace Database\Factories;

use App\Models\Client;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Client>
 */
class ClientFactory extends Factory
{
    protected $model = Client::class;

    public function definition(): array
    {
        return [
            'first_name' => fake()->firstName(),
            'last_name'  => fake()->lastName(),
            'email'      => fake()->unique()->safeEmail(),
            'phone'      => '2547' . fake()->numerify('#######'),
            'id_number'  => fake()->numerify('#######'),
            'address'    => fake()->streetAddress(),
            'town'       => 'Nairobi',
            'status'     => 'active',
        ];
    }
}
