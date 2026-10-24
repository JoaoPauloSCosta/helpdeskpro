<?php

namespace App\Http\Requests\Tickets;

use App\Http\Requests\Concerns\Attachments;
use App\Models\Ticket;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Abertura de chamado (RF-05). Só aceita o que o solicitante informa (a prioridade não está entre os campos: todo chamado abre como Média, RF-05); qualquer outro campo
 * enviado (autor, departamento, status, identificador, prazos) é ignorado.
 */
class StoreTicketRequest extends FormRequest
{
    public const FIELDS = ['title', 'description', 'category_id'];

    public function authorize(): bool
    {
        return $this->user()->can('create', Ticket::class);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:'.Ticket::TITLE_MAX_LENGTH],
            'description' => ['required', 'string', 'max:'.Ticket::DESCRIPTION_MAX_LENGTH],
            'category_id' => [
                'required',
                'integer',
                Rule::exists('ticket_categories', 'id')->where('is_active', true),
            ],
            'submission_token' => ['required', 'uuid'],
            ...Attachments::rules(),
        ];
    }

    /**
     * O departamento histórico vem do cadastro do solicitante (RF-05, regra 2). Sem departamento
     * ativo, a abertura falha de forma controlada.
     */
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
            'title.required' => 'Informe o título do chamado.',
            'title.max' => 'O título pode ter no máximo :max caracteres.',
            'description.required' => 'Descreva a sua solicitação.',
            'description.max' => 'A descrição pode ter no máximo :max caracteres.',
            'category_id.required' => 'Selecione uma categoria.',
            'category_id.integer' => 'Selecione uma categoria válida.',
            'category_id.exists' => 'A categoria selecionada não está disponível. Escolha outra.',
            'submission_token.*' => 'Não foi possível validar o envio. Recarregue a página e tente novamente.',
            ...Attachments::messages(),
            'title.string' => 'Informe um título válido.',
            'description.string' => 'Informe uma descrição válida.',
        ];
    }
}
