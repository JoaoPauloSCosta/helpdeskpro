<?php

use App\Models\User;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Gate;

/*
 * Canais privados do chat (docs/chat/02-system-design-chat.md, 6.2). Um canal por usuário: o servidor decide
 * quem recebe cada evento no momento do envio, então quem deixa uma sessão (transferência) para de receber na
 * hora, e conhecer o id de uma sessão não dá acesso a nada. Autorizados a cada assinatura.
 */
Broadcast::channel('chat.user.{userId}', fn (User $user, int $userId) => config('chat.enabled')
    && $user->is_active
    && $user->id === $userId);

// Fila operacional: só o total aguardando, sem dados de solicitantes.
Broadcast::channel('chat.queue', fn (User $user) => config('chat.enabled') && Gate::forUser($user)->allows('viewChatQueue'));
