<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Uma única política ativa por prioridade (RF-15.3: "não poderá haver seleção ambígua").
 * A coluna gerada repete a prioridade só nas ativas; nas inativas fica nula, e o índice único
 * aceita vários nulos. Assim a regra vale no banco mesmo com cadastros simultâneos (RN-17).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sla_policies', function (Blueprint $table) {
            $table->string('active_priority', 20)
                ->nullable()
                ->virtualAs('CASE WHEN is_active THEN priority END')
                ->after('is_active');

            $table->unique('active_priority');
        });
    }

    public function down(): void
    {
        Schema::table('sla_policies', function (Blueprint $table) {
            $table->dropUnique(['active_priority']);
            $table->dropColumn('active_priority');
        });
    }
};
