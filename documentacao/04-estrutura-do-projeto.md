# Estrutura do projeto

> Atualizado em 27/10/2026 (commit `bdb7667`). Convenção de confiança em [README.md](README.md). A listagem de arquivos foi feita no repositório; a descrição de cada classe vem de leitura do código e dos comentários.

## Diretórios de primeiro nível

| Caminho | Conteúdo |
|---|---|
| `app/` | Código PHP da aplicação (detalhado abaixo). |
| `bootstrap/` | `app.php`: roteamento, middleware e tratamento de exceções. |
| `config/` | Configuração do Laravel e `attachments.php` (limites de anexos). |
| `database/` | 18 migrations, 3 seeders e 6 factories. |
| `docker/` | `entrypoint.sh`, `php.ini` e `php.dev.ini`. |
| `docs/` | PRD, regras de negócio, QA e pendências do projeto. No `.gitignore`. |
| `documentacao/` | Esta documentação. |
| `lang/` | Arquivos de idioma (conteúdo não inspecionado nesta análise). |
| `public/` | Ponto de entrada web e `build/` (assets gerados pelo Vite). |
| `resources/` | `js/` (Vue + TypeScript), `views/app.blade.php`, CSS. |
| `routes/` | `web.php`, `auth.php`, `console.php`. |
| `storage/` | Logs, cache, sessões e anexos (`app/private/attachments`). |
| `tests/` | Suíte Pest (`Unit/` e `Feature/`). |
| Raiz | `Dockerfile`, `docker-compose.yml`, `docker-compose.override.yml`, `composer.json`, `package.json`, `phpunit.xml`, `vite.config.js`, `tailwind.config.js`, `tsconfig.json`, `README.md`, `TESTES.md`. |

## `app/`

### Controllers (`app/Http/Controllers`)

| Classe | Responsabilidade |
|---|---|
| `TicketController` | Listagem (`index`), formulário e criação (`create`, `store`) e detalhe (`show`). Monta as props filtradas pela visão restrita. |
| `TicketEditController` | Edição de prioridade, categoria e departamento. |
| `TicketAssignmentController`, `TicketSelfAssignmentController` | Atribuir/reatribuir e autoatribuir. |
| `TicketStatusController`, `TicketPendingController`, `TicketResolutionController`, `TicketReopeningController`, `TicketClosingController` | Uma ação do ciclo de vida cada. |
| `TicketCommentController` | Comentários públicos e notas internas. |
| `TicketCommentVersionController`, `TicketSlaHistoryController`, `TicketAuditController` | Consultas JSON sob demanda. |
| `TicketAttachmentController` | Download autorizado de anexo. |
| `TicketExportController` | Exportação CSV (autoriza por papel). |
| `DashboardController`, `PerformanceController`, `AuditController` | Indicadores e auditoria global. |
| `UserController`, `DepartmentController`, `CategoryController`, `RoleController`, `RolePermissionController`, `PermissionController`, `SlaPolicyController` | Administração. |
| `ProfileController` | Perfil e exclusão da própria conta. |
| `Auth/*` (9 controllers) | Login, logout, cadastro, recuperação e troca de senha, verificação de e-mail, confirmação de senha. |

`app/Http/Middleware/HandleInertiaRequests.php` compartilha com todas as páginas o usuário, `auth.permissions` e as mensagens `flash`.

### Form Requests (`app/Http/Requests`)

Um por tela ou ação, agrupados por módulo (`Tickets/`, `Users/`, `Roles/`, `Departments/`, `Categories/`, `SlaPolicies/`, `Permissions/`, `Audit/`, `Auth/`). Fazem validação, mensagens em português e `authorize()`. `Concerns/NormalizesListingFilters` normaliza filtros de listagem; `Concerns/Attachments` reúne as regras do campo `attachments`.

### Actions (`app/Actions`)

| Pasta | Classes | Observação |
|---|---|---|
| `Tickets/` | `OpenTicket`, `AssignTicket`, `SelfAssignTicket`, `ChangeTicketStatus`, `RegisterTicketPending`, `ResolveTicket`, `ReopenTicket`, `CloseTicket`, `UpdateTicketAttributes`, `StoreTicketAttachments`, `EditTicketComment` | `EditTicketComment` só é usada em testes. |
| `Tickets/` (exceções) | `TicketStatusConflict`, `TicketAssignmentConflict`, `TicketEditConflict`, `TicketNotSelfAssignable`, `TicketNumberUnavailable` | Conflitos de estado esperado e de unicidade. |
| `Users/` | `CreateUser`, `UpdateUser`, `UserEditConflict` | |
| `Roles/` | `CreateRole`, `UpdateRole`, `UpdateRolePermissions`, `RoleEditConflict`, `RolePermissionsConflict`, `PermissionsNotGrantable` | |
| `Departments/`, `Categories/` | `Create*`, `Update*`, `*EditConflict` | |
| `SlaPolicies/` | `CreateSlaPolicy`, `UpdateSlaPolicy`, `SlaPolicyEditConflict`, `ActiveSlaPolicyConflict` | |

### Models (`app/Models`)

