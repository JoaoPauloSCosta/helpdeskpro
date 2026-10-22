<?php

namespace App\Http\Requests\Categories;

use App\Models\TicketCategory;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Edição de categoria (ADM-09, RF-04). Reaproveita as regras da criação (ADM-08): mesmos limites,
 * situação e ausência de unicidade do nome. No lugar do token de envio, exige a versão do formulário.
 */
class UpdateCategoryRequest extends StoreCategoryRequest
{
    private ?TicketCategory $target = null;

    public function authorize(): bool
    {
        // A permissão geral vem antes da busca, para não revelar quais identificadores existem.
        if (! $this->user()->can('viewAny', TicketCategory::class)) {
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
        unset($rules['submission_token']);

        $rules['version'] = ['required', 'string'];

        return $rules;
    }

    /** Categoria em edição, buscada uma vez depois da autorização geral. */
    public function target(): TicketCategory
    {
        return $this->target ??= TicketCategory::findOrFail($this->route('category'));
    }
}
