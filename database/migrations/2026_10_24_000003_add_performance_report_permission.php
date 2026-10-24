<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * RF-25: painel de desempenho dos técnicos. Cria `reports.view_performance` e a concede, uma única vez,
 * aos papéis Gestor e Administrador que já existem (o seeder só associa permissões a papéis recém-criados).
 * Papéis ausentes (banco sem seed) são ignorados; rodar de novo não duplica nada.
 */
return new class extends Migration
{
    private const SLUG = 'reports.view_performance';

    public function up(): void
    {
        DB::table('permissions')->insertOrIgnore([
            'name' => 'Consultar o painel de desempenho dos técnicos',
            'slug' => self::SLUG,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $permission = DB::table('permissions')->where('slug', self::SLUG)->value('id');

        foreach (DB::table('roles')->whereIn('slug', ['manager', 'admin'])->pluck('id') as $role) {
            DB::table('role_permissions')->insertOrIgnore([
                'role_id' => $role,
                'permission_id' => $permission,
                'created_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        $permission = DB::table('permissions')->where('slug', self::SLUG)->value('id');

        if ($permission !== null) {
            DB::table('role_permissions')->where('permission_id', $permission)->delete();
            DB::table('permissions')->where('id', $permission)->delete();
        }
    }
};
