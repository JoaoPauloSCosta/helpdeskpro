<?php

namespace App\Models;

use App\Models\Concerns\HasAuditLogs;
use Database\Factories\TicketCategoryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TicketCategory extends Model
{
    /** @use HasFactory<TicketCategoryFactory> */
    use HasAuditLogs, HasFactory;

    /** Tamanho da coluna `name` (VARCHAR 255). */
    public const NAME_MAX_LENGTH = 255;

    /**
     * Limite da descrição. A coluna é TEXT, mas o PRD não define um tamanho; 1.000 caracteres
     * descrevem quais solicitações pertencem à categoria, como nos departamentos.
     */
    public const DESCRIPTION_MAX_LENGTH = 1000;

    protected $fillable = ['name', 'description', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    /**
     * Assinatura dos campos editáveis na ADM-09. Detecta se outra pessoa alterou a categoria
     * enquanto o formulário estava aberto, sem depender de `updated_at` (precisão de segundos).
     */
    public function editVersion(): string
    {
        return sha1(implode('|', [$this->name, $this->description, (int) $this->is_active]));
    }

    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class, 'category_id');
    }
}
