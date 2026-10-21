<?php

namespace App\Http\Requests\Tickets;

use App\Enums\TicketPriority;
use App\Models\Ticket;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Edição operacional (CHA-04, RF-18). Cada atributo exige a sua permissão: enviar um campo
 * que o usuário não pode alterar é recusado, mesmo que o valor seja o atual. Status, responsável,
 * solicitante, identificador e datas não fazem parte deste contrato e são ignorados.
 */
class UpdateTicketRequest extends FormRequest
{
    /** Campo do formulário => habilidade da TicketPolicy. */
    public const FIELDS = [
        'priority' => 'changePriority',
        'category_id' => 'changeCategory',
        'department_id' => 'transferDepartment',
    ];

    public function authorize(): bool
    {
        $ticket = $this->ticket();

        if (! $this->user()->can('update', $ticket)) {
            return false;
        }

        foreach (self::FIELDS as $field => $ability) {
            if ($this->has($field) && ! $this->user()->can($ability, $ticket)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $ticket = $this->ticket();

        // O valor atual continua aceito mesmo se a categoria ou o departamento tiverem sido
        // inativados depois; uma nova escolha precisa estar ativa (RF-04, RF-18).
        $activeOrCurrent = fn (string $table, int $current) => Rule::exists($table, 'id')
            ->where(fn (Builder $query) => $query->where('is_active', true)->orWhere('id', $current));

        return [
            'priority' => ['sometimes', 'required', Rule::enum(TicketPriority::class)],
            'category_id' => ['sometimes', 'required', 'integer', $activeOrCurrent('ticket_categories', $ticket->category_id)],
            'department_id' => ['sometimes', 'required', 'integer', $activeOrCurrent('departments', $ticket->department_id)],
            'version' => ['required', 'string'],
        ];
    }

    public function messages(): array
    {
        return [
            'priority.required' => 'Selecione a prioridade.',
            'priority.enum' => 'Selecione uma prioridade válida.',
            'category_id.required' => 'Selecione uma categoria.',
            'category_id.integer' => 'Selecione uma categoria válida.',
            'category_id.exists' => 'A categoria selecionada não está disponível. Escolha outra.',
            'department_id.required' => 'Selecione o departamento.',
            'department_id.integer' => 'Selecione um departamento válido.',
            'department_id.exists' => 'O departamento selecionado não está disponível. Escolha outro.',
            'version.*' => 'Não foi possível validar o envio. Recarregue a página e tente novamente.',
        ];
    }

    /**
     * Somente os atributos editáveis enviados, já convertidos para os tipos do model.
     *
     * @return array<string, mixed>
     */
    public function changes(): array
    {
        $data = $this->safe()->only(array_keys(self::FIELDS));

        return array_map(
            fn ($value) => is_numeric($value) ? (int) $value : $value,
            $data,
        );
    }

    private function ticket(): Ticket
    {
        return $this->route('ticket');
    }
}
