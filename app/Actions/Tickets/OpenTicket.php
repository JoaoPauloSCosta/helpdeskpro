<?php

namespace App\Actions\Tickets;

use App\Enums\TicketEvent;
use App\Enums\TicketStatus;
use App\Models\Department;
use App\Models\Ticket;
use App\Models\TicketCategory;
use App\Models\User;
use App\Support\SlaClock;
use App\Support\TicketNumberGenerator;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Abertura de chamado (RF-05). Autor, departamento histórico, status inicial e identificador
 * são definidos aqui, nunca a partir dos dados enviados pelo navegador. O chamado e o evento de
 * auditoria da criação (RF-19) são gravados juntos ou não são gravados.
 *
 * SLA (RF-05 regra 7, RF-15.3): na mesma transação, aplica a política ativa da prioridade. O chamado
 * guarda a política e a cópia das metas, e os prazos saem de `created_at` (primeira resposta e
 * resolução). Sem política ativa, o chamado abre sem prazo e o evento de criação registra a
 * ausência. Mudar a política depois não altera chamados já abertos.
 */
class OpenTicket
{
    /** Tentativas em caso de colisão do identificador (RF-06, regra 8). */
    public const MAX_ATTEMPTS = 5;

    public function __construct(private TicketNumberGenerator $numbers) {}

    /**
     * @param  array{title: string, description: string, category_id: int, priority: string}  $data
     *
     * @throws TicketNumberUnavailable
     */
    public function __invoke(User $requester, array $data): Ticket
    {
        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            $ticket = (new Ticket)->fill($data)->forceFill([
                'ticket_number' => $this->numbers->generate(),
                'requester_id' => $requester->id,
                'department_id' => $requester->department_id,
                'status' => TicketStatus::Open,
            ]);

            try {
                DB::transaction(function () use ($ticket, $requester) {
                    $this->applySla($ticket);
                    $ticket->save();
                    $this->recordCreation($ticket, $requester);
                });

                return $ticket;
            } catch (UniqueConstraintViolationException) {
                // O único índice exclusivo além da chave primária é `ticket_number`: gera outro.
            }
        }

        throw new TicketNumberUnavailable;
    }

    /** Política ativa, cópia das metas e prazos a partir do instante de criação (D2: sem política, tudo nulo). */
    private function applySla(Ticket $ticket): void
    {
        $createdAt = now();
        $policy = SlaClock::policyFor($ticket->priority);

        $ticket->forceFill([
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
            'sla_policy_id' => $policy?->id,
            'sla_first_response_minutes' => $policy?->first_response_minutes,
            'sla_resolution_minutes' => $policy?->resolution_minutes,
            'first_response_due_at' => $policy ? $createdAt->copy()->addMinutes($policy->first_response_minutes) : null,
            'resolution_due_at' => $policy ? $createdAt->copy()->addMinutes($policy->resolution_minutes) : null,
        ]);
    }

    /**
     * Valores iniciais do chamado. Guarda também os nomes, para o histórico continuar legível se o
     * cadastro for renomeado. A descrição fica no próprio chamado e não é copiada para a auditoria.
     */
    private function recordCreation(Ticket $ticket, User $requester): void
    {
        $ticket->histories()->forceCreate([
            'user_id' => $requester->id,
            'event_type' => TicketEvent::Created,
            'old_values' => null,
            'new_values' => [
                'status' => $ticket->status->value,
                'label' => $ticket->status->label(),
                'title' => $ticket->title,
                'priority' => ['priority' => $ticket->priority->value, 'label' => $ticket->priority->label()],
                'category' => ['id' => $ticket->category_id, 'label' => TicketCategory::whereKey($ticket->category_id)->value('name')],
                'department' => ['id' => $ticket->department_id, 'label' => Department::whereKey($ticket->department_id)->value('name')],
                'sla' => $ticket->sla_policy_id === null
                    ? ['policy_id' => null, 'no_policy' => true]
                    : [
                        'policy_id' => $ticket->sla_policy_id,
                        'first_response_minutes' => $ticket->sla_first_response_minutes,
                        'resolution_minutes' => $ticket->sla_resolution_minutes,
                        'first_response_due_at' => $ticket->first_response_due_at->toIso8601String(),
                        'resolution_due_at' => $ticket->resolution_due_at->toIso8601String(),
                    ],
            ],
            'created_at' => $ticket->created_at,
        ]);
    }
}
