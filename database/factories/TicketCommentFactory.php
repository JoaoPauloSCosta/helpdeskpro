<?php

namespace Database\Factories;

use App\Enums\CommentVisibility;
use App\Models\Ticket;
use App\Models\TicketComment;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TicketComment>
 */
class TicketCommentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'ticket_id' => Ticket::factory(),
            'user_id' => User::factory(),
            'body' => fake()->paragraph(),
            'visibility' => CommentVisibility::Public,
        ];
    }

    public function internal(): static
    {
        return $this->state(fn (array $attributes) => [
            'visibility' => CommentVisibility::Internal,
        ]);
    }
}
