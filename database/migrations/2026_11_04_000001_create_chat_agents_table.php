<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Técnicos do chat (RF-28): disponibilidade manual, presença e capacidade numa só linha, que também é o
 * alvo do bloqueio de capacidade ao assumir ou receber sessões (docs/chat/02-system-design-chat.md, 2 e 4).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chat_agents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->restrictOnDelete();
            $table->boolean('is_available')->default(false);
            // D-C7. As linhas são criadas pelo código com config('chat.default_capacity'); este é só o piso do banco.
            $table->unsignedTinyInteger('max_sessions')->default(3);
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            $table->index(['is_available', 'last_seen_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_agents');
    }
};
