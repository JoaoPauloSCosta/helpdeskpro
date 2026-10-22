<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pendências e pausas do SLA de resolução (RF-15.2, RF-17, PRD seção 7.1). Cada pausa é um
 * registro próprio, nunca sobrescrito por uma posterior (RN-16); a retomada só preenche o fim e a
 * duração. As referências são restritas contra exclusão para preservar o histórico (RN-19).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ticket_sla_pauses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_id')->constrained()->restrictOnDelete();
            $table->foreignId('ticket_history_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('pause_status', 30);
            $table->text('reason');
            $table->string('supplier_name')->nullable();
            $table->string('protocol_number')->nullable();
            $table->foreignId('related_department_id')->nullable()->constrained('departments')->restrictOnDelete();
            $table->string('responsible_name')->nullable();
            $table->date('expected_return_at')->nullable();
            // Com default explícito, o MariaDB não aplica "ON UPDATE CURRENT_TIMESTAMP" a esta
            // coluna, o que alteraria o início da pausa ao gravar a retomada. O valor vem sempre do backend.
            $table->timestamp('started_at')->useCurrent();
            $table->timestamp('ended_at')->nullable();
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            // Uma única pausa ativa por chamado (RF-15.2, regra 5), garantida pelo banco: a coluna
            // só tem valor enquanto a pausa está aberta, e valores nulos não conflitam no índice único.
            $table->unsignedBigInteger('active_ticket_id')->nullable()
                ->storedAs('case when ended_at is null then ticket_id end');
            $table->unique('active_ticket_id');

            $table->index(['ticket_id', 'started_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_sla_pauses');
    }
};
