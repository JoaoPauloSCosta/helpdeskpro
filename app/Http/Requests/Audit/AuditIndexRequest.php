<?php

namespace App\Http\Requests\Audit;

use App\Enums\AdminEvent;
use App\Http\Controllers\AuditController;
use App\Http\Requests\Concerns\NormalizesListingFilters;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Filtros, ordenação, paginação e detalhe da consulta de auditoria (AUD-01).
 * A autorização é aplicada no controller.
 *
 * Datas: dias inteiros em UTC, fuso em que `created_at` é gravado. `from` inclui o dia inteiro a partir
 * de 00:00:00 e `to` inclui o dia inteiro até 23:59:59. O intervalo é limitado para evitar varreduras
 * amplas da tabela.
 */
class AuditIndexRequest extends FormRequest
{
    use NormalizesListingFilters;

    /** Nenhum nome de coluna vem diretamente do usuário: a ordenação é só pela data do evento. */
    public const DIRECTIONS = ['asc', 'desc'];

    public const FILTERS = ['q', 'event', 'entity', 'entity_id', 'from', 'to'];

    /** Maior intervalo, em dias, entre `from` e `to` (inclusive). */
    public const MAX_RANGE_DAYS = 366;

    /** Com filtros inválidos, volta à consulta padrão exibindo os erros. */
    protected $redirectRoute = 'audit.index';

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:100'],
            'event' => ['nullable', Rule::enum(AdminEvent::class)],
            'entity' => ['nullable', Rule::in(array_keys(AuditController::ENTITIES)), 'required_with:entity_id'],
            'entity_id' => ['nullable', 'integer', 'min:1', 'max:4294967295'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            // Enviado pela listagem compartilhada; a única ordenação é pela data do evento.
            'sort' => ['nullable', Rule::in(['created_at'])],
            'direction' => ['nullable', Rule::in(self::DIRECTIONS)],
            'page' => ['nullable', 'integer', 'min:1'],
            // Evento aberto no painel de detalhes.
            'selected' => ['nullable', 'integer', 'min:1', 'max:18446744073709551615'],
        ];
    }

    public function attributes(): array
    {
        return [
            'q' => 'pesquisa por autor',
            'event' => 'evento',
            'entity' => 'entidade',
            'entity_id' => 'identificador da entidade',
            'from' => 'data inicial',
            'to' => 'data final',
            'direction' => 'direção da ordenação',
            'selected' => 'evento',
        ];
    }

    public function messages(): array
    {
        return [
            'entity.required_with' => 'Escolha a entidade para filtrar pelo identificador.',
            'to.after_or_equal' => 'A data final não pode ser anterior à data inicial.',
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($validator->errors()->hasAny(['from', 'to']) || ! $this->filled('from') || ! $this->filled('to')) {
                    return;
                }

                $days = CarbonImmutable::createFromFormat('Y-m-d', $this->input('from'), 'UTC')
                    ->diffInDays(CarbonImmutable::createFromFormat('Y-m-d', $this->input('to'), 'UTC')) + 1;

                if ($days > self::MAX_RANGE_DAYS) {
                    $validator->errors()->add('to', 'O período pode ter no máximo '.self::MAX_RANGE_DAYS.' dias.');
                }
            },
        ];
    }

    public function direction(): string
    {
        return $this->validated('direction') ?? 'desc';
    }

    public function selected(): ?int
    {
        $selected = $this->validated('selected');

        return $selected === null ? null : (int) $selected;
    }
}
