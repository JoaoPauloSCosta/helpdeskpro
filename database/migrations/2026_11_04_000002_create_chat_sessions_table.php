<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sessões de chat e fila FIFO (RF-26, RF-27, RF-34). A fila são as sessões `waiting` ordenadas por `id`;
 * a posição é derivada e nunca gravada.
 *
 * - `active_requester_id` recebe o solicitante só enquanto a sessão está aberta (`waiting`/`active`):
 *   o índice único garante uma sessão aberta por pessoa mesmo com pedidos simultâneos.
 * - `ticket_id` único: uma sessão gera no máximo um chamado (RN-CHAT-08).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chat_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('requester_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('active_requester_id')->nullable()->unique()->constrained('users')->restrictOnDelete();
            $table->foreignId('department_id')->constrained()->restrictOnDelete();
            $table->string('subject');
            $table->string('status', 20);
            $table->foreignId('technician_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('first_response_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->string('outcome', 20)->nullable();
            $table->foreignId('ticket_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('requester_last_read_id')->nullable();
            $table->unsignedBigInteger('technician_last_read_id')->nullable();
            $table->timestamps();

            $table->index(['status', 'id']);
            $table->index(['technician_id', 'status']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_sessions');
    }
};
