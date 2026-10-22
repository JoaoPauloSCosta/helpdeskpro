<?php

namespace App\Http\Requests\Users;

use App\Http\Requests\Concerns\NormalizesListingFilters;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Pesquisa, filtros, ordenação e paginação da listagem de usuários (ADM-01).
 * A autorização é aplicada no controller.
 */
class UserIndexRequest extends FormRequest
{
    use NormalizesListingFilters;

    /** Campos de ordenação aceitos. Nenhum nome de coluna vem diretamente do usuário. */
    public const SORTS = ['name', 'email', 'created_at'];

    public const FILTERS = ['q', 'role', 'department', 'status'];

    /** Situações aceitas no filtro, correspondentes a `users.is_active`. */
    public const STATUSES = ['active' => 'Ativo', 'inactive' => 'Inativo'];

    /** Sem filtros válidos, volta à listagem padrão exibindo os erros. */
    protected $redirectRoute = 'admin.users.index';

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:100'],
            // `none` encontra usuários sem papel ou sem departamento associado.
            'role' => ['nullable', 'string', 'regex:/^(none|[1-9][0-9]*)$/'],
            'department' => ['nullable', 'string', 'regex:/^(none|[1-9][0-9]*)$/'],
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
            'role' => 'papel',
            'department' => 'departamento',
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
