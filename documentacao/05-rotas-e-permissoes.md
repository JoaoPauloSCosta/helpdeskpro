# Rotas e permissões

> Atualizado em 27/10/2026 (commit `bdb7667`). Convenção de confiança em [README.md](README.md).

Fontes: `routes/web.php`, `routes/auth.php`, `routes/console.php`, `app/Policies/*`, `database/seeders/RolesAndPermissionsSeeder.php`. A contagem vem de `php artisan route:list` (27/10/2026, ambiente local): **71 rotas**, sendo 55 em `web.php`, 15 em `auth.php` e `/design-system`, que só existe em `local`. Por método: 40 GET, 19 POST, 7 PATCH, 4 PUT e 1 DELETE.

## 1. Middlewares

| Grupo | Middleware | Onde |
|---|---|---|
| Convidado | `guest` | Cadastro, login, recuperação e redefinição de senha (`routes/auth.php`). |
| Autenticado | `auth` | Verificação de e-mail, confirmação de senha, troca de senha, logout (`auth.php`) e `/profile` (`web.php`). |
| Aplicação | `auth` e `verified` | `/dashboard` e todas as demais rotas de `web.php`. **O `verified` não bloqueia** (ver T5 em [10-debitos-tecnicos.md](10-debitos-tecnicos.md)). |
| Limites | `throttle:60,1` | Download de anexo. |
| Limites | `signed` e `throttle:6,1` | Link de verificação de e-mail; reenvio do link usa só `throttle:6,1`. |
| Global (grupo `web`) | `HandleInertiaRequests`, `AddLinkHeadersForPreloadedAssets` | `bootstrap/app.php`. |

Não há rota usando `password.confirm`. O login tem limitador de 5 tentativas por e-mail e IP, aplicado em `LoginRequest`, e não por middleware. O cadastro não tem limitador próprio.

## 2. Mapa de rotas

### Públicas e autenticação

| Método e caminho | Controller | Observação |
|---|---|---|
| `GET /` | closure | Redireciona para `dashboard` ou `login`. |
| `GET/POST /register` | `RegisteredUserController` | Cria sempre um Solicitante e já autentica. |
| `GET/POST /login` | `AuthenticatedSessionController` | Limite de 5 tentativas. |
| `GET/POST /forgot-password`, `GET /reset-password/{token}`, `POST /reset-password` | `PasswordResetLinkController`, `NewPasswordController` | Resposta sempre igual, exista a conta ou não. |
| `GET /verify-email`, `GET /verify-email/{id}/{hash}`, `POST /email/verification-notification` | Auth | Link assinado, throttle 6/min. |
| `GET/POST /confirm-password` | `ConfirmablePasswordController` | Sem uso por outras rotas. |
| `PUT /password`, `POST /logout` | Auth | Troca de senha exige a atual. |

### Chamados

Todas com `auth` e `verified`. A coluna "Autorização" resume a policy ou permissão aplicada.

| Método e caminho | Controller | Autorização |
|---|---|---|
| `GET /dashboard` | `DashboardController` | `viewAny` em `Ticket`; solicitante é redirecionado a `/tickets`. |
| `GET /tickets` | `TicketController@index` | `viewAny` e escopo `visibleTo`. |
| `GET /tickets/export` | `TicketExportController` | Papel `admin` ou `superadmin`. Declarada antes de `/tickets/{ticket}`. |
| `GET /tickets/create`, `POST /tickets` | `TicketController` | `create`. |
| `GET /tickets/{ticket}` | `TicketController@show` | `view`. |
| `GET /tickets/{ticket}/edit`, `PATCH /tickets/{ticket}` | `TicketEditController` | `update` e permissão por campo. |
| `PUT /tickets/{ticket}/assignee` | `TicketAssignmentController` | `assign` ou `reassign`. |
| `POST /tickets/{ticket}/self-assignment` | `TicketSelfAssignmentController` | Técnico elegível. |
| `PUT /tickets/{ticket}/status` | `TicketStatusController` | `tickets.change_status`. |
| `POST /tickets/{ticket}/pending` | `TicketPendingController` | `tickets.change_status`. |
| `POST /tickets/{ticket}/resolution` | `TicketResolutionController` | `tickets.resolve`. |
| `POST /tickets/{ticket}/reopening` | `TicketReopeningController` | Solicitante original. |
| `POST /tickets/{ticket}/closing` | `TicketClosingController` | `tickets.close`. |
| `POST /tickets/{ticket}/comments` | `TicketCommentController` | `comment` ou `addInternalNote`. |
| `GET /tickets/{ticket}/comments/{comment}/versions` | `TicketCommentVersionController` | `view` e filtro de visibilidade. JSON. |
| `GET /tickets/{ticket}/attachments/{attachment}` | `TicketAttachmentController` | `view` e `viewAttachment`; throttle 60/min. |
| `GET /tickets/{ticket}/sla-history` | `TicketSlaHistoryController` | `viewSla` (fora da visão restrita). JSON. |
| `GET /tickets/{ticket}/audit` | `TicketAuditController` | `audit.view` e acesso ao chamado. JSON paginado. |

