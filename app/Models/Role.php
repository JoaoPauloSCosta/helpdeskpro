<?php

namespace App\Models;

use App\Models\Concerns\HasAuditLogs;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Role extends Model
{
    use HasAuditLogs;

    /** Tamanho da coluna `name` (VARCHAR 255). */
    public const NAME_MAX_LENGTH = 255;

    /**
     * Limite da descrição. A coluna é TEXT, mas o PRD não define um tamanho; 1.000 caracteres,
     * como nos departamentos e nas categorias.
     */
    public const DESCRIPTION_MAX_LENGTH = 1000;

    protected $fillable = ['name', 'slug', 'description', 'is_system'];

    protected function casts(): array
    {
        return ['is_system' => 'boolean'];
    }

    /**
     * Versão dos dados editáveis (PER-03): muda quando outra pessoa salva o papel depois que o
     * formulário de edição foi aberto.
     */
    public function editVersion(): string
    {
        return sha1(implode('|', [$this->name, $this->description]));
    }

    /**
     * Versão das permissões associadas (PER-04): muda quando outra pessoa salva a matriz deste papel
     * depois que ela foi aberta.
     *
     * @param  iterable<string>|null  $slugs  Permissões já carregadas; consulta o banco quando nulo.
     */
    public function permissionsVersion(?iterable $slugs = null): string
    {
        $slugs = collect($slugs ?? $this->permissions()->pluck('slug'))->sort()->values();

        return sha1($slugs->implode(','));
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
