<?php

namespace App\Http\Requests\Tickets;

use App\Enums\TicketStatus;
use App\Models\Ticket;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Encerramento (CHA-11, RF-14). Não há dados a informar: o status final, o autor e o instante do
 * encerramento vêm sempre do backend. O status esperado é o que o usuário viu ao abrir o
 * encerramento, para detectar mudanças concorrentes.
 */
class CloseTicketRequest extends FormRequest
{
    /**
     * Entrada: acesso ao chamado e `tickets.close`. Se o status admite o encerramento é decidido pela
     * action sobre o estado bloqueado, para que uma mudança concorrente vire uma mensagem de
     * conflito e não um acesso negado genérico.
     */
    public function authorize(): bool
    {
        return $this->user()->hasPermission('tickets.close')
            && $this->user()->can('view', $this->ticket());
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'expected_status' => ['required', 'string', Rule::enum(TicketStatus::class)],
        ];
    }

    public function messages(): array
    {
        return [
            'expected_status.*' => 'Não foi possível validar o envio. Recarregue a página e tente novamente.',
        ];
    }

    private function ticket(): Ticket
    {
        return $this->route('ticket');
    }
}
