<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Preferência de som do chat (RF-31), por usuário, para acompanhar a pessoa em qualquer navegador. Ligada por
 * padrão; silenciar nunca interrompe o recebimento das mensagens.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('chat_sound_enabled')->default(true)->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('chat_sound_enabled');
        });
    }
};
