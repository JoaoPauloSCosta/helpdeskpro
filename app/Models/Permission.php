<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Permission extends Model
{
    /**
     * Permissões administrativas e de consulta ampla. Um papel que as contenha só pode ser
     * atribuído por quem também as possui (ver Role::isGrantableBy()).
     */
    public const ADMINISTRATIVE = [
        'users.manage',
        'departments.manage',
        'categories.manage',
        'roles.manage',
        'sla.manage',
        'audit.view',
    ];

    protected $fillable = ['name', 'slug', 'description'];

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'role_permissions')->withPivot('created_at');
    }
}