### Administração e consulta

Todas com `auth` e `verified`.

| Caminho | Controller | Permissão |
|---|---|---|
| `/admin/users` (index, create, store, edit, update) | `UserController` | `users.manage` |
| `/admin/departments` (5 rotas) | `DepartmentController` | `departments.manage` |
| `/admin/categories` (5 rotas) | `CategoryController` | `categories.manage` |
| `/admin/roles` (5 rotas) | `RoleController` | `roles.manage` |
| `GET /admin/roles/permissions`, `PUT /admin/roles/{role}/permissions` | `RolePermissionController` | `roles.manage` e `RolePolicy::managePermissions` |
| `GET /admin/permissions` | `PermissionController` | `roles.manage` (somente consulta) |
| `GET /performance` | `PerformanceController` | Gate `viewPerformance` |
| `GET /audit` | `AuditController` | `audit.view` e `tickets.view_all_departments` |
| `/sla-policies` (index, create, store, show, edit, update) | `SlaPolicyController` | `sla.manage` |

### Perfil e desenvolvimento

- `GET/PATCH/DELETE /profile` em `ProfileController`, somente com `auth`.
- `GET /design-system`: só registrada quando `app()->environment('local')`.

### Cuidados de roteamento

- Rotas fixas (`export`, `create`) vêm antes das com parâmetro; as de identificador numérico usam `whereNumber`.
- Nas telas de edição, a entidade só é carregada **depois** da autorização geral, para não revelar identificadores.
- A URL pública do chamado usa o `ticket_number`, não o `id`.

## 3. Modelo de autorização

Usuário → **um papel** (`users.role_id`) → N permissões (`role_permissions`). Papéis e permissões são dados no banco, e a matriz é editável em `/admin/roles/permissions`. O código **não** autoriza pelo nome do papel, com uma exceção: a exportação CSV.

- `User::hasPermission($slug)` lê os slugs do papel, memoizados por instância.
- `User::effectivePermissions()` devolve vazio para usuário inativo; é o que o Inertia compartilha (`auth.permissions`) para exibir menus e botões.
- Todas as policies conferem `is_active`. Usuário **sem papel** não abre chamados nem comenta.

### Onde a autorização acontece

1. **Rota:** `auth` e `verified`.
2. **Controller:** `Gate::authorize(...)` ou o `authorize()` do Form Request.
3. **Action:** reautoriza sobre a linha bloqueada (exceto `UpdateTicketAttributes`).
4. **Consulta:** o escopo `visibleTo` filtra no SQL, inclusive listas auxiliares.
5. **Interface:** `can` e `auth.permissions` só controlam a exibição.

## 4. Catálogo de permissões (20)

Fonte: `RolesAndPermissionsSeeder` e `app/Models/Permission.php`.

| Grupo | Permissão | Efeito |
|---|---|---|
| Chamados | `tickets.view_all_departments` | Vê chamados de todos os departamentos. |
| | `tickets.assign` / `tickets.reassign` | Atribui chamado sem responsável / troca o responsável. |
| | `tickets.self_assign` | Assume chamado disponível; define o "técnico elegível". |
| | `tickets.change_status` | Altera status e registra pendências. |
| | `tickets.resolve` / `tickets.close` | Resolve / encerra. |
| | `tickets.change_priority` / `change_category` / `transfer_department` | Edição de atributos. |
| | `tickets.view_internal_notes` / `add_internal_note` | Lê / escreve notas internas; a primeira separa a "equipe" do solicitante. |
| Comentários | `comments.edit_others` | Cadastrada, **sem uso no código**. |
| Auditoria e relatórios | `audit.view` | Auditoria dos chamados acessíveis; com `view_all_departments`, também a global. |
| | `reports.view_performance` | Painel de desempenho (também exige `view_all_departments`). |
| Administração | `users.manage`, `departments.manage`, `categories.manage`, `roles.manage`, `sla.manage` | Os respectivos cadastros. |

## 5. Matriz inicial (seeder)

Os papéis do seeder são `is_system`. A associação inicial só é feita quando o papel é criado, para não desfazer ajustes posteriores. **Não representa as permissões de uma instalação em uso.**

