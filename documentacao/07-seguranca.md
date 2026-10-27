# Segurança

> Atualizado em 27/10/2026 (commit `bdb7667`). Convenção de confiança em [README.md](README.md).

**Escopo e limites.** Os controles abaixo foram levantados por leitura do código. A suíte automatizada passou (1.486 casos), mas nenhum teste de intrusão, varredura de dependências ou revisão de infraestrutura foi feito. Este capítulo **não** afirma que o sistema é seguro nem que está pronto para produção: apenas registra o que existe e o que preocupa.

## Controles existentes

| Tema | Controle | Evidência |
|---|---|---|
| Autenticação | Senhas com hash (`'password' => 'hashed'`), limitador de 5 tentativas por e-mail e IP, sessão regenerada no login; o logout invalida a sessão e renova o token CSRF. | `User::casts`, `LoginRequest`, `AuthenticatedSessionController` |
| Recuperação de senha | Resposta sempre igual, exista a conta ou não. | `PasswordResetLinkController` |
| Escalada de privilégio | Cadastro público força o papel `requester`; `role_id` e `department_id` não são atribuíveis em massa; o operador só concede papéis cujas permissões administrativas ele já possui. | `RegisteredUserController`, `Role::isGrantableBy`, `UserPolicy` |
| Autorização em camadas | Policy ou Gate no controller, Form Request e reautorização na Action sobre a linha bloqueada. | [05-rotas-e-permissoes.md](05-rotas-e-permissoes.md) |
| IDOR | Identificador público aleatório (`HD-XXXXXXXX`), `view` baseado no escopo, entidades de edição carregadas só depois da autorização, anexo buscado dentro do chamado da rota. | `Ticket::getRouteKeyName`, `TicketAttachmentController` |
| Mass assignment | `$fillable` mínimo; modelos de histórico, auditoria, anexo e pausa usam `$guarded = ['*']` e `forceCreate` com campos definidos pelo backend. | Models |
| Notas internas | Filtradas na consulta SQL (comentários, versões, anexos e eventos de auditoria) e ocultas da exportação e do dashboard. | `scopeVisibleTo`, `TicketAuditController` |
| Visão restrita | Prioridade, SLA e solicitante nem são enviados; filtros e ordenações correspondentes são recusados no servidor. | `TicketController`, `TicketIndexRequest` |
| Anexos | Lista de extensões, MIME detectado pelo conteúdo, verificações por tipo, nome UUID, disco privado sem URL pública, cotas, download como arquivo com `nosniff`, `Content-Security-Policy: default-src 'none'; sandbox` e `no-store`. 404 quando negado. | `AttachmentInspector`, `StoreTicketAttachments`, `config/attachments.php` |
| CSV | Neutralização de fórmulas, `nosniff`, `no-store`, nome de arquivo fixo e sem dados do usuário. | `TicketCsvExport`, `TicketExportController` |
| Busca | Curingas `%` e `_` escapados nos filtros `LIKE`. | `TicketListingQuery` |
| Duplo envio | Token UUID com `Cache::add` atômico. | `SingleSubmission` |
| Integridade | `restrictOnDelete`, unicidades no banco, transações com `lockForUpdate`. | Migrations, Actions |
| Erros | Página de erro neutra para 403 e, fora de `local` e `testing`, 404, 500 e 503. | `bootstrap/app.php` |
| Segredos | `.env`, `.env.docker` e `helpdesk.sql` fora do Git; `.env.example` sem valores reais. | `.gitignore` |
| XSS no frontend | Nenhum `v-html` ou `innerHTML`; conteúdo escapado pelo Vue. | Varredura em `resources/js` |

## Riscos conhecidos

Em ordem aproximada de relevância. A coluna "Situação" diz como cada item foi estabelecido.

