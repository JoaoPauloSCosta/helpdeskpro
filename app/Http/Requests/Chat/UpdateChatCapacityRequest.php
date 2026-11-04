<?php

namespace App\Http\Requests\Chat;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/** Capacidade de um técnico (RF-28, RF-36). A permissão é conferida antes de buscar o técnico. */
class UpdateChatCapacityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('manageChatCapacity');
    }

    public function rules(): array
    {
        return ['max_sessions' => ['required', 'integer', 'min:1', 'max:'.config('chat.max_capacity')]];
    }

    public function messages(): array
    {
        $message = 'Informe um limite entre 1 e '.config('chat.max_capacity').' atendimentos simultâneos.';

        return [
            'max_sessions.required' => $message,
            'max_sessions.integer' => $message,
            'max_sessions.min' => $message,
            'max_sessions.max' => $message,
        ];
    }
}
