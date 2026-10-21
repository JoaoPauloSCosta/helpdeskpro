<?php

namespace App\Http\Requests\Tickets;

use App\Models\Ticket;
use App\Models\User;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Atribuição e reatribuição manual (CHA-05, RF-08). O técnico é conferido no servidor; o
 * responsável esperado é o que o usuário viu ao abrir o modal, para detectar conflitos.
 */
class AssignTicketRequest extends FormRequest
{
    /**
     * Entrada: acesso ao chamado e alguma das duas permissões. Qual delas vale (atribuir ou
     * reatribuir) é decidido pela action sobre o estado bloqueado, para que uma mudança
     * concorrente vire uma mensagem de conflito e não um acesso negado genérico.
     */
    public function authorize(): bool
    {
        $user = $this->user();

        return ($user->hasPermission('tickets.assign') || $user->hasPermission('tickets.reassign'))
            && $user->can('view', $this->ticket());
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'technician_id' => [
                'required',
                'integer',
                function (string $attribute, mixed $value, Closure $fail) {
                    if (! User::eligibleAssignees()->whereKey($value)->exists()) {
                        $fail('Selecione um técnico ativo e elegível.');
                    }
                },
            ],
            'expected_assignee_id' => ['present', 'nullable', 'integer'],
        ];
    }

    public function messages(): array
    {
        return [
            'technician_id.required' => 'Selecione um técnico.',
            'technician_id.integer' => 'Selecione um técnico válido.',
            'expected_assignee_id.*' => 'Não foi possível validar o envio. Recarregue a página e tente novamente.',
        ];
    }

    private function ticket(): Ticket
    {
        return $this->route('ticket');
    }
}
