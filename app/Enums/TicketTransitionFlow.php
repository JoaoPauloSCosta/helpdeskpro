<?php

namespace App\Enums;

/**
 * Operação que executa cada transição do RF-10. Só `StatusChange` é executada pela alteração de
 * status (CHA-07); as demais exigem dados e permissões próprios e têm fluxos dedicados.
 */
enum TicketTransitionFlow: string
{
    /** CHA-07: `tickets.change_status`, sem dados adicionais. */
    case StatusChange = 'status_change';

    /** CHA-08: registro de pendência com motivo obrigatório (RF-17), que inicia a pausa do SLA. */
    case Pending = 'pending';

    /** CHA-09: `tickets.resolve` e resumo da solução (RF-12). */
    case Resolution = 'resolution';

    /** CHA-10: solicitante original e justificativa (RF-13). */
    case Reopening = 'reopening';

    /** CHA-11: `tickets.close` (RF-14). */
    case Closing = 'closing';
}
