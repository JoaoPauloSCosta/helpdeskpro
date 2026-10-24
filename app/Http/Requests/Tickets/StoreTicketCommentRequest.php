<?php

namespace App\Http\Requests\Tickets;

use App\Enums\CommentVisibility;
use App\Models\TicketComment;
use App\Http\Requests\Concerns\Attachments;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Nova mensagem no chamado (RF-11). O chamado vem da rota e o autor da sessão; a visibilidade
 * pedida só é aceita se o usuário tiver o direito correspondente.
 */
class StoreTicketCommentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $ticket = $this->route('ticket');

        return $this->visibility() === CommentVisibility::Internal
            ? $this->user()->can('addInternalNote', $ticket)
            : $this->user()->can('comment', $ticket);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'body' => ['required', 'string', 'max:'.TicketComment::BODY_MAX_LENGTH],
            'visibility' => ['nullable', Rule::enum(CommentVisibility::class)],
            ...Attachments::rules(),
        ];
    }

    public function messages(): array
    {
        return [
            'body.required' => 'Escreva a mensagem antes de enviar.',
            'body.string' => 'Escreva uma mensagem válida.',
            'body.max' => 'A mensagem pode ter no máximo :max caracteres.',
            'visibility.enum' => 'Tipo de mensagem inválido.',
            ...Attachments::messages(),
        ];
    }

    /** Sem indicação, a mensagem é pública. Um valor desconhecido é rejeitado nas regras. */
    public function visibility(): ?CommentVisibility
    {
        $value = $this->input('visibility');

        return $value === null || $value === ''
            ? CommentVisibility::Public
            : CommentVisibility::tryFrom((string) $value);
    }
}
