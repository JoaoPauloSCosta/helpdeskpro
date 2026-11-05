<?php

namespace App\Http\Requests\Chat;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Mensagem do chat (RF-30): texto puro de 1 a `chat.message_max_length` caracteres (os espaços das pontas já
 * são removidos pelo middleware padrão) e o `client_id` gerado pelo navegador para o reenvio sem duplicidade.
 * A autorização fica na action, com a sessão bloqueada.
 */
class StoreChatMessageRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'body' => ['required', 'string', 'max:'.config('chat.message_max_length')],
            'client_id' => ['required', 'uuid'],
        ];
    }

    public function messages(): array
    {
        return [
            'body.required' => 'Escreva a mensagem.',
            'body.string' => 'Escreva a mensagem.',
            'body.max' => 'A mensagem pode ter no máximo :max caracteres.',
            'client_id.required' => 'Não foi possível identificar a mensagem. Recarregue a página.',
            'client_id.uuid' => 'Não foi possível identificar a mensagem. Recarregue a página.',
        ];
    }
}
