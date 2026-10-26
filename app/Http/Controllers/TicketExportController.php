<?php

namespace App\Http\Controllers;

use App\Http\Requests\Tickets\TicketExportRequest;
use App\Models\Ticket;
use App\Models\User;
use App\Support\TicketCsvExport;
use App\Support\TicketListingQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class TicketExportController extends Controller
{
    /** Papéis autorizados a exportar (slugs). */
    private const ALLOWED_ROLES = ['admin', 'superadmin'];

    public static function isAllowed(?User $user): bool
    {
        return $user !== null && in_array($user->role?->slug, self::ALLOWED_ROLES, true);
    }

    /**
     * EXP-01 — Exportação CSV dos chamados (RF-21), somente leitura.
     *
     * A autorização é refeita aqui: acesso à tela e escopo de visibilidade (`visibleTo`) valem para
     * todas as linhas, não só para a página exibida. Filtros e ordenação são os da listagem, pela
     * mesma classe. Sem filtros, exporta todos os chamados visíveis ao usuário (o PRD não define
     * limite de volume). Sem resultados, responde 204 e nenhum arquivo é gerado.
     */
    public function __invoke(TicketExportRequest $request, TicketCsvExport $export): Response|JsonResponse
    {
        Gate::authorize('viewAny', Ticket::class);

        abort_unless(self::isAllowed($request->user()), 403);

        $user = $request->user();
        $now = now();

        $tickets = Ticket::query()
            ->visibleTo($user)
            ->tap(fn ($query) => TicketListingQuery::filter($query, $request->filters(), $now))
            ->tap(fn ($query) => TicketListingQuery::sort($query, $request->sort(), $request->direction()));

        if (! (clone $tickets)->exists()) {
            return response()->noContent();
        }

        $stream = $export->write($tickets, $now, $user->hasRestrictedTicketView());

        return new StreamedResponse(function () use ($stream) {
            fpassthru($stream);
            fclose($stream);
        }, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            // Nome fixo e previsível: nada vem do usuário nem dos dados.
            'Content-Disposition' => 'attachment; filename="chamados-'.$now->clone()->utc()->format('Ymd-His').'.csv"',
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
