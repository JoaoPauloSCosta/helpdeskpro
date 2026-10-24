<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * RF-19 (v1.8): o Técnico passa a consultar a auditoria dos chamados que acessa. O seeder só associa
 * permissões a papéis recém-criados, então o papel já existente recebe a permissão aqui, uma única vez
 * e sem duplicar. Sem o papel ou a permissão (banco sem seed), não há nada a fazer.
 */
return new class extends Migration
{
    public function up(): void
    {
        $role = DB::table('roles')->where('slug', 'technician')->value('id');
        $permission = DB::table('permissions')->where('slug', 'audit.view')->value('id');

        if ($role === null || $permission === null) {
            return;
        }

        DB::table('role_permissions')->insertOrIgnore([
            'role_id' => $role,
            'permission_id' => $permission,
            'created_at' => now(),
        ]);
    }

    public function down(): void
    {
        $role = DB::table('roles')->where('slug', 'technician')->value('id');
        $permission = DB::table('permissions')->where('slug', 'audit.view')->value('id');

        if ($role !== null && $permission !== null) {
            DB::table('role_permissions')->where(['role_id' => $role, 'permission_id' => $permission])->delete();
        }
    }
};
