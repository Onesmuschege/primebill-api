<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use App\Models\Product;

/** @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Product> */
class ProductFactory extends Factory
{
    protected $model = Product::class;

    public function definition(): array
    {
        return [
            'name' => $this->faker->word(),
            'sku' => $this->faker->bothify('SKU-####'),
            'price' => $this->faker->randomFloat(2, 0, 2000),
            'description' => $this->faker->sentence(),
        ];
    }
}
