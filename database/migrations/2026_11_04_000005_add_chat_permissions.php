<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Permissões do chat (adendo, seção 5) e matriz aprovada (docs/chat/01-decisoes-e-contratos.md, seção 7).
 * Concede uma única vez aos papéis de sistema que já existem; o seeder só associa permissões a papéis
 * recém-criados. Papéis personalizados não recebem nada. Papéis ausentes são ignorados; rodar de novo não
 * duplica nada. Valores copiados aqui de propósito: a migration não muda se o seeder mudar depois.
 */
return new class extends Migration
{
    private const PERMISSIONS = [
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

    private const GRANTS = [
        'requester' => ['chat.request', 'chat.send_message'],
        'technician' => [
            'chat.send_message', 'chat.view_queue', 'chat.manage_availability',
            'chat.accept_next', 'chat.close', 'chat.transfer',
        ],
        'manager' => ['chat.view_queue', 'chat.transfer', 'chat.view_history', 'chat.manage_capacity', 'chat.view_metrics'],
        'admin' => ['chat.view_history', 'chat.view_metrics'],
    ];

    public function up(): void
    {
        foreach (self::PERMISSIONS as $slug => $name) {
            DB::table('permissions')->insertOrIgnore([
                'name' => $name,
                'slug' => $slug,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $permissions = DB::table('permissions')->whereIn('slug', array_keys(self::PERMISSIONS))->pluck('id', 'slug');
        $roles = DB::table('roles')->whereIn('slug', array_keys(self::GRANTS))->pluck('id', 'slug');

        foreach ($roles as $role => $roleId) {
            foreach (self::GRANTS[$role] as $slug) {
                DB::table('role_permissions')->insertOrIgnore([
                    'role_id' => $roleId,
                    'permission_id' => $permissions[$slug],
                    'created_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        $ids = DB::table('permissions')->whereIn('slug', array_keys(self::PERMISSIONS))->pluck('id');

        DB::table('role_permissions')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('id', $ids)->delete();
    }
};
