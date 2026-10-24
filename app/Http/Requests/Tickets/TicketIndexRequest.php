<?php

namespace App\Http\Requests\Tickets;

use App\Enums\SlaState;
use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Pesquisa, filtros (RF-07), ordenação e paginação da listagem de chamados.
 * Os parâmetros só restringem o resultado; o escopo de acesso é aplicado no controller.
 */
class TicketIndexRequest extends FormRequest
{
    /** Campos de ordenação aceitos. Nenhum nome de coluna vem diretamente do usuário. */
    public const SORTS = ['number', 'title', 'priority', 'status', 'assignee', 'created_at', 'updated_at'];

    public const FILTERS = [
        'q', 'number', 'title', 'requester', 'department', 'category',
        'priority', 'status', 'assignee', 'opened_from', 'opened_to', 'sla',
    ];

    /** Filtros e ordenações que a visão restrita do solicitante não tem (RF-24). */
    public const RESTRICTED_FILTERS = ['number', 'requester', 'priority', 'sla'];

    public const RESTRICTED_SORTS = ['number', 'priority'];

    /** Sem filtros válidos, volta à listagem padrão exibindo os erros. */
    protected $redirectRoute = 'tickets.index';

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $restricted = $this->user()?->hasRestrictedTicketView() ?? false;

        $rules = [
            'q' => ['nullable', 'string', 'max:100'],
            'number' => ['nullable', 'string', 'max:11'],
            'title' => ['nullable', 'string', 'max:255'],
            'requester' => ['nullable', 'integer', 'min:1'],
            'department' => ['nullable', 'integer', 'min:1'],
            'category' => ['nullable', 'integer', 'min:1'],
            'priority' => ['nullable', Rule::enum(TicketPriority::class)],
            'status' => ['nullable', Rule::enum(TicketStatus::class)],
            'assignee' => ['nullable', 'string', 'regex:/^(none|[1-9][0-9]*)$/'],
            'opened_from' => ['nullable', 'date_format:Y-m-d'],
            'opened_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:opened_from'],
            'sla' => ['nullable', Rule::enum(SlaState::class)],
            'sort' => ['nullable', Rule::in($restricted ? array_diff(self::SORTS, self::RESTRICTED_SORTS) : self::SORTS)],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
            'page' => ['nullable', 'integer', 'min:1'],
        ];

        // Parâmetros que a visão restrita não oferece são recusados no servidor, mesmo se forjados.
        if ($restricted) {
            foreach (self::RESTRICTED_FILTERS as $key) {
                $rules[$key] = ['prohibited'];
            }
        }

        return $rules;
    }

    public function attributes(): array
    {
        return [
            'q' => 'pesquisa',
            'number' => 'identificador',
            'title' => 'título',
            'requester' => 'solicitante',
            'department' => 'departamento',
            'category' => 'categoria',
            'priority' => 'prioridade',
            'status' => 'status',
            'assignee' => 'responsável',
            'opened_from' => 'abertura (de)',
            'opened_to' => 'abertura (até)',
            'sla' => 'situação do SLA',
            'sort' => 'ordenação',
            'direction' => 'direção da ordenação',
        ];
    }

    public function messages(): array
    {
        return [
            'number.prohibited' => 'Este filtro não está disponível.',
            'requester.prohibited' => 'Este filtro não está disponível.',
            'priority.prohibited' => 'Este filtro não está disponível.',
            'sla.prohibited' => 'Este filtro não está disponível.',
            'opened_to.after_or_equal' => 'A data final deve ser igual ou posterior à data inicial.',
        ];
    }

    /**
     * Filtros aplicados, sem valores vazios.
     *
     * @return array<string, string>
     */
    public function filters(): array
    {
        return collect($this->safe()->only(self::FILTERS))
            ->map(fn ($value) => is_string($value) ? trim($value) : $value)
            ->filter(fn ($value) => $value !== null && $value !== '')
            ->map(fn ($value) => (string) $value)
            ->all();
    }

    public function sort(): string
    {
        return $this->validated('sort') ?? 'created_at';
    }

    public function direction(): string
    {
        return $this->validated('direction') ?? 'desc';
    }
}
