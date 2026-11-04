<?php

namespace App\Actions\Chat;

use App\Actions\Tickets\OpenTicket;
use App\Actions\Tickets\TicketNumberUnavailable;
use App\Enums\ChatEvent;
use App\Enums\ChatOutcome;
use App\Enums\ChatSessionStatus;
use App\Enums\TicketEvent;
use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Models\ChatSession;
use App\Models\Ticket;
use App\Models\User;
use App\Support\ChatBroadcast;
use App\Support\ChatTransaction;
use App\Support\SlaClock;
use App\Support\TicketNumberGenerator;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Gate;

/**
 * Encerramento do atendimento e geração do chamado (RF-34, RN-CHAT-08, D-C5, D-C12).
 *
 * Numa só transação, com a sessão bloqueada: cria o chamado com os horários reais do chat, grava o histórico
 * (`created`, `self_assigned` e, se resolvido, `resolved`), apura o SLA, vincula o chamado e passa a sessão
 * para `ended`. Ou grava tudo ou nada: se falhar, a sessão continua em atendimento e o técnico tenta de novo.
 *
 * Idempotente: encerrar de novo uma sessão já encerrada devolve o chamado existente (só ao técnico
 * responsável), sem criar outro; `chat_sessions.ticket_id` único é a segunda barreira.
 */
class EndChatSession
{
    public function __construct(private TicketNumberGenerator $numbers, private OpenTicket $openTicket) {}

    /**
     * @param  array{outcome: string, title: string, category_id: int, summary?: ?string, note?: ?string}  $data
     * @return array{0: ChatSession, 1: bool} A sessão e se ela acabou de ser encerrada.
     *
     * @throws AuthorizationException
     * @throws ChatSessionConflict
     * @throws TicketNumberUnavailable
     */
    public function __invoke(User $technician, ChatSession $session, array $data): array
    {
        // Colisão do identificador (RF-06, regra 8): gera outro, como na abertura comum de chamado.
        for ($attempt = 1; $attempt <= OpenTicket::MAX_ATTEMPTS; $attempt++) {
            try {
                [$ended, $changed] = ChatTransaction::run(fn () => $this->end($technician, $session, $data));
            } catch (UniqueConstraintViolationException) {
                continue;
            }

            if ($changed) {
                ChatBroadcast::session($ended, ChatEvent::Ended);
            }

            return [$ended, $changed];
        }

        throw new TicketNumberUnavailable;
    }

    /** @return array{0: ChatSession, 1: bool} */
    private function end(User $technician, ChatSession $session, array $data): array
    {
        $current = ChatSession::whereKey($session->getKey())->lockForUpdate()->firstOrFail();

        if ($current->status === ChatSessionStatus::Ended) {
            // Repetição (duplo clique, resposta perdida): só o responsável vê o resultado, sem novo chamado.
            if ($current->technician_id !== $technician->id) {
                throw new AuthorizationException;
            }

            return [$current, false];
        }

        if ($current->status !== ChatSessionStatus::Active) {
            throw new ChatSessionConflict('Este atendimento não está em andamento e não pode ser encerrado.');
        }

        Gate::forUser($technician)->authorize('close', $current);

        $outcome = ChatOutcome::from($data['outcome']);
        $now = now();

        $ticket = $this->makeTicket($current, $technician, $outcome, $data, $now);
        $this->recordHistory($ticket, $current, $technician, $outcome, $data, $now);
        SlaClock::recordBreaches($ticket, $now);

        $current->forceFill([
            'status' => ChatSessionStatus::Ended,
            'active_requester_id' => null,
            'ended_at' => $now,
            'outcome' => $outcome,
            'ticket_id' => $ticket->id,
        ])->save();

        $current->events()->forceCreate([
            'user_id' => $technician->id,
            'event_type' => ChatEvent::Ended,
            'data' => ['outcome' => $outcome->value, 'ticket_id' => $ticket->id, 'ticket_number' => $ticket->ticket_number],
            'created_at' => $now,
        ]);

        return [$current->setRelation('ticket', $ticket), true];
    }

