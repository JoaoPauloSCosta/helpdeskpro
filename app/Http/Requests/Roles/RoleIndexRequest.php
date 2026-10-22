<?php

namespace App\Http\Requests\Roles;

use App\Http\Requests\Concerns\NormalizesListingFilters;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Pesquisa, ordenação e paginação da listagem de papéis (PER-01).
 * A autorização é aplicada no controller.
 */
class RoleIndexRequest extends FormRequest
{
    use NormalizesListingFilters;

    /** Campos de ordenação aceitos. Nenhum nome de coluna vem diretamente do usuário. */
    public const SORTS = ['name', 'users_count'];

    public const FILTERS = ['q'];

    /** Com parâmetros inválidos, volta à listagem padrão exibindo os erros. */
    protected $redirectRoute = 'admin.roles.index';

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:100'],
            'sort' => ['nullable', Rule::in(self::SORTS)],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    public function attributes(): array
    {
        return [
            'q' => 'pesquisa',
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
