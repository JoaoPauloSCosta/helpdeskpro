<?php

namespace App\Http\Requests\Chat;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Validator;

/**
 * Pedido de atendimento (RF-26, D-C5): só o assunto. Solicitante e departamento vêm do cadastro, como na
 * abertura de chamado (RF-05): sem departamento ativo, o pedido falha de forma controlada.
 */
class StoreChatSessionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('requestChat');
    }

    public function rules(): array
    {
        return ['subject' => ['required', 'string', 'max:'.config('chat.subject_max_length')]];
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                if (! $this->user()->department?->is_active) {
                    $validator->errors()->add(
                        'department',
                        'Seu cadastro não possui um departamento ativo. Procure o administrador do sistema.',
                    );
                }
            },
        ];
    }

    public function messages(): array
    {
        return [
            'subject.required' => 'Conte em poucas palavras o assunto do atendimento.',
            'subject.string' => 'Conte em poucas palavras o assunto do atendimento.',
            'subject.max' => 'O assunto pode ter no máximo :max caracteres.',
        ];
    }
}
