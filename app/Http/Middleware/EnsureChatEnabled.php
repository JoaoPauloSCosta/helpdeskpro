<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Com o módulo desligado (`CHAT_ENABLED=false`), as rotas do chat respondem 404. Roda depois do `auth`
 * (a ordenação de prioridade do Laravel o coloca lá): visitantes são mandados ao login antes, como em
 * qualquer rota protegida.
 */
class EnsureChatEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(config('chat.enabled'), 404);

        return $next($request);
    }
}
