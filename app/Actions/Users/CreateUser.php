<?php

namespace App\Actions\Users;

use App\Enums\AdminEvent;
use App\Models\Department;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Criação administrativa de usuário (ADM-02, RF-02). O usuário e o evento de auditoria (RF-19)
 * são gravados juntos ou não são gravados.
 *
 * A senha inicial é aleatória e não é conhecida por ninguém: o próprio usuário a define pelo fluxo
 * de redefinição de senha do Breeze (convite enviado pelo controller, ou "Esqueci minha senha").
 */
class CreateUser
{
    /**
     * @param  array{name: string, email: string, role_id: int, department_id: ?int, status: string}  $data
     */
    public function __invoke(User $operator, array $data): User
    {
        // Atribuição explícita: papel, departamento e situação não são mass assignable em User.
        $user = (new User)->forceFill([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Str::password(64),
            'role_id' => (int) $data['role_id'],
            'department_id' => $data['department_id'] !== null ? (int) $data['department_id'] : null,
            'is_active' => $data['status'] === 'active',
        ]);

        DB::transaction(function () use ($user, $operator) {
            $user->save();
            $this->recordCreation($user, $operator);
        });

        return $user;
    }

    /** Valores iniciais, com os nomes para o histórico continuar legível. Sem senha nem tokens. */
    private function recordCreation(User $user, User $operator): void
    {
        $user->auditLogs()->forceCreate([
            'user_id' => $operator->id,
            'event_type' => AdminEvent::UserCreated,
            'old_values' => null,
            'new_values' => [
                'name' => $user->name,
                'email' => $user->email,
                'role' => ['id' => $user->role_id, 'label' => Role::whereKey($user->role_id)->value('name')],
                'department' => $user->department_id === null
                    ? null
                    : ['id' => $user->department_id, 'label' => Department::whereKey($user->department_id)->value('name')],
                'is_active' => $user->is_active,
            ],
            'created_at' => $user->created_at,
        ]);
    }
}
