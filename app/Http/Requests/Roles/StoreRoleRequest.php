<?php

namespace App\Http\Requests\Roles;

use App\Models\Role;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Validator;

/**
 * Criação de papel (PER-02, RF-03). Só aceita nome e descrição: slug, `is_system` e permissões
 * vêm do backend. Espaços nas pontas são removidos e textos vazios viram nulos pelos middlewares
 * globais do Laravel (TrimStrings e ConvertEmptyStringsToNull).
 *
 * O nome é único, sem diferenciar maiúsculas, porque é por ele que o papel é escolhido no cadastro
 * de usuários. O slug derivado do nome também precisa ser único; a restrição do banco é a garantia
 * final em envios simultâneos.
 */
class StoreRoleRequest extends FormRequest
{
    public const FIELDS = ['name', 'description'];

    public function authorize(): bool
    {
        return $this->user()->can('create', Role::class);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:'.Role::NAME_MAX_LENGTH],
            'description' => ['nullable', 'string', 'max:'.Role::DESCRIPTION_MAX_LENGTH],
        ];
    }

    /** A unicidade só é conferida quando o nome já passou nas demais regras. */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($validator->errors()->has('name')) {
                    return;
                }

                $slug = $this->slug();
                // Na edição, o próprio papel não conta como duplicidade.
                $others = Role::query()
                    ->when($this->ignoredRoleId(), fn ($query, int $id) => $query->whereKeyNot($id))
                    ->get(['name', 'slug']);
                $name = mb_strtolower((string) $this->input('name'));

                // Comparações feitas aqui para não depender da collation do banco (a do MariaDB
                // ignora caixa, a do SQLite dos testes não). Os papéis são poucos.
                if ($slug === '') {
                    $validator->errors()->add('name', 'O nome do papel precisa conter letras ou números.');
                } elseif ($others->contains(fn (Role $role) => mb_strtolower($role->name) === $name)) {
                    $validator->errors()->add('name', 'Já existe um papel com este nome.');
                } elseif ($others->contains(fn (Role $role) => $role->slug === $slug || Str::slug($role->name) === $slug)) {
                    $validator->errors()->add('name', 'Já existe um papel com um nome equivalente.');
                }
            },
        ];
    }

    /** Papel desconsiderado na verificação de unicidade; nenhum na criação. */
    protected function ignoredRoleId(): ?int
    {
        return null;
    }

    /** Identificador interno derivado do nome (ex.: "Supervisor de atendimento" → supervisor-de-atendimento). */
    public function slug(): string
    {
        return Str::slug((string) $this->input('name'));
    }

    public function attributes(): array
    {
        return [
            'name' => 'nome do papel',
            'description' => 'descrição',
        ];
    }
}
