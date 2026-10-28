# Testes e qualidade

> Atualizado em 27/10/2026 (commit `bdb7667`). Convenção de confiança em [README.md](README.md).

## Resultado da execução (27/10/2026)

Executado nesta análise, com autorização do responsável, na máquina local (PHP 8.2.4).

| Verificação | Resultado |
|---|---|
| `php artisan test --compact` | **1.486 aprovados**, 0 falhas, 9.596 asserções, 122,78 s |
| `npm run build` (`vue-tsc` e Vite) | Sem erros; build concluído em 11,52 s |
| `php artisan route:list` | 71 rotas (somente leitura) |
| Git após as execuções | Sem alterações em arquivos versionados |

Efeitos em arquivos ignorados pelo Git: `public/build/` regenerado (107 arquivos de `assets/` substituídos) e `storage/logs/laravel.log` com 6,4 KB a mais (duas entradas de falhas simuladas de propósito pelos testes, em `UserCreateTest.php:226` e `TicketExportTest.php:334`). Existe um `public/hot` (faz a aplicação procurar assets no servidor do Vite); esta análise não o alterou nem o removeu.

O número bate com o declarado em `TESTES.md`. Contagem estática: 1.039 chamadas `test()`/`it()` em 59 arquivos (a pasta `tests/` tem 61 arquivos `.php`, contando os de apoio); datasets expandem isso até 1.486 casos. Os resultados valem para o código e o ambiente daquela data, em SQLite.

## Ferramental

- Pest 3.8.7 sobre PHPUnit 11.5.56, com `pestphp/pest-plugin-laravel`.
- `tests/Pest.php`: `RefreshDatabase` para todo o diretório `Feature`.
- `phpunit.xml`: SQLite em memória, `CACHE_STORE=array`, `SESSION_DRIVER=array`, `QUEUE_CONNECTION=sync`, `MAIL_MAILER=array`, `BCRYPT_ROUNDS=4`.
- Anexos testados com `Storage::fake('attachments')`; e-mails e notificações com `Notification::fake` (convite de usuário e redefinição de senha).

## Organização

```
tests/
├── Unit/                    SlaClock, transições de TicketStatus (+ ExampleTest)
└── Feature/
    ├── Admin/               usuários, departamentos, categorias, papéis, matriz, catálogo (14 arquivos)
    ├── Audit/               auditoria global
    ├── Auth/                login, cadastro, senha, verificação de e-mail
    ├── SlaPolicies/         índice, criação, edição, detalhes
    ├── Tickets/             ciclo completo do chamado, SLA, anexos, exportação, visão do solicitante
    └── *.php                Dashboard, Performance, Profile, SlaClockPolicy, migração de permissão
```

Módulos com mais casos (contagem de `test()`/`it()` por arquivo): `TicketPendingTest` (36), `TicketResolutionTest` (32), `RolePermissionMatrixTest` (32), `PerformanceTest` (31), `TicketReopeningTest` (30).

## Estratégia observada

- **Teste por funcionalidade** (um arquivo por requisito CHA, ADM ou SLA), com `beforeEach` que roda o seeder real e cria usuários de cada papel.
- **Estado por fluxo real:** os testes montam o cenário chamando as próprias Actions (por exemplo, `ResolveTicket` antes de testar o encerramento) e depois exercitam a rota HTTP.
- **Foco em autorização:** muitas famílias de casos cobrem papéis sem permissão, usuários inativos, usuários sem papel, identificadores forjados e o escopo de visibilidade.
- **Conflitos de edição:** testados simulando uma versão desatualizada (`editVersion` ou status esperado), não com execução paralela real.
- **Contrato do Inertia:** `assertInertia` verifica as props enviadas à página, o que cobre o acordo entre backend e frontend.

## Qualidade estática

| Item | Situação |
|---|---|
| Tipos no frontend | `vue-tsc` em modo `strict` roda dentro de `npm run build` e passou. |
| Estilo PHP | Pint 1.30.4 instalado, **sem `pint.json`**; não foi executado nesta análise. Segundo `docs/pendencias.md` (24/10/2026), o Pint já falhava no baseline por fim de linha CRLF e estilo de código antigo, e o projeto inteiro não foi reformatado. |
| ESLint, Prettier, PHPStan | Não existem. |
| CI | Não há pipeline; a suíte é rodada à mão. |
| Testes de frontend | Não existem (nem de componentes nem de ponta a ponta). |

## O que a suíte não prova

| Lacuna | Detalhe |
|---|---|
| Banco de produção | SQLite não reproduz `lockForUpdate`, e o ramo `DATE_SUB` de `Ticket::atRiskSql` (MariaDB) não é exercitado. As colunas geradas só rodam em SQLite. |
| Concorrência real | Não há teste com requisições simultâneas; os locks são verificados por inspeção do código. |
| Login de usuário inativo | Não há teste em `tests/Feature/Auth`. |
| Exclusão de conta com atividade | `ProfileTest` apaga apenas um usuário sem chamados. |
| Verificação de e-mail obrigatória | O teste confirma o link de verificação, mas o `verified` não bloqueia (o `User` não implementa `MustVerifyEmail`). |
| Edição de comentário | Só a Action é testada (`TicketCommentHistoryTest`, `TicketFirstResponseTest`); não há rota para exercitar. |
| Agendador | O comando é testado; quem executa `schedule:run` em cada ambiente não é. |
| Carga e desempenho | Fora do escopo declarado em `TESTES.md`. |
| Segurança ativa | Nenhum teste de intrusão; nenhuma varredura de dependências. |

## Validação manual declarada

`TESTES.md` descreve oito cenários (CT-01 a CT-08): seis testes manuais no navegador (login, abertura, comentário e anexo, status, regras de acesso, dashboard) e duas revisões de código. Esses resultados são **declaração do autor do documento**; esta análise não os reproduziu. `docs/relatorio-qa-helpdesk-pro.md` traz um QA parcial (38 verificações aprovadas, nenhuma falha confirmada, três observações) que deixa explícito o que **não** foi executado, incluindo usuário inativo, edição de comentário e anexos. A observação OBS-001 (tela de SLA com "Restam 2 min" em chamado pausado cuja violação já estava registrada) não foi reproduzida aqui.

## Como rodar

Comandos conferidos contra `composer.json`, `package.json` e `README.md`:

```bash
composer test                          # limpa o cache de config e roda a suíte
php artisan test --compact
php artisan test --filter=TicketSla    # um módulo
npm run build                          # vue-tsc + vite build
```
