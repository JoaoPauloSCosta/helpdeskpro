<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Role extends Model
{
    protected $fillable = ['name', 'slug', 'description', 'is_system'];

    protected function casts(): array
    {
        return ['is_system' => 'boolean'];
    }

    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class, 'role_permissions')->withPivot('created_at');
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /**
     * Impede elevação de privilégios ao atribuir papéis (RF-01, RF-03): o operador só concede um
     * papel se já possuir todas as permissões administrativas dele. Permissões operacionais
     * (chamados) não entram na regra, pois o Administrador não as recebe por padrão (seção 7.4)
     * e ainda assim precisa cadastrar técnicos e gestores.
     */
    public function isGrantableBy(User $operator): bool
    {
        $administrative = $this->permissions
            ->pluck('slug')
            ->intersect(Permission::ADMINISTRATIVE);

        return $administrative->diff($operator->effectivePermissions())->isEmpty();
    }
}
