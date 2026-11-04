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
        'reports.view_performance' => 'Consultar o painel de desempenho dos técnicos',
        'users.manage' => 'Gerenciar usuários',
        'departments.manage' => 'Gerenciar departamentos',
        'categories.manage' => 'Gerenciar categorias',
        'roles.manage' => 'Gerenciar papéis e permissões',
        'sla.manage' => 'Gerenciar políticas de SLA',
        // Chat (RF-26 a RF-36). Mesmos textos da migration 2026_11_04_000005_add_chat_permissions.
        'chat.request' => 'Solicitar atendimento via chat',
        'chat.send_message' => 'Enviar mensagens em atendimento via chat',
        'chat.view_queue' => 'Visualizar a fila do chat',
        'chat.manage_availability' => 'Alterar a própria disponibilidade no chat',
        'chat.accept_next' => 'Assumir o próximo atendimento via chat',
        'chat.close' => 'Encerrar atendimento via chat',
        'chat.transfer' => 'Transferir atendimento via chat',
        'chat.view_history' => 'Consultar o histórico de atendimentos via chat',
        'chat.manage_capacity' => 'Configurar a capacidade dos técnicos no chat',
        'chat.view_metrics' => 'Consultar os indicadores do chat',
    ];

    public const ROLES = [
        'requester' => ['Solicitante', ['chat.request', 'chat.send_message']],
        'technician' => ['Técnico', [
            'tickets.self_assign',
            'tickets.change_status',
            'tickets.resolve',
            'tickets.change_priority',
            'tickets.change_category',
            'tickets.view_internal_notes',
            'tickets.add_internal_note',
            'audit.view',
            'chat.send_message',
            'chat.view_queue',
            'chat.manage_availability',
            'chat.accept_next',
            'chat.close',
            'chat.transfer',
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
            'reports.view_performance',
            'chat.view_queue',
            'chat.transfer',
            'chat.view_history',
            'chat.manage_capacity',
            'chat.view_metrics',
        ]],
        'admin' => ['Administrador', [
            'tickets.view_all_departments',
            'tickets.assign',
            'tickets.reassign',
            'tickets.view_internal_notes',
            'audit.view',
            'reports.view_performance',
            'users.manage',
            'departments.manage',
            'categories.manage',
            'roles.manage',
            'sla.manage',
            'chat.view_history',
            'chat.view_metrics',
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
