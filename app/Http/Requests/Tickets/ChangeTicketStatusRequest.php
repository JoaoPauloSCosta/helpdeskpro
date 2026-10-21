<?php

namespace App\Http\Requests\Tickets;

use App\Enums\TicketStatus;
use App\Models\Ticket;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Alteração de status (CHA-07, RF-10). O status esperado é o que o usuário viu ao abrir a
 * alteração, para detectar mudanças concorrentes. Autor e horário vêm sempre do backend.
 */
class ChangeTicketStatusRequest extends FormRequest
{
    /**
     * Entrada: acesso ao chamado e `tickets.change_status`. Se a transição é executável é decidido
     * pela action sobre o estado bloqueado, para que uma mudança concorrente vire uma mensagem de
     * conflito e não um acesso negado genérico.
     */
    public function authorize(): bool
    {
        return $this->user()->hasPermission('tickets.change_status')
            && $this->user()->can('view', $this->ticket());
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'status' => ['required', 'string', Rule::enum(TicketStatus::class), 'different:expected_status'],
            'expected_status' => ['required', 'string', Rule::enum(TicketStatus::class)],
        ];
    }

    public function messages(): array
    {
        return [
            'status.required' => 'Selecione o novo status.',
            'status.string' => 'Selecione um status válido.',
            'status.enum' => 'Selecione um status válido.',
            'status.different' => 'Selecione um status diferente do atual.',
            'expected_status.*' => 'Não foi possível validar o envio. Recarregue a página e tente novamente.',
        ];
    }

    private function ticket(): Ticket
    {
        return $this->route('ticket');
    }
}
