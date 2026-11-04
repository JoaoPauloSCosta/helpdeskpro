<?php

namespace Database\Factories;

use App\Enums\ChatSessionStatus;
use App\Models\ChatSession;
use App\Models\Department;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Apenas para testes. Na aplicação, as sessões são criadas e movidas pelas actions do chat.
 * Por padrão, aguardando na fila.
 *
 * @extends Factory<ChatSession>
 */
class ChatSessionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'requester_id' => User::factory(),
            // Sessão aberta ocupa a vaga única do solicitante (RF-26).
            'active_requester_id' => fn (array $attributes) => $attributes['requester_id'],
            'department_id' => Department::factory(),
            'subject' => fake()->sentence(4),
            'status' => ChatSessionStatus::Waiting,
        ];
    }

    public function active(User $technician): static
    {
        return $this->state(fn () => [
            'status' => ChatSessionStatus::Active,
            'technician_id' => $technician->id,
            'accepted_at' => now(),
        ]);
    }

    public function ended(User $technician): static
    {
        return $this->state(fn () => [
            'status' => ChatSessionStatus::Ended,
            'active_requester_id' => null,
            'technician_id' => $technician->id,
            'accepted_at' => now()->subMinutes(10),
            'ended_at' => now(),
        ]);
    }

    public function cancelled(): static
    {
        return $this->state(fn () => [
            'status' => ChatSessionStatus::Cancelled,
            'active_requester_id' => null,
            'cancelled_at' => now(),
        ]);
    }
}
