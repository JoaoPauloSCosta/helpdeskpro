<?php

namespace App\Actions\Departments;

use App\Enums\AdminEvent;
use App\Models\Department;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Criação de departamento (ADM-05, RF-02). O departamento e o evento de auditoria (RF-19) são
 * gravados juntos ou não são gravados.
 */
class CreateDepartment
{
    /**
     * @param  array{name: string, description: ?string, status: string}  $data
     */
    public function __invoke(User $operator, array $data): Department
    {
        $department = (new Department)->forceFill([
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'is_active' => $data['status'] === 'active',
        ]);

        DB::transaction(function () use ($department, $operator) {
            $department->save();

            $department->auditLogs()->forceCreate([
                'user_id' => $operator->id,
                'event_type' => AdminEvent::DepartmentCreated,
                'old_values' => null,
                'new_values' => [
                    'name' => $department->name,
                    'description' => $department->description,
                    'is_active' => $department->is_active,
                ],
                'created_at' => $department->created_at,
            ]);
        });

        return $department;
    }
}
