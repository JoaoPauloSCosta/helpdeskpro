<?php

namespace App\Actions\SlaPolicies;

use App\Enums\AdminEvent;
use App\Models\SlaPolicy;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Edição de política de SLA (SLA-03, RF-15.3). Aplica apenas o que mudou e grava a auditoria (RF-19)
 * na mesma transação: um `SlaPolicyUpdated` com as metas alteradas e, se a situação mudar, um
 * `SlaPolicyDeactivated` ou `SlaPolicyActivated`.
 *
 * A prioridade não é editável: ela identifica a política. Nenhum chamado é tocado: cada chamado guarda
 * a cópia das metas aplicadas na abertura ou na última troca de prioridade (RF-15.3), então editar uma
 * política só vale para os chamados abertos depois dela.
 */
class UpdateSlaPolicy
{
    private const TARGETS = ['first_response_minutes', 'resolution_minutes'];

    /**
     * @param  array{first_response_minutes: int|string, resolution_minutes: int|string, status: string}  $changes
     * @return list<AdminEvent> Eventos gravados; vazio quando nada mudou.
     *
     * @throws SlaPolicyEditConflict
     * @throws ActiveSlaPolicyConflict quando a ativação daria duas políticas ativas à mesma prioridade.
     */
    public function __invoke(User $operator, SlaPolicy $policy, array $changes, string $version): array
    {
        try {
            return DB::transaction(function () use ($operator, $policy, $changes, $version) {
                // Bloqueia a linha para que duas edições simultâneas não se sobreponham.
                $current = SlaPolicy::whereKey($policy->getKey())->lockForUpdate()->firstOrFail();

                if (! hash_equals($current->editVersion(), $version)) {
                    throw new SlaPolicyEditConflict;
                }

                $old = [];
                $new = [];

                foreach (self::TARGETS as $field) {
                    $value = (int) $changes[$field];

                    if ($value !== $current->{$field}) {
                        $old[$field] = $current->{$field};
                        $new[$field] = $value;
                    }
                }

                $active = $changes['status'] === 'active';
                $statusChanged = $active !== $current->is_active;

                if ($new === [] && ! $statusChanged) {
                    return [];
                }

                // Atribuição explícita dos campos editáveis; prioridade, identificador e datas ficam com o banco.
                $current->forceFill([...$new, 'is_active' => $active])->save();

                $events = [];

                if ($new !== []) {
                    $events[] = $this->record($current, $operator, AdminEvent::SlaPolicyUpdated, $old, $new);
                }

                if ($statusChanged) {
                    $events[] = $this->record(
                        $current,
                        $operator,
                        $active ? AdminEvent::SlaPolicyActivated : AdminEvent::SlaPolicyDeactivated,
                        ['is_active' => ! $active],
                        ['is_active' => $active],
                    );
                }

                $policy->setRawAttributes($current->getAttributes(), true);

                return $events;
            });
        } catch (UniqueConstraintViolationException) {
            // Outra política ativa da mesma prioridade foi gravada entre a validação e a gravação.
            throw new ActiveSlaPolicyConflict;
        }
    }

    private function record(SlaPolicy $policy, User $operator, AdminEvent $event, array $old, array $new): AdminEvent
    {
        $policy->auditLogs()->forceCreate([
            'user_id' => $operator->id,
            'event_type' => $event,
            // A prioridade acompanha o evento para que ele seja legível sem consultar a política.
            'old_values' => ['priority' => $policy->priority->value, ...$old],
            'new_values' => ['priority' => $policy->priority->value, ...$new],
            'created_at' => now(),
        ]);

        return $event;
    }
}