| Model | Papel |
|---|---|
| `User` | Papel e departamento, `is_active`, `hasPermission`, `effectivePermissions`, `hasRestrictedTicketView`, `eligibleAssignees`, `editVersion`. Herda a trait `MustVerifyEmail` da classe base e não implementa a interface (o `use` dela está comentado). |
| `Role`, `Permission` | RBAC; `Role::isGrantableBy`; `Permission::ADMINISTRATIVE`. |
| `Department`, `TicketCategory` | Cadastros com inativação. |
| `Ticket` | Núcleo do domínio: `scopeVisibleTo`, `slaState`, `scopeWhereSlaState`, `getRouteKeyName` (número público). |
| `TicketComment`, `TicketCommentVersion` | Mensagens e versões anteriores (somente-inserção). |
| `TicketAttachment` | Anexos (sem coluna de visibilidade). |
| `TicketHistory` | Eventos do chamado (somente-inserção). |
| `TicketSlaPause` | Períodos de pausa do SLA. |
| `SlaPolicy` | Metas por prioridade. |
| `AuditLog` | Eventos administrativos (somente-inserção). |
| `Concerns/HasAuditLogs` | Relação polimórfica com `audit_logs`. |

### Policies, Rules, Notifications, Console e Providers

| Caminho | Responsabilidade |
|---|---|
| `app/Policies` | `TicketPolicy`, `UserPolicy`, `RolePolicy`, `DepartmentPolicy`, `TicketCategoryPolicy`, `SlaPolicyPolicy`, `PermissionPolicy`, `AuditLogPolicy`. Descobertas automaticamente pelo Laravel. |
| `app/Rules/ValidAttachment.php` | Valida tamanho, extensão e conteúdo de um anexo. |
| `app/Notifications/UserInvitation.php` | E-mail de definição de senha enviado no convite. |
| `app/Console/Commands/RecordSlaBreaches.php` | Comando `sla:record-breaches`. |
| `app/Providers/AppServiceProvider.php` | Define o Gate `viewPerformance`. |

### Support e Enums

| Classe | Responsabilidade |
|---|---|
| `Support/SlaClock` | Cálculo de prazos, pausas, recálculo e registro de violações. |
| `Support/TicketListingQuery` | Filtros, pesquisa (com escape de `%` e `_`) e ordenação da listagem e da exportação. |
| `Support/TicketCsvExport` | Geração do CSV em blocos, com neutralização de fórmulas. |
| `Support/AttachmentInspector` | Verificações por tipo de arquivo (PDF, Office, texto, imagem). |
| `Support/PerformanceReport` | Agregação do painel de desempenho. |
| `Support/SingleSubmission`, `SubmissionInProgress` | Anti-duplo-envio por token; a segunda é a exceção "primeiro envio ainda em processamento". |
| `Support/TicketNumberGenerator` | Gera `HD-` mais 8 caracteres. |
| `Enums/TicketStatus` | Oito status e `transitions()`. |
| `Enums/TicketPriority`, `SlaState`, `CommentVisibility`, `TicketTransitionFlow` | Prioridade, estado de SLA, visibilidade e fluxo da transição. |
| `Enums/TicketEvent`, `AdminEvent` | 17 tipos de evento de chamado e 19 de evento administrativo. |

## `resources/js`

| Pasta | Conteúdo |
|---|---|
| `app.ts` | `createInertiaApp`, resolve páginas por `import.meta.glob`, instala `ZiggyVue`. |
| `Layouts/` | `AuthenticatedLayout`, `AuthLayout`, `GuestLayout`. |
| `Pages/` | Uma página por tela: `Auth/`, `Tickets/` (`Index`, `Create`, `Edit`, `Show` e 10 componentes de apoio em `Partials/`: 7 usam `<Modal`; `AuditHistory` e `SlaHistory` não são modais, e `SelfAssignModal` não foi verificado), `Dashboard`, `Performance/`, `Admin/` (usuários, departamentos, categorias, papéis, permissões), `SlaPolicies/`, `Audit/`, `Profile/` (5 parciais), `Error`, `DesignSystem`. |
| `Components/` | 54 componentes reutilizáveis. Dois herdados do Breeze não têm uso (`NavLink` e `ResponsiveNavLink`); `DangerButton` e `SecondaryButton` só aparecem em `Pages/DesignSystem.vue`. |
| `composables/` | `useListing` (pesquisa, filtros, ordenação e paginação no servidor, sincronizados com a URL), `useLeaveGuard` (confirma saída com alterações), `useToast`. |
| `lib/` | Tipos e utilitários por módulo (`ticket.ts`, `sla.ts`, `dashboard.ts`, `navigation.ts`, `attachments.ts`...). |
| `types/` | Declarações globais. |

Varredura (comprovada) em `.vue` e `.ts`: nenhum `v-html` ou `innerHTML`; nenhum `localStorage`, `sessionStorage`, `setInterval` ou `console.log`; um único `any` (`Components/Checkbox.vue`). Páginas grandes e modais foram lidos só em parte.

## `database/` e `tests/`

- Migrations, seeders e esquema: [06-banco-de-dados.md](06-banco-de-dados.md).
- Testes: [08-testes-e-qualidade.md](08-testes-e-qualidade.md).
