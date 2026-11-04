<?php

namespace App\Http\Requests\Chat;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/** Disponibilidade manual do técnico (RF-28). Só a própria; o usuário vem da autenticação. */
class UpdateChatAvailabilityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('manageChatAvailability');
    }

    public function rules(): array
    {
        return ['available' => ['required', 'boolean']];
    }

    public function messages(): array
    {
        return [
            'available.required' => 'Informe se você está disponível.',
            'available.boolean' => 'Informe se você está disponível.',
        ];
    }
}
