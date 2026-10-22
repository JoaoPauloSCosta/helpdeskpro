<?php

namespace App\Http\Requests\Tickets;

use App\Enums\TicketStatus;
use App\Models\Ticket;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Resolução (CHA-09, RF-12). O resumo da solução é obrigatório; o status final, o autor e o
 * instante da resolução vêm sempre do backend. O status esperado é o que o usuário viu ao abrir a
 * resolução, para detectar mudanças concorrentes.
 */
class ResolveTicketRequest extends FormRequest
{
    /**
     * Entrada: acesso ao chamado e `tickets.resolve`. Se o status admite a resolução é decidido pela
     * action sobre o estado bloqueado, para que uma mudança concorrente vire uma mensagem de
     * conflito e não um acesso negado genérico.
     */
    public function authorize(): bool
    {
        return $this->user()->hasPermission('tickets.resolve')
            && $this->user()->can('view', $this->ticket());
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            // Espaços nas pontas são removidos pelo middleware; só espaços vira nulo e é recusado.
            'resolution_summary' => ['required', 'string', 'max:'.Ticket::RESOLUTION_SUMMARY_MAX_LENGTH],
            'expected_status' => ['required', 'string', Rule::enum(TicketStatus::class)],
        ];
    }

    public function messages(): array
    {
        return [
            'resolution_summary.required' => 'Descreva a solução aplicada.',
            'resolution_summary.string' => 'Descreva a solução aplicada.',
            'resolution_summary.max' => 'O resumo da solução pode ter no máximo :max caracteres.',
            'expected_status.*' => 'Não foi possível validar o envio. Recarregue a página e tente novamente.',
        ];
    }

    private function ticket(): Ticket
    {
        return $this->route('ticket');
    }
}
