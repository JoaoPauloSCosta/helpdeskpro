<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Versões anteriores dos comentários (RF-11, regras 6 a 9; PRD seção 7.1). Cada edição grava aqui o
 * conteúdo que estava armazenado antes de ser substituído. Registros só são inseridos: não há
 * atualização nem exclusão pelo fluxo normal (RN-09, RN-19).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ticket_comment_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_comment_id')->constrained()->restrictOnDelete();
            $table->foreignId('edited_by')->constrained('users')->restrictOnDelete();
            $table->text('previous_body');
            $table->string('previous_visibility', 20);
            $table->timestamp('edited_at');
            $table->timestamp('created_at')->nullable();

            $table->index(['ticket_comment_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_comment_versions');
    }
};
