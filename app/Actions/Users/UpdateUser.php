<?php

namespace App\Actions\Users;

use App\Enums\AdminEvent;
use App\Models\Department;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

/**
 * Edição administrativa de usuário (ADM-03, RF-02). Aplica apenas o que mudou e grava a auditoria
 * (RF-19) na mesma transação: um `UserUpdated` com os dados alterados e, se a situação mudar, um
 * `UserDeactivated` ou `UserActivated`.
 *
 * Chamados, comentários e históricos não são tocados: o departamento histórico dos chamados não
 * acompanha o cadastro do solicitante (RF-02, regra 6), e a inativação não apaga nada.
 */
class UpdateUser
{
    /**
     * @param  array{name: string, email: string, department_id?: ?int, role_id?: int, status?: string}  $changes
     * @return list<AdminEvent> Eventos gravados; vazio quando nada mudou.
     *
     * @throws UserEditConflict
     */
    public function __invoke(User $operator, User $user, array $changes, string $version): array
    {
        return DB::transaction(function () use ($operator, $user, $changes, $version) {
            // Bloqueia a linha para que duas edições simultâneas não se sobreponham.
            $current = User::whereKey($user->getKey())->lockForUpdate()->firstOrFail();

            if (! hash_equals($current->editVersion(), $version)) {
                throw new UserEditConflict;
            }

            $old = [];
            $new = [];

            foreach (['name', 'email'] as $field) {
                if ($changes[$field] !== $current->{$field}) {
                    $old[$field] = $current->{$field};
                    $new[$field] = $changes[$field];
                }
            }

            if (array_key_exists('role_id', $changes) && (int) $changes['role_id'] !== $current->role_id) {
                $old['role'] = $this->named(Role::class, $current->role_id);
                $new['role'] = $this->named(Role::class, (int) $changes['role_id']);
            }

            if (array_key_exists('department_id', $changes)) {
                $department = $changes['department_id'] !== null ? (int) $changes['department_id'] : null;

                if ($department !== $current->department_id) {
                    $old['department'] = $this->named(Department::class, $current->department_id);
                    $new['department'] = $this->named(Department::class, $department);
                }
            }

            $active = array_key_exists('status', $changes) ? $changes['status'] === 'active' : $current->is_active;
            $statusChanged = $active !== $current->is_active;

            if ($new === [] && ! $statusChanged) {
                return [];
            }

            if (isset($new['email'])) {
                // Mesma política do perfil (Breeze): o novo endereço não fica verificado, e um link de
                // redefinição emitido para o endereço anterior deixa de valer.
                Password::broker()->deleteToken($current);
                $current->email_verified_at = null;
            }

            // Atribuição explícita: papel, departamento e situação não são mass assignable em User.
            $current->forceFill([
                'name' => $changes['name'],
                'email' => $changes['email'],
                'role_id' => isset($new['role']) ? $new['role']['id'] : $current->role_id,
                'department_id' => array_key_exists('department', $new) ? $new['department']['id'] ?? null : $current->department_id,
                'is_active' => $active,
            ]);

            if ($statusChanged && ! $active) {
                $this->revokeAccess($current);
            }

            $current->save();

            $events = [];

            if ($new !== []) {
                $events[] = $this->record($current, $operator, AdminEvent::UserUpdated, $old, $new);
            }

            if ($statusChanged) {
                $events[] = $this->record(
                    $current,
                    $operator,
                    $active ? AdminEvent::UserActivated : AdminEvent::UserDeactivated,
                    ['is_active' => ! $active],
                    ['is_active' => $active],
                );
            }

            $user->setRawAttributes($current->getAttributes(), true);

            return $events;
        });
    }

    /**
     * Encerra as sessões abertas e invalida o "lembrar de mim". As policies já recusam operações de
     * usuários inativos a cada requisição; isto evita que uma sessão antiga continue navegando.
     */
    private function revokeAccess(User $user): void
    {
        $user->setRememberToken(Str::random(60));

        if (config('session.driver') === 'database') {
            DB::connection(config('session.connection'))
                ->table(config('session.table', 'sessions'))
                ->where('user_id', $user->id)
                ->delete();
        }
    }

    private function record(User $user, User $operator, AdminEvent $event, array $old, array $new): AdminEvent
    {
        $user->auditLogs()->forceCreate([
            'user_id' => $operator->id,
            'event_type' => $event,
            'old_values' => $old,
            'new_values' => $new,
            'created_at' => now(),
        ]);

        return $event;
    }

    /**
     * Identificador e nome, para o histórico continuar legível se o cadastro for renomeado.
     *
     * @param  class-string<Role|Department>  $model
     */
    private function named(string $model, ?int $id): ?array
    {
        return $id === null ? null : ['id' => $id, 'label' => $model::whereKey($id)->value('name')];
    }
}
