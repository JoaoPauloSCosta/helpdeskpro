<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Anexos de chamados e mensagens (RF-23, PRD seção 7.1). A visibilidade não é gravada: o anexo herda a
 * da mensagem (`ticket_comment_id`); sem mensagem, é um anexo da abertura e é público. Não há exclusão
 * pelo fluxo normal (RN-19), por isso as chaves estrangeiras restringem a exclusão.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ticket_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_id')->constrained()->restrictOnDelete();
            $table->foreignId('ticket_comment_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('uploaded_by')->constrained('users')->restrictOnDelete();
            $table->string('original_name', 255);
            $table->string('storage_path', 255)->unique();
            $table->string('mime_type', 120);
            $table->unsignedBigInteger('size_bytes');
            $table->char('sha256', 64);
            $table->timestamps();

            $table->index(['ticket_id', 'created_at']);
            $table->index('ticket_comment_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_attachments');
    }
};
