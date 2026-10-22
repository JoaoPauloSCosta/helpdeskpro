<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Auditoria das alterações administrativas (RF-19: usuários e, depois, departamentos, categorias,
 * papéis e políticas de SLA). Os eventos de chamados continuam em `ticket_histories`.
 * Registros só são inseridos: não há atualização nem exclusão pelo fluxo normal (RN-18, RN-19).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            // Operador responsável; nulo apenas para eventos sem usuário autenticado.
            $table->foreignId('user_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('event_type', 50);
            $table->morphs('auditable');
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
