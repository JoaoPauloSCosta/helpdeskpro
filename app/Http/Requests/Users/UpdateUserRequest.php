<?php

namespace App\Http\Requests\Users;

use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\Query\Builder;
use Illuminate\Validation\Rule;

/**
 * Edição administrativa de usuário (ADM-03, RF-02). Reaproveita as regras da criação (ADM-02):
 * normalização do e-mail, limites e a verificação de papéis concedíveis. Senha, verificação de
 * e-mail e tokens não fazem parte deste contrato e são ignorados.
 */
class UpdateUserRequest extends StoreUserRequest
{
    /** Campos que exigem `changeAccess` (papel e situação da conta). */
    public const ACCESS_FIELDS = ['role_id', 'status'];

    private ?User $target = null;

    public function authorize(): bool
    {
        // A permissão geral vem antes da busca, para não revelar quais identificadores existem.
        if (! $this->user()->can('viewAny', User::class)) {
            return false;
        }

        $target = $this->target();

        if (! $this->user()->can('update', $target)) {
            return false;
        }

        // Enviar papel ou situação sem poder alterá-los é recusado, mesmo que o valor seja o atual.
        return $this->user()->can('changeAccess', $target) || ! $this->hasAny(self::ACCESS_FIELDS);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $target = $this->target();
        $rules = parent::rules();

        // O próprio e-mail do usuário continua aceito; a restrição do banco é a garantia final.
        $rules['email'] = [
            'required',
            'string',
            'email',
            'max:'.self::EMAIL_MAX_LENGTH,
            Rule::unique('users', 'email')->ignore($target->id),
        ];

        // O departamento atual continua aceito mesmo se tiver sido inativado; uma nova escolha
        // precisa estar ativa (RF-02).
        $rules['department_id'] = [
            'nullable',
            'integer',
            Rule::exists('departments', 'id')->where(fn (Builder $query) => $query
                ->where('is_active', true)
                ->orWhere('id', $target->department_id ?? 0)),
        ];

        if (! $this->user()->can('changeAccess', $target)) {
            unset($rules['role_id'], $rules['status']);
        }

        $rules['version'] = ['required', 'string'];

        return $rules;
    }

    /** Usuário em edição, buscado uma vez com o papel e as permissões usados na autorização. */
    public function target(): User
    {
        return $this->target ??= User::with('role.permissions:id,slug')->findOrFail($this->route('user'));
    }

    /**
     * Alterações pedidas, somente com os campos validados que o operador pode alterar.
     *
     * @return array{name: string, email: string, department_id: ?int, role_id?: int, status?: string}
     */
    public function changes(): array
    {
        return $this->safe()->only(['name', 'email', 'department_id', ...self::ACCESS_FIELDS]);
    }
}
