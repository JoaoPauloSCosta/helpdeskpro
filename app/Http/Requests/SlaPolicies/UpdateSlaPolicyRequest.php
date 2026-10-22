<?php

namespace App\Http\Requests\SlaPolicies;

use App\Models\SlaPolicy;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Validator;

/**
 * Edição de política de SLA (SLA-03, RF-15.3). Reaproveita os limites da criação (SLA-02), sem a
 * prioridade, que não é editável. No lugar do token de envio, exige a versão do formulário.
 */
class UpdateSlaPolicyRequest extends StoreSlaPolicyRequest
{
    public const FIELDS = ['first_response_minutes', 'resolution_minutes', 'status'];

    private ?SlaPolicy $target = null;

    public function authorize(): bool
    {
        // A permissão geral vem antes da busca, para não revelar quais identificadores existem.
        if (! $this->user()->can('viewAny', SlaPolicy::class)) {
            return false;
        }

        return $this->user()->can('update', $this->target());
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $rules = parent::rules();
        unset($rules['priority'], $rules['submission_token']);

        $rules['version'] = ['required', 'string'];

        return $rules;
    }

    /** Ativar exige que a prioridade não tenha outra política ativa (RF-15.3). */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                $target = $this->target();

                if ($validator->errors()->has('status') || $this->input('status') !== 'active' || $target->is_active) {
                    return;
                }

                if (SlaPolicy::hasActiveFor($target->priority, $target->id)) {
                    $validator->errors()->add('status', self::activationConflictMessage($target));
                }
            },
        ];
    }

    public static function activationConflictMessage(SlaPolicy $policy): string
    {
        return "Já existe outra política ativa para a prioridade {$policy->priority->label()}. "
            .'Inative-a antes de ativar esta, para manter uma única política aplicável.';
    }

    /** Política em edição, buscada uma vez depois da autorização geral. */
    public function target(): SlaPolicy
    {
        return $this->target ??= SlaPolicy::findOrFail($this->route('policy'));
    }
}
