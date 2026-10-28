# Casos de uso

> Atualizado em 27/10/2026 (commit `bdb7667`). Convenção de confiança em [README.md](README.md); as marcas deste documento estão na legenda da seção 1.

Este documento integra a referência funcional de casos de uso ao código real do Help Desk Pro. A referência é o diagrama UML do responsável pelo sistema; aqui cada elemento dele é ligado aos arquivos que o implementam, e as diferenças comprovadas são registradas.

## 1. Objetivo e escopo

**Objetivo do sistema.** Gerenciar chamados de suporte técnico: abertura, atendimento, controle de prazos (SLA), histórico, auditoria e indicadores, com acesso por papéis e permissões. Visão geral em [01-visao-geral.md](01-visao-geral.md).

**Escopo deste documento.** Os 30 casos de uso do diagrama, seus atores, a matriz padrão de permissões e as regras de autorização. Não cobre fluxos alternativos, critérios de aceite ou regras que não estejam na referência nem no código.

### Fontes

| Fonte | Arquivo | Observação |
|---|---|---|
| Referência funcional (diagrama e matriz) | `docs/Casos-de-Uso-Help-Desk-Pro.html` | O arquivo "Help Desk Pro · Casos de Uso.html" fornecido tem o mesmo conteúdo; só falta ao do repositório o invólucro `<html>/<body>`. Marcado "atualizado em 27/10/2026". |
| Diagrama editável | `docs/diagrama-casos-de-uso.puml` | Atores, associações, generalizações e `«extend»` conferidos um a um contra o desenho do HTML: estão sincronizados. O PlantUML não foi renderizado. |
| Código | `routes/`, `app/Policies`, `app/Http`, `app/Actions`, `app/Support`, `database/seeders` | Lido na auditoria de 27/10/2026 (último commit: `bdb7667`). |
| Requisitos | `docs/prd-rn.md` (v1.8) | Usado para conferir RF-11, RF-20, RF-21, RF-24 e a matriz 7.4. |

Os arquivos de `docs/` não foram alterados. `docs/` está no `.gitignore`, então a referência e o `.puml` não são versionados.

### Legenda de rastreabilidade

| Marca | Significado |
|---|---|
| **[REF]** | Afirmado na referência funcional (HTML e `.puml`). |
| **[CÓDIGO]** | Confirmado lendo o código. |
| **[SEED]** | Configuração inicial definida por `RolesAndPermissionsSeeder`. Não vale necessariamente para uma instalação em uso. |
| **[CONFIG]** | Pode mudar em execução (matriz de permissões editável). |
| **[NÃO CONFIRMADO]** | Não foi possível confirmar no código ou depende do ambiente. |
| **[DIVERGÊNCIA]** | A referência e o código diferem. Detalhes na seção 9. |

Presença no diagrama **não** significa que a funcionalidade foi testada em uso real. A suíte automatizada (1.486 testes, executada em 27/10/2026) cobre grande parte, mas não os pontos listados em [08-testes-e-qualidade.md](08-testes-e-qualidade.md).

## 2. Atores

Terminologia preservada da referência.

| Ator | Tipo | Responsabilidade [REF] | Como o código o identifica |
|---|---|---|---|
| Visitante | Concreto | Cadastrar-se, entrar e recuperar a senha. | Sem sessão. Rotas com middleware `guest` (`routes/auth.php`). [CÓDIGO] |
| Usuário autenticado | **Abstrato** | Casos de uso comuns de chamados e do próprio perfil; herdados por todos os outros usuários. | Sessão válida (`auth`). Autorização por policy, não por nome de papel. [CÓDIGO] |
| Solicitante | Concreto | Reabrir chamado resolvido. Abre e acompanha chamados. | Papel `requester`, sem permissões. [SEED] |
| Equipe de atendimento | **Abstrato** | Alterar status, registrar pendência, nota interna, prioridade e categoria, consultar auditoria. | Não é um papel no código. A noção equivalente é a permissão `tickets.view_internal_notes` (ver D6). [DIVERGÊNCIA] |
| Técnico | Concreto | Assumir e resolver chamados, além do que herda de Equipe. | Papel `technician`. [SEED] |
| Gestor | Concreto | Atribuir, transferir departamento, encerrar, consultar desempenho. | Papel `manager`. [SEED] |
| Administrador | Concreto | Atribuir, auditar, consultar desempenho, exportar CSV e administrar cadastros, papéis e SLA. | Papel `admin`. [SEED] |
| Servidor de e-mail | Sistema | Entrega o convite e a recuperação de senha. | Notificações `UserInvitation` e `ResetPassword`; e-mail configurado em `MAIL_*`. [CÓDIGO] |
| Agendador | Sistema | Dispara o registro de violações de SLA. | `Schedule::command('sla:record-breaches')` em `routes/console.php`. [CÓDIGO] |

