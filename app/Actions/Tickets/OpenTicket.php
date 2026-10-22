<?php

namespace App\Actions\Tickets;

use App\Enums\TicketEvent;
use App\Enums\TicketStatus;
use App\Models\Department;
use App\Models\Ticket;
use App\Models\TicketCategory;
use App\Models\User;
use App\Support\TicketNumberGenerator;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Abertura de chamado (RF-05). Autor, departamento histórico, status inicial e identificador
 * são definidos aqui, nunca a partir dos dados enviados pelo navegador. O chamado e o evento de
 * auditoria da criação (RF-19) são gravados juntos ou não são gravados.
 *
 * Pendência de outra etapa: o cálculo dos prazos de SLA (Etapa 7, `sla_policies`) ainda não
 * existe; os prazos ficam nulos.
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
                // A transação também delimitará a gravação dos prazos de SLA quando o motor existir.
                DB::transaction(function () use ($ticket, $requester) {
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
            ],
            'created_at' => $ticket->created_at,
        ]);
    }
}
