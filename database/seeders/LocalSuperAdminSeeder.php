<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Papel de teste com todas as permissões do catálogo, apenas para o ambiente
 * local. Não altera a matriz do PRD (seção 7.4): o papel "admin" permanece
 * com as permissões definidas em RolesAndPermissionsSeeder.
 *
 * O usuário que recebe o papel é indicado por SEED_SUPERADMIN_EMAIL no .env.
 * Idempotente: novas permissões do catálogo são acrescentadas ao reexecutar.
 */
class LocalSuperAdminSeeder extends Seeder
{
    public const ROLE_SLUG = 'superadmin';

    public function run(): void
    {
        if (! app()->environment('local')) {
            $this->command?->warn('LocalSuperAdminSeeder ignorado: ambiente não é local.');

            return;
        }

        DB::transaction(function () {
            $role = Role::firstOrCreate(
                ['slug' => self::ROLE_SLUG],
                ['name' => 'Super administrador (teste)', 'is_system' => false],
            );

            $ids = Permission::pluck('id');
            $role->permissions()->syncWithoutDetaching(
                $ids->mapWithKeys(fn (int $id) => [$id => ['created_at' => now()]])->all()
            );

            $email = env('SEED_SUPERADMIN_EMAIL');

            if (! $email) {
                return;
            }

            $updated = User::where('email', $email)->update(['role_id' => $role->id]);

            if ($updated === 0) {
                $this->command?->warn("Usuário {$email} não encontrado; papel não atribuído.");
            }
        });
    }
}
