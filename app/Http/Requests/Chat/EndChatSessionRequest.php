<?php

namespace App\Http\Requests\Chat;

use App\Enums\ChatOutcome;
use App\Models\Ticket;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Encerramento do atendimento (RF-34, D-C5): resultado, título e categoria do chamado a gerar, resumo da
 * solução (só quando resolvido) e observação para continuidade. A autorização fica na action, com a sessão
 * bloqueada. Técnico, horários e status do chamado nunca vêm do navegador.
 */
class EndChatSessionRequest extends FormRequest
{
    public const NOTE_MAX_LENGTH = 2000;

    public function rules(): array
    {
        return [
            'outcome' => ['required', Rule::enum(ChatOutcome::class)],
            'title' => ['required', 'string', 'max:'.Ticket::TITLE_MAX_LENGTH],
            'category_id' => ['required', 'integer', Rule::exists('ticket_categories', 'id')->where('is_active', true)],
            'summary' => [Rule::requiredIf($this->input('outcome') === ChatOutcome::Resolved->value), 'nullable', 'string', 'max:'.Ticket::RESOLUTION_SUMMARY_MAX_LENGTH],
            'note' => ['nullable', 'string', 'max:'.self::NOTE_MAX_LENGTH],
        ];
    }

    public function messages(): array
    {
        return [
            'outcome.required' => 'Informe se o problema foi resolvido.',
            'outcome.enum' => 'Informe se o problema foi resolvido.',
            'title.required' => 'Informe o título do chamado.',
            'title.string' => 'Informe o título do chamado.',
            'title.max' => 'O título pode ter no máximo :max caracteres.',
            'category_id.required' => 'Escolha a categoria do chamado.',
            'category_id.integer' => 'Escolha a categoria do chamado.',
            'category_id.exists' => 'Escolha uma categoria ativa.',
            'summary.required' => 'Descreva a solução aplicada.',
            'summary.string' => 'Descreva a solução aplicada.',
            'summary.max' => 'O resumo da solução pode ter no máximo :max caracteres.',
            'note.string' => 'A observação não pôde ser lida.',
            'note.max' => 'A observação pode ter no máximo :max caracteres.',
        ];
    }
}
