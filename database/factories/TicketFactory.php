<?php

namespace Database\Factories;

use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Models\Department;
use App\Models\Ticket;
use App\Models\TicketCategory;
use App\Models\User;
use App\Support\TicketNumberGenerator;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Apenas para testes. Na aplicação, o identificador (RF-06) é gerado por
 * TicketNumberGenerator dentro de OpenTicket; aqui o mesmo formato vem do Faker
 * com unique() para evitar colisões entre registros de teste.
 *
 * @extends Factory<Ticket>
 */
class TicketFactory extends Factory
{
    public function definition(): array
    {
        $alphabet = TicketNumberGenerator::ALPHABET;
        $length = TicketNumberGenerator::LENGTH;

        return [
            'ticket_number' => TicketNumberGenerator::PREFIX.fake()->unique()->regexify("[{$alphabet}]{{$length}}"),
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
