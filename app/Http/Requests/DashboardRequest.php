<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\NormalizesListingFilters;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Período do Visão Geral (IND-01). Usa os mesmos parâmetros e regras do filtro de
 * abertura da listagem (RF-07), para que os atalhos do dashboard levem à mesma seleção de chamados.
 * O período só restringe os chamados considerados; o escopo de acesso é aplicado no controller.
 */
class DashboardRequest extends FormRequest
{
    use NormalizesListingFilters;

    public const FILTERS = ['opened_from', 'opened_to'];

    /** Com um período inválido, volta ao dashboard sem filtro exibindo os erros. */
    protected $redirectRoute = 'dashboard';

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'opened_from' => ['nullable', 'date_format:Y-m-d'],
            'opened_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:opened_from'],
        ];
    }

    public function attributes(): array
    {
        return [
            'opened_from' => 'abertura (de)',
            'opened_to' => 'abertura (até)',
        ];
    }

    public function messages(): array
    {
        return [
            'opened_to.after_or_equal' => 'A data final deve ser igual ou posterior à data inicial.',
        ];
    }
}
