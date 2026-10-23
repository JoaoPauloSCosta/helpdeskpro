<?php

namespace App\Http\Requests\Tickets;

/**
 * Exportação de chamados (EXP-01): os mesmos filtros e a mesma ordenação da listagem, com as mesmas
 * regras de validação. A página é ignorada, porque a exportação cobre todos os resultados.
 * Pedidos JSON com parâmetros inválidos recebem 422; os demais voltam à listagem com os erros.
 */
class TicketExportRequest extends TicketIndexRequest
{
}