| Permissão | Solicitante | Técnico | Gestor | Administrador |
|---|:-:|:-:|:-:|:-:|
| `view_all_departments` | | | ✔ | ✔ |
| `assign`, `reassign` | | | ✔ | ✔ |
| `self_assign` | | ✔ | | |
| `change_status` | | ✔ | ✔ | |
| `resolve` | | ✔ | | |
| `close` | | | ✔ | |
| `change_priority`, `change_category` | | ✔ | ✔ | |
| `transfer_department` | | | ✔ | |
| `view_internal_notes` | | ✔ | ✔ | ✔ |
| `add_internal_note` | | ✔ | ✔ | |
| `comments.edit_others` | | | | |
| `audit.view` | | ✔ | ✔ | ✔ |
| `reports.view_performance` | | | ✔ | ✔ |
| `users.manage`, `departments.manage`, `categories.manage`, `roles.manage`, `sla.manage` | | | | ✔ |

O Administrador **não** recebe as permissões operacionais de chamado por padrão. Esta é a matriz 7.4 do PRD; o trecho do `README.md` que diz o contrário está incorreto (T27 em [10-debitos-tecnicos.md](10-debitos-tecnicos.md)). O papel `superadmin` (todas as permissões) é criado só em ambiente `local`, por `LocalSuperAdminSeeder`.

Duas migrations de dados completam o seeder em bancos já existentes: `grant_audit_view_to_technician_role` e `add_performance_report_permission`.

## 6. Policies

| Policy | Regras relevantes |
|---|---|
| `TicketPolicy` | `viewAny` (ativo); `create` (ativo e com papel); `view` (mesmo escopo da listagem); `comment` (com papel, não Encerrado, `view`); `addInternalNote`; `update` (qualquer dos 3 atributos, não Encerrado); `assign`/`reassign` (estado do responsável + permissão); `selfAssign`; `changeStatus`/`registerPending` (permissão, transição válida e `view`); `resolve`; `reopen` (**solicitante original**, não permissão); `close`; `viewPendingDetails`; `viewAudit`; `viewAttachment`; `viewSla`. |
| `UserPolicy` | `users.manage`. Editar exige que o operador **possa conceder** o papel atual do alvo (`Role::isGrantableBy`). `changeAccess` não vale para a própria conta. |
| `RolePolicy` | `roles.manage`. A matriz só pode ser salva por quem a consulta; ninguém remove `roles.manage` do próprio papel. |
| `DepartmentPolicy`, `TicketCategoryPolicy`, `SlaPolicyPolicy`, `PermissionPolicy` | Uma permissão cada. `SlaPolicyPolicy::viewHistory` também exige `audit.view`. |
| `AuditLogPolicy` | `audit.view` **e** `tickets.view_all_departments`. Somente leitura. |
| Gate `viewPerformance` | `reports.view_performance` e `tickets.view_all_departments`. |

## 7. Regras especiais

- **Prevenção de elevação de privilégio.** `Role::isGrantableBy($operator)`: o operador só concede um papel se possuir todas as permissões **administrativas** que ele contém (`users.manage`, `departments.manage`, `categories.manage`, `roles.manage`, `sla.manage`, `audit.view`, `reports.view_performance`). Permissões operacionais ficam de fora, pois o Administrador não as recebe e ainda assim cadastra técnicos. A mesma regra impede que um operador menos privilegiado troque o e-mail de um administrador e assuma a conta pela redefinição de senha.
- **Trava contra bloqueio.** `UpdateRolePermissions` impede remover `roles.manage` do papel do próprio operador.
- **Reabertura por propriedade.** Reabrir é do solicitante original, mesmo sem nenhuma permissão. Técnicos, gestores e administradores não reabrem chamados de outras pessoas por esse fluxo.
- **Exportação CSV por papel.** `TicketExportController::ALLOWED_ROLES = ['admin', 'superadmin']`. É a única autorização por nome de papel. Gestor e papéis personalizados não exportam; em produção só o `admin` exporta, pois `superadmin` só existe em `local`. O README descreve isso como intencional (commit `c717ad5`); o PRD fala em "filtros e permissões".
- **Cadastro público.** Atribui sempre o papel `requester`; papel e departamento não vêm da requisição.

## 8. Matriz padrão × diagrama de casos de uso

A matriz do diagrama concorda com a do seeder, exceto: **auditoria global** (exige também `view_all_departments`; o Técnico só vê a do chamado), **painel de desempenho** (idem), **visão geral** (só com `view_internal_notes`; o Solicitante é redirecionado) e **"Equipe de atendimento"** (ator abstrato no diagrama; permissão `view_internal_notes` no código, que o Administrador também tem). Detalhes e demais divergências (D1 a D9) em [casos-de-uso.md](casos-de-uso.md).

Como o Administrador pode mudar as permissões de qualquer papel (PER-04), o que vale numa instalação é o conteúdo de `role_permissions`. Não mudam com a matriz: a lista de papéis que exportam CSV e a reabertura por propriedade.

## 9. Agendamento

`routes/console.php` agenda `sla:record-breaches` a cada minuto, sem sobreposição. O código só agenda; quem executa `php artisan schedule:run` depende do ambiente (**pendente de confirmação** por instalação).
