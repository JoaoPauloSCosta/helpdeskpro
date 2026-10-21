<?php

namespace App\Http\Controllers;

use App\Actions\Tickets\TicketEditConflict;
use App\Actions\Tickets\UpdateTicketAttributes;
use App\Enums\TicketPriority;
use App\Http\Requests\Tickets\UpdateTicketRequest;
use App\Models\Department;
use App\Models\Ticket;
use App\Models\TicketCategory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class TicketEditController extends Controller
{
    /**
     * CHA-04 — Edição operacional do chamado (RF-18).
     */
    public function edit(Request $request, Ticket $ticket): Response
    {
        Gate::authorize('update', $ticket);

        $user = $request->user();

        $can = [
            'priority' => $user->can('changePriority', $ticket),
            'category' => $user->can('changeCategory', $ticket),
            'department' => $user->can('transferDepartment', $ticket),
        ];

        return Inertia::render('Tickets/Edit', [
            'ticket' => [
                'number' => $ticket->ticket_number,
                'title' => $ticket->title,
                'priority' => $ticket->priority->label(),
                'status' => $ticket->status->label(),
            ],
            'values' => [
                'priority' => $ticket->priority->value,
                'category_id' => (string) $ticket->category_id,
                'department_id' => (string) $ticket->department_id,
            ],
            // Só envia as opções dos atributos que o usuário pode alterar.
            'options' => [
                'priorities' => $can['priority'] ? array_map(
                    fn (TicketPriority $priority) => ['value' => $priority->value, 'label' => $priority->label()],
                    TicketPriority::cases(),
                ) : [],
                'categories' => $can['category'] ? $this->catalog(TicketCategory::class, $ticket->category_id, 'inativa') : [],
                'departments' => $can['department'] ? $this->catalog(Department::class, $ticket->department_id, 'inativo') : [],
            ],
            'current' => [
                'category' => $ticket->category()->value('name'),
                'department' => $ticket->department()->value('name'),
            ],
            'can' => $can,
            'version' => $ticket->editVersion(),
        ]);
    }

    public function update(UpdateTicketRequest $request, Ticket $ticket, UpdateTicketAttributes $updateTicket): RedirectResponse
    {
        try {
            $events = $updateTicket($request->user(), $ticket, $request->changes(), $request->validated('version'));
        } catch (TicketEditConflict) {
            throw ValidationException::withMessages([
                'conflict' => 'Este chamado foi alterado por outra pessoa enquanto você editava. '
                    .'Recarregue os dados atuais antes de tentar novamente.',
            ]);
        }

        if ($events === []) {
            return to_route('tickets.edit', $ticket)->with('info', 'Nenhuma alteração para salvar.');
        }

        return to_route('tickets.show', $ticket)->with('success', 'Alterações salvas.');
    }

    /**
     * Itens ativos e, se estiver inativo, o valor atual do chamado, que continua selecionável
     * para não forçar uma troca.
     *
     * @param  class-string<TicketCategory|Department>  $model
     */
    private function catalog(string $model, int $currentId, string $inactiveLabel): array
    {
        return $model::query()
            ->where(fn ($query) => $query->where('is_active', true)->orWhere('id', $currentId))
            ->orderBy('name')
            ->get(['id', 'name', 'is_active'])
            ->map(fn ($item) => [
                'value' => (string) $item->id,
                'label' => $item->is_active ? $item->name : "{$item->name} ({$inactiveLabel})",
            ])
            ->all();
    }
}