**Hierarquia [REF], idêntica no `.puml`:** Solicitante, Equipe de atendimento e Administrador especializam Usuário autenticado; Técnico e Gestor especializam Equipe de atendimento.

**Papel `superadmin`.** Existe só em ambiente `local` (`LocalSuperAdminSeeder`), com todas as permissões, e por isso não aparece no diagrama. [CÓDIGO] [REF]

## 3. Casos de uso por módulo

Cada tabela traz: o caso de uso, os atores segundo a referência, a regra confirmada no código com seus arquivos e as observações. "Pré-condições" e "resultados" listados são as que o código impõe ou produz; nenhuma foi inventada.

### 3.1 Acesso

| Caso de uso | Atores [REF] | Confirmado no código [CÓDIGO] | Arquivos |
|---|---|---|---|
| Cadastrar-se | Visitante | **Pré-condições:** sem sessão; departamento ativo; senha dentro de `Password::defaults()`. **Resultado:** cria usuário com papel `requester` (nunca vindo da requisição), dispara `Registered` e autentica o usuário, redirecionando ao dashboard. Não gera evento de auditoria e não tem rate limit próprio. | `RegisteredUserController`, `routes/auth.php` |
| Entrar no sistema | Visitante | Máx. 5 tentativas por e-mail+IP; sessão regenerada. O login **não** consulta `is_active` (ver pendências). | `AuthenticatedSessionController`, `LoginRequest` |
| Recuperar senha | Visitante, Servidor de e-mail | Resposta idêntica exista a conta ou não; o e-mail com o link é enviado pelo broker de senhas. | `PasswordResetLinkController`, `NewPasswordController` |

A referência liga o Servidor de e-mail a "Recuperar senha" e a "Enviar convite", **não** a "Cadastrar-se". Isso é coerente com o código: o cadastro não envia e-mail de verificação, porque o `User` não implementa `MustVerifyEmail`.

### 3.2 Chamados