| # | Risco | Situação | Detalhe |
|---|---|---|---|
| 1 | `DatabaseSeeder` cria `test@example.com` com senha conhecida (senha padrão da factory). | **Comprovado** no código | O passo Docker `db:seed --force` roda com `APP_ENV=production`. A conta nasce sem papel (poucas permissões), mas é uma credencial conhecida. |
| 2 | Login de usuário inativo não é bloqueado na autenticação. | **Comprovado** no código; sem teste | Policies negam as telas, mas `/profile` só exige `auth`. |
| 3 | Exclusão de conta própria (`ProfileController::destroy`) é exclusão real. | **Inferido** do esquema; não executado | Deve falhar por chave estrangeira para quem tem atividade; qualquer papel pode se excluir. |
| 4 | Cadastro público sem limitador próprio e sem verificação de e-mail obrigatória. | **Comprovado** | Qualquer pessoa cria conta de Solicitante e abre chamados com anexos. |
| 5 | `verified` não bloqueia. | **Comprovado** no fonte do framework | O `User` usa a trait `MustVerifyEmail`, mas não implementa a interface. O PRD diz "quando habilitada". |
| 6 | `UpdateTicketAttributes` não reautoriza sob lock. | **Comprovado** | Janela estreita em que um chamado encerrado entre a autorização e o lock ainda poderia ser editado. |
| 7 | Sem antivírus e sem exclusão de anexos. | **Declarado** no README | A inspeção por conteúdo não substitui um scanner. |
| 8 | Fonte carregada de `fonts.bunny.net`. | **Comprovado** | Requisição a terceiro em cada página (privacidade e disponibilidade). |
| 9 | O Inertia compartilha o usuário inteiro (`$request->user()`). | **Comprovado** | Campos ocultos (`password`, `remember_token`) não saem, mas e-mail, papel e situação sim. |
| 10 | `docker compose up` sobe em modo desenvolvimento. | **Comprovado** | O override define `APP_DEBUG=true`. Produção exige `-f docker-compose.yml`. |
| 11 | Concorrência dos locks só é garantida em MariaDB. | **Pendente** (não testado) | A suíte usa SQLite. |
| 12 | A exportação CSV é autorizada pelo **papel** (`admin`/`superadmin`), fora do modelo de permissões. | **Comprovado** | Não muda com a matriz de permissões; o escopo exportado depende de `tickets.view_all_departments`. |
| 13 | O Administrador conta como "equipe" para o código (`view_internal_notes`), mas não para o diagrama de casos de uso. | **Comprovado** | Ele vê SLA, dados de pendência e dashboard, e seu comentário público vale como primeira resposta do SLA. Decidir se é intencional (D6). |
| 14 | O registro de violações de SLA depende de um agendador externo. | **Comprovado** no código; execução **pendente** de verificação | O código agenda o comando a cada minuto; quem executa `schedule:run` varia por ambiente. |
| 15 | `storage/framework/lsp-8ebd30caf6e53d1a.php` foi versionado no último commit. | **Comprovado** | Arquivo auxiliar de IDE (o commit o descreve como helper do LSP). Não foi inspecionado quanto a dados sensíveis; não deveria estar no Git (T16). |

## Não verificado

- Cabeçalhos de segurança do servidor web, CORS, política de cookies em produção e configuração de HTTPS.
- Dependências vulneráveis: `composer audit` e `npm audit` **não foram executados**.
- O conteúdo do `.env`, do `.env.docker`, do `.claude/settings.local.json` e do `helpdesk.sql`.
- Comportamento sob carga, limites reais de upload no servidor de produção e efeito do `throttle` atrás de proxy.
- Qualquer instalação em uso: permissões editadas na matriz, usuários existentes e contas de teste que ainda estejam ativas.

## Antes de publicar ou colocar em uso

Não é um atestado de prontidão, apenas os pontos que a análise indica tratar primeiro: itens 1, 2, 3 e 10 da tabela; rodar `composer audit` e `npm audit`; remover `storage/framework/lsp-*.php` do Git; decidir sobre a verificação de e-mail e sobre o limitador do cadastro. Os demais débitos estão em [10-debitos-tecnicos.md](10-debitos-tecnicos.md).
