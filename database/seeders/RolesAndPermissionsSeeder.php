<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

/**
 * Papéis, catálogo de permissões e matriz inicial (PRD, seções 7.3 e 7.4).
 * Idempotente: pode ser executado novamente sem duplicar registros nem
 * desfazer associações feitas depois pela administração.
 */
class RolesAndPermissionsSeeder extends Seeder
{
    public const PERMISSIONS = [
        'tickets.view_all_departments' => 'Visualizar chamados de todos os departamentos',
        'tickets.assign' => 'Atribuir chamados sem responsável',
        'tickets.reassign' => 'Alterar responsável existente',
        'tickets.self_assign' => 'Assumir chamados disponíveis',
        'tickets.change_status' => 'Executar transições permitidas',
        'tickets.resolve' => 'Resolver chamados',
        'tickets.close' => 'Encerrar chamados',
        'tickets.change_priority' => 'Alterar prioridade',
        'tickets.change_category' => 'Alterar categoria',
        'tickets.transfer_department' => 'Transferir chamados',
        'tickets.view_internal_notes' => 'Visualizar notas internas',
        'tickets.add_internal_note' => 'Adicionar notas internas',
        'comments.edit_others' => 'Editar comentários de outros usuários',
        'audit.view' => 'Consultar auditoria',
        'users.manage' => 'Gerenciar usuários',
        'departments.manage' => 'Gerenciar departamentos',
        'categories.manage' => 'Gerenciar categorias',
        'roles.manage' => 'Gerenciar papéis e permissões',
        'sla.manage' => 'Gerenciar políticas de SLA',
    ];

    public const ROLES = [
        'requester' => ['Solicitante', []],
        'technician' => ['Técnico', [
            'tickets.self_assign',
            'tickets.change_status',
            'tickets.resolve',
            'tickets.change_priority',
            'tickets.change_category',
            'tickets.view_internal_notes',
            'tickets.add_internal_note',
        ]],
        'manager' => ['Gestor', [
            'tickets.view_all_departments',
            'tickets.assign',
            'tickets.reassign',
            'tickets.change_status',
            'tickets.close',
            'tickets.change_priority',
            'tickets.change_category',
            'tickets.transfer_department',
            'tickets.view_internal_notes',
            'tickets.add_internal_note',
            'audit.view',
        ]],
        'admin' => ['Administrador', [
            'tickets.view_all_departments',
            'tickets.assign',
            'tickets.reassign',
            'tickets.view_internal_notes',
            'audit.view',
            'users.manage',
            'departments.manage',
            'categories.manage',
            'roles.manage',
            'sla.manage',
        ]],
    ];

    public function run(): void
    {
        foreach (self::PERMISSIONS as $slug => $name) {
            Permission::firstOrCreate(['slug' => $slug], ['name' => $name]);
        }

        foreach (self::ROLES as $slug => [$name, $permissions]) {
            $role = Role::firstOrCreate(['slug' => $slug], ['name' => $name, 'is_system' => true]);

            // Só cria a associação inicial quando o papel acabou de ser criado.
            if ($role->wasRecentlyCreated) {
                $ids = Permission::whereIn('slug', $permissions)->pluck('id');
                $role->permissions()->attach($ids, ['created_at' => now()]);
            }
        }
    }
}
