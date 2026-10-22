<?php

namespace App\Http\Requests\Users;

use App\Models\Role;
use App\Models\User;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Criação administrativa de usuário (ADM-02, RF-02). Só aceita os campos do formulário; senha,
 * verificação de e-mail e tokens nunca vêm do navegador.
 */
class StoreUserRequest extends FormRequest
{
    public const NAME_MAX_LENGTH = 255;

    public const EMAIL_MAX_LENGTH = 255;

    public function authorize(): bool
    {
        return $this->user()->can('create', User::class);
    }

    /** Mesma normalização do cadastro público, que só aceita e-mails em minúsculas. */
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('email'))) {
            $this->merge(['email' => mb_strtolower($this->input('email'))]);
        }
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:'.self::NAME_MAX_LENGTH],
            'email' => [
                'required',
                'string',
                'email',
                'max:'.self::EMAIL_MAX_LENGTH,
                Rule::unique('users', 'email'),
            ],
            'role_id' => [
                'bail',
                'required',
                'integer',
                Rule::exists('roles', 'id'),
                $this->grantable(...),
            ],
            // Opcional: administradores podem não pertencer a um departamento (PRD 7.1).
            'department_id' => [
                'nullable',
                'integer',
                Rule::exists('departments', 'id')->where('is_active', true),
            ],
            'status' => ['required', Rule::in(array_keys(UserIndexRequest::STATUSES))],
        ];
    }

    public function attributes(): array
    {
        return [
            'name' => 'nome completo',
            'email' => 'e-mail',
            'role_id' => 'papel',
            'department_id' => 'departamento',
            'status' => 'situação inicial',
        ];
    }

    public function messages(): array
    {
        return [
            // Não informa nada sobre a conta existente além do conflito.
            'email.unique' => 'Já existe um usuário cadastrado com este e-mail.',
            'department_id.exists' => 'Selecione um departamento ativo.',
        ];
    }

    /** Papéis que o operador pode conceder, em ordem alfabética. */
    public static function grantableRoles(User $operator): Collection
    {
        return Role::query()
            ->with('permissions:id,slug')
            ->orderBy('name')
            ->get(['id', 'name', 'description'])
            ->filter(fn (Role $role) => $role->isGrantableBy($operator))
            ->values();
    }

    protected function grantable(string $attribute, mixed $value, Closure $fail): void
    {
        $role = Role::with('permissions:id,slug')->find($value);

        if ($role && ! $role->isGrantableBy($this->user())) {
            $fail('Você não tem permissão para atribuir este papel.');
        }
    }
}