| Caso de uso | Atores [REF] | Confirmado no código [CÓDIGO] | Arquivos |
|---|---|---|---|
| Consultar visão geral | Usuário autenticado | **Divergência D1.** Só quem tem `tickets.view_internal_notes`; os demais são redirecionados à listagem. Os indicadores respeitam o escopo `visibleTo` e o período. | `DashboardController`, `lib/navigation.ts` |
| Listar e filtrar chamados | Usuário autenticado | Exige usuário ativo. O escopo `visibleTo` vale antes de filtro, ordenação, contagem e paginação. Filtros de prioridade, SLA e solicitante são proibidos na visão restrita. | `TicketController::index`, `TicketIndexRequest`, `TicketListingQuery`, `Ticket::scopeVisibleTo` |
| Abrir chamado | Usuário autenticado | **Pré-condições:** usuário ativo, com papel e com departamento ativo; categoria ativa. **Resultado:** chamado com identificador `HD-XXXXXXXX`, status Aberto, prioridade Média, departamento do solicitante, política de SLA da prioridade (se houver) e evento `Created`; redireciona ao detalhe. Repetição do envio não duplica. | `StoreTicketRequest`, `OpenTicket`, `TicketController::store`, `TicketPolicy::create` |
| Anexar arquivos (`«extend»` de Abrir e Comentar) | Usuário autenticado | Opcional. Até 5 arquivos e 20 MB por envio, 10 MB por arquivo, 20 arquivos e 100 MB por chamado. Tipo conferido pelo conteúdo. Evento `AttachmentAdded`. | `Attachments`, `ValidAttachment`, `AttachmentInspector`, `StoreTicketAttachments`, `config/attachments.php` |
| Comentar chamado | Usuário autenticado | **Pré-condições:** papel; chamado visível e **não Encerrado**. Nota interna é outro caso (3.3). **Resultado:** comentário público; o primeiro de alguém com `view_internal_notes` que não seja o solicitante registra a primeira resposta do SLA. | `StoreTicketCommentRequest`, `TicketCommentController`, `TicketPolicy::comment` |
| Consultar detalhes do chamado | Usuário autenticado | Exige `view` (mesmo escopo da listagem). Notas internas, anexos delas, versões e dados de pendência só chegam a quem tem `view_internal_notes`. Três consultas JSON sob demanda: versões do comentário, histórico de SLA e auditoria do chamado. | `TicketController::show`, `TicketCommentVersionController`, `TicketSlaHistoryController`, `TicketAuditController` |
| Baixar anexo | Usuário autenticado | **Divergência D8.** Autorizado a cada requisição; negado vira 404. Throttle de 60 por minuto. | `TicketAttachmentController`, `TicketPolicy::viewAttachment` |
| Reabrir chamado resolvido | Solicitante | **Divergência D5.** Pré-condições: status Resolvido e usuário ser o solicitante original, com justificativa. **Resultado:** status Aberto, prazo de resolução estendido pelo tempo em Resolvido, evento `Reopened`. | `ReopenTicketRequest`, `ReopenTicket`, `TicketPolicy::reopen` |
| Gerenciar perfil e senha | Usuário autenticado | Editar nome e e-mail (alterar o e-mail zera a verificação); trocar a senha, que exige a senha atual e confirmação; e **excluir a própria conta** com a senha (este último não está no diagrama). Rotas só com `auth`. | `ProfileController`, `ProfileUpdateRequest`, `PasswordController` |

### 3.3 Atendimento

| Caso de uso | Atores [REF] | Regra [CÓDIGO] e pré-condições | Arquivos |
|---|---|---|---|
| Assumir chamado | Técnico | **Divergência D7.** Técnico ativo com `tickets.self_assign`; chamado Aberto e sem responsável, visível. Resultado: responsável = o técnico e status Em atendimento, em uma transação; quem perde a disputa recebe mensagem neutra. Evento `SelfAssigned`. | `TicketSelfAssignmentController`, `SelfAssignTicket`, `TicketPolicy::selfAssign` |
| Resolver chamado | Técnico | `tickets.resolve`; status Em atendimento; sem pausa aberta; resumo da solução. Resultado: Resolvido, `resolved_at`, tempo efetivo de resolução, evento `Resolved`. | `ResolveTicketRequest`, `ResolveTicket`, `TicketPolicy::resolve` |
| Alterar status / retomar | Equipe de atendimento | `tickets.change_status` e transição válida (`TicketStatus::transitions()`). Aberto → Em atendimento exige responsável. Retomada encerra a pausa e estende o prazo de resolução. | `TicketStatusController`, `ChangeTicketStatus`, `TicketPolicy::changeStatus` |
| Registrar pendência | Equipe de atendimento | `tickets.change_status`; só a partir de Em atendimento; motivo obrigatório; quatro tipos com campos próprios; abre uma pausa de SLA. | `TicketPendingController`, `RegisterTicketPending`, `RegisterTicketPendingRequest` |
| Adicionar nota interna | Equipe de atendimento | `tickets.add_internal_note` e direito de comentar (chamado não Encerrado). Visível só a quem tem `view_internal_notes`. | `TicketPolicy::addInternalNote`, `TicketCommentController` |
| Alterar prioridade e categoria | Equipe de atendimento | `tickets.change_priority` e `tickets.change_category`, chamado não Encerrado, com detecção de edição concorrente. Trocar a prioridade recalcula o SLA. Não inclui o departamento (caso seguinte). | `TicketEditController`, `UpdateTicketRequest`, `UpdateTicketAttributes` |
| Atribuir ou reatribuir técnico | Gestor, Administrador | `tickets.assign` (sem responsável) ou `tickets.reassign` (com responsável). O destino deve ser um técnico ativo com `tickets.self_assign`. | `TicketAssignmentController`, `AssignTicketRequest`, `AssignTicket` |
| Transferir departamento | Gestor | `tickets.transfer_department`; chamado não Encerrado; departamento ativo. | `UpdateTicketRequest`, `UpdateTicketAttributes`, `TicketPolicy::transferDepartment` |
| Encerrar chamado | Gestor | `tickets.close`; status Resolvido. Resultado: Encerrado, `closed_at`, evento `Closed`; estado final. | `TicketClosingController`, `CloseTicket`, `TicketPolicy::close` |

