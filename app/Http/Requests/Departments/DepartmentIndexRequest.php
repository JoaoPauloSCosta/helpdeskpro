<?php

namespace App\Http\Requests\Departments;

use App\Http\Requests\Concerns\NormalizesListingFilters;
use App\Http\Requests\Users\UserIndexRequest;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Pesquisa, filtros, ordenação e paginação da listagem de departamentos (ADM-04).
 * A autorização é aplicada no controller.
 */
class DepartmentIndexRequest extends FormRequest
{
    use NormalizesListingFilters;

    /** Campos de ordenação aceitos. Nenhum nome de coluna vem diretamente do usuário. */
    public const SORTS = ['name', 'created_at', 'updated_at'];

    public const FILTERS = ['q', 'status'];

    /** Mesmas situações da administração de usuários, correspondentes a `is_active`. */
    public const STATUSES = UserIndexRequest::STATUSES;

    /** Sem filtros válidos, volta à listagem padrão exibindo os erros. */
    protected $redirectRoute = 'admin.departments.index';

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
