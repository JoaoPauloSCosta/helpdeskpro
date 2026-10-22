<?php

namespace App\Http\Controllers;

use App\Enums\CommentVisibility;
use App\Models\Ticket;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class TicketCommentVersionController extends Controller
{
    /**
     * CHA-12 — Histórico de versões de um comentário (RF-11), somente leitura.
     *
     * O acesso ao chamado é conferido antes de qualquer consulta ao comentário, que é buscado entre
     * os comentários deste chamado e com o mesmo escopo de leitura da tela de detalhes: um comentário
     * de outro chamado ou uma nota interna fora do alcance do usuário respondem como inexistentes,
     * sem confirmar que existem. Versões que eram internas seguem a mesma regra.
     *
     * Só há versões para edições efetivamente gravadas; nada é reconstruído a partir de outras fontes.
     */
    public function index(Request $request, Ticket $ticket, int $comment): JsonResponse
    {
        Gate::authorize('view', $ticket);

        $user = $request->user();

        $comment = $ticket->comments()
            ->visibleTo($user)
            ->with('author:id,name')
            ->findOrFail($comment, ['id', 'ticket_id', 'user_id', 'body', 'visibility', 'created_at']);

        $stored = $comment->versions()
            ->with('editor:id,name')
            ->orderBy('id')
            ->get(['id', 'ticket_comment_id', 'edited_by', 'previous_body', 'previous_visibility', 'edited_at']);

        // Cada versão gravada é o texto anterior a uma edição; o texto atual fecha a sequência. Quem
        // escreveu cada texto é o autor do comentário (original) ou quem fez a edição anterior a ele.
        $entries = [];
        $author = $comment->author?->name;
        $recordedAt = $comment->created_at;

        foreach ($stored as $version) {
            $entries[] = [$version->previous_body, $version->previous_visibility, $author, $recordedAt];
            $author = $version->editor?->name;
            $recordedAt = $version->edited_at;
        }

        $entries[] = [$comment->body, $comment->visibility, $author, $recordedAt];

        $seesInternal = $user->hasPermission('tickets.view_internal_notes');
        $last = count($entries) - 1;

        $versions = collect($entries)
            ->map(fn (array $entry, int $index) => [
                'number' => $index + 1,
                'body' => $entry[0],
                'internal' => $entry[1] === CommentVisibility::Internal,
                'author' => $entry[2],
                'recorded_at' => $entry[3]?->toIso8601String(),
                'original' => $index === 0,
                'current' => $index === $last,
            ])
            ->filter(fn (array $version) => $seesInternal || ! $version['internal'])
            ->reverse()
            ->values();

        return response()
            ->json([
                'ticket' => $ticket->ticket_number,
                'internal' => $comment->visibility === CommentVisibility::Internal,
                'versions' => $versions,
            ])
            // Conteúdo de comentários não deve ficar em caches intermediários.
            ->header('Cache-Control', 'no-store, private');
    }
}
