<?php

namespace App\Http\Requests\Permissions;

use App\Http\Requests\Concerns\NormalizesListingFilters;
use App\Models\Permission;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Pesquisa, filtro por módulo, ordenação, paginação e detalhe do catálogo de permissões (PER-05).
 * A autorização é aplicada no controller.
 */
class PermissionIndexRequest extends FormRequest
{
    use NormalizesListingFilters;

    /** Campos de ordenação aceitos. Nenhum nome de coluna vem diretamente do usuário. */
    public const SORTS = ['name', 'slug'];

    public const FILTERS = ['q', 'module'];

    /** Com parâmetros inválidos, volta ao catálogo completo exibindo os erros. */
    protected $redirectRoute = 'admin.permissions.index';

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:100'],
            'module' => ['nullable', Rule::in([...array_keys(Permission::GROUPS), Permission::OTHER_GROUP])],
            'sort' => ['nullable', Rule::in(self::SORTS)],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
            'page' => ['nullable', 'integer', 'min:1'],
            // Permissão aberta no painel de detalhes.
            'selected' => ['nullable', 'integer', 'min:1'],
        ];
    }

    public function attributes(): array
    {
        return [
            'q' => 'pesquisa',
            'module' => 'módulo',
            'sort' => 'ordenação',
            'direction' => 'direção da ordenação',
            'selected' => 'permissão',
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

    public function selected(): ?int
    {
        $selected = $this->validated('selected');

        return $selected === null ? null : (int) $selected;
    }
}
