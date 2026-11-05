<?php

namespace App\Models;

use App\Enums\ChatAvailability;
use App\Enums\ChatSessionStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Técnico do chat (RF-28): disponibilidade manual, presença (`last_seen_at`) e capacidade. Os valores
 * vêm sempre do backend; a linha é bloqueada ao assumir ou receber sessões.
 */
class ChatAgent extends Model
{
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'is_available' => 'boolean',
            'max_sessions' => 'integer',
            'last_seen_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Linha do técnico, criada na primeira vez com a capacidade padrão (D-C7). `insertOrIgnore` evita
     * erro se duas abas a criarem ao mesmo tempo (o `user_id` é único).
     */
    public static function forUser(User $user): self
    {
        return static::firstWhere('user_id', $user->id) ?? static::insertDefault($user);
    }

    /**
     * A linha bloqueada até o fim da transação: todo caminho que dá uma sessão ao técnico passa por aqui.
     *
     * Use dentro de ChatTransaction::run() (READ COMMITTED), para as leituras depois do bloqueio enxergarem o
     * que outras transações gravaram.
     */
    public static function lockFor(User $user): self
    {
        $locked = fn () => static::where('user_id', $user->id)->lockForUpdate()->first();

        if ($agent = $locked()) {
            return $agent;
        }

        static::insertDefaultRow($user);

        return $locked() ?? throw new \LogicException('Linha do técnico não encontrada.');
    }

    private static function insertDefault(User $user): self
    {
        static::insertDefaultRow($user);

        return static::where('user_id', $user->id)->firstOrFail();
    }

    private static function insertDefaultRow(User $user): void
    {
        static::query()->insertOrIgnore([
            'user_id' => $user->id,
            'is_available' => false,
            'max_sessions' => config('chat.default_capacity'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * De plantão (D-C2): ativo, com `chat.accept_next`, disponível manualmente e presente. Inclui quem está
     * no limite de capacidade: a fila anda quando ele libera uma vaga.
     */
    public function scopeOnDuty(Builder $query): Builder
    {
        return $query->where('chat_agents.is_available', true)
            ->where('chat_agents.last_seen_at', '>=', now()->subSeconds(config('chat.presence_ttl')))
            ->whereHas('user', fn (Builder $user) => $user->where('users.is_active', true)
                ->whereHas('role.permissions', fn (Builder $permission) => $permission->where('slug', 'chat.accept_next')));
    }

    /** Presença (D-C3): o painel enviou sinal dentro do prazo. */
    public function isPresent(): bool
    {
        return $this->last_seen_at !== null
            && $this->last_seen_at->gte(now()->subSeconds(config('chat.presence_ttl')));
    }

    public function activeSessionsCount(): int
    {
        return ChatSession::where('technician_id', $this->user_id)
            ->where('status', ChatSessionStatus::Active)
            ->count();
    }

    /**
     * Disponibilidade efetiva (RN-CHAT-03). Um limite abaixo das sessões já ativas só deixa o técnico
     * ocupado; nenhuma sessão é encerrada (RF-28).
     */
    public function availability(?int $activeSessions = null): ChatAvailability
    {
        $user = $this->user;

        if (! $this->is_available || ! $this->isPresent() || ! $user->is_active || ! $user->hasPermission('chat.accept_next')) {
            return ChatAvailability::Unavailable;
        }

        return ($activeSessions ?? $this->activeSessionsCount()) >= $this->max_sessions
            ? ChatAvailability::Busy
            : ChatAvailability::Available;
    }
}
