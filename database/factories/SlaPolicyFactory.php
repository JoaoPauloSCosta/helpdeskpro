<?php

namespace Database\Factories;

use App\Enums\TicketPriority;
use App\Models\SlaPolicy;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Somente para testes: as metas reais ainda não foram definidas e não são semeadas.
 *
 * @extends Factory<SlaPolicy>
 */
class SlaPolicyFactory extends Factory
{
    public function definition(): array
    {
        return [
            'priority' => fake()->randomElement(TicketPriority::cases()),
            'first_response_minutes' => fake()->numberBetween(15, 480),
            'resolution_minutes' => fake()->numberBetween(480, 4320),
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => ['is_active' => false]);
    }
}
