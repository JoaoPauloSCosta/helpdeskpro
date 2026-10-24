<?php

namespace App\Http\Controllers;

use App\Enums\CommentVisibility;
use App\Enums\TicketEvent;
use App\Models\Ticket;
use App\Models\TicketHistory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class TicketAuditController extends Controller
{
    private const PER_PAGE = 20;

    /** Eventos que se referem a uma mensagem e herdam a visibilidade dela (RF-11). */
    private const COMMENT_EVENTS = [TicketEvent::CommentAdded, TicketEvent::CommentEdited, TicketEvent::AttachmentAdded];

    /**
     * CHA-14 — Histórico de auditoria do chamado (RF-19), somente leitura.
     *
     * Exige `audit.view` e acesso ao chamado, conferidos a cada consulta. Os eventos vêm somente de
     * `ticket_histories` deste chamado, em ordem cronológica e paginados no servidor; nada é gravado,
     * recalculado ou derivado do estado atual. Lacunas (eventos que nunca foram registrados) ficam
     * como lacunas.
     *
     * Cada evento é montado a partir de uma lista fechada de campos por tipo: o JSON gravado nunca é
     * repassado como está. Valem as mesmas regras de leitura da tela de detalhes: eventos de notas
     * internas não existem para quem não as vê, e os dados da pendência (motivo, fornecedor...) ficam
     * com a equipe de suporte, como na CHA-03 e na CHA-13.
     */
    public function index(Request $request, Ticket $ticket): JsonResponse
    {
        Gate::authorize('viewAudit', $ticket);

        $user = $request->user();
        $seesInternal = $user->hasPermission('tickets.view_internal_notes');
        $support = $user->can('viewPendingDetails', $ticket);

        $page = $ticket->histories()
            ->select(['id', 'ticket_id', 'user_id', 'event_type', 'old_values', 'new_values', 'created_at'])
            ->when(! $seesInternal, fn (Builder $query) => $query->whereNot(fn (Builder $query) => $query
                ->whereIn('event_type', self::COMMENT_EVENTS)
                ->where('new_values->visibility', CommentVisibility::Internal->value)))
            ->with('author:id,name')
            ->orderBy('created_at')
            ->orderBy('id')
            ->paginate(self::PER_PAGE);

        return response()
            ->json([
                'ticket' => $ticket->ticket_number,
                'events' => collect($page->items())->map(fn (TicketHistory $event) => $this->event($event, $support)),
                'meta' => [
                    'current_page' => $page->currentPage(),
                    'last_page' => $page->lastPage(),
                    'per_page' => $page->perPage(),
                    'total' => $page->total(),
                ],
            ])
            // Motivos, soluções e justificativas não devem ficar em caches intermediários.
            ->header('Cache-Control', 'no-store, private');
    }

    /**
     * Um evento como a interface o apresenta: alterações de campos (`from` ausente quando não há valor
     * anterior aplicável, nulo quando não foi gravado) e detalhes complementares autorizados.
     */
    private function event(TicketHistory $event, bool $support): array
    {
        $old = $event->old_values ?? [];
        $new = $event->new_values ?? [];

        [$title, $tone, $description, $changes, $details] = match ($event->event_type) {
            TicketEvent::Created => [null, 'primary', null, [
                $this->value('Status inicial', $new['label'] ?? null),
                $this->value('Prioridade', $new['priority']['label'] ?? null),
                $this->value('Categoria', $new['category']['label'] ?? null),
                $this->value('Departamento', $new['department']['label'] ?? null),
            ], [
                $this->detail('Título', $new['title'] ?? null),
                ($new['sla']['no_policy'] ?? false)
                    ? $this->detail('SLA', 'Sem política ativa para a prioridade: o chamado abriu sem prazo definido.')
                    : null,
                $this->detail('Prazo de primeira resposta', $new['sla']['first_response_due_at'] ?? null, 'datetime'),
                $this->detail('Prazo de resolução', $new['sla']['resolution_due_at'] ?? null, 'datetime'),
            ]],
            TicketEvent::PriorityChanged => [null, 'primary', null, [$this->change('Prioridade', $old, $new)], []],
            TicketEvent::CategoryChanged => [null, 'primary', null, [$this->change('Categoria', $old, $new)], []],
            TicketEvent::DepartmentTransferred => [null, 'primary', null, [$this->change('Departamento', $old, $new)], []],
            // Sem valor anterior gravado, a atribuição partiu de um chamado sem responsável (RF-08).
            TicketEvent::Assigned => [null, 'primary', null, [
                ['field' => 'Responsável', 'from' => $old['label'] ?? 'Sem responsável', 'to' => $new['label'] ?? null],
            ], []],
            TicketEvent::Reassigned => [null, 'primary', null, [$this->change('Responsável', $old, $new)], []],
            TicketEvent::SelfAssigned => [null, 'primary', null, [
                ['field' => 'Responsável', 'from' => $old['assignee']['name'] ?? 'Sem responsável', 'to' => $new['assignee']['name'] ?? null],
                $this->change('Status', $old, $new),
            ], []],
            TicketEvent::StatusChanged => [null, 'primary', isset($new['sla_pause']) ? 'Pausa do SLA de resolução encerrada.' : null, [
                $this->change('Status', $old, $new),
            ], [
                $this->detail('Início da pausa', $new['sla_pause']['started_at'] ?? null, 'datetime'),
                $this->detail('Fim da pausa', $new['sla_pause']['ended_at'] ?? null, 'datetime'),
                $this->detail('Duração da pausa', $new['sla_pause']['duration_seconds'] ?? null, 'duration'),
                $this->detail('Prazo de resolução antes da retomada', $new['sla_pause']['resolution_due_before'] ?? null, 'datetime'),
                $this->detail('Prazo de resolução depois da retomada', $new['sla_pause']['resolution_due_after'] ?? null, 'datetime'),
            ]],
            TicketEvent::PendingRegistered => [null, 'warning', 'Pausa do SLA de resolução iniciada.', [
                $this->change('Status', $old, $new),
            ], [
                $this->detail('Início da pausa', $new['sla_pause']['started_at'] ?? null, 'datetime'),
                ...($support ? [
                    $this->detail('Motivo', $new['reason'] ?? null, 'longtext'),
                    $this->detail('Fornecedor ou terceiro', $new['supplier_name'] ?? null),
                    $this->detail('Departamento aguardado', $new['related_department'] ?? null),
                    $this->detail('Protocolo externo', $new['protocol_number'] ?? null),
                    $this->detail('Responsável externo ou contato', $new['responsible_name'] ?? null),
                    $this->detail('Previsão de retorno', $new['expected_return_at'] ?? null, 'date'),
                ] : []),
            ]],
            TicketEvent::Resolved => [null, 'success', null, [$this->change('Status', $old, $new)], [
                $this->detail('Solução registrada', $new['resolution_summary'] ?? null, 'longtext'),
                $this->detail('Resolvido em', $new['resolved_at'] ?? null, 'datetime'),
                $this->detail('Prazo de resolução vigente', $new['resolution_due_at'] ?? null, 'datetime'),
                $this->detail('Tempo efetivo de resolução', $new['resolution_effective_seconds'] ?? null, 'duration'),
                // Resolução de um ciclo anterior, substituída por esta (RF-12, regras 6 a 8).
                $this->detail('Solução anterior', $old['resolution_summary'] ?? null, 'longtext'),
                $this->detail('Resolução anterior em', $old['resolved_at'] ?? null, 'datetime'),
            ]],
            TicketEvent::Reopened => [null, 'primary', null, [$this->change('Status', $old, $new)], [
                $this->detail('Justificativa', $new['justification'] ?? null, 'longtext'),
                $this->detail('Solução reaberta', $old['resolution_summary'] ?? null, 'longtext'),
                $this->detail('Prazo de primeira resposta mantido', $new['first_response_due_at'] ?? null, 'datetime'),
                $this->detail('Prazo de resolução antes da reabertura', $new['resolution_due_before'] ?? null, 'datetime'),
                $this->detail('Prazo de resolução depois da reabertura', $new['resolution_due_at'] ?? null, 'datetime'),
            ]],
            TicketEvent::Closed => [null, 'neutral', null, [$this->change('Status', $old, $new)], [
                $this->detail('Encerrado em', $new['closed_at'] ?? null, 'datetime'),
            ]],
            TicketEvent::SlaTargetsChanged => [null, 'primary', null, [
                $this->change('Prioridade', $old['priority'] ?? [], $new['priority'] ?? []),
                $this->minutes('Meta de primeira resposta', $old['first_response_minutes'] ?? null, $new['first_response_minutes'] ?? null),
                $this->minutes('Meta de resolução', $old['resolution_minutes'] ?? null, $new['resolution_minutes'] ?? null),
            ], [
                $this->detail('Prazo de primeira resposta anterior', $old['first_response_due_at'] ?? null, 'datetime'),
                $this->detail('Novo prazo de primeira resposta', $new['first_response_due_at'] ?? null, 'datetime'),
                $this->detail('Prazo de resolução anterior', $old['resolution_due_at'] ?? null, 'datetime'),
                $this->detail('Novo prazo de resolução', $new['resolution_due_at'] ?? null, 'datetime'),
                $this->detail('Tempo de resolução consumido', $new['consumed_seconds'] ?? null, 'duration'),
            ]],
            TicketEvent::SlaBreached => [
                ($new['indicator'] ?? null) === 'first_response' ?'Primeira resposta fora do prazo' : 'Resolução fora do prazo',
                'danger', null, [], [
                    $this->detail('Prazo violado', $new['due_at'] ?? null, 'datetime'),
                    $this->detail('Violação registrada em', $new['breached_at'] ?? null, 'datetime'),
                ],
            ],
            TicketEvent::AttachmentAdded => [
                ($new['visibility'] ?? null) === CommentVisibility::Internal->value ? 'Anexos adicionados à nota interna' : 'Anexos adicionados',
                'primary', null, [],
                collect($new['files'] ?? [])->map(fn (array $file) => $this->detail(
                    'Arquivo',
                    ($file['name'] ?? 'arquivo').' ('.number_format(($file['size'] ?? 0) / 1024, 0, ',', '.').' KB)',
                ))->all(),
            ],
            TicketEvent::CommentAdded, TicketEvent::CommentEdited => $this->commentEvent($event->event_type, $new),
        };

        return [
            'id' => $event->id,
            'type' => $event->event_type->value,
            'title' => $title ?? $event->event_type->label(),
            'tone' => $tone,
            'created_at' => $event->created_at?->toIso8601String(),
            'author' => $event->author?->name,
            'description' => $description,
            'changes' => array_values(array_filter($changes)),
            'details' => array_values(array_filter($details)),
            // Motivo e dados da pendência gravados, mas fora do alcance de quem não é da equipe de suporte.
            'restricted' => $event->event_type === TicketEvent::PendingRegistered && ! $support && isset($new['reason']),
        ];
    }

    /**
     * Inclusão ou edição de mensagem. O texto não está na auditoria: fica na mensagem e no histórico
     * de versões, sob as regras de leitura do comentário.
     */
    private function commentEvent(TicketEvent $type, array $new): array
    {
        $visibility = CommentVisibility::tryFrom($new['visibility'] ?? '');
        $added = $type === TicketEvent::CommentAdded;

        $title = match ($visibility) {
            CommentVisibility::Internal => $added ? 'Nota interna adicionada' : 'Nota interna editada',
            CommentVisibility::Public => $added ? 'Comentário público adicionado' : 'Comentário público editado',
            null => null,
        };

        return [$title, 'primary', $added
            ? 'O conteúdo é exibido nas interações do chamado.'
            : 'O texto anterior está no histórico de versões da mensagem.', [], []];
    }

    /** Campo com valor anterior e novo, pelos rótulos gravados (`label`). */
    private function change(string $field, array $old, array $new): array
    {
        return ['field' => $field, 'from' => $old['label'] ?? null, 'to' => $new['label'] ?? null];
    }

    /**
     * Meta em minutos; "Sem prazo definido" quando o chamado não tinha política (RF-15.3).
     * Omitida se nenhum dos dois valores foi gravado.
     */
    private function minutes(string $field, ?int $old, ?int $new): ?array
    {
        if ($old === null && $new === null) {
            return null;
        }

        $format = fn (?int $minutes) => $minutes === null ? 'Sem prazo definido' : "{$minutes} min";

        return ['field' => $field, 'from' => $format($old), 'to' => $format($new)];
    }

    /** Valor registrado sem valor anterior aplicável (por exemplo, na abertura). */
    private function value(string $field, ?string $value): array
    {
        return ['field' => $field, 'to' => $value];
    }

    /** Informação complementar; omitida quando não foi gravada. */
    private function detail(string $label, string|int|null $value, string $format = 'text'): ?array
    {
        return $value === null || $value === '' ? null : ['label' => $label, 'value' => $value, 'format' => $format];
    }
}
