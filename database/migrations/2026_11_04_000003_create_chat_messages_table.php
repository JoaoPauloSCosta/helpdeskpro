<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mensagens do chat (RF-30). Não são editadas nem apagadas (RN-19). `client_id` é gerado pelo navegador:
 * reenviar a mesma mensagem esbarra no índice único e não a duplica.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chat_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('chat_session_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->text('body');
            $table->uuid('client_id');
            $table->timestamp('created_at')->nullable();

            $table->unique(['chat_session_id', 'client_id']);
            $table->index(['chat_session_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_messages');
    }
};
