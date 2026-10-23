<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Motor de SLA (RF-15, RF-16, RN-15): política aplicada, cópia das metas, primeiras violações
 * registradas e tempo efetivo de resolução. As metas ficam copiadas no chamado porque a política
 * pode ser editada depois. Chamados sem política (RF-15.3) ficam com tudo nulo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->foreignId('sla_policy_id')->nullable()->after('closed_at')
                ->constrained('sla_policies')->restrictOnDelete();
            $table->unsignedInteger('sla_first_response_minutes')->nullable()->after('sla_policy_id');
            $table->unsignedInteger('sla_resolution_minutes')->nullable()->after('sla_first_response_minutes');
            // Primeira violação de cada indicador; nunca apagadas (RN-15).
            $table->timestamp('first_response_breached_at')->nullable()->after('sla_resolution_minutes');
            $table->timestamp('resolution_breached_at')->nullable()->after('first_response_breached_at');
            // Tempo efetivo de resolução, sem as pausas; gravado na resolução.
            $table->unsignedInteger('resolution_effective_seconds')->nullable()->after('resolution_breached_at');
        });
    }

    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->dropConstrainedForeignId('sla_policy_id');
            $table->dropColumn([
                'sla_first_response_minutes',
                'sla_resolution_minutes',
                'first_response_breached_at',
                'resolution_breached_at',
                'resolution_effective_seconds',
            ]);
        });
    }
};