### 3.4 Gestão e administração

| Caso de uso | Atores [REF] | Regra [CÓDIGO] | Arquivos |
|---|---|---|---|
| Consultar auditoria | Equipe de atendimento, Administrador | **Divergência D2.** Dois casos distintos: a auditoria **do chamado** (`audit.view` e acesso ao chamado) e a **global** (`audit.view` e `tickets.view_all_departments`). Somente leitura. | `TicketAuditController`, `AuditController`, `AuditLogPolicy`, `TicketPolicy::viewAudit` |
| Consultar painel de desempenho | Gestor, Administrador | **Divergência D3.** Gate `viewPerformance`: `reports.view_performance` e `tickets.view_all_departments`. | `PerformanceController`, `PerformanceReport`, `AppServiceProvider` |
| Exportar chamados (CSV) | Administrador | Papel `admin` ou `superadmin` (slug), mais usuário ativo; mesmo escopo, filtros e ordenação da listagem; sem resultado, 204; 403 para os demais. Neutraliza fórmulas. **Não** muda com a matriz de permissões. | `TicketExportController::ALLOWED_ROLES`, `TicketCsvExport`, `TicketExportRequest`; commit `c717ad5` |
| Gerenciar usuários | Administrador | `users.manage`. Editar exige que o operador possa conceder o papel atual do alvo; papel e situação da própria conta não são alteráveis; inativar revoga as sessões (driver `database`). Edição concorrente detectada. | `UserController`, `CreateUser`, `UpdateUser`, `UserPolicy` |
| Enviar convite por e-mail (`«extend»` de Gerenciar usuários) | Administrador, Servidor de e-mail | **Divergência D9.** Enviado na criação **de usuário ativo**, como e-mail de definição de senha; falha no envio não desfaz o cadastro. | `UserController::store`, `UserInvitation` |
| Gerenciar departamentos e categorias | Administrador | Duas permissões independentes: `departments.manage` e `categories.manage`. Inativar em vez de excluir. | `DepartmentController`, `CategoryController`, `DepartmentPolicy`, `TicketCategoryPolicy` |
| Gerenciar papéis e permissões | Administrador | `roles.manage`: papéis, matriz e catálogo (somente leitura). Ninguém retira `roles.manage` do próprio papel; só se concede o que o operador já possui entre as permissões administrativas. | `RoleController`, `RolePermissionController`, `PermissionController`, `UpdateRolePermissions`, `RolePolicy` |
| Gerenciar políticas de SLA | Administrador | `sla.manage`. Uma política ativa por prioridade (garantida no banco). Histórico exige também `audit.view`. | `SlaPolicyController`, `SlaPolicyPolicy`, `CreateSlaPolicy`, `UpdateSlaPolicy` |
| Registrar violações de SLA | Agendador | Comando a cada minuto, sem sobreposição; só lê chamados com prazo vencido e sem violação registrada; bloqueia cada chamado ao gravar. Depende de alguém executar `schedule:run`. | `RecordSlaBreaches`, `SlaClock::recordBreaches`, `routes/console.php`, `docker-compose.yml` (serviço `scheduler`) |

