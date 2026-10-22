<?php

namespace App\Actions\SlaPolicies;

use App\Enums\AdminEvent;
use App\Enums\TicketPriority;
use App\Models\SlaPolicy;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Criação de política de SLA (SLA-02, RF-15.3). A política e o evento de auditoria (RF-19) são
 * gravados juntos ou não são gravados. Não altera outras políticas nem chamados: só configura
 * metas, sem calcular prazos.
 */
class CreateSlaPolicy
{
    /**
     * @param  array{priority: string, first_response_minutes: int|string, resolution_minutes: int|string, status: string}  $data
     *
     * @throws ActiveSlaPolicyConflict quando a prioridade já tem uma política ativa.
     */
    public function __invoke(User $operator, array $data): SlaPolicy
    {
        $policy = (new SlaPolicy)->forceFill([
            'priority' => TicketPriority::from($data['priority']),
            'first_response_minutes' => (int) $data['first_response_minutes'],
            'resolution_minutes' => (int) $data['resolution_minutes'],
            'is_active' => $data['status'] === 'active',
        ]);

        try {
            DB::transaction(function () use ($policy, $operator) {
                $policy->save();

                $policy->auditLogs()->forceCreate([
                    'user_id' => $operator->id,
                    'event_type' => AdminEvent::SlaPolicyCreated,
                    'old_values' => null,
                    'new_values' => [
                        'priority' => $policy->priority->value,
                        'first_response_minutes' => $policy->first_response_minutes,
                        'resolution_minutes' => $policy->resolution_minutes,
                        'is_active' => $policy->is_active,
                    ],
                    'created_at' => $policy->created_at,
                ]);
            });
        } catch (UniqueConstraintViolationException) {
            // Outra política ativa da mesma prioridade foi gravada entre a validação e a gravação.
            throw new ActiveSlaPolicyConflict;
        }

        return $policy;
    }
}
