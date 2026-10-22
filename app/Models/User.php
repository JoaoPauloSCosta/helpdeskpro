<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Models\Concerns\HasAuditLogs;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasAuditLogs, HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
        ];
    }

    /** Slugs das permissões do papel, carregados uma vez por instância. */
    private ?array $permissionSlugs = null;

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    /**
     * Técnicos que podem receber um chamado por atribuição (RF-08): ativos e com
     * `tickets.self_assign`, a permissão que identifica quem assume atendimentos (RF-09).
     * O PRD não define elegibilidade além disso; este é o único ponto a ajustar se ela mudar.
     */
    public function scopeEligibleAssignees(Builder $query): Builder
    {
        return $query->where('users.is_active', true)
            ->whereHas('role.permissions', fn (Builder $query) => $query->where('slug', 'tickets.self_assign'));
    }

    /**
     * Assinatura dos dados administrativos editáveis na ADM-03. Detecta se outra pessoa alterou o
     * cadastro enquanto o formulário estava aberto, sem depender de `updated_at` (precisão de segundos).
     */
    public function editVersion(): string
    {
        return sha1(implode('|', [$this->name, $this->email, $this->role_id, $this->department_id, (int) $this->is_active]));
    }

    /** Mesma regra de scopeEligibleAssignees(), para um usuário já carregado. */
    public function isEligibleAssignee(): bool
    {
        return $this->is_active && $this->hasPermission('tickets.self_assign');
    }

    /**
     * Verifica uma permissão do papel (RF-03). Sem papel, não há permissões.
     * O nome do papel nunca é usado como atalho de autorização.
     */
    public function hasPermission(string $slug): bool
    {
        return in_array($slug, $this->permissionSlugs(), true);
    }

    /**
     * Slugs das permissões efetivas: nenhuma para usuários inativos (RF-02).
     * Usado pela interface para ocultar o que não está disponível; a autorização continua nas policies.
     *
     * @return list<string>
     */
    public function effectivePermissions(): array
    {
        return $this->is_active ? $this->permissionSlugs() : [];
    }

    /** @return list<string> */
    private function permissionSlugs(): array
    {
        return $this->permissionSlugs ??= $this->role?->permissions()->pluck('slug')->all() ?? [];
    }
}