## 4. Matriz padrão de permissões

Reproduz a matriz da referência. **Não** representa as permissões atuais de uma instalação: veja a seção 7. A matriz canônica por permissão está em [05-rotas-e-permissoes.md](05-rotas-e-permissoes.md); esta tabela a reorganiza por caso de uso.

| Caso de uso | Regra no código [REF] | Solicitante | Técnico | Gestor | Admin |
|---|---|:-:|:-:|:-:|:-:|
| *Chamados* | | | | | |
| Visão geral, listar, abrir, comentar, baixar anexo | usuário ativo com papel · escopo `visibleTo` | ● | ● | ● | ● |
| Reabrir chamado resolvido | `isOriginalRequester` | ● | | | |
| *Atendimento* | | | | | |
| Assumir chamado | `tickets.self_assign` | | ● | | |
| Resolver chamado | `tickets.resolve` | | ● | | |
| Alterar status e registrar pendência | `tickets.change_status` | | ● | ● | |
| Adicionar nota interna | `tickets.add_internal_note` | | ● | ● | |
| Alterar prioridade e categoria | `tickets.change_priority` · `change_category` | | ● | ● | |
| Atribuir ou reatribuir técnico | `tickets.assign` · `tickets.reassign` | | | ● | ● |
| Transferir departamento | `tickets.transfer_department` | | | ● | |
| Encerrar chamado | `tickets.close` | | | ● | |
| *Gestão e administração* | | | | | |
| Consultar auditoria | `audit.view` | | ● | ● | ● |
| Consultar painel de desempenho | `reports.view_performance` | | | ● | ● |
| Exportar chamados (CSV) | papel `admin` ou `superadmin` · escopo `visibleTo` | | | | ● |
| Gerenciar usuários (com convite) | `users.manage` | | | | ● |
| Gerenciar departamentos e categorias | `departments.manage` · `categories.manage` | | | | ● |
| Gerenciar papéis e permissões | `roles.manage` | | | | ● |
| Gerenciar políticas de SLA | `sla.manage` | | | | ● |

**Conferência contra o seeder.** Cada ponto das colunas Solicitante, Técnico, Gestor e Admin corresponde à matriz de `RolesAndPermissionsSeeder` [SEED] e à matriz 7.4 do PRD, exceto as divergências D1 a D3 (linhas "Visão geral", "Consultar auditoria" e "Consultar painel de desempenho"). Esta conferência vale para o código lido em 27/10/2026.

## 5. Regras de negócio identificadas

Somente as que a referência ou o código sustentam; o detalhamento está em [02-requisitos-funcionais.md](02-requisitos-funcionais.md).

1. A autorização é por **permissão do papel**, com uma exceção: a exportação CSV é por **papel**. [REF] [CÓDIGO]
2. Usuário inativo não executa operações protegidas; sem papel, não abre nem comenta chamados. [CÓDIGO]
3. Técnicos elegíveis a receber atribuição: ativos e com `tickets.self_assign`. [CÓDIGO]
4. Visibilidade (`Ticket::scopeVisibleTo`): todos com `tickets.view_all_departments`; senão, os próprios, os atribuídos e, com `tickets.self_assign`, os Abertos sem responsável. [REF: "escopo visibleTo"] [CÓDIGO]
5. Resolver, encerrar, atribuir e autoatribuir são operações separadas, com permissões distintas. [CÓDIGO] [SEED]
6. A reabertura depende da **propriedade** do chamado, não de permissão. [REF] [CÓDIGO]
7. O anexo herda a visibilidade da mensagem; o da abertura é público. [CÓDIGO]
8. Chamado Encerrado não recebe comentários nem edições. [CÓDIGO]
9. O cadastro público atribui sempre `requester`. [CÓDIGO]
10. Violações de SLA são registradas por ações do chamado e pelo comando agendado; nunca são apagadas. [REF para o agendador] [CÓDIGO]
11. Operador só concede papéis cujas permissões administrativas ele já possui. [CÓDIGO]

