<?php

namespace App\Http\Requests\Concerns;

/**
 * Filtros das listagens administrativas: só os campos de `static::FILTERS` já validados,
 * sem espaços nas pontas e sem valores vazios.
 */
trait NormalizesListingFilters
{
    /**
     * @return array<string, string>
     */
    public function filters(): array
    {
        return collect($this->safe()->only(static::FILTERS))
            ->map(fn ($value) => is_string($value) ? trim($value) : $value)
            ->filter(fn ($value) => $value !== null && $value !== '')
            ->map(fn ($value) => (string) $value)
            ->all();
    }
}
