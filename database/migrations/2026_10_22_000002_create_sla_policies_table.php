<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Políticas de SLA (RF-15, PRD seção 7.1): metas em minutos por prioridade, em horas corridas.
 * Só a estrutura do PRD; nenhuma política é criada aqui (as metas ainda não foram definidas).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sla_policies', function (Blueprint $table) {
            $table->id();
            $table->string('priority', 20);
            $table->unsignedInteger('first_response_minutes');
            $table->unsignedInteger('resolution_minutes');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            // Busca da política ativa de uma prioridade (RF-15.3).
            $table->index(['priority', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sla_policies');
    }
};
