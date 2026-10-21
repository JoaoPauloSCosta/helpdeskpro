<?php

namespace App\Actions\Tickets;

use App\Enums\TicketStatus;
use App\Models\Ticket;
use App\Models\User;
use App\Support\TicketNumberGenerator;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Abertura de chamado (RF-05). Autor, departamento histórico, status inicial e identificador
 * são definidos aqui, nunca a partir dos dados enviados pelo navegador.
 *
 * Pendências de outras etapas: o evento de auditoria (Etapa 6, `ticket_histories`) e o cálculo
 * dos prazos de SLA (Etapa 7, `sla_policies`) ainda não existem; os prazos ficam nulos.
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
                // A transação já delimita a operação para quando auditoria e SLA forem gravados junto.
                DB::transaction(fn () => $ticket->save());

                return $ticket;
            } catch (UniqueConstraintViolationException) {
                // O único índice exclusivo além da chave primária é `ticket_number`: gera outro.
            }
        }

        throw new TicketNumberUnavailable;
    }
}
