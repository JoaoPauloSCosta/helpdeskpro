<?php

namespace App\Http\Requests;

use App\Enums\SlaState;
use App\Enums\TicketStatus;
use App\Http\Requests\Concerns\NormalizesListingFilters;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Filtros do painel de desempenho (RF-25), validados no servidor. Só restringem o resultado: o escopo de
 * acesso é aplicado pelo relatório, e um técnico ou departamento fora do escopo apenas não retorna dados.
 */
class PerformanceRequest extends FormRequest
{
    use NormalizesListingFilters;

    public const FILTERS = ['opened_from', 'opened_to', 'technician', 'department', 'category', 'status', 'sla'];

    /** Maior intervalo aceito, em dias: mantém a evolução e as consultas num tamanho razoável. */
    public const MAX_DAYS = 366;

    protected $redirectRoute = 'performance.index';

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'opened_from' => ['nullable', 'date_format:Y-m-d'],
            'opened_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:opened_from'],
            'technician' => ['nullable', 'integer', 'min:1'],
            'department' => ['nullable', 'integer', 'min:1'],
            'category' => ['nullable', 'integer', 'min:1'],
            'status' => ['nullable', Rule::enum(TicketStatus::class)],
            'sla' => ['nullable', Rule::enum(SlaState::class)],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    public function after(): array
    {
        return [
            function ($validator) {
                $from = $this->input('opened_from');
                $to = $this->input('opened_to');

                if ($from && $to && ! $validator->errors()->has('opened_to')
                    && \Illuminate\Support\Carbon::parse($from)->diffInDays(\Illuminate\Support\Carbon::parse($to)) > self::MAX_DAYS) {
                    $validator->errors()->add('opened_to', 'O período pode ter no máximo '.self::MAX_DAYS.' dias.');
                }
            },
        ];
    }

    public function attributes(): array
    {
        return [
            'opened_from' => 'período (de)',
            'opened_to' => 'período (até)',
            'technician' => 'técnico',
            'department' => 'departamento',
            'category' => 'categoria',
            'status' => 'status',
            'sla' => 'situação do SLA',
        ];
    }

    public function messages(): array
    {
        return [
            'opened_to.after_or_equal' => 'A data final deve ser igual ou posterior à data inicial.',
        ];
    }
}
