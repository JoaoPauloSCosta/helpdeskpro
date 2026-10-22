<?php

namespace App\Http\Requests\Tickets;

use App\Enums\TicketStatus;
use App\Models\Ticket;
use App\Models\TicketSlaPause;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Registro de pendência (CHA-08, RF-17). O motivo é sempre obrigatório; os campos específicos
 * são opcionais e só são aceitos pelo tipo a que se referem (TicketStatus::pendingFields()).
 * O status esperado é o que o usuário viu ao abrir o registro, para detectar mudanças
 * concorrentes. Autor, início da pausa e duração vêm sempre do backend.
 */
class RegisterTicketPendingRequest extends FormRequest
{
    /** Todos os campos específicos do RF-17 que o formulário pode enviar. */
    public const DETAIL_FIELDS = ['supplier_name', 'protocol_number', 'related_department_id', 'responsible_name', 'expected_return_at'];

    /**
     * Entrada: acesso ao chamado e `tickets.change_status`. Se o tipo é admitido no estado atual é
     * decidido pela action sobre o estado bloqueado, para que uma mudança concorrente vire uma
     * mensagem de conflito e não um acesso negado genérico.
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
        $accepted = $this->type()?->pendingFields() ?? [];
        // Campo de outro tipo: recusado quando preenchido.
        $applies = fn (string $field) => in_array($field, $accepted, true) ? 'nullable' : 'prohibited';
        $text = fn (string $field) => [$applies($field), 'string', 'max:'.TicketSlaPause::TEXT_MAX_LENGTH];

        return [
            'pause_status' => ['required', 'string', Rule::enum(TicketStatus::class)->only(TicketStatus::paused())],
            'expected_status' => ['required', 'string', Rule::enum(TicketStatus::class)],
            'reason' => ['required', 'string', 'max:'.TicketSlaPause::REASON_MAX_LENGTH],
            'supplier_name' => $text('supplier_name'),
            'protocol_number' => $text('protocol_number'),
            'responsible_name' => $text('responsible_name'),
            'related_department_id' => [
                $applies('related_department_id'), 'integer',
                Rule::exists('departments', 'id')->where('is_active', true),
            ],
            // Data, sem horário: evita conversão de fuso entre navegador e servidor.
            'expected_return_at' => [$applies('expected_return_at'), 'date_format:Y-m-d', 'after_or_equal:today'],
        ];
    }

    public function messages(): array
    {
        return [
            'pause_status.required' => 'Selecione o tipo de pendência.',
            'pause_status.*' => 'Selecione um tipo de pendência válido.',
            'reason.required' => 'Informe o motivo da pendência.',
            'reason.string' => 'Informe o motivo da pendência.',
            'reason.max' => 'O motivo pode ter no máximo :max caracteres.',
            '*.prohibited' => 'Este campo não se aplica ao tipo de pendência selecionado.',
            'supplier_name.max' => 'O fornecedor pode ter no máximo :max caracteres.',
            'protocol_number.max' => 'O protocolo pode ter no máximo :max caracteres.',
            'responsible_name.max' => 'O responsável pode ter no máximo :max caracteres.',
            'related_department_id.integer' => 'Selecione um departamento válido.',
            'related_department_id.exists' => 'O departamento selecionado não está disponível. Escolha outro.',
            'expected_return_at.date_format' => 'Informe uma data válida.',
            'expected_return_at.after_or_equal' => 'A previsão de retorno não pode estar no passado.',
            'expected_status.*' => 'Não foi possível validar o envio. Recarregue a página e tente novamente.',
        ];
    }

    public function type(): ?TicketStatus
    {
        $value = $this->input('pause_status');

        return is_string($value) ? TicketStatus::tryFrom($value) : null;
    }

    /**
     * Somente os campos específicos preenchidos e aceitos pelo tipo, já nos tipos do model.
     *
     * @return array<string, string|int>
     */
    public function details(): array
    {
        $data = array_filter(
            $this->safe()->only($this->type()?->pendingFields() ?? []),
            fn ($value) => $value !== null,
        );

        if (isset($data['related_department_id'])) {
            $data['related_department_id'] = (int) $data['related_department_id'];
        }

        return $data;
    }

    private function ticket(): Ticket
    {
        return $this->route('ticket');
    }
}