## 6. Exceções e restrições de acesso

| Restrição | Efeito [CÓDIGO] | Arquivo |
|---|---|---|
| Exportação CSV | 403 para quem não tem papel `admin`/`superadmin`; o botão some da listagem (`can.export`). | `TicketExportController::isAllowed`, `TicketController::index` |
| Visão restrita do solicitante | Sem prioridade, SLA e solicitante nos dados; filtros e ordenações correspondentes recusados; dashboard redireciona. | `User::hasRestrictedTicketView`, `TicketIndexRequest`, `DashboardController` |
| Auditoria global | 403 para quem tem `audit.view` sem `view_all_departments` (por exemplo, o Técnico). | `AuditLogPolicy::viewAny` |
| Anexo de nota interna | 404 para quem não tem `view_internal_notes`. | `TicketPolicy::viewAttachment` |
| Edição de usuário | Bloqueada se o alvo tem papel que o operador não poderia conceder; papel e situação da própria conta não mudam. | `UserPolicy::update/changeAccess` |
| Matriz de permissões | Ninguém retira `roles.manage` do próprio papel. | `UpdateRolePermissions` |
| Convite | Não é enviado a usuário inativo. | `UserController::store` |
| Chamado Encerrado | Sem comentário, edição, atribuição, mudança de status. | `TicketPolicy` |

## 7. Permissões configuráveis × matriz padrão

- A matriz padrão é a configuração **inicial** [SEED]. Desde o PER-04, o Administrador pode mudar as permissões de **qualquer** papel, inclusive os de sistema [CONFIG]. Os testes `administrators and super administrators change the permissions of every role` e `operational permissions can be granted without holding them` registram esse comportamento.
- O seeder só associa permissões a papéis **recém-criados**. Em bancos já existentes, o que mudou depois do primeiro `seed` só chegou por migrations de dados: `audit.view` ao Técnico e `reports.view_performance` a Gestor e Administrador. [CÓDIGO]
- Não é possível saber, pelo código, quais permissões uma instalação específica tem hoje. **[NÃO CONFIRMADO]**
- Itens que a configuração **não** altera: o conjunto de papéis que exportam CSV e o atalho da reabertura pela propriedade.
- Efeitos indiretos de mexer na matriz: sem `tickets.view_all_departments`, o Administrador vê (e exporta) só os chamados que abriu ou atende (`TicketExportTest`); sem `view_internal_notes`, um papel cai na visão restrita.

## 8. Casos presentes no código e ausentes do diagrama

Não são erros da referência; são lacunas de cobertura que a integração registra.

| Comportamento no código | Onde |
|---|---|
| Sair (logout) | `AuthenticatedSessionController::destroy` |
| Excluir a própria conta | `ProfileController::destroy` (ver [10-debitos-tecnicos.md](10-debitos-tecnicos.md), T3) |
| Consultar histórico de SLA, auditoria e versões do comentário de um chamado | Subconsultas de "Consultar detalhes" |
| Consultar o catálogo de permissões | `PermissionController` (`roles.manage`) |
| Verificar e-mail e confirmar senha | Rotas de `routes/auth.php`; `verified` não bloqueia e nenhuma rota usa `password.confirm` |

Editar comentário **não** está no diagrama, de acordo com o código (a Action existe, mas não há rota).

## 9. Divergências entre a referência e o código

