<?php

namespace Database\Factories;

use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Models\Department;
use App\Models\Ticket;
use App\Models\TicketCategory;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Apenas para testes. A geração definitiva do identificador (RF-06) será feita
 * pelo backend na abertura do chamado.
 *
 * @extends Factory<Ticket>
 */
class TicketFactory extends Factory
{
    /** Alfabeto sem caracteres ambíguos (O/0, I/1). */
    private const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    public function definition(): array
    {
        return [
            'ticket_number' => 'HD-'.fake()->unique()->regexify('['.self::ALPHABET.']{8}'),
            'title' => fake()->sentence(4),
            'description' => fake()->paragraph(),
            'category_id' => TicketCategory::factory(),
            'requester_id' => User::factory(),
            'department_id' => Department::factory(),
            'priority' => TicketPriority::Medium,
            'status' => TicketStatus::Open,
        ];
    }
}
