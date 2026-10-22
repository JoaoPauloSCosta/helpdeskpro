<?php

namespace App\Http\Requests\Categories;

use App\Http\Requests\Concerns\NormalizesListingFilters;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Pesquisa, filtros, ordenação e paginação da listagem de categorias (ADM-07).
 * A autorização é aplicada no controller.
 */
class CategoryIndexRequest extends FormRequest
{
    use NormalizesListingFilters;

    /** Campos de ordenação aceitos. Nenhum nome de coluna vem diretamente do usuário. */
    public const SORTS = ['name', 'updated_at'];

    public const FILTERS = ['q', 'status'];

    /** Situações correspondentes a `is_active`, no feminino de "categoria". */
    public const STATUSES = ['active' => 'Ativas', 'inactive' => 'Inativas'];

    /** Sem filtros válidos, volta à listagem padrão exibindo os erros. */
    protected $redirectRoute = 'admin.categories.index';

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(array_keys(self::STATUSES))],
            'sort' => ['nullable', Rule::in(self::SORTS)],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    public function attributes(): array
    {
        return [
            'q' => 'pesquisa',
            'status' => 'situação',
            'sort' => 'ordenação',
            'direction' => 'direção da ordenação',
        ];
    }

    public function sort(): string
    {
        return $this->validated('sort') ?? 'name';
    }

    public function direction(): string
    {
        return $this->validated('direction') ?? 'asc';
    }
}
