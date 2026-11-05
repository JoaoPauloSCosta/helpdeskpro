<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Eventos do ciclo de vida da sessão (pedido, cancelamento, assunção, transferência, encerramento).
 * Como `ticket_histories`: só inserção (RN-18, RN-19).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chat_session_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('chat_session_id')->constrained()->restrictOnDelete();
            // Nulo apenas para eventos do sistema.
            $table->foreignId('user_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('event_type', 30);
            $table->json('data')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['chat_session_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_session_events');
    }
};
