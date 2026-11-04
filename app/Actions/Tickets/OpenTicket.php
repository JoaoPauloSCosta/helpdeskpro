<?php

namespace App\Actions\Tickets;

use App\Enums\TicketEvent;
use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Models\Department;
use App\Models\Ticket;
use App\Models\TicketCategory;
use App\Models\User;
use App\Support\SlaClock;
use App\Support\TicketNumberGenerator;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
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

    public function __construct(private TicketNumberGenerator $numbers, private StoreTicketAttachments $attachments) {}

    /**
     * @param  array{title: string, description: string, category_id: int}  $data
     * @param  list<UploadedFile>  $files  Anexos da abertura (RF-23), públicos; gravados na mesma transação.
     *
     * @throws TicketNumberUnavailable
     */
    public function __invoke(User $requester, array $data, array $files = []): Ticket
    {
        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            $ticket = (new Ticket)->fill($data)->forceFill([
                'ticket_number' => $this->numbers->generate(),
                'requester_id' => $requester->id,
                'department_id' => $requester->department_id,
                'status' => TicketStatus::Open,
                // O solicitante não escolhe a prioridade (RF-05); a equipe a ajusta depois (RF-16, RF-18).
                'priority' => TicketPriority::Medium,
            ]);

            // Arquivos não participam da transação: o que já foi gravado é removido se ela falhar.
            $written = [];

            try {
                DB::transaction(function () use ($ticket, $requester, $files, &$written) {
                    SlaClock::applyPolicy($ticket, now());
                    $ticket->save();
                    $this->recordCreation($ticket, $requester);
                    ($this->attachments)($requester, $ticket, null, $files, $written);
                });

                return $ticket;
            } catch (UniqueConstraintViolationException) {
                // O único índice exclusivo além da chave primária é `ticket_number`: gera outro.
                StoreTicketAttachments::purge($written);
            } catch (\Throwable $e) {
                StoreTicketAttachments::purge($written);

                throw $e;
            }
        }

        throw new TicketNumberUnavailable;
    }

    /**
     * Valores iniciais do chamado. Guarda também os nomes, para o histórico continuar legível se o
     * cadastro for renomeado. A descrição fica no próprio chamado e não é copiada para a auditoria.
     * `$extra` acrescenta dados da origem (chamado gerado pelo chat, RF-34).
     *
     * @param  array<string, mixed>  $extra
     */
    public function recordCreation(Ticket $ticket, User $requester, array $extra = []): void
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
                // Dados da origem; podem sobrepor o status (o chamado do chat nasce Aberto no histórico).
                ...$extra,
            ],
            'created_at' => $ticket->created_at,
        ]);
    }
}
