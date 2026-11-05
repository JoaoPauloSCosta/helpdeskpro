<?php

namespace App\Http\Controllers;

use App\Actions\Chat\UpdateChatCapacity;
use App\Http\Requests\Chat\UpdateChatCapacityRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;

/**
 * Capacidade dos técnicos (RF-28, RF-36). A tela CHAT-06 entra na Etapa 9; a gravação já fica protegida.
 * O técnico só é buscado depois da autorização geral, para não revelar quais identificadores existem.
 */
class ChatCapacityController extends Controller
{
    public function update(UpdateChatCapacityRequest $request, int $user, UpdateChatCapacity $update): RedirectResponse
    {
        $technician = User::find($user);

        // Só quem atende pelo chat tem capacidade.
        abort_unless($technician?->hasPermission('chat.accept_next'), 404);

        $max = (int) $request->validated('max_sessions');

        if (! $update($request->user(), $technician, $max)) {
            return back()->with('info', 'Nenhuma alteração para salvar.');
        }

        return back()->with('success', "Capacidade de {$technician->name} no chat alterada para {$max} atendimentos simultâneos.");
    }
}
