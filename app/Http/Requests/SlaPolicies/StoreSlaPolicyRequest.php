<?php

namespace App\Http\Requests\SlaPolicies;

use App\Enums\TicketPriority;
use App\Models\SlaPolicy;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Criação de política de SLA (SLA-02, RF-15.3). Só aceita os campos do formulário, que são os do
 * PRD 7.1; identificador, datas e `active_priority` vêm do backend. Metas em minutos inteiros.
 */
class StoreSlaPolicyRequest extends FormRequest
{
    public const FIELDS = ['priority', 'first_response_minutes', 'resolution_minutes', 'status'];

    /** Situação de uma política, correspondente a `is_active`. */
    public const STATUSES = ['active' => 'Ativa', 'inactive' => 'Inativa'];

    public function authorize(): bool
    {
        return $this->user()->can('create', SlaPolicy::class);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $target = ['required', 'integer', 'min:'.SlaPolicy::TARGET_MIN_MINUTES, 'max:'.SlaPolicy::TARGET_MAX_MINUTES];

        return [
            'priority' => ['required', Rule::enum(TicketPriority::class)],
            'first_response_minutes' => $target,
            'resolution_minutes' => $target,
            'status' => ['required', Rule::in(array_keys(self::STATUSES))],
            'submission_token' => ['required', 'uuid'],
        ];
    }

    /** Uma política ativa por prioridade (RF-15.3). O índice único garante a regra sob concorrência. */
    public function after(): array
    {
        return [
            $this->validateTargetOrder(...),
            function (Validator $validator) {
                if ($validator->errors()->hasAny(['priority', 'status']) || $this->input('status') !== 'active') {
                    return;
                }

                $priority = TicketPriority::from($this->input('priority'));

                if (SlaPolicy::hasActiveFor($priority)) {
                    $validator->errors()->add('status', self::activeConflictMessage($priority));
                }
            },
        ];
    }

    /** A resolução inclui a primeira resposta: uma meta de resolução menor que a de resposta é incoerente. */
    protected function validateTargetOrder(Validator $validator): void
    {
        if ($validator->errors()->hasAny(['first_response_minutes', 'resolution_minutes'])) {
            return;
        }

        if ((int) $this->input('resolution_minutes') < (int) $this->input('first_response_minutes')) {
            $validator->errors()->add('resolution_minutes', 'A meta de resolução não pode ser menor que a meta de primeira resposta.');
        }
    }

    public static function activeConflictMessage(TicketPriority $priority): string
    {
        return "Já existe uma política ativa para a prioridade {$priority->label()}. "
            .'Cadastre esta como inativa para manter uma única política aplicável.';
    }

    public function attributes(): array
    {
        return [
            'priority' => 'prioridade',
            'first_response_minutes' => 'meta de primeira resposta',
            'resolution_minutes' => 'meta de resolução',
            'status' => 'situação inicial',
        ];
    }

    public function messages(): array
    {
        return [
            'first_response_minutes.integer' => 'Informe a meta de primeira resposta em minutos inteiros.',
            'resolution_minutes.integer' => 'Informe a meta de resolução em minutos inteiros.',
        ];
    }
}
