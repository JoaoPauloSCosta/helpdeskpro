<?php

namespace App\Http\Requests\Tickets;

use App\Enums\TicketStatus;
use App\Models\Ticket;
use App\Policies\TicketPolicy;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Reabertura (CHA-10, RF-13). A justificativa é obrigatória; o novo status, o autor e o instante da
 * reabertura vêm sempre do backend. O status esperado é o que o usuário viu ao abrir a reabertura,
 * para detectar mudanças concorrentes.
 */
class ReopenTicketRequest extends FormRequest
{
    /**
     * Entrada: solicitante original, pela relação gravada no chamado, e acesso a ele. Se o status
     * admite a reabertura é decidido pela action sobre o estado bloqueado, para que uma mudança
     * concorrente vire uma mensagem de conflito e não um acesso negado genérico.
     */
    public function authorize(): bool
    {
        return TicketPolicy::isOriginalRequester($this->user(), $this->ticket())
            && $this->user()->can('view', $this->ticket());
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            // Espaços nas pontas são removidos pelo middleware; só espaços vira nulo e é recusado.
            'justification' => ['required', 'string', 'max:'.Ticket::REOPEN_JUSTIFICATION_MAX_LENGTH],
            'expected_status' => ['required', 'string', Rule::enum(TicketStatus::class)],
        ];
    }

    public function messages(): array
    {
        return [
            'justification.required' => 'Explique o motivo da reabertura.',
            'justification.string' => 'Explique o motivo da reabertura.',
            'justification.max' => 'A justificativa pode ter no máximo :max caracteres.',
            'expected_status.*' => 'Não foi possível validar o envio. Recarregue a página e tente novamente.',
        ];
    }

    private function ticket(): Ticket
    {
        return $this->route('ticket');
    }
}
