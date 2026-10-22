<?php

namespace App\Actions\Categories;

use App\Enums\AdminEvent;
use App\Models\TicketCategory;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Criação de categoria (ADM-08, RF-04). A categoria e o evento de auditoria (RF-19) são gravados
 * juntos ou não são gravados.
 */
class CreateCategory
{
    /**
     * @param  array{name: string, description: ?string, status: string}  $data
     */
    public function __invoke(User $operator, array $data): TicketCategory
    {
        $category = (new TicketCategory)->forceFill([
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'is_active' => $data['status'] === 'active',
        ]);

        DB::transaction(function () use ($category, $operator) {
            $category->save();

            $category->auditLogs()->forceCreate([
                'user_id' => $operator->id,
                'event_type' => AdminEvent::CategoryCreated,
                'old_values' => null,
                'new_values' => [
                    'name' => $category->name,
                    'description' => $category->description,
                    'is_active' => $category->is_active,
                ],
                'created_at' => $category->created_at,
            ]);
        });

        return $category;
    }
}
