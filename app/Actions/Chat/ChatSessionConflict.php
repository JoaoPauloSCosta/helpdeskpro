<?php

namespace App\Actions\Chat;

use RuntimeException;

/** A sessão mudou de estado antes da operação (ex.: cancelar uma solicitação que um técnico acabou de assumir). */
class ChatSessionConflict extends RuntimeException {}
