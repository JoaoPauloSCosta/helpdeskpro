<?php

namespace App\Http\Requests\Departments;

use App\Models\Department;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Criação de departamento (ADM-05, RF-02). Só aceita os campos do formulário; identificador e datas
 * vêm do backend. Espaços nas pontas são removidos e textos vazios viram nulos pelos middlewares
 * globais do Laravel (TrimStrings e ConvertEmptyStringsToNull).
 *
 * O PRD não exige nomes únicos para departamentos; por isso não há regra de unicidade.
 */
class StoreDepartmentRequest extends FormRequest
{
    public const FIELDS = ['name', 'description', 'status'];

    public function authorize(): bool
    {
        return $this->user()->can('create', Department::class);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:'.Department::NAME_MAX_LENGTH],
            'description' => ['nullable', 'string', 'max:'.Department::DESCRIPTION_MAX_LENGTH],
            'status' => ['required', Rule::in(array_keys(DepartmentIndexRequest::STATUSES))],
            'submission_token' => ['required', 'uuid'],
        ];
    }

    public function attributes(): array
    {
        return [
            'name' => 'nome do departamento',
            'description' => 'descrição',
            'status' => 'situação inicial',
        ];
    }
}
