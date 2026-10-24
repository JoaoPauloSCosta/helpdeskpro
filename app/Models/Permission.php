<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
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
        'reports.view_performance',
    ];

    /**
     * Módulos do catálogo, na ordem de exibição da matriz (PER-04). Não há coluna de grupo: o módulo
     * é o prefixo do identificador (PRD 7.3), e prefixos desconhecidos caem em "Outras".
     */
    public const GROUPS = [
        'tickets' => 'Chamados',
        'comments' => 'Comentários',
        'audit' => 'Auditoria',
        'reports' => 'Relatórios',
        'users' => 'Usuários',
        'departments' => 'Departamentos',
        'categories' => 'Categorias',
        'roles' => 'Papéis e permissões',
        'sla' => 'Políticas de SLA',
    ];

    public const OTHER_GROUP = 'other';

    protected $fillable = ['name', 'slug', 'description'];

    /**
     * Rótulos de todos os módulos, inclusive "Outras", na ordem de exibição.
     *
     * @return array<string, string>
     */
    public static function groupLabels(): array
    {
        return [...self::GROUPS, self::OTHER_GROUP => 'Outras'];
    }

    /** Permissões de um módulo, pelo prefixo do identificador; "Outras" reúne os desconhecidos. */
    public function scopeInGroup(Builder $query, string $group): void
    {
        if ($group !== self::OTHER_GROUP) {
            $query->where('permissions.slug', 'like', $group.'.%');

            return;
        }

        foreach (array_keys(self::GROUPS) as $known) {
            $query->where('permissions.slug', 'not like', $known.'.%');
        }
    }

    public function group(): string
    {
        $prefix = strstr($this->slug, '.', true);

        return array_key_exists($prefix, self::GROUPS) ? $prefix : self::OTHER_GROUP;
    }

    public function isAdministrative(): bool
    {
        return in_array($this->slug, self::ADMINISTRATIVE, true);
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'role_permissions')->withPivot('created_at');
    }
}
