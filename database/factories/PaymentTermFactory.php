<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\PaymentTerm\Models\PaymentTerm;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PaymentTerm>
 */
class PaymentTermFactory extends Factory
{
    protected $model = PaymentTerm::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => fake()->unique()->bothify('PT_##??'),
            'name' => fake()->words(2, true),
            'due_days' => fake()->randomElement([0, 7, 15, 30, 45, 60]),
            'description' => fake()->optional(0.4)->sentence(),
            'is_default' => false,
            'is_active' => true,
        ];
    }
}
