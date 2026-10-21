<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Estende `users` (PRD seção 7.1). Os campos são opcionais para não quebrar os usuários existentes:
 * sem papel, o usuário não recebe nenhuma permissão.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('role_id')->nullable()->after('password')->constrained()->restrictOnDelete();
            $table->foreignId('department_id')->nullable()->after('role_id')->constrained()->restrictOnDelete();
            $table->boolean('is_active')->default(true)->after('department_id');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('department_id');
            $table->dropConstrainedForeignId('role_id');
            $table->dropColumn('is_active');
        });
    }
};
