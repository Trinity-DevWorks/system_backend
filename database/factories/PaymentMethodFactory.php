<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\PaymentMethod\Enums\PaymentMethodType;
use App\Modules\PaymentMethod\Models\PaymentMethod;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PaymentMethod>
 */
class PaymentMethodFactory extends Factory
{
    protected $model = PaymentMethod::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => fake()->unique()->bothify('PM_##??'),
            'name' => fake()->words(2, true),
            'type' => PaymentMethodType::Cash->value,
            'currency_id' => null,
            'requires_reference' => false,
            'supports_change' => true,
            'is_default' => false,
            'is_active' => true,
            'notes' => null,
        ];
    }
}
