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

    /**
     * Transições do RF-10 a partir deste status e a operação que executa cada uma. Transições
     * não listadas são rejeitadas (RN-06). Aberto → Em atendimento também ocorre pela
     * autoatribuição (RF-09), que altera responsável e status juntos.
     *
     * @return array<string, TicketTransitionFlow> Chave: valor do status de destino.
     */
    public function transitions(): array
    {
        return match ($this) {
            self::Open => [
                self::InProgress->value => TicketTransitionFlow::StatusChange,
            ],
            self::InProgress => [
                self::AwaitingRequester->value => TicketTransitionFlow::Pending,
                self::Pending->value => TicketTransitionFlow::Pending,
                self::PendingThirdParty->value => TicketTransitionFlow::Pending,
                self::PendingDepartments->value => TicketTransitionFlow::Pending,
                self::Resolved->value => TicketTransitionFlow::Resolution,
            ],
            // Retomada: o SLA de resolução volta a correr (RF-15.2).
            self::AwaitingRequester, self::Pending, self::PendingThirdParty, self::PendingDepartments => [
                self::InProgress->value => TicketTransitionFlow::StatusChange,
            ],
            self::Resolved => [
                self::Open->value => TicketTransitionFlow::Reopening,
                self::Closed->value => TicketTransitionFlow::Closing,
            ],
            self::Closed => [],
        };
    }

    /**
     * Destinos que a alteração de status (CHA-07) pode executar a partir deste status.
     *
     * @return list<self>
     */
    public function statusChangeTargets(): array
    {
        return $this->targetsOf(TicketTransitionFlow::StatusChange);
    }

    /**
     * Tipos de pendência que o registro de pendência (CHA-08) pode aplicar a partir deste status.
     *
     * @return list<self>
     */
    public function pendingTargets(): array
    {
        return $this->targetsOf(TicketTransitionFlow::Pending);
    }

    /**
     * Campos específicos do RF-17 aceitos por este tipo de pendência, além do motivo (sempre
     * obrigatório). Todos são opcionais; um campo de outro tipo é recusado.
     *
     * @return list<string>
     */
    public function pendingFields(): array
    {
        return match ($this) {
            self::AwaitingRequester, self::Pending => ['expected_return_at'],
            self::PendingThirdParty => ['supplier_name', 'protocol_number', 'responsible_name', 'expected_return_at'],
            self::PendingDepartments => ['related_department_id', 'protocol_number', 'responsible_name', 'expected_return_at'],
            default => [],
        };
    }

    /** Se a resolução (CHA-09, RF-12) pode partir deste status. */
    public function resolvable(): bool
    {
        return ($this->transitions()[self::Resolved->value] ?? null) === TicketTransitionFlow::Resolution;
    }

    /** Se a reabertura (CHA-10, RF-13) pode partir deste status. */
    public function reopenable(): bool
    {
        return ($this->transitions()[self::Open->value] ?? null) === TicketTransitionFlow::Reopening;
    }

    /** Se o encerramento (CHA-11, RF-14) pode partir deste status. */
    public function closable(): bool
    {
        return ($this->transitions()[self::Closed->value] ?? null) === TicketTransitionFlow::Closing;
    }

    /** @return list<self> */
    private function targetsOf(TicketTransitionFlow $flow): array
    {
        $targets = array_keys(array_filter($this->transitions(), fn (TicketTransitionFlow $f) => $f === $flow));

        return array_map(fn (string $value) => self::from($value), $targets);
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
