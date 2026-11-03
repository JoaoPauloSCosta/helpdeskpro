<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A confirmação de e-mail passou a ser exigida (User implementa MustVerifyEmail). Quem já estava
 * cadastrado antes disso fica dispensado; só os cadastros posteriores precisam confirmar.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('users')->whereNull('email_verified_at')->update(['email_verified_at' => now()]);
    }

    // Sem volta: não dá para saber quais usuários estavam sem confirmação antes.
    public function down(): void {}
};
