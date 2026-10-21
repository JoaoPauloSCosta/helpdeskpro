<?php

namespace App\Enums;

/**
 * Os oito status do RF-10. A ordem dos casos é a ordem do ciclo de vida (usada na ordenação).
 */
enum TicketStatus: string
{
    case Open = 'open';
    case InProgress = 'in_progress';
    case AwaitingRequester = 'awaiting_requester';
    case Pending = 'pending';
    case PendingThirdParty = 'pending_third_party';
    case PendingDepartments = 'pending_departments';
    case Resolved = 'resolved';
    case Closed = 'closed';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Aberto',
            self::InProgress => 'Em atendimento',
            self::AwaitingRequester => 'Aguardando solicitante',
            self::Pending => 'Pendente',
            self::PendingThirdParty => 'Pendente Terceiros',
            self::PendingDepartments => 'Pendente Departamentos',
            self::Resolved => 'Resolvido',
            self::Closed => 'Encerrado',
        };
    }

    /** Status que pausam o SLA de resolução (RF-15.2). */
    public static function paused(): array
    {
        return [self::AwaitingRequester, self::Pending, self::PendingThirdParty, self::PendingDepartments];
    }

    /** Status em que o relógio de resolução está correndo. */
    public static function running(): array
    {
        return [self::Open, self::InProgress];
    }

    /** Status em que o atendimento já foi concluído. */
    public static function finished(): array
    {
        return [self::Resolved, self::Closed];
    }
}
