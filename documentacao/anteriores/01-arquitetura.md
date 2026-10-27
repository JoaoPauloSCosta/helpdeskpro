# Arquitetura

## Visão em camadas

```mermaid
flowchart LR
    V[Vue 3 + TS<br/>Pages / Components] -->|Inertia visit<br/>Ziggy route| R[routes/web.php<br/>auth + verified]
    R --> C[Controller]
    C -->|Gate::authorize<br/>ou FormRequest::authorize| P[Policies / Gate]
    C --> F[FormRequest<br/>validação]
    C --> A[Action<br/>DB::transaction + lockForUpdate]
    A --> M[(Models / MariaDB)]
    A --> H[(ticket_histories<br/>audit_logs)]
    C -->|props filtradas<br/>por permissão| V
```

| Camada | Diretório | Responsabilidade |
|---|---|---|
| Rotas | `routes/web.php`, `auth.php`, `console.php` | Mapeamento HTTP. Os comentários citam o requisito (CHA-xx, ADM-xx, SLA-xx). O scheduler roda `sla:record-breaches` a cada minuto. |
| Controllers | `app/Http/Controllers` | Finos. Autorizam, montam as props do Inertia e delegam a escrita a uma Action. Cada ação sobre o chamado tem controller próprio (`TicketStatusController`, `TicketResolutionController`...). |
| Form Requests | `app/Http/Requests/*` | Validação, mensagens em pt-BR e `authorize()`. Tem o trait `NormalizesListingFilters` e a classe auxiliar `Attachments`. |
| Actions | `app/Actions/*` | Regra de negócio transacional. Agrupadas por módulo, com exceções de conflito (`TicketStatusConflict`, `RoleEditConflict`...). |
| Policies | `app/Policies/*` | Autorização por permissão. `AppServiceProvider` define também o Gate `viewPerformance`. |
| Support | `app/Support/*` | Regras transversais: `SlaClock`, `TicketListingQuery`, `TicketCsvExport`, `AttachmentInspector`, `PerformanceReport`, `SingleSubmission`, `TicketNumberGenerator`. |
| Enums | `app/Enums/*` | `TicketStatus` (com as transições), `TicketPriority`, `SlaState`, `TicketEvent`, `AdminEvent`, `CommentVisibility`, `TicketTransitionFlow`. |
| Models | `app/Models/*` | Eloquent, escopos de visibilidade e históricos imutáveis. |
| Frontend | `resources/js` | Páginas Inertia, componentes, layouts, composables e `lib/*.ts` com tipos. Detalhes em [07-frontend.md](07-frontend.md). |

## Padrões que se repetem

### 1. Action transacional com bloqueio e reautorização
Quase toda mutação de chamado segue a mesma sequência (ver `ResolveTicket`, `ChangeTicketStatus`, `CloseTicket`, `ReopenTicket`, `RegisterTicketPending`):

1. `DB::transaction`.
2. Relê o chamado com `lockForUpdate()`.
3. Compara com o estado que a tela mostrou ao usuário (`expected`). Se mudou, lança uma exceção de conflito.
4. Confere se a transição é válida no estado atual.
5. **Reautoriza** com `Gate::forUser($editor)->authorize(...)` sobre o estado bloqueado.
6. Grava o chamado e um evento imutável em `ticket_histories`.

Exceção: `UpdateTicketAttributes` não reautoriza dentro da transação (só o `UpdateTicketRequest` autoriza).

### 2. Concorrência otimista nos cadastros
Usuário, departamento, categoria, papel, política de SLA e a edição de atributos do chamado têm um método `editVersion()` (um `sha1` dos campos editáveis). O formulário envia a versão que carregou e a Action compara com a do banco sob `lockForUpdate`. Se difere, a gravação é recusada com mensagem de conflito.

### 3. Históricos e auditoria somente-inserção
`ticket_histories`, `ticket_comment_versions` e `audit_logs` têm só `created_at` (`UPDATED_AT = null`) e `$guarded = ['*']`. Autor e instante vêm do backend, nunca do formulário. As chaves estrangeiras são `restrictOnDelete`.

### 4. Escopo de visibilidade antes de tudo
`Ticket::scopeVisibleTo($user)` é aplicado antes de filtro, ordenação, contagem e paginação, e é reutilizado por listagem, detalhe, dashboard, desempenho e exportação. A policy `view` usa o mesmo escopo.

### 5. Props do Inertia já filtradas
O servidor decide o que enviar. Na visão restrita do solicitante, prioridade, SLA e solicitante nem chegam ao navegador (`TicketController::index/show`). A interface só reflete; o objeto `can` de cada página serve para exibir ou ocultar botões, e o backend revalida.

### 6. Anti-duplo-envio por token
Formulários de criação enviam um UUID gerado ao abrir a tela. `Cache::add` (atômico) garante que só o primeiro envio crie o registro. `SingleSubmission` implementa isso para categorias, departamentos e políticas de SLA. A abertura de chamado repete a mesma lógica em linha dentro de `TicketController::store`.

### 7. Estado de SLA derivado, violação persistida
`Ticket::slaState()` compara os prazos gravados com o instante atual. A primeira violação de cada indicador é gravada em `*_breached_at` e nunca é apagada. `SlaClock` concentra o cálculo.

## Execução em segundo plano

- O único processo recorrente é `sla:record-breaches`, agendado a cada minuto com `withoutOverlapping(5)` (`routes/console.php`). Ele precisa de um agendador externo chamando `php artisan schedule:run`.
- Não existem jobs nem notificações em fila (`ShouldQueue` e `dispatch` não aparecem em `app/`). O serviço `queue` do Docker não tem trabalho a executar.

## Tratamento de erros

`bootstrap/app.php` converte 403 (e, fora de `local`/`testing`, 404, 500 e 503) em uma página Inertia neutra (`Pages/Error.vue`), sem detalhes internos.

## Infraestrutura

- Sessão, cache e fila usam o banco (`SESSION_DRIVER=database`, `CACHE_STORE=database`, `QUEUE_CONNECTION=database`) nos exemplos de ambiente.
- Anexos ficam no disco privado `attachments` (`storage/app/private/attachments`), sem URL pública.
- Fuso horário da aplicação: UTC (`config/app.php`). A exportação CSV declara UTC nos cabeçalhos.
