<?php

namespace App\Http\Controllers;

use App\Models\Ticket;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class TicketAttachmentController extends Controller
{
    /**
     * Download de anexo (RF-23). Somente leitura e sempre como arquivo para salvar, nunca exibido pelo
     * navegador: o tipo é o detectado no servidor, `nosniff` impede que ele reinterprete o conteúdo e
     * a política de segurança zera scripts e recursos.
     *
     * Autorizado a cada requisição, pelo acesso ao chamado e pela visibilidade da mensagem do anexo
     * (RN-21). Conhecer o endereço não basta. O anexo é buscado dentro do chamado da rota, então trocar
     * o identificador não alcança arquivos de outro chamado. Quem não pode ver o anexo (por exemplo, o
     * solicitante diante de uma nota interna) recebe 404, como se ele não existisse.
     */
    public function show(Request $request, Ticket $ticket, int $attachment): StreamedResponse
    {
        Gate::authorize('view', $ticket);

        $file = $ticket->attachments()->with('comment:id,visibility')->find($attachment);

        abort_if($file === null || ! $request->user()->can('viewAttachment', [$ticket, $file]), 404);

        $disk = Storage::disk(config('attachments.disk'));

        abort_unless($disk->exists($file->storage_path), 404);

        return $disk->download($file->storage_path, $file->original_name, [
            'Content-Type' => $file->mime_type,
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
            'Cache-Control' => 'no-store, private',
        ]);
    }
}
