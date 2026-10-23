<?php

namespace App\Support;

use App\Models\Ticket;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

/**
 * Geração do CSV de chamados (EXP-01, RF-21).
 *
 * Formato: UTF-8 com BOM (acentos corretos no Excel), delimitador `;` (o separador de lista do Excel
 * em pt-BR) e fim de linha CRLF. Datas em UTC, no formato `AAAA-MM-DD HH:MM:SS`, com o fuso no nome
 * da coluna. Valores nulos viram células vazias.
 *
 * Só colunas da listagem (RF-07) e a data de resolução. Nunca comentários, notas internas, descrição,
 * solução ou dados de pendência.
 */
class TicketCsvExport
{
    public const DELIMITER = ';';

    public const HEADERS = [
        'Identificador', 'Título', 'Solicitante', 'Departamento', 'Categoria', 'Prioridade', 'Status',
        'Responsável', 'Situação do SLA', 'Abertura (UTC)', 'Última atualização (UTC)', 'Resolução (UTC)',
    ];

    /** Linhas lidas por vez; o arquivo inteiro nunca fica na memória. */
    private const CHUNK = 500;

    /** Memória usada antes de o arquivo temporário passar para o disco (bytes). */
    private const MEMORY_LIMIT = 2 * 1024 * 1024;

    /**
     * Escreve o CSV em um fluxo temporário e o devolve posicionado no início. Todo o arquivo é gerado
     * antes de qualquer byte ser enviado: uma falha na consulta vira erro HTTP, nunca um arquivo
     * truncado entregue como se estivesse completo.
     *
     * @param  Builder<Ticket>  $tickets  Consulta já com escopo de acesso, filtros e ordenação.
     * @return resource
     */
    public function write(Builder $tickets, CarbonInterface $now)
    {
        $stream = fopen('php://temp/maxmemory:'.self::MEMORY_LIMIT, 'w+');

        try {
            fwrite($stream, "\xEF\xBB\xBF");
            $this->put($stream, self::HEADERS);

            $tickets
                ->select([
                    'tickets.id', 'tickets.ticket_number', 'tickets.title', 'tickets.requester_id',
                    'tickets.department_id', 'tickets.category_id', 'tickets.assigned_to',
                    'tickets.priority', 'tickets.status', 'tickets.resolution_due_at',
                    'tickets.resolved_at', 'tickets.created_at', 'tickets.updated_at',
                ])
                // Uma linha por chamado: só relações "belongsTo", carregadas em bloco, sem junções.
                ->with(['requester:id,name', 'assignee:id,name', 'department:id,name', 'category:id,name'])
                ->lazy(self::CHUNK)
                ->each(fn (Ticket $ticket) => $this->put($stream, [
                    $ticket->ticket_number,
                    $ticket->title,
                    $ticket->requester?->name,
                    $ticket->department?->name,
                    $ticket->category?->name,
                    $ticket->priority->label(),
                    $ticket->status->label(),
                    $ticket->assignee?->name,
                    $ticket->slaState($now)->label(),
                    $this->date($ticket->created_at),
                    $this->date($ticket->updated_at),
                    $this->date($ticket->resolved_at),
                ]));

            rewind($stream);

            return $stream;
        } catch (\Throwable $e) {
            fclose($stream);

            throw $e;
        }
    }

    /**
     * Neutraliza texto que uma planilha interpretaria como fórmula (CSV/Formula Injection): se o valor
     * começa com `=`, `+`, `-`, `@`, tabulação ou retorno de carro (mesmo depois de espaços), é
     * prefixado com apóstrofo, que o Excel e o Google Planilhas não exibem e que impede a avaliação.
     * Isso é independente do escape de aspas e delimitadores, feito por `fputcsv`. Texto comum não é
     * alterado.
     */
    public static function neutralize(?string $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        return preg_match('/^[\s]*[=+\-@\t\r]/u', $value) === 1 || preg_match('/^[\t\r]/', $value) === 1
            ? "'".$value
            : $value;
    }

    /** @param  resource  $stream */
    private function put($stream, array $row): void
    {
        // Escape vazio: sem o caractere de escape legado do PHP, aspas são sempre duplicadas (RFC 4180).
        fputcsv($stream, array_map(fn ($cell) => self::neutralize($cell === null ? null : (string) $cell), $row), self::DELIMITER, '"', '', "\r\n");
    }

    private function date(?CarbonInterface $date): ?string
    {
        return $date?->copy()->utc()->format('Y-m-d H:i:s');
    }
}
