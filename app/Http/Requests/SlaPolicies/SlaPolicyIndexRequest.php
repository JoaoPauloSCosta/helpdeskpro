<?php

namespace App\Http\Requests\SlaPolicies;

use App\Enums\TicketPriority;
use App\Http\Requests\Concerns\NormalizesListingFilters;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Filtros, ordenação e paginação da listagem de políticas de SLA (SLA-01).
 * A tabela não tem campos de texto (PRD 7.1), então não há pesquisa livre: os filtros são a
 * prioridade e a situação. A autorização é aplicada no controller.
 */
class SlaPolicyIndexRequest extends FormRequest
{
    use NormalizesListingFilters;

    /** Campos de ordenação aceitos. Nenhum nome de coluna vem diretamente do usuário. */
    public const SORTS = ['priority', 'first_response_minutes', 'resolution_minutes', 'updated_at'];

    public const FILTERS = ['priority', 'status'];

    /** Situações correspondentes a `is_active`, no feminino de "política". */
    public const STATUSES = ['active' => 'Ativas', 'inactive' => 'Inativas'];

    /** Sem filtros válidos, volta à listagem padrão exibindo os erros. */
    protected $redirectRoute = 'sla-policies.index';

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'priority' => ['nullable', Rule::enum(TicketPriority::class)],
            'status' => ['nullable', Rule::in(array_keys(self::STATUSES))],
            'sort' => ['nullable', Rule::in(self::SORTS)],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    public function attributes(): array
    {
        return [
            'priority' => 'prioridade',
            'status' => 'situação',
            'sort' => 'ordenação',
            'direction' => 'direção da ordenação',
        ];
    }

    public function sort(): string
    {
        return $this->validated('sort') ?? 'priority';
    }

    public function direction(): string
    {
        return $this->validated('direction') ?? 'asc';
    }
}
