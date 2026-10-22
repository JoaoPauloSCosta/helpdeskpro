<?php

namespace App\Http\Requests\Departments;

use App\Models\Department;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Edição de departamento (ADM-06, RF-02). Reaproveita as regras da criação (ADM-05): mesmos limites,
 * situação e ausência de unicidade do nome. No lugar do token de envio, exige a versão do formulário.
 */
class UpdateDepartmentRequest extends StoreDepartmentRequest
{
    private ?Department $target = null;

    public function authorize(): bool
    {
        // A permissão geral vem antes da busca, para não revelar quais identificadores existem.
        if (! $this->user()->can('viewAny', Department::class)) {
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

    /** Departamento em edição, buscado uma vez depois da autorização geral. */
    public function target(): Department
    {
        return $this->target ??= Department::findOrFail($this->route('department'));
    }
}
