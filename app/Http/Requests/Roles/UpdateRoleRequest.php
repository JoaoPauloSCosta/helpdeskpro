<?php

namespace App\Http\Requests\Roles;

use App\Models\Role;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Validator;

/**
 * Edição de papel (PER-03, RF-03). Reaproveita as regras da criação (PER-02): mesmos limites e
 * unicidade do nome, desconsiderando o próprio papel. Exige a versão do formulário para detectar
 * edições simultâneas. Slug, `is_system`, permissões e usuários não são editáveis aqui.
 *
 * Papéis do sistema (os quatro perfis de referência do PRD, seção 4) mantêm o nome; só a descrição
 * pode ser alterada.
 */
class UpdateRoleRequest extends StoreRoleRequest
{
    private ?Role $target = null;

    public function authorize(): bool
    {
        // A permissão geral vem antes da busca, para não revelar quais identificadores existem.
        if (! $this->user()->can('viewAny', Role::class)) {
            return false;
        }

        return $this->user()->can('update', $this->target());
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'version' => ['required', 'string'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                $target = $this->target();

                if ($target->is_system && ! $validator->errors()->has('name') && $this->input('name') !== $target->name) {
                    $validator->errors()->add('name', 'O nome dos papéis do sistema não pode ser alterado.');
                }
            },
            ...parent::after(),
        ];
    }

    protected function ignoredRoleId(): ?int
    {
        return $this->target()->id;
    }

    /** Papel em edição, buscado uma vez depois da autorização geral. */
    public function target(): Role
    {
        return $this->target ??= Role::findOrFail($this->route('role'));
    }
}