    /** Chamado com a entrada na fila como abertura e a política de SLA da prioridade Média a partir dela (D-C12). */
    private function makeTicket(ChatSession $session, User $technician, ChatOutcome $outcome, array $data, $now): Ticket
    {
        $resolved = $outcome === ChatOutcome::Resolved;

        $ticket = (new Ticket)->fill([
            'title' => $data['title'],
            'description' => $this->description($session, $technician, $outcome, $data, $now),
            'category_id' => $data['category_id'],
            'priority' => TicketPriority::Medium,
        ])->forceFill([
            'ticket_number' => $this->numbers->generate(),
            'requester_id' => $session->requester_id,
            'department_id' => $session->department_id,
            'assigned_to' => $technician->id,
            'status' => $resolved ? TicketStatus::Resolved : TicketStatus::InProgress,
            'first_response_at' => $session->first_response_at,
        ]);

        SlaClock::applyPolicy($ticket, $session->created_at);
        $ticket->updated_at = $now;

        if ($resolved) {
            // O tempo efetivo sai do relógio já parado em `resolved_at`.
            $ticket->forceFill(['resolution_summary' => $data['summary'], 'resolved_at' => $now]);
            $ticket->resolution_effective_seconds = SlaClock::consumedResolutionSeconds($ticket, $now);
        }

        $ticket->save();

        return $ticket;
    }

    /** Sequência real do atendimento no histórico do chamado, com os instantes do chat (seção 5 do contrato). */
    private function recordHistory(Ticket $ticket, ChatSession $session, User $technician, ChatOutcome $outcome, array $data, $now): void
    {
        ($this->openTicket)->recordCreation($ticket, $session->requester, [
            'origin' => 'chat',
            'chat_session_id' => $session->id,
            // O chamado nasce Aberto no histórico e só então é assumido (RF-10); o status final vem depois.
            'status' => TicketStatus::Open->value,
            'label' => TicketStatus::Open->label(),
        ]);

        $ticket->histories()->forceCreate([
            'user_id' => $technician->id,
            'event_type' => TicketEvent::SelfAssigned,
            'old_values' => [
                'assignee' => null,
                'status' => TicketStatus::Open->value,
                'label' => TicketStatus::Open->label(),
            ],
            'new_values' => [
                'assignee' => ['id' => $technician->id, 'name' => $technician->name],
                'status' => TicketStatus::InProgress->value,
                'label' => TicketStatus::InProgress->label(),
            ],
            'created_at' => $session->accepted_at ?? $now,
        ]);

        if ($outcome !== ChatOutcome::Resolved) {
            return;
        }

        $ticket->histories()->forceCreate([
            'user_id' => $technician->id,
            'event_type' => TicketEvent::Resolved,
            'old_values' => [
                'status' => TicketStatus::InProgress->value,
                'label' => TicketStatus::InProgress->label(),
            ],
            'new_values' => [
                'status' => TicketStatus::Resolved->value,
                'label' => TicketStatus::Resolved->label(),
                'resolution_summary' => $data['summary'],
                'resolved_at' => $now->toIso8601String(),
                'resolution_due_at' => $ticket->resolution_due_at?->toIso8601String(),
                ...($ticket->resolution_effective_seconds !== null ? ['resolution_effective_seconds' => $ticket->resolution_effective_seconds] : []),
            ],
            'created_at' => $now,
        ]);
    }

    /** Texto gerado pelo sistema: a conversa não é copiada, fica em `chat_messages` (contrato, seção 6). */
    private function description(ChatSession $session, User $technician, ChatOutcome $outcome, array $data, $now): string
    {
        $when = fn ($instant) => $instant?->timezone(config('app.timezone'))->format('d/m/Y H:i');

        $lines = [
            "Assunto informado: {$session->subject}",
            "Atendimento via chat nº {$session->id}",
            'Solicitado em: '.$when($session->created_at),
            'Atendimento iniciado em: '.($when($session->accepted_at) ?? '—'),
            'Encerrado em: '.$when($now),
            "Técnico: {$technician->name}",
            "Resultado: {$outcome->label()}",
        ];

        if (filled($data['note'] ?? null)) {
            $lines[] = '';
            $lines[] = "Observação para continuidade:\n{$data['note']}";
        }

        return implode("\n", $lines);
    }
}
