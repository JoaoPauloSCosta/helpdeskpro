<?php

namespace App\Http\Requests\Categories;

use App\Models\TicketCategory;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Criação de categoria (ADM-08, RF-04). Só aceita os campos do formulário; identificador e datas
 * vêm do backend. Espaços nas pontas são removidos e textos vazios viram nulos pelos middlewares
 * globais do Laravel (TrimStrings e ConvertEmptyStringsToNull).
 *
 * O PRD não exige nomes únicos para categorias; por isso não há regra de unicidade.
 */
class StoreCategoryRequest extends FormRequest
{
    public const FIELDS = ['name', 'description', 'status'];

    /** Situação de uma categoria, correspondente a `is_active`. */
    public const STATUSES = ['active' => 'Ativa', 'inactive' => 'Inativa'];

    public function authorize(): bool
    {
        return $this->user()->can('create', TicketCategory::class);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:'.TicketCategory::NAME_MAX_LENGTH],
            'description' => ['nullable', 'string', 'max:'.TicketCategory::DESCRIPTION_MAX_LENGTH],
            'status' => ['required', Rule::in(array_keys(self::STATUSES))],
            'submission_token' => ['required', 'uuid'],
        ];
    }

    public function attributes(): array
    {
        return [
            'name' => 'nome da categoria',
            'description' => 'descrição',
            'status' => 'situação inicial',
        ];
    }
}