| # | A referência diz | O código faz | Evidência |
|---|---|---|---|
| D1 | O Solicitante tem "Consultar visão geral". | `/dashboard` redireciona o Solicitante à listagem; o item de menu exige `tickets.view_internal_notes`. O PRD (RF-24) e o teste "the requester has no overview" concordam com o código. | `DashboardController`, `DashboardTest`, `lib/navigation.ts` |
| D2 | "Consultar auditoria" é um caso, com `audit.view`, para Técnico, Gestor e Admin. | Há dois: do chamado (`audit.view`) e global (`audit.view` e `view_all_departments`). O Técnico recebe 403 em `/audit`. | `AuditLogPolicy`, `TicketPolicy::viewAudit`, teste "the technician reads ticket audits but not the global audit trail" |
| D3 | Desempenho: `reports.view_performance`. | Exige também `tickets.view_all_departments`. | Gate `viewPerformance`, `PerformanceTest` |
| D4 | Regra comum: "usuário ativo com papel". | Listar, detalhes, visão geral e baixar exigem só usuário ativo; papel é exigido para abrir, comentar e reabrir. | `TicketPolicy::viewAny/create/comment` |
| D5 | Reabrir é do Solicitante. | É do solicitante **original**, de qualquer papel. | `TicketPolicy::isOriginalRequester` |
| D6 | "Equipe de atendimento" não inclui o Administrador. | O código define "equipe" por `view_internal_notes`, que o Administrador tem no padrão: ele sai da visão restrita, vê SLA, pendência e dashboard, e seu comentário público conta como primeira resposta. | `User::hasRestrictedTicketView`, `TicketCommentController` |
| D7 | "Assumir chamado": `tickets.self_assign`. | Também exige técnico ativo, chamado Aberto e sem responsável, visível. | `TicketPolicy::selfAssign` |
| D8 | "Baixar anexo" para todos, sem ressalva. | Anexo de nota interna exige `view_internal_notes`; negado vira 404. | `TicketPolicy::viewAttachment` |
| D9 | "Gerenciar usuários (com convite)" e "Comentar" sem ressalvas. | Convite só para usuário ativo e sem rollback se falhar; comentário não vale em chamado Encerrado. | `UserController::store`, `TicketPolicy::comment` |

Outras diferenças menores: a matriz agrupa departamentos e categorias, que são duas permissões; e o texto da referência cita só o Agendador do Windows, enquanto o projeto também traz um serviço `scheduler` em `docker-compose.yml`.

## 10. Pendências que dependem de confirmação

1. **Corrigir a referência?** Decidir se as divergências D1 a D3 e D5 a D9 devem virar ajustes no HTML e no `.puml` (hoje intocados) ou ficar apenas registradas aqui.
2. **Papel do Administrador na "Equipe de atendimento" (D6).** Confirmar se é intencional que o Administrador conte como equipe para visibilidade e primeira resposta.
3. **Exportação CSV por papel.** Confirmar se a restrição a `admin` e `superadmin` é definitiva ou deve virar permissão (o PRD fala em "filtros e permissões").
4. **Agendador.** Confirmar em cada ambiente quem executa `schedule:run`. O código só agenda o comando. **[NÃO CONFIRMADO]**
5. **Login de inativo.** O fluxo `Entrar` não verifica `is_active`; falta teste e decisão.
6. **Excluir conta.** Decidir se esse caso de uso existe como requisito, dado que as chaves estrangeiras o impedem para quem tem atividade.
7. **Renderização do PlantUML.** Não executei o PlantUML; a sincronização do `.puml` com o HTML foi conferida por leitura.
8. **Permissões atuais.** Qualquer afirmação sobre uma instalação específica exige consultar o banco dela.

## 11. Referências cruzadas

| Assunto | Documento |
|---|---|
| Matriz completa, policies e regras especiais | [05-rotas-e-permissoes.md](05-rotas-e-permissoes.md) |
| Estados, SLA, anexos, CSV | [02-requisitos-funcionais.md](02-requisitos-funcionais.md) |
| Rotas | [05-rotas-e-permissoes.md](05-rotas-e-permissoes.md) |
| Fluxos de dados | [03-arquitetura.md](03-arquitetura.md) |
| Riscos e dívida | [07-seguranca.md](07-seguranca.md), [10-debitos-tecnicos.md](10-debitos-tecnicos.md) |
| Testes | [08-testes-e-qualidade.md](08-testes-e-qualidade.md) |
