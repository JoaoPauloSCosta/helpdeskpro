# RBAC e autorização

## Modelo

Usuário → **um papel** (`users.role_id`) → N permissões (`role_permissions`). Papéis e permissões são dados no banco, e a matriz é editável em `/admin/roles/permissions`. O código **não** autoriza pelo nome do papel, com **uma exceção** (exportação CSV), descrita abaixo.

- `User::hasPermission($slug)` lê os slugs do papel, memoizados por instância.
- `User::effectivePermissions()` devolve vazio para usuário inativo. É o que o Inertia compartilha com o navegador (`auth.permissions`) para exibir menus e botões.
- Usuário inativo não executa operações protegidas: todas as policies conferem `is_active`.
- Usuário **sem papel** não abre chamados nem comenta.

## Catálogo de permissões (20)

Fonte: `database/seeders/RolesAndPermissionsSeeder.php` e `app/Models/Permission.php`.

| Grupo | Permissão | Efeito |
|---|---|---|
| Chamados | `tickets.view_all_departments` | Vê chamados de todos os departamentos. |
| | `tickets.assign` / `tickets.reassign` | Atribui chamado sem responsável / troca o responsável. |
| | `tickets.self_assign` | Assume chamado disponível. Também define quem é "técnico elegível". |
| | `tickets.change_status` | Altera status e registra pendências. |
| | `tickets.resolve` / `tickets.close` | Resolve / encerra. Operações separadas. |
| | `tickets.change_priority` / `change_category` / `transfer_department` | Edição de atributos. |
| | `tickets.view_internal_notes` / `add_internal_note` | Lê / escreve notas internas. A primeira também separa a "equipe" do solicitante. |
| Comentários | `comments.edit_others` | Cadastrada, **sem uso no código** (edição não implementada). |
| Auditoria e relatórios | `audit.view` | Auditoria dos chamados acessíveis; com `view_all_departments`, também a auditoria global. |
| | `reports.view_performance` | Painel de desempenho (também exige `view_all_departments`). |
| Administração | `users.manage`, `departments.manage`, `categories.manage`, `roles.manage`, `sla.manage` | Os respectivos cadastros. |

## Matriz inicial

Os papéis criados pelo seeder são `is_system`. A associação inicial só é feita quando o papel é criado, para não desfazer ajustes posteriores.

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

O Administrador **não** recebe as permissões operacionais de chamado por padrão (resolver, encerrar, mudar status, prioridade, categoria, departamento, nota interna). Esta é a matriz do PRD 7.4; o texto do README que diz o contrário está incorreto.

O papel `superadmin` (todas as permissões) é criado só em ambiente `local` pelo `LocalSuperAdminSeeder`.

## Policies

| Policy | Regras relevantes |
|---|---|
| `TicketPolicy` | `viewAny` (ativo); `create` (ativo e com papel); `view` (mesmo escopo da listagem); `comment` (com papel, não Encerrado, `view`); `addInternalNote`; `update` (qualquer dos 3 atributos, não Encerrado); `assign`/`reassign` (estado do responsável + permissão); `selfAssign`; `changeStatus`/`registerPending` (permissão, transição válida e `view`); `resolve`; `reopen` (**solicitante original**, não permissão); `close`; `viewPendingDetails`; `viewAudit`; `viewAttachment`; `viewSla`. |
| `UserPolicy` | `users.manage`. Editar exige que o operador **possa conceder** o papel atual do alvo (`Role::isGrantableBy`). `changeAccess` (papel e situação) não vale para a própria conta. |
| `RolePolicy` | `roles.manage`. A matriz só pode ser salva por quem a consulta; ninguém remove `roles.manage` do próprio papel. |
| `DepartmentPolicy`, `TicketCategoryPolicy`, `SlaPolicyPolicy`, `PermissionPolicy` | Uma permissão cada. `SlaPolicyPolicy::viewHistory` também exige `audit.view`. |
| `AuditLogPolicy` | `audit.view` **e** `tickets.view_all_departments`. Somente leitura. |
| Gate `viewPerformance` | `reports.view_performance` e `tickets.view_all_departments`. |

## Regras especiais

### Prevenção de elevação de privilégio
`Role::isGrantableBy($operator)`: o operador só concede um papel se possuir todas as permissões **administrativas** que ele contém (`Permission::ADMINISTRATIVE`: `users.manage`, `departments.manage`, `categories.manage`, `roles.manage`, `sla.manage`, `audit.view`, `reports.view_performance`). Permissões operacionais ficam de fora, pois o Administrador não as recebe e ainda assim cadastra técnicos. A mesma regra impede que um operador menos privilegiado troque o e-mail de um administrador e assuma a conta pela redefinição de senha (`UserPolicy::update`).

### Trava contra bloqueio
`UpdateRolePermissions` impede remover `roles.manage` do papel do próprio operador.

### Reabertura por propriedade
Reabrir é do **solicitante original**, mesmo que ele não tenha nenhuma permissão. Técnicos, gestores e administradores não reabrem chamados de outras pessoas por esse fluxo.

### Exportação CSV por papel
`TicketExportController::ALLOWED_ROLES = ['admin', 'superadmin']`. É a única autorização por nome de papel. O PRD (RF-21) fala em "filtros e permissões", e não há uma permissão de exportação no catálogo. Consequências: papéis personalizados e o Gestor não exportam, e em produção só o `admin` exporta, pois `superadmin` só existe em `local`. O README descreve isso como regra intencional (commit `c717ad5`).

### Cadastro público
`RegisteredUserController` atribui sempre o papel `requester`; papel e departamento não vêm da requisição.

## Onde a autorização acontece

1. **Rota**: `auth` e `verified` (este último não bloqueia, ver [09-seguranca.md](09-seguranca.md)).
2. **Controller**: `Gate::authorize(...)` ou o `authorize()` do Form Request.
3. **Action**: reautoriza sobre a linha bloqueada (exceto `UpdateTicketAttributes`).
4. **Consulta**: o escopo `visibleTo` filtra no SQL, inclusive para listas auxiliares (solicitantes e responsáveis dos filtros vêm só dos chamados visíveis).
5. **Interface**: o objeto `can` e `auth.permissions` só controlam a exibição.

## Matriz padrão × diagrama de casos de uso

A matriz acima é a **padrão do seeder** [SEED]. O diagrama de casos de uso do projeto a reproduz, e a conferência de 27/10/2026 encontrou concordância em todas as permissões, com estas exceções (detalhes em [casos-de-uso.md](../casos-de-uso.md), seção 9):

- **Auditoria global:** `audit.view` não basta; é preciso também `tickets.view_all_departments`. O Técnico consulta só a auditoria dos chamados que acessa.
- **Painel de desempenho:** também exige `tickets.view_all_departments`.
- **Visão geral:** só para quem tem `tickets.view_internal_notes`; o Solicitante é redirecionado.
- **"Equipe de atendimento":** no diagrama é um ator abstrato de Técnico e Gestor. No código é a permissão `tickets.view_internal_notes`, que o Administrador também tem.

**Configurável em execução.** O Administrador pode mudar as permissões de qualquer papel (PER-04), então o que vale numa instalação é o conteúdo de `role_permissions`, não esta tabela. Não alteram com a matriz: a lista de papéis que exportam CSV e a reabertura por propriedade do chamado.

## Pontos a conferir

- Login de usuário inativo: o código de autenticação não consulta `is_active`. As policies negam as telas, mas não há teste cobrindo o login.
- A permissão `comments.edit_others` não é verificada em nenhum ponto do código.
