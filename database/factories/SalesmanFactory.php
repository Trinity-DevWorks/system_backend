<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Salesman\Enums\CommissionType;
use App\Modules\Salesman\Models\Salesman;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Salesman>
 */
class SalesmanFactory extends Factory
{
    protected $model = Salesman::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $firstName = fake()->firstName();
        $lastName = fake()->lastName();

        return [
            'salesman_code' => fake()->unique()->bothify('SR####'),
            'first_name' => $firstName,
            'last_name' => $lastName,
            'full_name' => trim($firstName.' '.$lastName),
            'phone' => fake()->optional(0.7)->numerify('###########'),
            'email' => fake()->optional(0.6)->unique()->safeEmail(),
            'address' => fake()->optional(0.4)->streetAddress(),
            'commission_type' => CommissionType::None->value,
            'commission_value' => null,
            'target_amount' => fake()->optional(0.3)->randomFloat(4, 1000, 50000),
            'hire_date' => fake()->optional(0.5)->date(),
            'warehouse_id' => null,
            'user_id' => null,
            'is_active' => true,
            'notes' => null,
        ];
    }
}
