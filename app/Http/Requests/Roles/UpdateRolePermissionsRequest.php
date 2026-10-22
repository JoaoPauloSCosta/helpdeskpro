<?php

namespace App\Http\Requests\Roles;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Gravação da matriz de permissões de um papel (PER-04). Recebe o conjunto completo de identificadores
 * desejados (a matriz não é paginada, então nada fica de fora) e a versão carregada com a tela.
 * Cada identificador precisa existir no catálogo; nada além disso é aceito.
 */
class UpdateRolePermissionsRequest extends FormRequest
{
    private ?Role $target = null;

    public function authorize(): bool
    {
        // A permissão geral vem antes da busca, para não revelar quais identificadores existem.
        if (! $this->user()->can('viewAny', Role::class)) {
            return false;
        }

        return $this->user()->can('managePermissions', $this->target());
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            // `present`: uma lista vazia é válida e retira todas as permissões do papel.
            'permissions' => ['present', 'array'],
            'permissions.*' => ['required', 'string', 'distinct', Rule::in(Permission::pluck('slug')->all())],
            'version' => ['required', 'string'],
        ];
    }

    public function attributes(): array
    {
        return [
            'permissions' => 'permissões',
            'permissions.*' => 'permissão',
        ];
    }

    public function messages(): array
    {
        return [
            'permissions.*.in' => 'Uma das permissões enviadas não existe no catálogo.',
            'permissions.*.distinct' => 'Uma permissão foi enviada mais de uma vez.',
        ];
    }

    /**
     * @return list<string>
     */
    public function slugs(): array
    {
        return array_values($this->validated('permissions'));
    }

    /** Papel em edição, buscado uma vez depois da autorização geral. */
    public function target(): Role
    {
        return $this->target ??= Role::findOrFail($this->route('role'));
    }
}
